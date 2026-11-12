"""Deduplikasi tepat (SHA-256 teks ternormalisasi) dan hampir-sama — S03.

Hampir-sama: SimHash 64-bit sebagai penyaring kandidat (murah, dapat diindeks), lalu konfirmasi
dengan kemiripan Jaccard atas shingle 3-kata. SimHash saja kurang andal untuk teks pendek
(berita singkat/unggahan medsos). F1: MinHash-LSH terdistribusi.
"""

from __future__ import annotations

import hashlib
import re

_WORD = re.compile(r"\w+", re.UNICODE)
_MASK64 = (1 << 64) - 1


def normalize(title: str, body: str) -> str:
    return " ".join(_WORD.findall(f"{title} {body}".lower()))


def content_hash(title: str, body: str) -> str:
    return hashlib.sha256(normalize(title, body).encode()).hexdigest()


def simhash(text: str, shingle: int = 3) -> int:
    """SimHash 64-bit atas shingle kata; dikembalikan sebagai int bertanda (muat di BIGINT)."""
    words = _WORD.findall(text.lower())
    if not words:
        return 0
    grams = [" ".join(words[i: i + shingle]) for i in range(max(1, len(words) - shingle + 1))]
    vec = [0] * 64
    for g in grams:
        h = int.from_bytes(hashlib.blake2b(g.encode(), digest_size=8).digest(), "big")
        for bit in range(64):
            vec[bit] += 1 if (h >> bit) & 1 else -1
    value = 0
    for bit in range(64):
        if vec[bit] > 0:
            value |= 1 << bit
    return value - (1 << 64) if value >= (1 << 63) else value


def hamming(a: int, b: int) -> int:
    return bin((a & _MASK64) ^ (b & _MASK64)).count("1")


SIMHASH_PREFILTER = 22
JACCARD_THRESHOLD = 0.8


def is_near_duplicate(a: int, b: int, threshold: int = 3) -> bool:
    return hamming(a, b) <= threshold


def shingles(text: str, size: int = 3) -> frozenset[int]:
    words = _WORD.findall(text.lower())
    if len(words) < size:
        return frozenset({hash(" ".join(words))}) if words else frozenset()
    return frozenset(hash(" ".join(words[i: i + size])) for i in range(len(words) - size + 1))


def jaccard(a: frozenset[int], b: frozenset[int]) -> float:
    if not a or not b:
        return 0.0
    return len(a & b) / len(a | b)
