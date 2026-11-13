#!/usr/bin/env python3
"""
BizCity LiteParse Sidecar — Phase 0.42
HTTP server tại port 7860, nhận file upload và trả JSON layout-aware.

POST /parse      multipart/form-data  field: file  → { pages: [{page, text, blocks}] }
GET  /health     → { status: ok, engine: pymupdf, version: x.y.z }

Cài deps:
    pip install pymupdf fastapi uvicorn python-multipart

Chạy:
    python3 liteparse-sidecar.py          # port 7860 mặc định
    python3 liteparse-sidecar.py --port 7860

Chạy như service (systemd):
    cp liteparse-sidecar.py /home/<user>/liteparse-sidecar.py
    systemctl enable --now liteparse-sidecar   (xem service file bên dưới)
"""

import argparse
import io
import os
import sys
import tempfile

try:
    import fitz  # PyMuPDF
except ImportError:
    sys.exit("ERROR: PyMuPDF not installed. Run: pip install pymupdf")

try:
    from fastapi import FastAPI, File, UploadFile, HTTPException
    from fastapi.responses import JSONResponse
    import uvicorn
except ImportError:
    sys.exit("ERROR: FastAPI not installed. Run: pip install fastapi uvicorn python-multipart")

app = FastAPI(title="BizCity LiteParse Sidecar", version="0.42.0")


# ──────────────────────────────────────────────
#  Health check
# ──────────────────────────────────────────────

@app.get("/health")
def health():
    return {"status": "ok", "engine": "pymupdf", "version": fitz.version[0]}


# ──────────────────────────────────────────────
#  Parse endpoint — POST /parse
#  Accepts: multipart/form-data field "file"
#  Returns: { pages: [ { page: int, text: str, blocks: [] } ] }
# ──────────────────────────────────────────────

@app.post("/parse")
async def parse(file: UploadFile = File(...)):
    data = await file.read()
    filename = (file.filename or "upload").lower()

    # Route by extension / content
    if filename.endswith(".pdf") or _is_pdf_bytes(data):
        return JSONResponse(content=_parse_pdf(data))

    # Office formats — convert via libreoffice to PDF then parse
    office_exts = (".docx", ".doc", ".xlsx", ".xls", ".pptx", ".ppt",
                   ".odt", ".ods", ".odp", ".rtf")
    if any(filename.endswith(e) for e in office_exts):
        pdf_data = _office_to_pdf(data, filename)
        if pdf_data:
            return JSONResponse(content=_parse_pdf(pdf_data))
        # Fallback: extract raw text via python-docx if available
        text = _office_text_fallback(data, filename)
        return JSONResponse(content={"pages": [{"page": 1, "text": text, "blocks": []}]})

    # Images — OCR via PyMuPDF Tesseract bridge or just return empty
    image_exts = (".jpg", ".jpeg", ".png", ".tiff", ".tif", ".bmp", ".webp")
    if any(filename.endswith(e) for e in image_exts):
        return JSONResponse(content=_parse_image(data))

    raise HTTPException(status_code=415, detail=f"Unsupported file type: {filename}")


# ──────────────────────────────────────────────
#  PDF parser (PyMuPDF)
# ──────────────────────────────────────────────

def _parse_pdf(data: bytes) -> dict:
    pages = []
    try:
        doc = fitz.open(stream=data, filetype="pdf")
        for i, page in enumerate(doc):
            # get_text("blocks") returns (x0,y0,x1,y1,text,block_no,block_type)
            raw_blocks = page.get_text("blocks", sort=True)
            blocks = []
            text_parts = []
            for b in raw_blocks:
                if b[6] != 0:  # skip image blocks
                    continue
                txt = b[4].strip()
                if not txt:
                    continue
                text_parts.append(txt)
                blocks.append({
                    "x1": round(b[0], 1), "y1": round(b[1], 1),
                    "x2": round(b[2], 1), "y2": round(b[3], 1),
                    "text": txt,
                })
            pages.append({
                "page": i + 1,
                "text": "\n".join(text_parts),
                "blocks": blocks,
            })
        doc.close()
    except Exception as e:
        pages = [{"page": 1, "text": f"[parse error: {e}]", "blocks": []}]
    return {"pages": pages}


