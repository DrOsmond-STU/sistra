"""API Editorial & Persetujuan — Modul 5 (S34, S36) dan pratinjau HITL dasar (S33)."""

from __future__ import annotations

from datetime import datetime, timedelta

from fastapi import APIRouter, Depends, HTTPException, Query, Request, status
from pydantic import BaseModel, Field
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core import audit
from app.core.db import get_db
from app.core.security import client_ip, get_current_user, require_roles
from app.core.timeutil import as_utc, utcnow
from app.modules.analytics.geo import PROVINCE_NAMES
from app.modules.analytics.models import Issue
from app.modules.identity.models import User
from app.modules.workflow import service
from app.modules.workflow.models import STATUSES, ApprovalStep, CalendarEntry, Channel, Draft, DraftRevision

router = APIRouter(tags=["editorial"])

CONFLICT_WINDOW = timedelta(minutes=30)


class ChannelOut(BaseModel):
    id: int
    code: str
    name: str
    type: str
    province_code: str | None


class DraftIn(BaseModel):
    kind: str = Field(pattern="^(rilis|opini|editorial|briefing|infografis|video|audio)$")
    title: str = Field(min_length=5, max_length=300)
    body: str = Field(min_length=20)
    province_code: str | None = None
    issue_code: str | None = None
    ai_generated: bool = False


class DraftEdit(BaseModel):
    title: str = Field(min_length=5, max_length=300)
    body: str = Field(min_length=20)


class TransitionIn(BaseModel):
    to_status: str
    comment: str = Field(default="", max_length=2000)


class ScheduleIn(BaseModel):
    channel_codes: list[str] = Field(min_length=1)
    planned_at: datetime


class StepOut(BaseModel):
    from_status: str
    to_status: str
    actor_id: int
    actor_name: str
    actor_role: str
    comment: str
    version: int
    signature: str | None
    at: datetime


class RevisionOut(BaseModel):
    version: int
    title: str
    edited_by: int
    at: datetime


class CalendarOut(BaseModel):
    id: int
    draft_id: int
    draft_title: str
    draft_status: str
    channel_code: str
    channel_name: str
    planned_at: datetime
    status: str


class DraftOut(BaseModel):
    id: int
    kind: str
    title: str
    body: str
    province_code: str | None
    province_name: str | None
    issue_code: str | None
    ai_generated: bool
    ai_label: str | None
    status: str
    version: int
    content_hash: str
    created_by: int
    author_name: str
    created_at: datetime
    updated_at: datetime
    approved_by: int | None
    approved_at: datetime | None
    allowed_actions: list[str]


class DraftDetail(DraftOut):
    steps: list[StepOut]
    revisions: list[RevisionOut]
    schedule: list[CalendarOut]


class ScheduleResult(BaseModel):
    entries: list[CalendarOut]
    conflicts: list[str]


def _names(db: Session, ids: set[int]) -> dict[int, str]:
    if not ids:
        return {}
    return dict(db.execute(select(User.id, User.full_name).where(User.id.in_(ids))).all())


def _issue_code(db: Session, issue_id: int | None) -> str | None:
    return db.execute(select(Issue.code).where(Issue.id == issue_id)).scalar() if issue_id else None


def _calendar_out(e: CalendarEntry, d: Draft) -> CalendarOut:
    return CalendarOut(id=e.id, draft_id=d.id, draft_title=d.title, draft_status=d.status, channel_code=e.channel.code,
                       channel_name=e.channel.name, planned_at=e.planned_at, status=e.status)


