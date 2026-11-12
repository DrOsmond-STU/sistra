"""Penyimpanan berkas berbasis hash isi (content-addressed) — S66 deduplikasi, S67 integritas.

MVP: sistem berkas lokal. F1: object storage S3 dengan object lock; antarmuka sama.
"""

from __future__ import annotations

import hashlib
import io
import re
import zipfile
from pathlib import Path

from app.core.config import get_settings


class IntegrityError(Exception):
    pass


def _path_for(sha256: str) -> Path:
    if not re.fullmatch(r"[0-9a-f]{64}", sha256):
        raise ValueError("sha256 tidak valid")
    return get_settings().blob_dir / sha256[:2] / sha256[2:4] / sha256


def put(data: bytes) -> tuple[str, bool]:
    """Simpan data; kembalikan (sha256, sudah_ada)."""
    digest = hashlib.sha256(data).hexdigest()
    path = _path_for(digest)
    if path.exists():
        return digest, True
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(".tmp")
    tmp.write_bytes(data)
    tmp.replace(path)
    return digest, False


def get(sha256: str) -> bytes:
    """Baca data dan verifikasi integritas SHA-256."""
    data = _path_for(sha256).read_bytes()
    if hashlib.sha256(data).hexdigest() != sha256:
        raise IntegrityError(f"Integritas berkas {sha256[:12]}… gagal diverifikasi")
    return data


TEXT_MIMES = {"text/plain", "text/markdown", "text/csv", "application/json", "text/html"}
TEXT_EXT = {".txt", ".md", ".csv", ".json", ".html", ".htm"}


def is_text(filename: str, mime: str) -> bool:
    return mime in TEXT_MIMES or mime.startswith("text/") or Path(filename).suffix.lower() in TEXT_EXT


def extract_text(filename: str, mime: str, data: bytes, limit: int = 2_000_000) -> str:
    """Ekstraksi teks untuk pencarian (S70). Kegagalan ekstraksi tidak menggagalkan unggah."""
    ext = Path(filename).suffix.lower()
    try:
        if is_text(filename, mime):
            text = data.decode("utf-8", errors="ignore")
            if ext in {".html", ".htm"} or mime == "text/html":
                text = re.sub(r"<[^>]+>", " ", text)
            return text[:limit]
        if ext == ".pdf" or mime == "application/pdf":
            from pypdf import PdfReader

            reader = PdfReader(io.BytesIO(data))
            parts = []
            for page in reader.pages:
                parts.append(page.extract_text() or "")
                if sum(map(len, parts)) > limit:
                    break
            return "\n".join(parts)[:limit]
        if ext == ".docx":
            with zipfile.ZipFile(io.BytesIO(data)) as zf:
                xml = zf.read("word/document.xml").decode("utf-8", errors="ignore")
            xml = re.sub(r"</w:p>", "\n", xml)
            return re.sub(r"<[^>]+>", "", xml)[:limit]
    except Exception:  # noqa: BLE001 — berkas rusak/terenkripsi: tetap simpan tanpa teks
        return ""
    return ""
