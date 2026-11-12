"""Pengayaan konten dan metrik isu (S07, S08, S14, S16-dasar)."""

from __future__ import annotations

from collections import Counter, defaultdict
from datetime import datetime, timedelta

from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.core.timeutil import as_utc, utcnow
from app.modules.analytics.geo import geotag
from app.modules.analytics.issues import KeywordIssueClassifier
from app.modules.analytics.models import ContentIssue, Issue
from app.modules.analytics.sentiment import default_analyzer
from app.modules.ingest.models import ContentItem


def load_classifier(db: Session) -> tuple[KeywordIssueClassifier, dict[str, int]]:
    issues = db.execute(select(Issue).where(Issue.active.is_(True))).scalars().all()
    return KeywordIssueClassifier({i.code: list(i.keywords or []) for i in issues}), {i.code: i.id for i in issues}


def enrich(db: Session, item: ContentItem, classifier: KeywordIssueClassifier, issue_ids: dict[str, int]) -> None:
    """Isi sentimen, provinsi, dan isu pada item (item harus sudah di-flush agar punya id)."""
    text = f"{item.title}. {item.body}"
    s = default_analyzer.analyze(text)
    item.sentiment_label, item.sentiment_score = s.label, s.score
    g = geotag(text)
    item.provinces = g.provinces
    fallback = item.source.province_code if item.source is not None else None
    item.province_code = g.primary or fallback
    for match in classifier.classify(text):
        db.add(ContentIssue(content_id=item.id, issue_id=issue_ids[match.code], confidence=match.confidence,
                            assigned_by="model"))


def issue_metrics(db: Session, issue_id: int, days: int = 14, now: datetime | None = None) -> dict:
    """Deret waktu harian: volume, rasio negatif, rerata sentimen, sebaran provinsi."""
    now = as_utc(now) or utcnow()
    start = (now - timedelta(days=days - 1)).replace(hour=0, minute=0, second=0, microsecond=0)
    rows = db.execute(
        select(ContentItem.published_at, ContentItem.sentiment_label, ContentItem.sentiment_score,
               ContentItem.province_code)
        .join(ContentIssue, ContentIssue.content_id == ContentItem.id)
        .where(ContentIssue.issue_id == issue_id, ContentItem.published_at >= start, ContentItem.published_at <= now)
    ).all()
    buckets: dict[str, dict] = {}
    for d in range(days):
        key = (start + timedelta(days=d)).date().isoformat()
        buckets[key] = {"date": key, "volume": 0, "negative": 0, "positive": 0, "score_sum": 0.0}
    provinces: Counter[str] = Counter()
    for published_at, label, score, prov in rows:
        key = as_utc(published_at).date().isoformat()
        if key not in buckets:
            continue
        b = buckets[key]
        b["volume"] += 1
        b["score_sum"] += score or 0.0
        if label == "negatif":
            b["negative"] += 1
        elif label == "positif":
            b["positive"] += 1
        if prov:
            provinces[prov] += 1
    series = []
    for b in buckets.values():
        vol = b.pop("volume")
        score_sum = b.pop("score_sum")
        series.append({
            **b,
            "volume": vol,
            "negative_ratio": round(b["negative"] / vol, 3) if vol else 0.0,
            "sentiment_avg": round(score_sum / vol, 3) if vol else 0.0,
        })
    return {"issue_id": issue_id, "days": days, "series": series, "provinces": dict(provinces.most_common())}


def issue_window_stats(db: Session, since: datetime, until: datetime) -> dict[int, dict]:
    """Agregat per isu dalam jendela waktu: volume, negatif, jumlah provinsi."""
    rows = db.execute(
        select(ContentIssue.issue_id, ContentItem.sentiment_label, ContentItem.province_code, func.count())
        .join(ContentItem, ContentItem.id == ContentIssue.content_id)
        .where(ContentItem.published_at >= since, ContentItem.published_at < until)
        .group_by(ContentIssue.issue_id, ContentItem.sentiment_label, ContentItem.province_code)
    ).all()
    out: dict[int, dict] = defaultdict(lambda: {"volume": 0, "negative": 0, "provinces": set()})
    for issue_id, label, prov, n in rows:
        s = out[issue_id]
        s["volume"] += n
        if label == "negatif":
            s["negative"] += n
        if prov:
            s["provinces"].add(prov)
    return out