def _draft_out(db: Session, d: Draft, user: User, detail: bool = False) -> DraftOut:
    ids = {d.created_by} | ({s.actor_id for s in d.steps} if detail else set())
    names = _names(db, ids)
    base = dict(
        id=d.id, kind=d.kind, title=d.title, body=d.body, province_code=d.province_code,
        province_name=PROVINCE_NAMES.get(d.province_code or ""), issue_code=_issue_code(db, d.issue_id),
        ai_generated=d.ai_generated, ai_label=service.AI_LABEL if d.ai_generated else None, status=d.status,
        version=d.version, content_hash=d.content_hash, created_by=d.created_by,
        author_name=names.get(d.created_by, "-"), created_at=d.created_at, updated_at=d.updated_at,
        approved_by=d.approved_by, approved_at=d.approved_at, allowed_actions=service.allowed_actions(d, user),
    )
    if not detail:
        return DraftOut(**base)
    entries = db.execute(select(CalendarEntry).where(CalendarEntry.draft_id == d.id)
                         .order_by(CalendarEntry.planned_at)).scalars().all()
    return DraftDetail(
        **base,
        steps=[StepOut(from_status=s.from_status, to_status=s.to_status, actor_id=s.actor_id,
                       actor_name=names.get(s.actor_id, "-"), actor_role=s.actor_role, comment=s.comment,
                       version=s.version, signature=s.signature, at=s.at) for s in d.steps],
        revisions=[RevisionOut(version=r.version, title=r.title, edited_by=r.edited_by, at=r.at) for r in d.revisions],
        schedule=[_calendar_out(e, d) for e in entries],
    )


def _get_draft(db: Session, draft_id: int) -> Draft:
    d = db.get(Draft, draft_id)
    if d is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="Naskah tidak ditemukan")
    return d


@router.get("/channels", response_model=list[ChannelOut])
def list_channels(db: Session = Depends(get_db), _: User = Depends(get_current_user)) -> list[ChannelOut]:
    rows = db.execute(select(Channel).where(Channel.active.is_(True)).order_by(Channel.code)).scalars().all()
    return [ChannelOut(id=c.id, code=c.code, name=c.name, type=c.type, province_code=c.province_code) for c in rows]


@router.get("/drafts", response_model=list[DraftOut])
def list_drafts(
    status_: str | None = Query(default=None, alias="status"),
    mine: bool = False,
    db: Session = Depends(get_db),
    user: User = Depends(get_current_user),
) -> list[DraftOut]:
    stmt = select(Draft)
    if status_:
        stmt = stmt.where(Draft.status == status_)
    if mine:
        stmt = stmt.where(Draft.created_by == user.id)
    drafts = db.execute(stmt.order_by(Draft.updated_at.desc()).limit(300)).scalars().all()
    return [_draft_out(db, d, user) for d in drafts]


@router.post("/drafts", response_model=DraftDetail, status_code=201)
def create_draft(body: DraftIn, request: Request, db: Session = Depends(get_db),
                 user: User = Depends(require_roles(*service.AUTHOR_ROLES))) -> DraftDetail:
    if body.province_code and body.province_code not in PROVINCE_NAMES:
        raise HTTPException(422, detail="Kode provinsi tidak dikenal")
    issue_id = None
    if body.issue_code:
        issue_id = db.execute(select(Issue.id).where(Issue.code == body.issue_code)).scalar()
        if issue_id is None:
            raise HTTPException(422, detail="Kode isu tidak dikenal")
    now = utcnow()
    d = Draft(kind=body.kind, title=body.title.strip(), body=body.body.strip(), province_code=body.province_code,
              issue_id=issue_id, ai_generated=body.ai_generated, status="draf", version=1, created_by=user.id,
              created_at=now, updated_at=now)
    d.content_hash = service.content_hash(d.title, d.body)
    d.revisions.append(DraftRevision(version=1, title=d.title, body=d.body, edited_by=user.id, at=now))
    db.add(d)
    db.flush()
    audit.record(db, actor=user, action="naskah.buat", object_type="draft", object_id=d.id, ip=client_ip(request),
                 detail={"kind": d.kind, "ai_generated": d.ai_generated, "hash": d.content_hash})
    db.commit()
    return _draft_out(db, d, user, detail=True)  # type: ignore[return-value]


