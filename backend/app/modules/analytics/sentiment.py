"""Analisis sentimen berbasis leksikon Bahasa Indonesia (S08, implementasi MVP).

Antarmuka `SentimentAnalyzer` bersifat pluggable: pada F1 diganti model encoder
(IndoBERT/XLM-R) yang di-fine-tune, tanpa mengubah pemanggil.
"""

from __future__ import annotations

import re
from dataclasses import dataclass
from typing import Protocol

POSITIVE = frozenset("""
adil akurat aman apresiasi bagus baik bangga bantuan berhasil berkembang bermanfaat bersatu bersih bijak
damai dukung dukungan efektif efisien gembira harmonis hebat inklusif inovatif jujur keberhasilan kolaborasi
kompeten kondusif kuat lancar layak maju makmur membaik memuaskan mendukung menguat optimis optimistis
pemulihan percaya positif prestasi puas pulih rukun sehat sejahtera selamat senang setuju sinergi solid stabil
sukses tangguh terbantu terjangkau terkendali terlindungi toleran toleransi transparan unggul untung
keuntungan menyambut menggembirakan diapresiasi berprestasi kesejahteraan perdamaian
""".split())

NEGATIVE = frozenset("""
anarkis ancaman anjlok beban bentrok bohong buruk curang darurat defisit diskriminasi gagal gaduh hoaks hoax
inflasi intimidasi intoleran intoleransi kacau kecewa kecurangan kegagalan kekecewaan kekerasan kelangkaan
keluhan kemarahan kemiskinan kerugian kerusakan kerusuhan kericuhan kesulitan ketakutan korban korupsi krisis
kritik lambat langka lonjakan mahal marah melanggar melonjak membebani memburuk mencekik menderita mengancam
mengeluh mengeluhkan mengkritik menolak menyerang merugikan miskin mogok panik parah pelanggaran pemerasan
penderitaan pengangguran penipuan penolakan phk protes provokasi provokatif radikal resah keresahan ricuh rugi
rusak rusuh sengketa serangan sulit takut terancam teror terorisme tewas tragedi kecam mengecam dikecam
memprihatinkan meresahkan menyesatkan fitnah kebencian kisruh polemik
""".split())

NEGATORS = frozenset({"tidak", "tak", "bukan", "belum", "tanpa", "jangan", "kurang", "enggak", "nggak", "gak"})
INTENSIFIERS = frozenset({"sangat", "amat", "sekali", "paling", "terlalu", "begitu", "benar-benar"})

_TOKEN = re.compile(r"[a-zA-ZÀ-ɏ]+(?:-[a-zA-Z]+)?")


@dataclass(frozen=True)
class SentimentResult:
    label: str  # positif | negatif | netral
    score: float  # -1..1
    positive_hits: int
    negative_hits: int


class SentimentAnalyzer(Protocol):
    def analyze(self, text: str) -> SentimentResult: ...


class LexiconSentimentAnalyzer:
    threshold = 0.15

    def analyze(self, text: str) -> SentimentResult:
        tokens = [t.lower() for t in _TOKEN.findall(text or "")]
        pos = neg = 0.0
        pos_hits = neg_hits = 0
        for i, tok in enumerate(tokens):
            polarity = 1 if tok in POSITIVE else -1 if tok in NEGATIVE else 0
            if polarity == 0:
                continue
            window = tokens[max(0, i - 2): i]
            if any(w in NEGATORS for w in window):
                polarity = -polarity
            weight = 1.5 if any(w in INTENSIFIERS for w in window) or (i + 1 < len(tokens) and tokens[i + 1] == "sekali") else 1.0
            if polarity > 0:
                pos += weight
                pos_hits += 1
            else:
                neg += weight
                neg_hits += 1
        total = pos + neg
        score = 0.0 if total == 0 else round((pos - neg) / total, 4)
        if total == 0 or abs(score) < self.threshold:
            label = "netral"
        else:
            label = "positif" if score > 0 else "negatif"
        return SentimentResult(label, score, pos_hits, neg_hits)


default_analyzer: SentimentAnalyzer = LexiconSentimentAnalyzer()
