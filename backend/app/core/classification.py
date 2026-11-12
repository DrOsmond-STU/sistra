"""Tingkat klasifikasi keamanan (S71) dan peran pengguna."""

from __future__ import annotations

from enum import IntEnum


class Level(IntEnum):
    TERBUKA = 0
    TERBATAS = 1
    RAHASIA = 2
    SANGAT_RAHASIA = 3

    @classmethod
    def parse(cls, value: str) -> "Level":
        try:
            return cls[value.strip().upper().replace(" ", "_")]
        except KeyError as exc:
            raise ValueError(f"Klasifikasi tidak dikenal: {value}") from exc


LEVEL_LABELS = {
    Level.TERBUKA: "Terbuka",
    Level.TERBATAS: "Terbatas",
    Level.RAHASIA: "Rahasia",
    Level.SANGAT_RAHASIA: "Sangat Rahasia",
}


class Role:
    PIMPINAN = "pimpinan"
    ANALIS = "analis"
    REDAKTUR = "redaktur"
    PRODUSER = "produser"
    OPERATOR = "operator"
    ARSIPARIS = "arsiparis"
    PELAPOR = "pelapor"
    ADMIN = "admin"
    AUDITOR = "auditor"

    ALL = frozenset({PIMPINAN, ANALIS, REDAKTUR, PRODUSER, OPERATOR, ARSIPARIS, PELAPOR, ADMIN, AUDITOR})
