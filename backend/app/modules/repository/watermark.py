"""Watermark dinamis per unduhan (S71).

- Teks: header dan footer watermark disisipkan.
- PDF: anotasi teks pada setiap halaman + metadata dokumen.
- Format lain (MVP): berkas asli; watermark tercatat di log unduhan dan header respons.
  F1: watermark tak tampak (steganografi) untuk citra/video dan render PDF untuk Office.
"""

from __future__ import annotations

import io

from app.modules.repository.storage import is_text


def apply(filename: str, mime: str, data: bytes, mark: str) -> tuple[bytes, bool]:
    """Kembalikan (data_berwatermark, berhasil_disisipkan)."""
    if is_text(filename, mime):
        line = f"[WATERMARK] {mark}"
        header = f"{line}\n{'=' * min(len(line), 120)}\n\n"
        footer = f"\n\n{'=' * min(len(line), 120)}\n{line}\n"
        return (header + data.decode("utf-8", errors="replace") + footer).encode("utf-8"), True
    if filename.lower().endswith(".pdf") or mime == "application/pdf":
        try:
            return _pdf(data, mark), True
        except Exception:  # noqa: BLE001
            return data, False
    return data, False


def _pdf(data: bytes, mark: str) -> bytes:
    from pypdf import PdfReader, PdfWriter
    from pypdf.annotations import FreeText

    reader = PdfReader(io.BytesIO(data))
    writer = PdfWriter()
    writer.append(reader)
    for idx, page in enumerate(writer.pages):
        box = page.mediabox
        x0, y0 = float(box.left), float(box.bottom)
        width = float(box.width)
        annot = FreeText(
            text=mark,
            rect=(x0 + 20, y0 + 8, x0 + width - 20, y0 + 26),
            font="Helvetica",
            font_size="8pt",
            font_color="b40000",
            border_color=None,
            background_color=None,
        )
        annot.flags = 4 | 128  # Print + Locked
        writer.add_annotation(page_number=idx, annotation=annot)
    writer.add_metadata({"/SISTRA-Watermark": mark})
    out = io.BytesIO()
    writer.write(out)
    return out.getvalue()
