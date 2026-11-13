<?php
/**
 * Plugin Name:       BizCity Twin Notebook (Notebook · Twin Chat · Đồ thị tri thức)
 * Plugin URI:        https://github.com/hoanganh8389/bizcity-twin-notebook
 * Description:       Notebook học sâu cho Zalo Brain: đồ thị tri thức (KG), Twin Chat, học tài liệu thành notebook, hỏi đồ thị. Cần plugin Zalo Brain (bizcity-twin-ai) — tên thư mục không quan trọng. Không có plugin này Zalo Brain vẫn đủ: Kho tri thức + Guru trả lời từ Vault.
 * Version:           1.0.0
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * Network:           false
 * Author:            Biz Central Brain — Johnny Chu (Chu Hoàng Anh)
 * License:           GPL-2.0-or-later
 * Text Domain:       bizcity-twin-ai
 * Zalo Brain Addon:  notebook
 *
 * [2026-11-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.148 W3 S148-N1 (doc 30, D148-8, Q148-2 = 1) — core/kg-hub (KG part) and modules/twinchat left
 * Zalo Brain for this plugin (R-ADDON-HOST v1, standards/ADDON-HOST-STANDARD.md):
 *   - this file loads nothing by itself: Zalo Brain finds this folder through BizCity_Addon_Locator::file( 'notebook/kg/bootstrap.php' ) and
 *     ::file( 'notebook/twinchat/bootstrap.php' ) (file name + the `Zalo Brain Addon: notebook` header above, in ANY folder) and requires them at
 *     the same points core/kg-hub and modules/twinchat used to load — same request gates, same order;
 *   - load mode: define( 'BIZCITY_NOTEBOOK_LOAD', 'auto' | 'active' | 'off' ) in wp-config.php — default 'auto' (the folder being present is enough,
 *     activated or not, so an upload keeps the notebook working exactly as before the split); 'off' = run Zalo Brain without the notebook (T148-1);
 *   - no fallback to an old copy inside Zalo Brain (same class names = fatal): the notebook is this plugin or nothing.
 * Without it: Kho tri thức, Journal, Twin Sheet, Guru, knowledge.search (Vault passages), Zalo capture (into the Vault) all work; TwinChat,
 * the KG graph, "Học sâu" and the notebook MCP tools are absent.
 */

defined( 'ABSPATH' ) || exit;

// AH-4: activated without Zalo Brain ⇒ one notice, nothing loaded.
add_action( 'admin_notices', static function () {
	if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) && current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-warning"><p>' . esc_html( 'BizCity Twin Notebook cần plugin Zalo Brain (bizcity-twin-ai) đang bật.' ) . '</p></div>';
	}
} );
