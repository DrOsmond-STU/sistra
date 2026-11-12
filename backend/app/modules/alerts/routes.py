"""API Peringatan Dini — Modul 2 (S21, S22)."""

from __future__ import annotations

from datetime import datetime

from fastapi import APIRouter, Depends, HTTPException, Query, Request, status
from pydantic import BaseModel, Field
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core import audit
from app.core.classification import Role
from app.core.db import get_db
from app.core.security import client_ip, get_current_user, require_roles
from app.core.timeutil import utcnow
from app.modules.alerts import service
from app.modules.alerts.models import LEVEL_RANK, OPEN_STATUSES, Alert, AlertEvent
from app.modules.identity.models import User

router = APIRouter(prefix="/alerts", tags=["peringatan"])


class AlertEventOut(BaseModel):
    at: datetime
    action: str
    level: str
    note: str
    actor_id: int | None


class AlertOut(BaseModel):
    id: int
    issue_id: int
    issue_code: str
    issue_name: str
    level: str
    status: str
    reason: str
    metrics: dict
    owner_id: int | None
    triggered_at: datetime
    updated_at: datetime
    resolved_at: datetime | None
    events: list[AlertEventOut] = Field(default_factory=list)

    @classmethod
    def of(cls, a: Alert, with_events: bool = False) -> "AlertOut":
        return cls(
            id=a.id, issue_id=a.issue_id, issue_code=a.issue.code, issue_name=a.issue.name, level=a.level,
            status=a.status, reason=a.reason, metrics=a.metrics or {}, owner_id=a.owner_id,
            triggered_at=a.triggered_at, updated_at=a.updated_at, resolved_at=a.resolved_at,
            events=[AlertEventOut(at=e.at, action=e.action, level=e.level, note=e.note, actor_id=e.actor_id)
                    for e in a.events] if with_events else [],
        )


class ActionIn(BaseModel):
    note: str = Field(default="", max_length=2000)


@router.get("", response_model=list[AlertOut])
def list_alerts(
    state: str = Query(default="aktif", pattern="^(aktif|selesai|semua)$"),
    limit: int = Query(default=100, ge=1, le=500),
    db: Session = Depends(get_db),
    _: User = Depends(get_current_user),
) -> list[AlertOut]:
    stmt = select(Alert)
    if state == "aktif":
        stmt = stmt.where(Alert.status.in_(OPEN_STATUSES))
    elif state == "selesai":
        stmt = stmt.where(Alert.status == "resolved")
    alerts = db.execute(stmt.order_by(Alert.updated_at.desc()).limit(limit)).unique().scalars().all()
    alerts = sorted(alerts, key=lambda a: (a.status == "resolved", -LEVEL_RANK[a.level], -a.updated_at.timestamp()))
    return [AlertOut.of(a) for a in alerts]


@router.get("/assessment")
def assessment(db: Session = Depends(get_db), _: User = Depends(get_current_user)) -> list[dict]:
    """Penilaian semua isu saat ini (termasuk hijau), tanpa membuat peringatan."""
    return [{"issue_id": a.issue_id, "level": a.level, "reason": a.reason, **a.metrics()} for a in service.assess(db)]


@router.post("/evaluate")
def evaluate(request: Request, db: Session = Depends(get_db),
             user: User = Depends(require_roles(Role.ANALIS, Role.ADMIN))) -> dict:
    result = service.evaluate(db, actor=user)
    audit.record(db, actor=user, action="peringatan.evaluasi", object_type="alert", ip=client_ip(request),
                 detail={"created": len(result["created"]), "escalated": len(result["escalated"])})
    db.commit()
    return result


@router.get("/{alert_id}", response_model=AlertOut)
def get_alert(alert_id: int, db: Session = Depends(get_db), _: User = Depends(get_current_user)) -> AlertOut:
    alert = db.get(Alert, alert_id)
    if alert is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="Peringatan tidak ditemukan")
    return AlertOut.of(alert, with_events=True)


@router.post("/{alert_id}/{action}", response_model=AlertOut)
def act(
    alert_id: int,
    action: str,
    body: ActionIn,
    request: Request,
    db: Session = Depends(get_db),
    user: User = Depends(require_roles(Role.ANALIS, Role.PIMPINAN)),
) -> AlertOut:
    alert = db.get(Alert, alert_id)
    if alert is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="Peringatan tidak ditemukan")
    now = utcnow()
    if action == "catatan":
        if not body.note.strip():
            raise HTTPException(422, detail="Catatan wajib diisi")
        alert.events.append(AlertEvent(at=now, actor_id=user.id, action="catatan", level=alert.level, note=body.note))
    elif action in service.TRANSITIONS:
        allowed_from, to_status = service.TRANSITIONS[action]
        if alert.status not in allowed_from:
            raise HTTPException(status.HTTP_409_CONFLICT,
                                detail=f"Aksi '{action}' tidak dapat dilakukan pada status '{alert.status}'")
        if action == "selesai" and not body.note.strip():
            raise HTTPException(422, detail="Catatan penyelesaian wajib diisi")
        alert.status = to_status
        if action in ("ack", "tangani") and alert.owner_id is None:
            alert.owner_id = user.id
        if to_status == "resolved":
            alert.resolved_at = now
        alert.events.append(AlertEvent(at=now, actor_id=user.id, action=action, level=alert.level, note=body.note))
    else:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="Aksi tidak dikenal")
    alert.updated_at = now
    audit.record(db, actor=user, action=f"peringatan.{action}", object_type="alert", object_id=alert.id,
                 ip=client_ip(request), detail={"status": alert.status, "note": body.note[:200]})
    db.commit()
    return AlertOut.of(alert, with_events=True)
