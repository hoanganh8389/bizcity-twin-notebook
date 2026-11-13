<?php
/**
 * BizCity KG-Hub — LiteParse Layout-Preserving Adapter (Phase 0.42).
 *
 * Tier-2 adapter sitting BETWEEN existing Tier-1 (Smalot / pdftotext) and the
 * future Tier-3 Vision OCR (/tools/ocr). Wraps the upstream LiteParse CLI
 * (`lit parse` from `@llamaindex/liteparse`) or an optional HTTP sidecar
 * (`BIZCITY_LITEPARSE_URL`) — whichever is available on the host.
 *
 * Why a separate adapter
 * ----------------------
 *   - Tier-1 PDF/Office drops tables, multi-column flow and scan PDFs.
 *   - Vendor "upload-multimodal-to-Gemini" was rejected (vendor lock-in,
 *     privacy for enterprise customers, multipart refactor in gateway).
 *   - LiteParse keeps everything local: deterministic ASCII layout text +
 *     optional per-page PNG screenshot + bbox blocks; works offline.
 *
 * Registry behaviour
 * ------------------
 *   - `supports()` returns TRUE only when ALL of:
 *       1. Extension/MIME matches one of SUPPORTED_EXTS / SUPPORTED_MIMES.
 *       2. `is_available()` (cached) succeeds — sidecar URL responds OR
 *          local `lit` binary is on PATH AND `shell_exec` is allowed.
 *       3. Current user is logged in AND `BizCity_Entitlement::can(uid,
 *          'learning.layout_parse')` is true.
 *   - For Free users, anonymous requests and cron context (uid=0),
 *     `supports()` returns FALSE so the registry transparently falls through
 *     to the existing Tier-1 PDF / Office adapters — no 402 regression for
 *     plain text-layer PDFs.
 *
 * Output schema (matches `BizCity_KG_Source_Adapter::extract`)
 * ------------------------------------------------------------
 *   [
 *     'text'     => "[Page 1]\n...\n\n[Page 2]\n...",
 *     'segments' => [ { page_num, text, bbox_blocks?: [...] }, ... ],
 *     'assets'   => [ { kind:'screenshot', page_num, relative_path,
 *                       mime:'image/png', bytes, sha256 }, ... ],
 *     'modality' => 'pdf_layout' | 'office_layout' | 'image_layout',
 *     'meta'     => [
 *        'engine'             => 'liteparse_cli' | 'liteparse_http',
 *        'liteparse_version'  => '0.x.y' | '',
 *        'page_count'         => int,
 *        'has_screenshots'    => bool,
 *        'screenshot_dir'     => 'twin-vectors/{blog}/screenshots/{sha256}/',
 *        'total_chars'        => int,
 *        'duration_ms'        => int,
 *     ],
 *   ]
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KGHub\Adapters
 * @since      2026-05-27 (Phase 0.42)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

// Default sidecar URL — baked in so the adapter works out-of-the-box when the
// HTTP sidecar runs on the same host at port 7860. Override via wp-config.php:
//   define( 'BIZCITY_LITEPARSE_URL', 'http://127.0.0.1:7860' );
// or via env var BIZCITY_LITEPARSE_URL (Docker / cPanel env).
// This file is gitignored (private addon) — constant travels with the file, not wp-config.php.
defined( 'BIZCITY_LITEPARSE_URL' ) or define( 'BIZCITY_LITEPARSE_URL', 'http://127.0.0.1:7860' );

class BizCity_KG_LiteParse_Adapter implements BizCity_KG_Source_Adapter {

	const FEATURE_KEY      = 'learning.layout_parse';
	const SHELL_TIMEOUT    = 90;   // seconds — guard against hangs
	const HTTP_TIMEOUT     = 95;   // seconds — sidecar mode
	const SCREENSHOT_MAX   = 30;   // cap pages screenshotted per file

	// Gemini Flash fallback (Wave 0.42.B) — reuses /bizcity/v1/llm/chat via
	// BizCity_LLM_Client. Same Bearer biz-xxx auth, same Pro entitlement gate.
	const GEMINI_FALLBACK_MODEL = 'google/gemini-2.5-flash'; // legacy default when tier cannot be resolved
	const GEMINI_FALLBACK_FB    = 'google/gemini-2.5-flash-lite'; // legacy fallback slot
	const GEMINI_MODEL_FREE     = 'google/gemini-2.0-flash-001';
	const GEMINI_MODEL_PRO      = 'google/gemini-2.5-flash';
	const GEMINI_MODEL_PREMIUM  = 'google/gemini-2.5-pro';
	const GEMINI_MODEL_FB_FREE    = 'google/gemini-2.0-flash-001';
	const GEMINI_MODEL_FB_PRO     = 'google/gemini-2.0-flash-001';
	const GEMINI_MODEL_FB_PREMIUM = 'google/gemini-2.5-flash';
	const GEMINI_INLINE_CAP     = 18874368; // 18 MB — OpenAI-compat inline limit
	const GEMINI_MAX_PAGES_HINT = 25;       // soft cap embedded in prompt to keep cost bounded
	const GEMINI_TIMEOUT        = 120;      // seconds — LLM cold-start + multipage parse
	const GEMINI_INVALID_JSON_RETRIES = 2;  // first pass + one reduced-output retry
	const GEMINI_CHUNK_PAGE_SIZE      = 6;  // page window size per chunk call
	const GEMINI_CHUNK_MAX_PARTS      = 24; // max 144 pages per chunked fallback run

	const SUPPORTED_EXTS = [
		// PDF
		'pdf',
		// Office
		'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt',
		'odt', 'ods', 'odp', 'rtf', 'csv', 'tsv',
		// Image (layout / OCR)
		'jpg', 'jpeg', 'png', 'tiff', 'tif', 'bmp', 'webp',
	];

	const SUPPORTED_MIMES = [
		'application/pdf',
		'application/x-pdf',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/msword',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.ms-excel',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'application/vnd.ms-powerpoint',
		'application/vnd.oasis.opendocument.text',
		'application/vnd.oasis.opendocument.spreadsheet',
		'application/vnd.oasis.opendocument.presentation',
		'application/rtf', 'text/rtf',
		'text/csv', 'text/tab-separated-values',
		'image/jpeg', 'image/png', 'image/tiff', 'image/bmp', 'image/webp',
	];

	/**
	 * [2026-07-27 Johnny Chu] HOTFIX learning-log-parity — mirror `[liteparse]`
	 * evidence into the canonical per-blog learning log
	 * (uploads/.../bizcity_learning_logs/YYYY-MM-DD.log) in ADDITION to the
	 * existing error_log() call, so parse/chunk failures (FPDI missing,
	 * Gemini quota exhausted, invalid JSON…) are downloadable/greppable from
	 * the Learning Log UI's "Tải JSON" export without SSH access to the
	 * shared PHP error log.
	 *
	 * @param string $msg
	 */
	private static function tc_log( $msg ) {
		if ( function_exists( 'bizcity_tc_learning_debug_log' ) ) {
			bizcity_tc_learning_debug_log( (string) $msg );
		}
	}

	/* ────────────────────────  Contract  ──────────────────────── */

	public static function id() {
		return 'liteparse';
	}

	public static function supports( $ext, $mime ) {
		$ext  = strtolower( (string) $ext );
		$mime = strtolower( (string) $mime );

		$ext_ok  = $ext !== '' && in_array( $ext, self::SUPPORTED_EXTS, true );
		$mime_ok = $mime !== '' && in_array( $mime, self::SUPPORTED_MIMES, true );
		if ( ! $ext_ok && ! $mime_ok ) {
			return false;
		}

		// Tier gate at supports-time so the registry falls through to Tier-1
		// for Free / cron / anonymous contexts (R-GW-8: BizCity_Entitlement
		// only exists when bizcity-llm-router is installed — guard accordingly).
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			return false;
		}
		if ( class_exists( 'BizCity_Entitlement' )
			&& ! BizCity_Entitlement::can( $uid, self::FEATURE_KEY )
		) {
			return false;
		}

		// [2026-07-14 Johnny Chu] HOTFIX — keep supports() aligned with extract().
		// Do not claim this adapter unless at least one concrete path can serve
		// the current extension under the current runtime flags.
		if ( self::native_engine_enabled() && self::is_available() ) {
			return true;
		}
		if ( self::is_office_ext( $ext ) && class_exists( 'ZipArchive' ) ) {
			return true;
		}
		if ( self::gemini_fallback_enabled() && self::is_gemini_eligible_ext( $ext ) ) {
			return true;
		}
		return false;
	}

	public function extract( $file_path, array $opts ) {
		$t0 = microtime( true );

		if ( ! is_string( $file_path ) || ! file_exists( $file_path ) ) {
			return new WP_Error( 'liteparse_file_missing', 'LiteParse: input file not found' );
		}
		if ( ! is_readable( $file_path ) ) {
			return new WP_Error( 'liteparse_file_unreadable', 'LiteParse: input file unreadable' );
		}

		// Re-check tier inside extract() in case caller bypassed supports()
		// (e.g. manual filter wiring or a test harness). Returns 402 here.
		$uid = isset( $opts['user_id'] ) ? (int) $opts['user_id'] : (int) get_current_user_id();
		if ( $uid > 0
			&& class_exists( 'BizCity_Entitlement' )
			&& ! BizCity_Entitlement::can( $uid, self::FEATURE_KEY )
		) {
			return new WP_Error(
				'tier_required',
				'LiteParse layout parsing requires a Pro plan.',
				[
					'http_status'      => 402,
					'requires_feature' => self::FEATURE_KEY,
				]
			);
		}

		$ext  = strtolower( (string) ( $opts['ext'] ?? pathinfo( $file_path, PATHINFO_EXTENSION ) ) );
		$mime = strtolower( (string) ( $opts['mime'] ?? '' ) );

		// ── Path A · native LiteParse engine (sidecar > CLI) ─────────────
		// [2026-06-08 Johnny Chu] HOTFIX — Python sidecar disabled (missing python-docx / libreoffice).
		// Route straight to Gemini Flash fallback (Path B). Re-enable by removing `false &&`
		// once sidecar host has `pip install python-docx` and sidecar restarted.
		$native_err = null;
		$engine     = '';
		$pages      = [];
		$parsed     = null;
		// [2026-07-14 Johnny Chu] HOTFIX — native parser path now behind explicit flag.
		if ( self::native_engine_enabled() && self::is_available() ) {
			$engine = self::picked_engine();
			// [2026-06-08 Johnny Chu] HOTFIX — forward opts so run_via_http/build_multipart can use original filename (prevents HTTP 415 when $file_path is a tmp name without extension)
			$parsed = ( $engine === 'liteparse_http' )
				? self::run_via_http( $file_path, $opts )
				: self::run_via_cli( $file_path );
			if ( is_wp_error( $parsed ) ) {
				$native_err = $parsed;
			} else {
				$pages = isset( $parsed['pages'] ) && is_array( $parsed['pages'] ) ? $parsed['pages'] : [];
				if ( empty( $pages ) ) {
					$native_err = new WP_Error( 'liteparse_empty', 'LiteParse returned no pages.', [ 'http_status' => 422 ] );
				} else {
					// [2026-06-08 Johnny Chu] HOTFIX — detect sidecar placeholder when Office deps missing
					// Sidecar returns HTTP 200 + JSON but text = "[office text extraction unavailable ...]"
					// → adapter must treat this as native_err so Gemini fallback can trigger.
					$first_text = trim( (string) ( $pages[0]['text'] ?? '' ) );
					if ( strpos( $first_text, '[office text extraction unavailable' ) !== false
						|| strpos( $first_text, 'install libreoffice' ) !== false
						|| strpos( $first_text, 'install python-docx' ) !== false
					) {
						$native_err = new WP_Error(
							'liteparse_office_deps_missing',
							$first_text,
							[
								'http_status' => 422,
								'hint'        => 'Run: pip install python-docx  OR  install libreoffice on sidecar host, then restart sidecar.',
							]
						);
						$pages = [];
					}
				}
			}
		} else {
			// [2026-06-08 Johnny Chu] HOTFIX — native engine skipped; PHP-native (Path A.5) will try first.
			$native_err = new WP_Error( 'liteparse_skipped', 'LiteParse native engine disabled.' );
		}

		// ── Path A.5 · PHP-native Office extraction (ZipArchive — no server needed) ──
		// [2026-06-08 Johnny Chu] HOTFIX — DOCX/ODT/XLSX can be read as ZIP in pure PHP.
		// This is faster, cheaper, and works offline. Runs before Gemini fallback.
		if ( empty( $pages ) && self::is_office_ext( $ext ) ) {
			$php_native = self::run_via_php_office( $file_path, $ext );
			if ( ! is_wp_error( $php_native ) && ! empty( $php_native['pages'] ) ) {
				$engine     = 'php_native';
				$pages      = $php_native['pages'];
				$native_err = null;
				$msg = '[liteparse] PHP-native Office OK · ext=' . $ext . ' pages=' . count( $pages );
				error_log( $msg );
				self::tc_log( $msg );
			} else {
				$native_err = is_wp_error( $php_native ) ? $php_native
					: new WP_Error( 'php_office_empty', 'PHP-native Office extractor returned no text.' );
				$msg = '[liteparse] PHP-native Office FAILED: ' . $native_err->get_error_message();
				error_log( $msg );
				self::tc_log( $msg );
			}
		}

		// ── Path B · Gemini Flash fallback via /bizcity/v1/llm/chat ──────
		// Only for IMAGE files (jpg/png/pdf) — Gemini image_url accepts these.
		// Office binary formats (docx/xlsx/pptx) MUST NOT reach Gemini via image_url (502).
		$fallback_meta = null;
		$gemini_err    = null;
		if ( empty( $pages ) && $native_err !== null && self::gemini_fallback_enabled() && self::is_gemini_eligible_ext( $ext ) ) {
			$gemini = self::run_via_gemini( $file_path, $ext, $mime );
			if ( ! is_wp_error( $gemini ) ) {
				$engine = 'gemini_flash';
				$pages  = $gemini['pages'];
				$fallback_meta = [
					'gemini_model'    => $gemini['meta']['model']     ?? self::GEMINI_FALLBACK_MODEL,
					'gemini_usage'    => $gemini['meta']['usage']     ?? null,
					'gemini_duration' => $gemini['meta']['duration_ms'] ?? null,
					'native_error'    => $native_err->get_error_code(),
				];
				$msg = '[liteparse] native skipped → Gemini fallback OK · ' . count( $pages ) . ' pages';
				error_log( $msg );
				self::tc_log( $msg );
			} else {
				// [2026-06-08 Johnny Chu] HOTFIX — store Gemini error to surface real failure message
				$gemini_err = $gemini;
				$msg = '[liteparse] Gemini fallback FAILED: ' . $gemini->get_error_code() . ' — ' . $gemini->get_error_message();
				error_log( $msg );
				self::tc_log( $msg );
			}
		}

		// Both paths failed — return the most informative error (Gemini error > native error).
		if ( empty( $pages ) ) {
			return $gemini_err ?: $native_err ?: new WP_Error( 'liteparse_unknown', 'LiteParse: no engine produced output.' );
		}

		$segments    = [];
		$text_total  = '';
		$total_chars = 0;
		foreach ( $pages as $i => $p ) {
			$page_num = (int) ( $p['page'] ?? ( $i + 1 ) );
			$txt      = self::cleanup_text( (string) ( $p['text'] ?? '' ) );
			$bbox     = isset( $p['blocks'] ) && is_array( $p['blocks'] ) ? $p['blocks'] : [];

			$segments[]  = [
				'page_num'    => $page_num,
				'text'        => $txt,
				'bbox_blocks' => $bbox,
			];
			$total_chars += strlen( $txt );
			if ( $txt !== '' ) {
				$text_total .= "\n\n[Page {$page_num}]\n" . $txt;
			}
		}
		$text_total = trim( $text_total );

		// Optional screenshots (CLI mode only — sidecar can be extended later).
		$assets        = [];
		$screenshot_ok = false;
		$screen_rel    = '';
		if ( $engine === 'liteparse_cli' && ! empty( $opts['with_screenshots'] ) ) {
			$blog_id = isset( $opts['blog_id'] ) ? (int) $opts['blog_id'] : (int) get_current_blog_id();
			$sha     = hash_file( 'sha256', $file_path );
			$shot    = self::shoot_pages( $file_path, $pages, $blog_id, $sha );
			if ( ! is_wp_error( $shot ) ) {
				$assets        = $shot['assets'];
				$screen_rel    = $shot['rel_dir'];
				$screenshot_ok = ! empty( $assets );
			}
		}

		$modality = self::modality_for_ext( $ext, $mime );

		$meta = [
			'engine'            => $engine,
			'liteparse_version' => self::version_string(),
			'page_count'        => count( $segments ),
			'has_screenshots'   => $screenshot_ok,
			'screenshot_dir'    => $screen_rel,
			'total_chars'       => $total_chars,
			'duration_ms'       => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
		];
		if ( $fallback_meta !== null ) {
			$meta['fallback'] = $fallback_meta;
		}

		return [
			'text'     => $text_total,
			'segments' => $segments,
			'assets'   => $assets,
			'modality' => $modality,
			'meta'     => $meta,
		];
	}

	/* ────────────────────────  Availability  ──────────────────────── */

	/**
	 * Cached per-request engine availability check.
	 * Order: HTTP sidecar (BIZCITY_LITEPARSE_URL) → CLI on PATH.
	 *
	 * Wrappable via `bizcity_kg_liteparse_available` filter for tests.
	 *
	 * @return bool
	 */
	public static function is_available() {
		static $cached = null;
		if ( $cached !== null ) {
			return $cached;
		}

		$forced = apply_filters( 'bizcity_kg_liteparse_available', null );
		if ( $forced !== null ) {
			$cached = (bool) $forced;
			return $cached;
		}

		if ( self::sidecar_url() !== '' ) {
			$cached = true;
			return $cached;
		}
		$cached = self::cli_available();
		return $cached;
	}

	/**
	 * Runtime switch for native LiteParse engine (sidecar / CLI).
	 * Default OFF while sidecar dependencies are being stabilized.
	 *
	 * @return bool
	 */
	private static function native_engine_enabled() {
		$forced = apply_filters( 'bizcity_kg_liteparse_native_enabled', null );
		if ( is_bool( $forced ) ) {
			return $forced;
		}
		if ( defined( 'BIZCITY_LITEPARSE_NATIVE_ENABLED' ) ) {
			return (bool) BIZCITY_LITEPARSE_NATIVE_ENABLED;
		}
		return false;
	}

	public static function picked_engine() {
		return self::sidecar_url() !== '' ? 'liteparse_http' : 'liteparse_cli';
	}

	private static function sidecar_url() {
		$url = defined( 'BIZCITY_LITEPARSE_URL' ) ? (string) BIZCITY_LITEPARSE_URL : '';
		if ( $url === '' ) {
			$url = (string) getenv( 'BIZCITY_LITEPARSE_URL' );
		}
		$url = trim( $url );
		if ( $url === '' ) {
			return '';
		}
		// Defensive: only http(s) accepted to avoid SSRF surface widening.
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		return rtrim( $url, '/' );
	}

	private static function cli_available() {
		if ( ! function_exists( 'shell_exec' ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( in_array( 'shell_exec', $disabled, true ) ) {
			return false;
		}
		$bin = self::cli_bin();
		if ( $bin === '' ) {
			return false;
		}
		$cmd = ( PHP_OS_FAMILY === 'Windows' )
			? 'where ' . escapeshellarg( $bin ) . ' 2>NUL'
			: 'command -v ' . escapeshellarg( $bin ) . ' 2>/dev/null';
		$out = @shell_exec( $cmd );
		return ! empty( trim( (string) $out ) );
	}

	private static function cli_bin() {
		$bin = defined( 'BIZCITY_LITEPARSE_BIN' ) ? (string) BIZCITY_LITEPARSE_BIN : '';
		if ( $bin === '' ) {
			$bin = (string) getenv( 'BIZCITY_LITEPARSE_BIN' );
		}
		$bin = trim( $bin );
		return $bin !== '' ? $bin : 'lit';
	}

	public static function version_string() {
		static $cached = null;
		if ( $cached !== null ) {
			return $cached;
		}
		if ( ! self::cli_available() ) {
			$cached = '';
			return $cached;
		}
		$bin = self::cli_bin();
		$out = @shell_exec( escapeshellcmd( $bin ) . ' --version 2>&1' );
		$out = trim( (string) $out );
		if ( $out === '' ) {
			$cached = '';
			return $cached;
		}
		// Try semver pattern; otherwise truncate
		if ( preg_match( '/\d+\.\d+\.\d+/', $out, $m ) ) {
			$cached = $m[0];
		} else {
			$cached = substr( $out, 0, 32 );
		}
		return $cached;
	}

	/* ────────────────────────  CLI runner  ──────────────────────── */

	/**
	 * Shell out to `lit parse --format json <file>`. Returns
	 * [ 'pages' => [ { page, text, blocks? }, ... ] ] or WP_Error.
	 *
	 * @param string $file_path
	 * @return array|WP_Error
	 */
	private static function run_via_cli( $file_path ) {
		$bin = escapeshellcmd( self::cli_bin() );
		$tmp = wp_tempnam( 'bizcity-liteparse-' );
		if ( ! $tmp ) {
			return new WP_Error( 'liteparse_tmp_failed', 'Could not allocate tempfile for LiteParse.' );
		}
		// `lit parse --format json --out <tmp> <file>` is the canonical contract.
		// We keep the call defensive: also tee stderr.
		$cmd = sprintf(
			'%s parse --format json --out %s %s 2>&1',
			$bin,
			escapeshellarg( $tmp ),
			escapeshellarg( $file_path )
		);
		// Cap with a timeout wrapper when GNU `timeout` is available (Linux prod).
		if ( PHP_OS_FAMILY !== 'Windows' ) {
			$cmd = 'timeout ' . (int) self::SHELL_TIMEOUT . ' ' . $cmd;
		}

		$stderr = (string) @shell_exec( $cmd );
		$payload_raw = '';
		if ( file_exists( $tmp ) && filesize( $tmp ) > 0 ) {
			$payload_raw = (string) file_get_contents( $tmp );
		}
		@unlink( $tmp );

		if ( $payload_raw === '' ) {
			return new WP_Error(
				'liteparse_cli_no_output',
				'LiteParse CLI produced no JSON output.',
				[ 'stderr_tail' => substr( $stderr, -512 ) ]
			);
		}
		$data = json_decode( $payload_raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'liteparse_cli_bad_json',
				'LiteParse CLI returned non-JSON payload.',
				[ 'sample' => substr( $payload_raw, 0, 256 ) ]
			);
		}
		return self::normalise_payload( $data );
	}

	/* ────────────────────────  HTTP sidecar runner  ──────────────────────── */

	private static function run_via_http( $file_path, $opts = [] ) {
		$base = self::sidecar_url();
		if ( $base === '' ) {
			return new WP_Error( 'liteparse_sidecar_missing', 'LiteParse sidecar URL not configured.' );
		}
		$endpoint = $base . '/parse';

		$boundary = 'bizcityliteparse' . wp_generate_uuid4();
		$body     = self::build_multipart( $file_path, $boundary, $opts );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$res = wp_remote_post( $endpoint, [
			'timeout' => self::HTTP_TIMEOUT,
			'headers' => [
				'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
				'Accept'       => 'application/json',
			],
			'body'    => $body,
		] );
		if ( is_wp_error( $res ) ) {
			return new WP_Error(
				'liteparse_http_failed',
				'LiteParse sidecar request failed: ' . $res->get_error_message()
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'liteparse_http_status',
				'LiteParse sidecar returned HTTP ' . $code,
				[ 'sample' => substr( $raw, 0, 256 ) ]
			);
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'liteparse_http_bad_json',
				'LiteParse sidecar returned non-JSON.',
				[ 'sample' => substr( $raw, 0, 256 ) ]
			);
		}
		return self::normalise_payload( $data );
	}

	private static function build_multipart( $file_path, $boundary, $opts = [] ) {
		$contents = @file_get_contents( $file_path );
		if ( $contents === false ) {
			return new WP_Error( 'liteparse_read_failed', 'Could not read input file for upload.' );
		}
		// [2026-06-08 Johnny Chu] HOTFIX — use original filename so sidecar can detect extension (e.g. .docx)
		// basename($file_path) would be the PHP tmp name (e.g. phpXXXXXX) → sidecar returns HTTP 415
		$name = isset( $opts['filename'] ) && $opts['filename'] !== ''
			? basename( (string) $opts['filename'] )
			: basename( $file_path );
		$body  = "--{$boundary}\r\n";
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . $name . "\"\r\n";
		$body .= "Content-Type: application/octet-stream\r\n\r\n";
		$body .= $contents . "\r\n";
		$body .= "--{$boundary}--\r\n";
		return $body;
	}

	/* ────────────────────────  Normalisation  ──────────────────────── */

	/**
	 * Accept both the canonical LiteParse JSON shape and a small handful of
	 * common variants so the adapter stays resilient to upstream renames.
	 *
	 * Canonical:  { pages: [ { page:int, text:string, blocks?:[] } ] }
	 * Variant A:  { document: { pages: [ { page_number, text } ] } }
	 * Variant B:  { items: [ { type:'page', index, content } ] }
	 *
	 * @param array $data
	 * @return array{pages:array}
	 */
	private static function normalise_payload( array $data ) {
		$pages = [];

		if ( isset( $data['pages'] ) && is_array( $data['pages'] ) ) {
			foreach ( $data['pages'] as $i => $p ) {
				$pages[] = [
					'page'   => (int) ( $p['page'] ?? $p['page_number'] ?? ( $i + 1 ) ),
					'text'   => (string) ( $p['text'] ?? $p['content'] ?? '' ),
					'blocks' => isset( $p['blocks'] ) && is_array( $p['blocks'] ) ? $p['blocks'] : [],
				];
			}
		} elseif ( isset( $data['document']['pages'] ) && is_array( $data['document']['pages'] ) ) {
			foreach ( $data['document']['pages'] as $i => $p ) {
				$pages[] = [
					'page'   => (int) ( $p['page'] ?? $p['page_number'] ?? ( $i + 1 ) ),
					'text'   => (string) ( $p['text'] ?? $p['content'] ?? '' ),
					'blocks' => isset( $p['blocks'] ) && is_array( $p['blocks'] ) ? $p['blocks'] : [],
				];
			}
		} elseif ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
			$i = 0;
			foreach ( $data['items'] as $item ) {
				if ( ! is_array( $item ) ) continue;
				if ( ( $item['type'] ?? '' ) !== 'page' ) continue;
				$pages[] = [
					'page'   => (int) ( $item['index'] ?? ( $i + 1 ) ),
					'text'   => (string) ( $item['content'] ?? $item['text'] ?? '' ),
					'blocks' => [],
				];
				$i++;
			}
		} elseif ( isset( $data['text'] ) ) {
			// Single-page fallback (e.g. image OCR mode).
			$pages[] = [
				'page'   => 1,
				'text'   => (string) $data['text'],
				'blocks' => isset( $data['blocks'] ) && is_array( $data['blocks'] ) ? $data['blocks'] : [],
			];
		}

		return [ 'pages' => $pages ];
	}

	/* ────────────────────────  Screenshots  ──────────────────────── */

	/**
	 * Render the first N pages to PNG via `lit screenshot` and stash them
	 * under uploads/twin-vectors/{blog}/screenshots/{sha256}/p{N}.png.
	 * Honors R-VFS layout (PHASE-0.7 Wave F4.1c).
	 *
	 * @return array{assets:array,rel_dir:string}|WP_Error
	 */
	private static function shoot_pages( $file_path, array $pages, $blog_id, $sha ) {
		$bin = self::cli_bin();
		if ( ! self::cli_available() ) {
			return new WP_Error( 'liteparse_no_cli_for_shot', 'CLI required for screenshots.' );
		}

		$uploads = wp_upload_dir( null, false );
		if ( empty( $uploads['basedir'] ) ) {
			return new WP_Error( 'liteparse_no_upload_dir', 'Could not resolve upload dir.' );
		}
		$rel_dir  = sprintf( 'twin-vectors/%d/screenshots/%s', (int) $blog_id, $sha );
		$abs_dir  = trailingslashit( $uploads['basedir'] ) . $rel_dir;
		if ( ! wp_mkdir_p( $abs_dir ) ) {
			return new WP_Error( 'liteparse_mkdir_failed', 'Could not create screenshot dir.' );
		}

		$cap   = min( count( $pages ), self::SCREENSHOT_MAX );
		$range = '1-' . $cap;

		$cmd = sprintf(
			'%s screenshot --target-pages %s -o %s %s 2>&1',
			escapeshellcmd( $bin ),
			escapeshellarg( $range ),
			escapeshellarg( $abs_dir ),
			escapeshellarg( $file_path )
		);
		if ( PHP_OS_FAMILY !== 'Windows' ) {
			$cmd = 'timeout ' . (int) self::SHELL_TIMEOUT . ' ' . $cmd;
		}
		@shell_exec( $cmd );

		$assets = [];
		for ( $p = 1; $p <= $cap; $p++ ) {
			$candidate = trailingslashit( $abs_dir ) . 'p' . $p . '.png';
			if ( ! file_exists( $candidate ) ) {
				// some CLI versions write `page-1.png` — accept it too
				$alt = trailingslashit( $abs_dir ) . 'page-' . $p . '.png';
				if ( file_exists( $alt ) ) {
					@rename( $alt, $candidate );
				} else {
					continue;
				}
			}
			$assets[] = [
				'kind'          => 'screenshot',
				'page_num'      => $p,
				'relative_path' => trailingslashit( $rel_dir ) . 'p' . $p . '.png',
				'mime'          => 'image/png',
				'bytes'         => (int) filesize( $candidate ),
				'sha256'        => hash_file( 'sha256', $candidate ),
			];
		}
		return [ 'assets' => $assets, 'rel_dir' => trailingslashit( $rel_dir ) ];
	}

	/* ────────────────────────  Helpers  ──────────────────────── */

	private static function modality_for_ext( $ext, $mime ) {
		$ext = strtolower( (string) $ext );
		if ( $ext === 'pdf' || strpos( (string) $mime, 'pdf' ) !== false ) {
			return 'pdf_layout';
		}
		$img = [ 'jpg', 'jpeg', 'png', 'tiff', 'tif', 'bmp', 'webp' ];
		if ( in_array( $ext, $img, true ) || strpos( (string) $mime, 'image/' ) === 0 ) {
			return 'image_layout';
		}
		return 'office_layout';
	}

	private static function cleanup_text( $s ) {
		$s = (string) $s;
		// Normalise newlines, collapse 3+ blank lines, strip stray nulls.
		$s = str_replace( [ "\r\n", "\r", "\0" ], [ "\n", "\n", '' ], $s );
		$s = preg_replace( "/\n{3,}/", "\n\n", $s );
		return trim( (string) $s );
	}

	/* ════════════════════════  Gemini Flash fallback  ════════════════════════
	 *
	 * Hybrid path (Wave 0.42.B, 2026-05-27): when the local LiteParse engine
	 * is unavailable or errors out, send the file to Gemini 2.5 Flash through
	 * the existing 1-API gateway at https://bizcity.vn/wp-json/bizcity/v1/llm/chat.
	 *
	 *  • Reuses BizCity_LLM_Client (Bearer biz-xxx, fail-OPEN logging, fallback
	 *    chain). No new gateway REST surface needed.
	 *  • Inline base64 only — files > 18 MB return WP_Error and the registry
	 *    falls through to Tier-1. URL-passthrough is a future enhancement once
	 *    R-VFS signed-fetch ships.
	 *  • Same Pro entitlement gate (`learning.layout_parse`) — no new key.
	 *  • JSON-mode output: model returns { pages: [{ page:int, text:string }] }
	 *    matching the canonical `normalise_payload` shape so downstream code
	 *    is unchanged.
	 */

	/**
	 * Whether the Gemini fallback path is wired up on this site.
	 * Cheap-only check (constants + class_exists + is_ready); no network.
	 */
	public static function gemini_fallback_enabled() {
		$enabled = apply_filters( 'bizcity_kg_liteparse_gemini_fallback', null );
		if ( is_bool( $enabled ) ) {
			return $enabled;
		}
		if ( defined( 'BIZCITY_LITEPARSE_GEMINI_FALLBACK' ) && BIZCITY_LITEPARSE_GEMINI_FALLBACK === false ) {
			return false;
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return false;
		}
		return (bool) BizCity_LLM_Client::instance()->is_ready();
	}

	/**
	 * Best-effort short status payload for the diagnostic / status pill.
	 *
	 * @return array { enabled: bool, model: string, fallback_model: string, tier: string, reason: string }
	 */
	public static function gemini_fallback_status() {
		// [2026-07-15 Johnny Chu] PHASE-KG-PDF-CHUNK — diagnostics evidence.
		$physical_chunking = class_exists( 'setasign\\Fpdi\\Fpdi' );
		if ( ! self::gemini_fallback_enabled() ) {
			$reason = ! class_exists( 'BizCity_LLM_Client' )
				? 'llm_client_missing'
				: ( BizCity_LLM_Client::instance()->is_ready() ? 'disabled_by_filter' : 'no_api_key' );
			return [
				'enabled'           => false,
				'model'             => '',
				'fallback_model'    => '',
				'tier'              => 'free',
				'reason'            => $reason,
				'physical_chunking' => $physical_chunking,
				'pdf_chunk_engine'  => $physical_chunking ? 'physical_fpdi' : 'prompt_window',
			];
		}
		$cfg = self::resolve_gemini_models_for_tier();
		return [
			'enabled'        => true,
			'model'          => (string) ( $cfg['primary'] ?? self::GEMINI_FALLBACK_MODEL ),
			'fallback_model' => (string) ( $cfg['fallback'] ?? self::GEMINI_FALLBACK_FB ),
			'tier'           => (string) ( $cfg['tier'] ?? 'free' ),
			'reason'         => 'ok',
			'physical_chunking' => $physical_chunking,
			'pdf_chunk_engine'  => $physical_chunking ? 'physical_fpdi' : 'prompt_window',
		];
	}

	/**
	 * [2026-07-15 Johnny Chu] PHASE-KG-LLM-TIER — resolve Gemini models by hub tier.
	 *
	 * @return array{tier:string,master_level:string,primary:string,fallback:string}
	 */
	private static function resolve_gemini_models_for_tier() {
		$master_level = self::current_master_level();
		$tier_bucket  = self::tier_bucket_from_master_level( $master_level );

		switch ( $tier_bucket ) {
			case 'premium':
				$default_primary  = self::GEMINI_MODEL_PREMIUM;
				$default_fallback = self::GEMINI_MODEL_FB_PREMIUM;
				break;
			case 'pro':
				$default_primary  = self::GEMINI_MODEL_PRO;
				$default_fallback = self::GEMINI_MODEL_FB_PRO;
				break;
			case 'free':
			default:
				$default_primary  = self::GEMINI_MODEL_FREE;
				$default_fallback = self::GEMINI_MODEL_FB_FREE;
				break;
		}

		$settings = (array) get_option( 'bizcity_llm_settings', [] );
		$primary  = isset( $settings[ 'kg_liteparse_gemini_model_' . $tier_bucket ] )
			? sanitize_text_field( (string) $settings[ 'kg_liteparse_gemini_model_' . $tier_bucket ] )
			: '';
		$fallback = isset( $settings[ 'kg_liteparse_gemini_fallback_model_' . $tier_bucket ] )
			? sanitize_text_field( (string) $settings[ 'kg_liteparse_gemini_fallback_model_' . $tier_bucket ] )
			: '';

		if ( $primary === '' ) {
			$primary = $default_primary;
		}
		if ( $fallback === '' ) {
			$fallback = $default_fallback;
		}

		$resolved = [
			'tier'         => $tier_bucket,
			'master_level' => $master_level,
			'primary'      => $primary,
			'fallback'     => $fallback,
		];
		$resolved = apply_filters( 'bizcity_kg_liteparse_gemini_models', $resolved, $tier_bucket, $master_level );
		if ( ! is_array( $resolved ) ) {
			$resolved = [];
		}

		$resolved_tier = isset( $resolved['tier'] ) ? sanitize_key( (string) $resolved['tier'] ) : $tier_bucket;
		$resolved_master = isset( $resolved['master_level'] ) ? sanitize_key( (string) $resolved['master_level'] ) : $master_level;
		$resolved_primary = isset( $resolved['primary'] ) ? sanitize_text_field( (string) $resolved['primary'] ) : $primary;
		$resolved_fallback = isset( $resolved['fallback'] ) ? sanitize_text_field( (string) $resolved['fallback'] ) : $fallback;

		if ( $resolved_primary === '' ) {
			$resolved_primary = self::GEMINI_FALLBACK_MODEL;
		}
		if ( $resolved_fallback === '' ) {
			$resolved_fallback = $resolved_primary;
		}

		return [
			'tier'         => $resolved_tier,
			'master_level' => $resolved_master,
			'primary'      => $resolved_primary,
			'fallback'     => $resolved_fallback,
		];
	}

	/**
	 * @return string
	 */
	private static function current_master_level() {
		$level = sanitize_key( (string) get_option( 'bizcity_hub_master_level', 'free' ) );
		return $level !== '' ? $level : 'free';
	}

	/**
	 * @param string $master_level
	 * @return string
	 */
	private static function tier_bucket_from_master_level( $master_level ) {
		$master_level = sanitize_key( (string) $master_level );
		if ( in_array( $master_level, [ 'master_premium', 'premium', 'enterprise', 'business', 'plus' ], true ) ) {
			return 'premium';
		}
		if ( in_array( $master_level, [ 'master_pro', 'pro', 'starter' ], true ) ) {
			return 'pro';
		}
		return 'free';
	}

	/**
	 * Run a file through Gemini 2.5 Flash via the gateway.
	 *
	 * @return array|WP_Error { pages: [...], meta: { model, usage, duration_ms } }
	 */
	private static function run_via_gemini( $file_path, $ext, $mime ) {
		$t0 = microtime( true );

		$size = @filesize( $file_path );
		if ( $size === false || $size <= 0 ) {
			return new WP_Error( 'gemini_file_unreadable', 'Gemini fallback: cannot stat file.' );
		}
		if ( $size > self::GEMINI_INLINE_CAP ) {
			return new WP_Error(
				'gemini_file_too_large',
				sprintf( 'Gemini fallback: file %d bytes exceeds inline cap %d.', $size, self::GEMINI_INLINE_CAP )
			);
		}

		$bytes = @file_get_contents( $file_path );
		if ( $bytes === false ) {
			return new WP_Error( 'gemini_read_failed', 'Gemini fallback: file_get_contents failed.' );
		}
		$mime = $mime !== '' ? $mime : self::guess_mime( $ext );
		if ( $mime === '' ) {
			return new WP_Error( 'gemini_mime_unknown', 'Gemini fallback: cannot infer MIME type for ext=' . $ext );
		}
		$b64       = base64_encode( $bytes );
		$file_data = 'data:' . $mime . ';base64,' . $b64;
		$model_cfg = self::resolve_gemini_models_for_tier();
		$model_id  = (string) ( $model_cfg['primary'] ?? self::GEMINI_FALLBACK_MODEL );
		$fb_model  = (string) ( $model_cfg['fallback'] ?? $model_id );

		// [2026-07-15 Johnny Chu] HOTFIX — default PDF strategy is chunked page windows.
		$ext_key = strtolower( (string) $ext );
		$last_err = null;
		if ( $ext_key === 'pdf' ) {
			// [2026-07-15 Johnny Chu] PHASE-KG-PDF-CHUNK — pass the original
			// PDF path so FPDI can create real page-window files before upload.
			$chunked = self::run_via_gemini_chunked( $file_path, $mime, $file_data, $model_id, $fb_model );
			if ( ! is_wp_error( $chunked ) ) {
				$chunked['meta']['duration_ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );
				$chunked['meta']['strategy'] = 'chunked_default';
				$chunked['meta']['tier'] = (string) ( $model_cfg['tier'] ?? 'free' );
				$chunked['meta']['master_level'] = (string) ( $model_cfg['master_level'] ?? 'free' );
				return $chunked;
			}
			$last_err = $chunked;
			// [2026-07-15 Johnny Chu] HOTFIX — do not issue a redundant second
			// single-request call when chunked path already failed on non-retryable
			// quota/rate/page-limit conditions.
			if ( self::should_skip_single_after_chunk_error( $chunked ) ) {
				$msg = '[liteparse] Gemini chunked default FAILED: ' . $chunked->get_error_code() . ' — no single fallback';
				error_log( $msg );
				self::tc_log( $msg );
				return $chunked;
			}
			$msg = '[liteparse] Gemini chunked default FAILED: ' . $chunked->get_error_code() . ' — fallback to single request';
			error_log( $msg );
			self::tc_log( $msg );
		}

		// [2026-07-15 Johnny Chu] HOTFIX — retry invalid JSON with reduced output for single-request path.
		for ( $attempt = 1; $attempt <= (int) self::GEMINI_INVALID_JSON_RETRIES; $attempt++ ) {
			$max_pages = $attempt === 1 ? (int) self::GEMINI_MAX_PAGES_HINT : max( 6, (int) floor( self::GEMINI_MAX_PAGES_HINT / 2 ) );
			$max_tokens = $attempt === 1 ? 16000 : 9000;
			$single = self::run_via_gemini_request( $file_data, 0, 0, $max_pages, $max_tokens, $model_id, $fb_model );
			if ( ! is_wp_error( $single ) ) {
				$single['meta']['duration_ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );
				$single['meta']['tier'] = (string) ( $model_cfg['tier'] ?? 'free' );
				$single['meta']['master_level'] = (string) ( $model_cfg['master_level'] ?? 'free' );
				if ( $ext_key === 'pdf' && is_wp_error( $last_err ) ) {
					$single['meta']['strategy'] = $attempt === 1 ? 'single_after_chunk_fail' : 'single_retry_after_chunk_fail';
				} else {
					$single['meta']['strategy'] = $attempt === 1 ? 'single' : 'single_retry';
				}
				return $single;
			}
			$last_err = $single;
			if ( $single->get_error_code() !== 'gemini_invalid_json' ) {
				return $single;
			}
		}

		return is_wp_error( $last_err )
			? $last_err
			: new WP_Error( 'gemini_invalid_json', 'Gemini fallback: response was not valid JSON.', [ 'http_status' => 422 ] );
	}

	/**
	 * [2026-07-15 Johnny Chu] HOTFIX — classify chunked failures that should
	 * stop immediately instead of trying a full single-file Gemini request.
	 *
	 * @param WP_Error $err
	 * @return bool
	 */
	private static function should_skip_single_after_chunk_error( WP_Error $err ) {
		$code = (string) $err->get_error_code();
		return in_array(
			$code,
			[
				'gemini_quota_exhausted',
				'gemini_rate_limited',
				'gemini_page_limit_exceeded',
			],
			true
		);
	}

	/**
	 * [2026-07-15 Johnny Chu] HOTFIX — one Gemini extraction request.
	 *
	 * @param string $file_data
	 * @param int    $range_start 1-indexed inclusive; 0 = whole file
	 * @param int    $range_end   1-indexed inclusive; 0 = whole file
	 * @param int    $max_pages_hint
	 * @param int    $max_tokens
	 * @return array|WP_Error
	 */
	private static function run_via_gemini_request( $file_data, $range_start, $range_end, $max_pages_hint, $max_tokens, $model_id, $fallback_model_id ) {
		$range_start = (int) $range_start;
		$range_end   = (int) $range_end;
		$max_pages_hint = max( 1, (int) $max_pages_hint );
		$max_tokens  = max( 1000, (int) $max_tokens );
		$model_id = sanitize_text_field( (string) $model_id );
		$fallback_model_id = sanitize_text_field( (string) $fallback_model_id );
		if ( $model_id === '' ) {
			$model_id = self::GEMINI_FALLBACK_MODEL;
		}

		$range_clause = '';
		$user_text = 'Extract layout-preserving text per page. Return JSON exactly as specified by the system message.';
		if ( $range_start > 0 && $range_end >= $range_start ) {
			$range_clause = ' ONLY include pages from ' . $range_start . ' to ' . $range_end . '. If no pages exist in this interval, return {"pages":[]}.';
			$user_text = 'Extract ONLY pages ' . $range_start . '-' . $range_end . '. Do not include any page outside this interval. Return JSON only.';
		}

		// Build messages — JSON-mode for predictable parsing.
		// [2026-06-08 Johnny Chu] HOTFIX — use standard OpenAI-compat image_url format (not proprietary 'file' type)
		// Gateway forwards to OpenRouter -> Gemini which accepts data URIs via image_url for all MIME types.
		$system = 'You are a document layout extraction service. Read the attached file and return ONLY a JSON object of shape: {"pages":[{"page":<1-indexed-int>,"text":"<plain text of that page, preserving table structure with pipe-separated cells and multi-column reading order>"}]}. Use AT MOST ' . $max_pages_hint . ' pages.' . $range_clause . ' Do not add commentary. Do not wrap in markdown.';
		$user_parts = [
			[ 'type' => 'text', 'text' => $user_text ],
			[
				'type'      => 'image_url',
				'image_url' => [ 'url' => $file_data ],
			],
		];
		$messages = [
			[ 'role' => 'system', 'content' => $system ],
			[ 'role' => 'user',   'content' => $user_parts ],
		];

		$client = BizCity_LLM_Client::instance();
		$opts = [
			'model'       => self::GEMINI_FALLBACK_MODEL,
			'purpose'     => 'layout_fallback',
			'temperature' => 0.0,
			'max_tokens'  => $max_tokens,
			'timeout'     => self::GEMINI_TIMEOUT,
			'extra_body'  => [
				'response_format' => [ 'type' => 'json_object' ],
			],
		];
		$opts['model'] = $model_id;
		if ( $fallback_model_id !== '' && $fallback_model_id !== $model_id ) {
			$opts['fallback_model'] = $fallback_model_id;
		}
		$result = $client->chat( $messages, $opts );

		if ( empty( $result['success'] ) ) {
			// [2026-07-15 Johnny Chu] HOTFIX — explicit upstream failure taxonomy for better UX.
			$err_msg = (string) ( $result['error'] ?? 'unknown' );
			$err_code = (string) ( $result['error_code'] ?? '' );
			if ( $err_code === 'site_unmapped' ) {
				return new WP_Error(
					'gemini_site_unmapped',
					'Gemini fallback blocked: domain chua mapping vao multisite.',
					[
						'http_status' => 503,
						'ctx' => [
							'domain'   => (string) ( $result['degraded_domain'] ?? '' ),
							'upstream' => $err_msg,
						],
					]
				);
			}
			if ( ! empty( $result['quota_exhausted'] ) ) {
				// [2026-07-15 Johnny Chu] PHASE-KG-PDF-CHUNK — preserve the
				// authoritative Hub key-plan/counter evidence for frontend UX.
				return new WP_Error(
					'gemini_quota_exhausted',
					'Gemini fallback: API key đã dùng hết quota Hub trong kỳ hiện tại.',
					[
						'http_status' => 402,
						'ctx' => [
							'quota_layer'     => (string) ( $result['quota_layer'] ?? '' ),
							'tier'            => (string) ( $result['tier'] ?? '' ),
							'master_level'    => (string) ( $result['master_level'] ?? $result['tier'] ?? '' ),
							'used_requests'   => (int) ( $result['used_requests'] ?? 0 ),
							'cap_requests_day'=> (int) ( $result['cap_requests_day'] ?? 0 ),
							'used_usd'        => (float) ( $result['used_usd'] ?? 0 ),
							'cap_usd'         => (float) ( $result['cap_usd'] ?? 0 ),
							'reset_at'        => (string) ( $result['reset_at'] ?? '' ),
							'upstream'        => $err_msg,
						],
					]
				);
			}

			$is_rate_limited = false;
			if ( $err_code === 'rate_limited' ) {
				$is_rate_limited = true;
			}
			if ( ! $is_rate_limited ) {
				$err_l = strtolower( $err_msg );
				if (
					strpos( $err_l, 'rate limit' ) !== false
					|| strpos( $err_l, 'too many requests' ) !== false
					|| strpos( $err_l, '429' ) !== false
				) {
					$is_rate_limited = true;
				}
			}
			if ( $is_rate_limited ) {
				return new WP_Error(
					'gemini_rate_limited',
					'Gemini fallback: hệ thống đang rate limit, vui lòng thử lại sau 1-2 phút.',
					[
						'http_status' => 429,
						'ctx' => [
							'upstream' => $err_msg,
						],
					]
				);
			}

			return new WP_Error(
				'gemini_upstream_error',
				'Gemini fallback failed: ' . $err_msg,
				[
					'http_status' => 502,
					'ctx' => [
						'upstream' => $err_msg,
					],
				]
			);
		}

		$content = (string) ( $result['message'] ?? '' );
		if ( $content === '' ) {
			return new WP_Error( 'gemini_empty_response', 'Gemini fallback: empty content from gateway.' );
		}

		$decoded = self::decode_json_object_relaxed( $content );
		if ( ! is_array( $decoded ) ) {
			$msg = '[liteparse] Gemini fallback invalid JSON · len=' . strlen( $content ) . ' · decode_failed=1';
			error_log( $msg );
			self::tc_log( $msg );
			return new WP_Error(
				'gemini_invalid_json',
				'Gemini fallback: response was not valid JSON.',
				[ 'http_status' => 422 ]
			);
		}

		$raw_pages = isset( $decoded['pages'] ) && is_array( $decoded['pages'] ) ? $decoded['pages'] : [];
		if ( empty( $raw_pages ) ) {
			if ( ! empty( $decoded['text'] ) ) {
				$raw_pages = [ [ 'page' => ( $range_start > 0 ? $range_start : 1 ), 'text' => (string) $decoded['text'] ] ];
			} elseif ( $range_start > 0 && $range_end >= $range_start ) {
				return [
					'pages' => [],
					'meta'  => [
						'model' => (string) ( $result['model'] ?? $model_id ),
						'usage' => $result['usage'] ?? null,
					],
				];
			} else {
				return new WP_Error( 'gemini_no_pages', 'Gemini fallback: no pages in response.' );
			}
		}

		$pages = self::normalise_gemini_pages( $raw_pages );
		if ( $range_start > 0 && $range_end >= $range_start ) {
			$pages = self::coerce_chunk_pages_to_range( $pages, $range_start, $range_end );
		}

		return [
			'pages' => $pages,
			'meta'  => [
				'model' => (string) ( $result['model'] ?? $model_id ),
				'usage' => $result['usage'] ?? null,
			],
		];
	}

	/**
	 * [2026-07-15 Johnny Chu] PHASE-KG-PDF-CHUNK — physically split PDFs into
	 * page-window files with FPDI. Prompt-only windows remain a compatibility
	 * fallback when Composer dependencies have not been deployed yet.
	 *
	 * @param string $file_path
	 * @param string $mime
	 * @param string $file_data
	 * @return array|WP_Error
	 */
	private static function run_via_gemini_chunked( $file_path, $mime, $file_data, $model_id, $fallback_model_id ) {
		$chunk_size = (int) self::GEMINI_CHUNK_PAGE_SIZE;
		$max_parts  = (int) self::GEMINI_CHUNK_MAX_PARTS;
		$all_pages  = [];
		$total_calls = 0;
		$hit_max_parts  = false;
		$last_was_full  = false;
		$total_pdf_pages = 0;
		$physical_split = class_exists( 'setasign\\Fpdi\\Fpdi' );
		$split_strategy = $physical_split ? 'physical_fpdi' : 'prompt_window';

		if ( ! $physical_split ) {
			$msg = '[liteparse] PDF physical chunking unavailable: FPDI not loaded; using prompt-window fallback.';
			error_log( $msg );
			self::tc_log( $msg );
		}

		for ( $part = 0; $part < $max_parts; $part++ ) {
			$start = ( $part * $chunk_size ) + 1;
			$end   = $start + $chunk_size - 1;
			$request_data  = $file_data;
			$request_start = $start;
			$request_end   = $end;
			$page_offset   = 0;
			$tmp_path      = '';

			if ( $physical_split ) {
				$physical = self::build_physical_pdf_chunk( $file_path, $mime, $start, $end );
				if ( is_wp_error( $physical ) ) {
					$msg = '[liteparse] FPDI split failed: ' . $physical->get_error_code() . ' — using prompt-window fallback.';
					error_log( $msg );
					self::tc_log( $msg );
					$physical_split = false;
					$split_strategy = 'prompt_window_after_fpdi_error';
				} elseif ( ! empty( $physical['done'] ) ) {
					break;
				} else {
					$total_pdf_pages = (int) ( $physical['total_pages'] ?? 0 );
					$end             = (int) ( $physical['end_page'] ?? $end );
					$request_data    = (string) ( $physical['file_data'] ?? '' );
					$tmp_path        = (string) ( $physical['tmp_path'] ?? '' );
					$request_start   = 1;
					$request_end     = max( 1, $end - $start + 1 );
					$page_offset     = $start - 1;

					$max_supported = $max_parts * $chunk_size;
					if ( $total_pdf_pages > $max_supported ) {
						self::cleanup_physical_pdf_chunk( $tmp_path );
						return new WP_Error(
							'gemini_page_limit_exceeded',
							'Gemini fallback: tài liệu quá dài, vượt giới hạn xử lý theo chunk hiện tại (' . $max_supported . ' trang).',
							[
								'http_status' => 413,
								'ctx' => [
									'max_supported_pages' => $max_supported,
									'actual_pages'        => $total_pdf_pages,
									'chunk_page_size'     => $chunk_size,
									'chunk_max_parts'     => $max_parts,
								],
							]
						);
					}
				}
			}

			$chunk = self::run_via_gemini_request( $request_data, $request_start, $request_end, $chunk_size, 7000, $model_id, $fallback_model_id );
			self::cleanup_physical_pdf_chunk( $tmp_path );
			$total_calls++;

			if ( is_wp_error( $chunk ) ) {
				if ( $chunk->get_error_code() === 'gemini_invalid_json' ) {
					$retry_size = max( 3, (int) floor( $chunk_size / 2 ) );
					$retry_end  = $start + $retry_size - 1;
					$retry_data  = $file_data;
					$retry_start = $start;
					$retry_range_end = $retry_end;
					$retry_tmp = '';
					if ( $physical_split ) {
						$retry_physical = self::build_physical_pdf_chunk( $file_path, $mime, $start, $retry_end );
						if ( is_wp_error( $retry_physical ) ) {
							return $retry_physical;
						}
						$retry_data      = (string) ( $retry_physical['file_data'] ?? '' );
						$retry_tmp       = (string) ( $retry_physical['tmp_path'] ?? '' );
						$retry_end       = (int) ( $retry_physical['end_page'] ?? $retry_end );
						$retry_start     = 1;
						$retry_range_end = max( 1, $retry_end - $start + 1 );
					}
					$chunk = self::run_via_gemini_request( $retry_data, $retry_start, $retry_range_end, $retry_size, 5000, $model_id, $fallback_model_id );
					self::cleanup_physical_pdf_chunk( $retry_tmp );
					$total_calls++;
					if ( is_wp_error( $chunk ) ) {
						return $chunk;
					}
					$end = $retry_end;
				} else {
					return $chunk;
				}
			}

			$pages = isset( $chunk['pages'] ) && is_array( $chunk['pages'] ) ? $chunk['pages'] : [];
			if ( empty( $pages ) ) {
				if ( $part === 0 ) {
					return new WP_Error( 'gemini_no_pages', 'Gemini fallback: no pages in response.', [ 'http_status' => 422 ] );
				}
				break;
			}

			foreach ( $pages as $p ) {
				if ( ! is_array( $p ) ) {
					continue;
				}
				$page_num = (int) ( $p['page'] ?? 0 );
				if ( $physical_split && $page_num > 0 ) {
					$page_num += $page_offset;
				}
				if ( $page_num <= 0 ) {
					continue;
				}
				$all_pages[ $page_num ] = [
					'page' => $page_num,
					'text' => (string) ( $p['text'] ?? '' ),
				];
			}

			$expected = ( $end - $start + 1 );
			$hit_max_parts = ( $part === ( $max_parts - 1 ) );
			$last_was_full = count( $pages ) >= $expected;
			if ( $physical_split && $total_pdf_pages > 0 && $end >= $total_pdf_pages ) {
				break;
			}
			if ( count( $pages ) < $expected ) {
				break;
			}
		}

		if ( ! $physical_split && $hit_max_parts && $last_was_full ) {
			$probe_page = ( $max_parts * $chunk_size ) + 1;
			$probe = self::run_via_gemini_request( $file_data, $probe_page, $probe_page, 1, 2000, $model_id, $fallback_model_id );
			$total_calls++;
			if ( is_wp_error( $probe ) ) {
				return $probe;
			}
			$probe_pages = isset( $probe['pages'] ) && is_array( $probe['pages'] ) ? $probe['pages'] : [];
			if ( ! empty( $probe_pages ) ) {
				$max_supported = $max_parts * $chunk_size;
				return new WP_Error(
					'gemini_page_limit_exceeded',
					'Gemini fallback: tài liệu quá dài, vượt giới hạn xử lý theo chunk hiện tại (' . $max_supported . ' trang).',
					[
						'http_status' => 413,
						'ctx' => [
							'max_supported_pages' => $max_supported,
							'chunk_page_size'     => $chunk_size,
							'chunk_max_parts'     => $max_parts,
							'next_page'           => $probe_page,
						],
					]
				);
			}
		}

		if ( empty( $all_pages ) ) {
			return new WP_Error( 'gemini_no_pages', 'Gemini fallback: no pages in response.', [ 'http_status' => 422 ] );
		}

		ksort( $all_pages, SORT_NUMERIC );
		return [
			'pages' => array_values( $all_pages ),
			'meta'  => [
				'model' => $model_id,
				'fallback_model' => $fallback_model_id,
				'usage' => [
					'chunk_calls'  => $total_calls,
					'chunk_size'   => $chunk_size,
					'physical'     => $physical_split,
					'split_engine' => $split_strategy,
					'total_pages'  => $total_pdf_pages,
				],
			],
		];
	}

	/**
	 * Create a real PDF containing only the requested page range.
	 *
	 * @param string $file_path
	 * @param string $mime
	 * @param int    $start_page
	 * @param int    $end_page
	 * @return array|WP_Error
	 */
	private static function build_physical_pdf_chunk( $file_path, $mime, $start_page, $end_page ) {
		// [2026-07-15 Johnny Chu] PHASE-KG-PDF-CHUNK — FPDI physical page split.
		if ( ! class_exists( 'setasign\\Fpdi\\Fpdi' ) ) {
			return new WP_Error( 'gemini_pdf_splitter_missing', 'FPDI PDF splitter is not loaded.' );
		}

		$tmp_path = '';
		try {
			$class = 'setasign\\Fpdi\\Fpdi';
			$pdf   = new $class();
			$pdf->SetAutoPageBreak( false );
			$total_pages = (int) $pdf->setSourceFile( $file_path );
			$start_page = max( 1, (int) $start_page );
			$end_page   = min( (int) $end_page, $total_pages );
			if ( $start_page > $total_pages || $end_page < $start_page ) {
				return [ 'done' => true, 'total_pages' => $total_pages ];
			}

			for ( $page = $start_page; $page <= $end_page; $page++ ) {
				$template = $pdf->importPage( $page );
				$size     = $pdf->getTemplateSize( $template );
				$width    = (float) ( $size['width'] ?? 210 );
				$height   = (float) ( $size['height'] ?? 297 );
				$pdf->AddPage( $width > $height ? 'L' : 'P', [ $width, $height ] );
				$pdf->useTemplate( $template );
			}

			$tmp_path = function_exists( 'wp_tempnam' )
				? (string) wp_tempnam( 'bizcity-pdf-chunk.pdf' )
				: (string) tempnam( sys_get_temp_dir(), 'bizcity-pdf-' );
			if ( $tmp_path === '' ) {
				return new WP_Error( 'gemini_pdf_split_tmp_failed', 'Could not create temporary PDF chunk.' );
			}
			$pdf->Output( 'F', $tmp_path );
			$bytes = @file_get_contents( $tmp_path );
			if ( $bytes === false || $bytes === '' ) {
				self::cleanup_physical_pdf_chunk( $tmp_path );
				return new WP_Error( 'gemini_pdf_split_read_failed', 'Could not read temporary PDF chunk.' );
			}

			return [
				'done'        => false,
				'total_pages' => $total_pages,
				'end_page'    => $end_page,
				'tmp_path'    => $tmp_path,
				'file_data'   => 'data:' . ( $mime !== '' ? $mime : 'application/pdf' ) . ';base64,' . base64_encode( $bytes ),
			];
		} catch ( \Throwable $e ) {
			self::cleanup_physical_pdf_chunk( $tmp_path );
			return new WP_Error(
				'gemini_pdf_split_failed',
				'Physical PDF split failed: ' . $e->getMessage(),
				[ 'http_status' => 422 ]
			);
		}
	}

	/**
	 * @param string $tmp_path
	 * @return void
	 */
	private static function cleanup_physical_pdf_chunk( $tmp_path ) {
		// [2026-07-15 Johnny Chu] PHASE-KG-PDF-CHUNK — delete temp chunk eagerly.
		if ( is_string( $tmp_path ) && $tmp_path !== '' && file_exists( $tmp_path ) ) {
			@unlink( $tmp_path );
		}
	}

	/**
	 * @param array $raw_pages
	 * @return array
	 */
	private static function normalise_gemini_pages( array $raw_pages ) {
		$pages = [];
		foreach ( $raw_pages as $i => $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$pages[] = [
				'page' => (int) ( $p['page'] ?? ( $i + 1 ) ),
				'text' => (string) ( $p['text'] ?? '' ),
			];
		}
		return $pages;
	}

	/**
	 * @param array $pages
	 * @param int   $range_start
	 * @param int   $range_end
	 * @return array
	 */
	private static function coerce_chunk_pages_to_range( array $pages, $range_start, $range_end ) {
		$range_start = (int) $range_start;
		$range_end   = (int) $range_end;
		if ( $range_start <= 0 || $range_end < $range_start ) {
			return $pages;
		}

		$filtered = [];
		$seq      = 0;
		foreach ( $pages as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$text = (string) ( $p['text'] ?? '' );
			$page = (int) ( $p['page'] ?? 0 );
			if ( $page < $range_start || $page > $range_end ) {
				$page = $range_start + $seq;
			}
			if ( $page < $range_start || $page > $range_end ) {
				continue;
			}
			$filtered[ $page ] = [
				'page' => $page,
				'text' => $text,
			];
			$seq++;
		}

		if ( empty( $filtered ) ) {
			return [];
		}

		ksort( $filtered, SORT_NUMERIC );
		return array_values( $filtered );
	}

	/**
	 * [2026-07-15 Johnny Chu] HOTFIX — robust JSON object decode for Gemini fallback.
	 * Accepts plain JSON, fenced JSON, or JSON wrapped in extra prose.
	 *
	 * @param string $raw
	 * @return array|null
	 */
	private static function decode_json_object_relaxed( $raw ) {
		$payload = trim( (string) $raw );
		if ( $payload === '' ) {
			return null;
		}
		if ( substr( $payload, 0, 3 ) === "\xEF\xBB\xBF" ) {
			$payload = substr( $payload, 3 );
		}

		$decoded = json_decode( $payload, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		if ( preg_match( '/```(?:json)?\s*(.+?)\s*```/is', $payload, $m ) ) {
			$decoded = json_decode( trim( (string) $m[1] ), true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		$object_json = self::extract_first_json_object( $payload );
		if ( $object_json !== '' ) {
			$decoded = json_decode( $object_json, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * [2026-07-15 Johnny Chu] HOTFIX — extract first balanced JSON object.
	 *
	 * @param string $text
	 * @return string
	 */
	private static function extract_first_json_object( $text ) {
		$text = (string) $text;
		$len  = strlen( $text );
		if ( $len <= 0 ) {
			return '';
		}

		$start     = -1;
		$depth     = 0;
		$in_string = false;
		$escaped   = false;

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $text[ $i ];

			if ( $in_string ) {
				if ( $escaped ) {
					$escaped = false;
					continue;
				}
				if ( $ch === '\\\\' ) {
					$escaped = true;
					continue;
				}
				if ( $ch === '"' ) {
					$in_string = false;
				}
				continue;
			}

			if ( $ch === '"' ) {
				$in_string = true;
				continue;
			}

			if ( $ch === '{' ) {
				if ( $depth === 0 ) {
					$start = $i;
				}
				$depth++;
				continue;
			}

			if ( $ch === '}' && $depth > 0 ) {
				$depth--;
				if ( $depth === 0 && $start >= 0 ) {
					return substr( $text, $start, $i - $start + 1 );
				}
			}
		}

		return '';
	}

	/* ────────────────────────  PHP-native Office extractor  ──────────────────────── */

	/**
	 * Returns TRUE for Office extensions we can read with PHP ZipArchive.
	 * These must NOT be sent to Gemini via image_url (binary, not images).
	 *
	 * @param string $ext
	 * @return bool
	 */
	private static function is_office_ext( $ext ) {
		return in_array( strtolower( (string) $ext ), [
			'docx', 'odt', 'xlsx', 'ods', 'pptx', 'odp',
		], true );
	}

	/**
	 * Returns TRUE for extensions Gemini can receive as base64 data URI.
	 * Only images and PDF — NOT binary Office formats.
	 *
	 * @param string $ext
	 * @return bool
	 */
	private static function is_gemini_eligible_ext( $ext ) {
		return in_array( strtolower( (string) $ext ), [
			'pdf', 'jpg', 'jpeg', 'png', 'webp', 'tiff', 'tif', 'bmp',
		], true );
	}

	/**
	 * [2026-06-08 Johnny Chu] HOTFIX — PHP-native Office text extraction.
	 * Uses ZipArchive (standard on all PHP 5.6+ / WordPress hosts).
	 *
	 * DOCX: word/document.xml → strip XML tags
	 * XLSX: xl/sharedStrings.xml + xl/worksheets/sheet1.xml → rows
	 * ODT:  content.xml → strip XML tags
	 * PPTX: ppt/slides/slide*.xml → strip XML tags
	 *
	 * Returns { pages: [{ page:1, text:string }] } or WP_Error.
	 *
	 * @param string $file_path
	 * @param string $ext
	 * @return array|WP_Error
	 */
	private static function run_via_php_office( $file_path, $ext ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'php_office_no_zip', 'ZipArchive extension not available.' );
		}
		$zip = new ZipArchive();
		if ( $zip->open( $file_path ) !== true ) {
			return new WP_Error( 'php_office_zip_open', 'Cannot open file as ZIP archive (corrupt or unsupported format).' );
		}

		$text = '';
		$ext  = strtolower( (string) $ext );

		if ( $ext === 'docx' ) {
			$text = self::zip_extract_text( $zip, 'word/document.xml' );
		} elseif ( $ext === 'odt' ) {
			$text = self::zip_extract_text( $zip, 'content.xml' );
		} elseif ( $ext === 'xlsx' || $ext === 'ods' ) {
			// Shared strings for XLSX
			$shared = [];
			$ss_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
			if ( $ss_xml !== false ) {
				$ss_clean = preg_replace( '/<(?!t\b)[^>]+>/', ' ', (string) $ss_xml );
				preg_match_all( '/<t[^>]*>([^<]*)<\/t>/', (string) $ss_xml, $m );
				$shared = isset( $m[1] ) ? $m[1] : [];
			}
			// Sheet 1
			$sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
			if ( $sheet === false ) {
				$sheet = '';
				// Try to find any sheet
				for ( $i = 0; $i < $zip->numFiles; $i++ ) {
					$name = $zip->getNameIndex( $i );
					if ( $name !== false && strpos( $name, 'xl/worksheets/sheet' ) === 0 ) {
						$sheet = (string) $zip->getFromIndex( $i );
						break;
					}
				}
			}
			// Extract cell values: shared string references (<v>) inside <c t="s">
			preg_match_all( '/<row[^>]*>(.*?)<\/row>/s', (string) $sheet, $rows );
			$lines = [];
			foreach ( $rows[1] as $row_xml ) {
				preg_match_all( '/<c([^>]*)>(.*?)<\/c>/s', $row_xml, $cells, PREG_SET_ORDER );
				$cols = [];
				foreach ( $cells as $cell ) {
					$attr     = $cell[1];
					$cell_xml = $cell[2];
					preg_match( '/<v>([^<]*)<\/v>/', $cell_xml, $vm );
					$val = isset( $vm[1] ) ? $vm[1] : '';
					if ( strpos( $attr, 't="s"' ) !== false && $val !== '' ) {
						$val = isset( $shared[ (int) $val ] ) ? $shared[ (int) $val ] : $val;
					}
					$cols[] = trim( html_entity_decode( $val, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				}
				if ( array_filter( $cols ) ) {
					$lines[] = implode( "\t", $cols );
				}
			}
			$text = implode( "\n", $lines );
		} elseif ( $ext === 'pptx' ) {
			$parts = [];
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = $zip->getNameIndex( $i );
				if ( $name !== false && preg_match( '#^ppt/slides/slide\d+\.xml$#', $name ) ) {
					$slide_text = self::zip_extract_text( $zip, $name );
					if ( $slide_text !== '' ) {
						$parts[] = $slide_text;
					}
				}
			}
			$text = implode( "\n\n---\n\n", $parts );
		} elseif ( $ext === 'odp' ) {
			$text = self::zip_extract_text( $zip, 'content.xml' );
		}

		$zip->close();
		$text = self::cleanup_text( $text );
		if ( $text === '' ) {
			return new WP_Error( 'php_office_empty', 'No text found in Office file.' );
		}
		return [ 'pages' => [ [ 'page' => 1, 'text' => $text, 'blocks' => [] ] ] ];
	}

	/**
	 * Read a named entry from ZipArchive and return stripped plain text.
	 *
	 * @param ZipArchive $zip
	 * @param string     $entry
	 * @return string
	 */
	private static function zip_extract_text( $zip, $entry ) {
		$xml = $zip->getFromName( $entry );
		if ( $xml === false ) {
			return '';
		}
		// Insert spaces between XML tags so words don't merge, then strip all tags.
		$text = preg_replace( '/(<\/[a-zA-Z:]+>|<[a-zA-Z:]+[^>]*\/>)/', '$1 ', (string) $xml );
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Collapse whitespace
		$text = preg_replace( '/[ \t]{2,}/', ' ', (string) $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", (string) $text );
		return trim( (string) $text );
	}

	/* ────────────────────────  Helpers  ──────────────────────── */

	private static function guess_mime( $ext ) {
		static $map = [
			'pdf'  => 'application/pdf',
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'webp' => 'image/webp',
			'tiff' => 'image/tiff',
			'tif'  => 'image/tiff',
			'bmp'  => 'image/bmp',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'doc'  => 'application/msword',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'xls'  => 'application/vnd.ms-excel',
			'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'ppt'  => 'application/vnd.ms-powerpoint',
			'csv'  => 'text/csv',
			'tsv'  => 'text/tab-separated-values',
			'rtf'  => 'application/rtf',
			'odt'  => 'application/vnd.oasis.opendocument.text',
			'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
			'odp'  => 'application/vnd.oasis.opendocument.presentation',
		];
		return $map[ strtolower( (string) $ext ) ] ?? '';
	}
}