# ──────────────────────────────────────────────
#  Image OCR (PyMuPDF + Tesseract if available)
# ──────────────────────────────────────────────

def _parse_image(data: bytes) -> dict:
    try:
        doc = fitz.open(stream=data, filetype="png")  # fitz auto-detects
    except Exception:
        try:
            # Try inserting as image into a blank PDF page for OCR
            tmp_pdf = fitz.open()
            page = tmp_pdf.new_page()
            rect = fitz.Rect(0, 0, 595, 842)
            page.insert_image(rect, stream=data)
            doc = tmp_pdf
        except Exception as e:
            return {"pages": [{"page": 1, "text": f"[image parse error: {e}]", "blocks": []}]}

    pages = []
    for i, page in enumerate(doc):
        try:
            text = page.get_text("text").strip()
        except Exception:
            text = ""
        pages.append({"page": i + 1, "text": text, "blocks": []})
    doc.close()
    return {"pages": pages}


# ──────────────────────────────────────────────
#  Office → PDF via LibreOffice (best effort)
# ──────────────────────────────────────────────

def _office_to_pdf(data, filename):
    # type: (bytes, str) -> bytes
    """Convert Office doc to PDF via LibreOffice. Returns bytes or None."""
    import subprocess, shutil
    if not shutil.which("libreoffice") and not shutil.which("soffice"):
        return None
    binary = shutil.which("libreoffice") or shutil.which("soffice")
    with tempfile.TemporaryDirectory() as tmpdir:
        in_path = os.path.join(tmpdir, filename)
        with open(in_path, "wb") as f:
            f.write(data)
        try:
            subprocess.run(
                [binary, "--headless", "--convert-to", "pdf", "--outdir", tmpdir, in_path],
                timeout=60, capture_output=True, check=True,
            )
        except Exception:
            return None
        pdf_name = os.path.splitext(filename)[0] + ".pdf"
        pdf_path = os.path.join(tmpdir, pdf_name)
        if os.path.exists(pdf_path):
            with open(pdf_path, "rb") as f:
                return f.read()
    return None


def _office_text_fallback(data: bytes, filename: str) -> str:
    """Best-effort text extraction without LibreOffice."""
    if filename.endswith(".docx"):
        try:
            from docx import Document  # python-docx
            doc = Document(io.BytesIO(data))
            return "\n".join(p.text for p in doc.paragraphs if p.text.strip())
        except Exception:
            pass
    if filename.endswith((".xlsx", ".xls")):
        try:
            import openpyxl
            wb = openpyxl.load_workbook(io.BytesIO(data), read_only=True, data_only=True)
            lines = []
            for ws in wb.worksheets:
                for row in ws.iter_rows(values_only=True):
                    line = "\t".join(str(c) for c in row if c is not None)
                    if line.strip():
                        lines.append(line)
            return "\n".join(lines)
        except Exception:
            pass
    if filename.endswith(".csv") or filename.endswith(".tsv"):
        try:
            return data.decode("utf-8", errors="replace")
        except Exception:
            pass
    return "[office text extraction unavailable — install libreoffice or python-docx]"


# ──────────────────────────────────────────────
#  Helpers
# ──────────────────────────────────────────────

def _is_pdf_bytes(data: bytes) -> bool:
    return data[:4] == b"%PDF"


# ──────────────────────────────────────────────
#  Entry point
# ──────────────────────────────────────────────

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="BizCity LiteParse Sidecar")
    parser.add_argument("--port", type=int, default=7860)
    parser.add_argument("--host", default="127.0.0.1")
    args = parser.parse_args()
    print(f"[liteparse-sidecar] starting on {args.host}:{args.port}  engine=pymupdf/{fitz.version[0]}")
    uvicorn.run(app, host=args.host, port=args.port, log_level="info")
