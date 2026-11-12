"""Klasifikasi isu berbasis kata kunci (S14, implementasi MVP).

F1: diganti model klasifikasi multi-label yang dilatih ulang dari koreksi analis.
"""

from __future__ import annotations

import re
from dataclasses import dataclass

# Taksonomi awal (contoh) — dikelola pengguna melalui API /issues.
DEFAULT_ISSUES: list[dict] = [
    {
        "code": "EKO-PANGAN", "name": "Harga Pangan & Inflasi", "risk_level": "tinggi",
        "taxonomy_path": "Ekonomi/Pangan",
        "keywords": ["harga beras", "beras", "minyak goreng", "harga cabai", "sembako", "inflasi", "harga pangan",
                     "bahan pokok", "kelangkaan", "operasi pasar", "bulog", "gula pasir"],
    },
    {
        "code": "POL-PEMILU", "name": "Pemilu & Pilkada", "risk_level": "tinggi",
        "taxonomy_path": "Politik/Pemilu",
        "keywords": ["pemilu", "pilkada", "kpu", "bawaslu", "kampanye", "surat suara", "politik uang",
                     "rekapitulasi", "tps", "calon kepala daerah"],
    },
    {
        "code": "SOS-SARA", "name": "Kerukunan & Isu SARA", "risk_level": "tinggi",
        "taxonomy_path": "Sosial/Kerukunan",
        "keywords": ["sara", "intoleransi", "intoleran", "rumah ibadah", "antarumat", "kerukunan", "ujaran kebencian",
                     "penistaan", "antaretnis"],
    },
    {
        "code": "BEN-ALAM", "name": "Bencana Alam", "risk_level": "sedang",
        "taxonomy_path": "Keselamatan/Bencana",
        "keywords": ["banjir", "gempa", "tanah longsor", "longsor", "erupsi", "tsunami", "bnpb", "bpbd",
                     "pengungsi", "cuaca ekstrem", "kebakaran hutan"],
    },
    {
        "code": "DIS-HOAKS", "name": "Disinformasi & Hoaks", "risk_level": "tinggi",
        "taxonomy_path": "Informasi/Disinformasi",
        "keywords": ["hoaks", "hoax", "disinformasi", "berita bohong", "deepfake", "misinformasi", "cek fakta",
                     "akun palsu", "narasi menyesatkan"],
    },
    {
        "code": "KES-PUBLIK", "name": "Kesehatan Publik", "risk_level": "sedang",
        "taxonomy_path": "Sosial/Kesehatan",
        "keywords": ["wabah", "demam berdarah", "dbd", "vaksinasi", "vaksin", "bpjs kesehatan", "puskesmas",
                     "stunting", "rumah sakit", "kasus penyakit"],
    },
    {
        "code": "LIN-AGRARIA", "name": "Lingkungan & Agraria", "risk_level": "sedang",
        "taxonomy_path": "Sumber Daya/Agraria",
        "keywords": ["sengketa lahan", "konflik agraria", "deforestasi", "tambang", "pencemaran", "limbah",
                     "masyarakat adat", "reklamasi", "izin tambang"],
    },
    {
        "code": "EKO-KERJA", "name": "Ketenagakerjaan", "risk_level": "sedang",
        "taxonomy_path": "Ekonomi/Ketenagakerjaan",
        "keywords": ["phk", "pemutusan hubungan kerja", "upah minimum", "ump", "buruh", "pengangguran",
                     "mogok kerja", "serikat pekerja", "lowongan kerja"],
    },
]


@dataclass(frozen=True)
class IssueMatch:
    code: str
    confidence: float
    hits: list[str]


class KeywordIssueClassifier:
    def __init__(self, taxonomy: dict[str, list[str]]):
        self._patterns = {
            code: [(kw, re.compile(r"(?<!\w)" + re.escape(kw.lower()) + r"(?!\w)")) for kw in kws if kw.strip()]
            for code, kws in taxonomy.items()
        }

    def classify(self, text: str) -> list[IssueMatch]:
        low = (text or "").lower()
        out = []
        for code, pats in self._patterns.items():
            hits = [kw for kw, pat in pats if pat.search(low)]
            if hits:
                conf = round(min(0.95, 0.5 + 0.15 * (len(hits) - 1)), 2)
                out.append(IssueMatch(code, conf, hits))
        return sorted(out, key=lambda m: m.confidence, reverse=True)
