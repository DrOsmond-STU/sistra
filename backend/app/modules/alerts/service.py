"""Mesin peringatan dini berjenjang (S21) dan siklus hidup ancaman (S22).

Aturan MVP (ambang pada `Settings.alerts`):
  volume_24j = jumlah konten isu dalam 24 jam terakhir
  baseline   = rerata harian 7 hari sebelumnya
  pertumbuhan = volume_24j / max(baseline, 1)
  rasio_neg  = konten negatif / volume_24j

  MERAH  : volume ≥ red_volume    DAN pertumbuhan ≥ red_growth    DAN rasio_neg ≥ red_negative
  ORANYE : volume ≥ orange_volume DAN (pertumbuhan ≥ orange_growth ATAU rasio_neg ≥ orange_negative)
  KUNING : volume ≥ yellow_volume DAN (pertumbuhan ≥ yellow_growth ATAU rasio_neg ≥ yellow_negative)
  selain itu HIJAU.
F1: ambang dinamis per wilayah/tokoh/topik dan model prediksi (S16).
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timedelta

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core import audit
from app.core.config import AlertThresholds, get_settings
from app.core.timeutil import as_utc, utcnow
from app.modules.alerts.models import LEVEL_RANK, OPEN_STATUSES, Alert, AlertEvent
from app.modules.analytics.models import Issue
from app.modules.analytics.service import issue_window_stats


@dataclass
class IssueAssessment:
    issue_id: int
    level: str
    volume: int
    baseline_daily: float
    growth: float
    negative_ratio: float
    province_spread: int
    reason: str

    def metrics(self) -> dict:
        return {
            "volume_24h": self.volume,
            "baseline_daily": round(self.baseline_daily, 2),
            "growth": round(self.growth, 2),
            "negative_ratio": round(self.negative_ratio, 3),
            "province_spread": self.province_spread,
        }


def classify_level(volume: int, growth: float, neg: float, t: AlertThresholds) -> tuple[str, str]:
    if volume >= t.red_volume and growth >= t.red_growth and neg >= t.red_negative:
        return "merah", f"Lonjakan {growth:.1f}× dengan sentimen negatif {neg:.0%} (volume {volume})"
    if volume >= t.orange_volume and (growth >= t.orange_growth or neg >= t.orange_negative):
        return "oranye", f"Pertumbuhan {growth:.1f}×, sentimen negatif {neg:.0%} (volume {volume})"
    if volume >= t.yellow_volume and (growth >= t.yellow_growth or neg >= t.yellow_negative):
        return "kuning", f"Indikasi peningkatan: {growth:.1f}×, negatif {neg:.0%} (volume {volume})"
    return "hijau", "Normal"


def assess(db: Session, now: datetime | None = None) -> list[IssueAssessment]:
    now = as_utc(now) or utcnow()
    t = get_settings().alerts
    window = issue_window_stats(db, now - timedelta(hours=24), now)
    baseline = issue_window_stats(db, now - timedelta(days=8), now - timedelta(hours=24))
    out = []
    for issue in db.execute(select(Issue).where(Issue.active.is_(True))).scalars().all():
        w = window.get(issue.id, {"volume": 0, "negative": 0, "provinces": set()})
        base = baseline.get(issue.id, {"volume": 0})["volume"] / 7.0
        vol = w["volume"]
        growth = vol / max(base, 1.0)
        neg = (w["negative"] / vol) if vol else 0.0
        level, reason = classify_level(vol, growth, neg, t)
        out.append(IssueAssessment(issue.id, level, vol, base, growth, neg, len(w["provinces"]), reason))
    return out


def evaluate(db: Session, now: datetime | None = None, actor=None) -> dict:  # noqa: ANN001
    """Buat/eskalasi peringatan sesuai penilaian terkini. Pemanggil melakukan commit."""
    now = as_utc(now) or utcnow()
    created, escalated = [], []
    for a in assess(db, now):
        if a.level == "hijau":
            continue
        current = db.execute(
            select(Alert).where(Alert.issue_id == a.issue_id, Alert.status.in_(OPEN_STATUSES))
            .order_by(Alert.id.desc()).limit(1)
        ).scalar_one_or_none()
        if current is None:
            alert = Alert(issue_id=a.issue_id, level=a.level, status="open", reason=a.reason, metrics=a.metrics(),
                          triggered_at=now, updated_at=now)
            alert.events.append(AlertEvent(at=now, action="dibuat", level=a.level, note=a.reason))
            db.add(alert)
            db.flush()
            created.append(alert.id)
            audit.record(db, actor=actor, action="peringatan.dibuat", object_type="alert", object_id=alert.id,
                         detail={"issue_id": a.issue_id, "level": a.level, **a.metrics()})
        elif LEVEL_RANK[a.level] > LEVEL_RANK[current.level]:
            prev = current.level
            current.level, current.reason, current.metrics, current.updated_at = a.level, a.reason, a.metrics(), now
            current.events.append(AlertEvent(at=now, action="eskalasi", level=a.level, note=f"{prev} → {a.level}: {a.reason}"))
            escalated.append(current.id)
            audit.record(db, actor=actor, action="peringatan.eskalasi", object_type="alert", object_id=current.id,
                         detail={"from": prev, "to": a.level, **a.metrics()})
        else:
            current.metrics = a.metrics()
    return {"created": created, "escalated": escalated, "evaluated_at": now.isoformat()}


# Transisi status siklus hidup (S22)
TRANSITIONS = {
    "ack": ({"open"}, "ack"),
    "tangani": ({"open", "ack"}, "in_progress"),
    "selesai": ({"open", "ack", "in_progress"}, "resolved"),
}