@router.get("/drafts/{draft_id}", response_model=DraftDetail)
def get_draft(draft_id: int, db: Session = Depends(get_db), user: User = Depends(get_current_user)) -> DraftDetail:
    return _draft_out(db, _get_draft(db, draft_id), user, detail=True)  # type: ignore[return-value]


@router.put("/drafts/{draft_id}", response_model=DraftDetail)
def edit_draft(draft_id: int, body: DraftEdit, request: Request, db: Session = Depends(get_db),
               user: User = Depends(get_current_user)) -> DraftDetail:
    d = _get_draft(db, draft_id)
    if d.status != "draf":
        raise HTTPException(status.HTTP_409_CONFLICT, detail="Naskah hanya dapat disunting pada status draf")
    if d.created_by != user.id and not user.has_role("redaktur"):
        raise HTTPException(status.HTTP_403_FORBIDDEN, detail="Hanya penulis atau redaktur yang dapat menyunting")
    new_hash = service.content_hash(body.title.strip(), body.body.strip())
    if new_hash == d.content_hash:
        return _draft_out(db, d, user, detail=True)  # type: ignore[return-value]
    now = utcnow()
    d.title, d.body, d.content_hash = body.title.strip(), body.body.strip(), new_hash
    d.version += 1
    d.updated_at = now
    d.revisions.append(DraftRevision(version=d.version, title=d.title, body=d.body, edited_by=user.id, at=now))
    audit.record(db, actor=user, action="naskah.sunting", object_type="draft", object_id=d.id, ip=client_ip(request),
                 detail={"version": d.version, "hash": new_hash})
    db.commit()
    return _draft_out(db, d, user, detail=True)  # type: ignore[return-value]


@router.post("/drafts/{draft_id}/transition", response_model=DraftDetail)
def transition(draft_id: int, body: TransitionIn, request: Request, db: Session = Depends(get_db),
               user: User = Depends(get_current_user)) -> DraftDetail:
    d = _get_draft(db, draft_id)
    if body.to_status not in STATUSES:
        raise HTTPException(422, detail="Status tujuan tidak dikenal")
    try:
        rule = service.check_transition(d, body.to_status, user, body.comment)
    except service.TransitionError as exc:
        audit.record(db, actor=user, action="naskah.transisi_ditolak", object_type="draft", object_id=d.id,
                     ip=client_ip(request), detail={"from": d.status, "to": body.to_status, "reason": str(exc)})
        db.commit()
        raise HTTPException(exc.code, detail=str(exc))

    now = utcnow()
    signature = None
    if rule.sign:
        signature = service.approval_signature(d, user)
        d.approved_by, d.approved_at, d.approval_signature = user.id, now, signature
    if body.to_status == "draf":  # dikembalikan: hapus persetujuan lama bila ada
        d.approved_by = d.approved_at = d.approval_signature = None
    role = next(r for r in rule.roles if user.has_role(r))
    d.steps.append(ApprovalStep(from_status=d.status, to_status=body.to_status, actor_id=user.id, actor_role=role,
                                comment=body.comment.strip(), version=d.version, content_hash=d.content_hash,
                                signature=signature, at=now))
    prev = d.status
    d.status, d.updated_at = body.to_status, now

    entries = db.execute(select(CalendarEntry).where(CalendarEntry.draft_id == d.id,
                                                     CalendarEntry.status == "rencana")).scalars().all()
    for e in entries:
        if body.to_status == "tayang":
            e.status = "tayang"
        elif body.to_status == "ditarik":
            e.status = "batal"

    audit.record(db, actor=user, action="naskah.transisi", object_type="draft", object_id=d.id, ip=client_ip(request),
                 detail={"from": prev, "to": d.status, "version": d.version, "hash": d.content_hash,
                         "signed": signature is not None})
    db.commit()
    return _draft_out(db, d, user, detail=True)  # type: ignore[return-value]


