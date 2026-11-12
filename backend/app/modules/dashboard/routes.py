"""Dashboard situasi (S60) dan jejak audit (S65)."""

from __future__ import annotations

from collections import Counter
from datetime import timedelta

from fastapi import APIRouter, Depends, Query, Request
from pydantic import BaseModel
from sqlalchemy import func, select
from sqlalchemy.orm import Session

from app.core import audit
from app.core.audit import AuditEvent
from app.core.classification import Role
from app.core.db import get_db
from app.core.security import client_ip, get_current_user, require_roles
from app.core.timeutil import as_utc, utcnow
from app.modules.alerts.models import LEVEL_RANK, OPEN_STATUSES, Alert
from app.modules.alerts.service import assess
from app.modules.analytics.geo import PROVINCE_NAMES
from app.modules.analytics.models import Issue
from app.modules.identity.models import User
from app.modules.ingest.models import ContentItem, Source
from app.modules.repository.service import search_documents
from app.modules.workflow.models import Draft

router = APIRouter(tags=["dashboard"])


@router.get("/dashboard/summary")
def summary(hours: int = Query(default=24, ge=1, le=168), days: int = Query(default=14, ge=2, le=60),
            db: Session = Depends(get_db), user: User = Depends(get_current_user)) -> dict:
    now = utcnow()
    since = now - timedelta(hours=hours)

    sentiment = dict(db.execute(
        select(ContentItem.sentiment_label, func.count()).where(ContentItem.published_at >= since)
        .group_by(ContentItem.sentiment_label)
    ).all())
    total_window = sum(sentiment.values())

    start = (now - timedelta(days=days - 1)).replace(hour=0, minute=0, second=0, microsecond=0)
    daily: dict[str, Counter] = {(start + timedelta(days=i)).date().isoformat(): Counter() for i in range(days)}
    for published_at, label in db.execute(
        select(ContentItem.published_at, ContentItem.sentiment_label).where(ContentItem.published_at >= start)
    ):
        key = as_utc(published_at).date().isoformat()
        if key in daily:
            daily[key][label] += 1
    timeline = [{"date": k, "positif": c["positif"], "netral": c["netral"], "negatif": c["negatif"],
                 "total": sum(c.values())} for k, c in daily.items()]

    prov_counts = dict(db.execute(
        select(ContentItem.province_code, func.count()).where(ContentItem.published_at >= since,
                                                              ContentItem.province_code.is_not(None))
        .group_by(ContentItem.province_code)
    ).all())
    prov_neg = dict(db.execute(
        select(ContentItem.province_code, func.count()).where(ContentItem.published_at >= since,
                                                              ContentItem.province_code.is_not(None),
                                                              ContentItem.sentiment_label == "negatif")
        .group_by(ContentItem.province_code)
    ).all())
    provinces = sorted(
        ({"code": c, "name": n, "volume": prov_counts.get(c, 0), "negative": prov_neg.get(c, 0)}
         for c, n in PROVINCE_NAMES.items()),
        key=lambda p: -p["volume"],
    )

    issue_map = {i.id: i for i in db.execute(select(Issue)).scalars()}
    issues = sorted(
        ({"issue_id": a.issue_id, "code": issue_map[a.issue_id].code, "name": issue_map[a.issue_id].name,
          "risk_level": issue_map[a.issue_id].risk_level, "level": a.level, "reason": a.reason, **a.metrics()}
         for a in assess(db, now)),
        key=lambda x: (-LEVEL_RANK[x["level"]], -x["volume_24h"]),
    )

    open_alerts = dict(db.execute(
        select(Alert.level, func.count()).where(Alert.status.in_(OPEN_STATUSES)).group_by(Alert.level)
    ).all())
    drafts = dict(db.execute(select(Draft.status, func.count()).group_by(Draft.status)).all())
    last_ingest = db.execute(select(func.max(Source.last_fetched_at))).scalar()

    return {
        "generated_at": now.isoformat(),
        "window_hours": hours,
        "content": {
            "total_window": total_window,
            "total_all": db.execute(select(func.count(ContentItem.id))).scalar_one(),
            "sentiment": {k: sentiment.get(k, 0) for k in ("positif", "netral", "negatif")},
            "provinces_covered": sum(1 for p in provinces if p["volume"] > 0),
        },
        "timeline": timeline,
        "provinces": provinces,
        "issues": issues,
        "alerts": {lvl: open_alerts.get(lvl, 0) for lvl in ("merah", "oranye", "kuning")},
        "drafts": {s: drafts.get(s, 0) for s in ("draf", "ditinjau", "disetujui", "tayang", "ditarik")},
        "documents_accessible": len(search_documents(db, user)),
        "sources": db.execute(select(func.count(Source.id)).where(Source.active.is_(True))).scalar_one(),
        "last_ingest_at": as_utc(last_ingest).isoformat() if last_ingest else None,
    }


class AuditOut(BaseModel):
    seq: int
    at: str
    actor_username: str
    action: str
    object_type: str
    object_id: str
    ip: str
    detail: dict
    hash: str


@router.get("/audit", response_model=list[AuditOut])
def list_audit(
    action: str | None = None,
    actor: str | None = None,
    limit: int = Query(default=100, ge=1, le=500),
    before_seq: int | None = None,
    db: Session = Depends(get_db),
    _: User = Depends(require_roles(Role.AUDITOR)),
) -> list[AuditOut]:
    stmt = select(AuditEvent)
    if action:
        stmt = stmt.where(AuditEvent.action.startswith(action))
    if actor:
        stmt = stmt.where(AuditEvent.actor_username == actor)
    if before_seq:
        stmt = stmt.where(AuditEvent.seq < before_seq)
    rows = db.execute(stmt.order_by(AuditEvent.seq.desc()).limit(limit)).scalars().all()
    return [AuditOut(seq=e.seq, at=as_utc(e.at).isoformat(), actor_username=e.actor_username, action=e.action,
                     object_type=e.object_type, object_id=e.object_id, ip=e.ip, detail=e.detail or {}, hash=e.hash)
            for e in rows]


@router.get("/audit/verify")
def verify_audit(request: Request, db: Session = Depends(get_db),
                 user: User = Depends(require_roles(Role.AUDITOR, Role.ADMIN))) -> dict:
    result = audit.verify_chain(db)
    audit.record(db, actor=user, action="audit.verifikasi", object_type="audit", ip=client_ip(request),
                 detail={"ok": result.ok, "checked": result.checked, "first_broken_seq": result.first_broken_seq})
    db.commit()
    return result.__dict__
