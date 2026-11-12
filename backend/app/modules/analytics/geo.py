"""Geotag 38 provinsi Indonesia berbasis gazetteer (S07, implementasi MVP).

Kode provinsi mengikuti kode wilayah Kemendagri (termasuk 6 provinsi Papua pasca-2022).
Pencocokan peka huruf besar agar kata umum ("medan perang", "nasib malang") tidak salah tandai.
"""

from __future__ import annotations

import re
from collections import Counter
from dataclasses import dataclass

# kode: (nama resmi, [alias/kota utama/singkatan])
PROVINCES: dict[str, tuple[str, list[str]]] = {
    "11": ("Aceh", ["Aceh", "Banda Aceh", "Lhokseumawe", "Nanggroe Aceh Darussalam"]),
    "12": ("Sumatera Utara", ["Sumatera Utara", "Sumut", "Medan", "Pematangsiantar", "Binjai"]),
    "13": ("Sumatera Barat", ["Sumatera Barat", "Sumbar", "Padang", "Bukittinggi"]),
    "14": ("Riau", ["Riau", "Pekanbaru", "Dumai"]),
    "15": ("Jambi", ["Jambi"]),
    "16": ("Sumatera Selatan", ["Sumatera Selatan", "Sumsel", "Palembang"]),
    "17": ("Bengkulu", ["Bengkulu"]),
    "18": ("Lampung", ["Lampung", "Bandar Lampung"]),
    "19": ("Kepulauan Bangka Belitung", ["Kepulauan Bangka Belitung", "Bangka Belitung", "Babel", "Pangkalpinang"]),
    "21": ("Kepulauan Riau", ["Kepulauan Riau", "Kepri", "Batam", "Tanjungpinang"]),
    "31": ("DKI Jakarta", ["DKI Jakarta", "Jakarta", "DKI"]),
    "32": ("Jawa Barat", ["Jawa Barat", "Jabar", "Bandung", "Bogor", "Bekasi", "Depok", "Cirebon"]),
    "33": ("Jawa Tengah", ["Jawa Tengah", "Jateng", "Semarang", "Surakarta", "Kota Solo"]),
    "34": ("DI Yogyakarta", ["DI Yogyakarta", "Daerah Istimewa Yogyakarta", "Yogyakarta", "DIY", "Jogja"]),
    "35": ("Jawa Timur", ["Jawa Timur", "Jatim", "Surabaya", "Kota Malang", "Sidoarjo"]),
    "36": ("Banten", ["Banten", "Serang", "Tangerang", "Cilegon"]),
    "51": ("Bali", ["Bali", "Denpasar"]),
    "52": ("Nusa Tenggara Barat", ["Nusa Tenggara Barat", "NTB", "Mataram", "Lombok"]),
    "53": ("Nusa Tenggara Timur", ["Nusa Tenggara Timur", "NTT", "Kupang"]),
    "61": ("Kalimantan Barat", ["Kalimantan Barat", "Kalbar", "Pontianak"]),
    "62": ("Kalimantan Tengah", ["Kalimantan Tengah", "Kalteng", "Palangka Raya", "Palangkaraya"]),
    "63": ("Kalimantan Selatan", ["Kalimantan Selatan", "Kalsel", "Banjarmasin", "Banjarbaru"]),
    "64": ("Kalimantan Timur", ["Kalimantan Timur", "Kaltim", "Samarinda", "Balikpapan"]),
    "65": ("Kalimantan Utara", ["Kalimantan Utara", "Kaltara", "Tanjung Selor", "Tarakan"]),
    "71": ("Sulawesi Utara", ["Sulawesi Utara", "Sulut", "Manado"]),
    "72": ("Sulawesi Tengah", ["Sulawesi Tengah", "Sulteng", "Palu"]),
    "73": ("Sulawesi Selatan", ["Sulawesi Selatan", "Sulsel", "Makassar"]),
    "74": ("Sulawesi Tenggara", ["Sulawesi Tenggara", "Sultra", "Kendari"]),
    "75": ("Gorontalo", ["Gorontalo"]),
    "76": ("Sulawesi Barat", ["Sulawesi Barat", "Sulbar", "Mamuju"]),
    "81": ("Maluku", ["Maluku", "Ambon"]),
    "82": ("Maluku Utara", ["Maluku Utara", "Malut", "Ternate", "Sofifi"]),
    "91": ("Papua", ["Papua", "Jayapura"]),
    "92": ("Papua Barat", ["Papua Barat", "Manokwari"]),
    "93": ("Papua Selatan", ["Papua Selatan", "Merauke"]),
    "94": ("Papua Tengah", ["Papua Tengah", "Nabire", "Timika"]),
    "95": ("Papua Pegunungan", ["Papua Pegunungan", "Wamena", "Jayawijaya"]),
    "96": ("Papua Barat Daya", ["Papua Barat Daya", "Sorong"]),
}

assert len(PROVINCES) == 38

PROVINCE_NAMES = {code: name for code, (name, _) in PROVINCES.items()}

_ALIASES = sorted(
    ((alias, code) for code, (_, aliases) in PROVINCES.items() for alias in aliases),
    key=lambda x: len(x[0]),
    reverse=True,
)
_PATTERN = re.compile(r"(?<![\w-])(" + "|".join(re.escape(a) for a, _ in _ALIASES) + r")(?![\w-])")
_LOOKUP = {alias: code for alias, code in _ALIASES}


@dataclass(frozen=True)
class GeoResult:
    primary: str | None
    provinces: list[str]  # kode, urut frekuensi
    counts: dict[str, int]


def geotag(text: str) -> GeoResult:
    # Regex alternasi diurutkan dari alias terpanjang, sehingga "Papua Barat Daya"
    # menang atas "Papua Barat" dan "Papua".
    counts: Counter[str] = Counter(_LOOKUP[m.group(1)] for m in _PATTERN.finditer(text or ""))
    ordered = [code for code, _ in counts.most_common()]
    return GeoResult(primary=ordered[0] if ordered else None, provinces=ordered, counts=dict(counts))