@router.post("/drafts/{draft_id}/schedule", response_model=ScheduleResult, status_code=201)
def schedule(draft_id: int, body: ScheduleIn, request: Request, db: Session = Depends(get_db),
             user: User = Depends(require_roles("redaktur", "operator", "pimpinan"))) -> ScheduleResult:
    d = _get_draft(db, draft_id)
    if d.status in ("tayang", "ditarik"):
        raise HTTPException(status.HTTP_409_CONFLICT, detail=f"Naskah berstatus {d.status} tidak dapat dijadwalkan")
    planned = as_utc(body.planned_at)
    codes = sorted(set(body.channel_codes))
    channels = db.execute(select(Channel).where(Channel.code.in_(codes), Channel.active.is_(True))).scalars().all()
    unknown = set(codes) - {c.code for c in channels}
    if unknown:
        raise HTTPException(422, detail=f"Kanal tidak dikenal: {', '.join(sorted(unknown))}")
    conflicts: list[str] = []
    entries: list[CalendarEntry] = []
    for ch in channels:
        clash = db.execute(
            select(CalendarEntry, Draft.title).join(Draft, Draft.id == CalendarEntry.draft_id).where(
                CalendarEntry.channel_id == ch.id, CalendarEntry.status == "rencana",
                CalendarEntry.draft_id != d.id,
                CalendarEntry.planned_at > planned - CONFLICT_WINDOW,  # type: ignore[operator]
                CalendarEntry.planned_at < planned + CONFLICT_WINDOW,  # type: ignore[operator]
            )
        ).first()
        if clash:
            conflicts.append(f"{ch.name}: berdekatan dengan \"{clash[1]}\" ({as_utc(clash[0].planned_at):%d-%m %H:%M} UTC)")
        e = CalendarEntry(draft_id=d.id, channel_id=ch.id, planned_at=planned, status="rencana", created_by=user.id)
        e.channel = ch
        db.add(e)
        entries.append(e)
    db.flush()
    audit.record(db, actor=user, action="kalender.jadwalkan", object_type="draft", object_id=d.id,
                 ip=client_ip(request), detail={"channels": codes, "planned_at": planned.isoformat(),  # type: ignore[union-attr]
                                                 "conflicts": len(conflicts)})
    db.commit()
    return ScheduleResult(entries=[_calendar_out(e, d) for e in entries], conflicts=conflicts)


@router.delete("/calendar/{entry_id}", response_model=CalendarOut)
def cancel_entry(entry_id: int, request: Request, db: Session = Depends(get_db),
                 user: User = Depends(require_roles("redaktur", "operator", "pimpinan"))) -> CalendarOut:
    e = db.get(CalendarEntry, entry_id)
    if e is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, detail="Jadwal tidak ditemukan")
    if e.status != "rencana":
        raise HTTPException(status.HTTP_409_CONFLICT, detail="Hanya jadwal berstatus rencana yang dapat dibatalkan")
    e.status = "batal"
    audit.record(db, actor=user, action="kalender.batal", object_type="calendar", object_id=e.id, ip=client_ip(request))
    db.commit()
    return _calendar_out(e, _get_draft(db, e.draft_id))


@router.get("/calendar", response_model=list[CalendarOut])
def calendar(start: datetime | None = None, end: datetime | None = None, db: Session = Depends(get_db),
             _: User = Depends(get_current_user)) -> list[CalendarOut]:
    start = as_utc(start) or (utcnow() - timedelta(days=7))
    end = as_utc(end) or (start + timedelta(days=37))
    rows = db.execute(
        select(CalendarEntry, Draft).join(Draft, Draft.id == CalendarEntry.draft_id)
        .where(CalendarEntry.planned_at >= start, CalendarEntry.planned_at < end)
        .order_by(CalendarEntry.planned_at)
    ).all()
    return [_calendar_out(e, d) for e, d in rows]
