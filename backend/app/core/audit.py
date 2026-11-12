"""Jejak audit WORM berantai hash (S15, S65).

Setiap peristiwa menyimpan `prev_hash` dan `hash = SHA256(prev_hash || json_kanonik(peristiwa))`.
Perubahan atau penghapusan baris mana pun memutus rantai dan terdeteksi oleh `verify_chain`.
Pada level basis data, pemicu (trigger) menolak UPDATE/DELETE pada tabel ini.
"""

from __future__ import annotations

import hashlib
import json
import threading
from dataclasses import dataclass
from datetime import datetime
from typing import Any

from sqlalchemy import JSON, BigInteger, Integer, String, select, text
from sqlalchemy.orm import Mapped, Session, mapped_column

from app.core.db import Base, UTCDateTime
from app.core.timeutil import iso_z, utcnow

GENESIS_HASH = "0" * 64
_lock = threading.Lock()


class AuditEvent(Base):
    __tablename__ = "audit_events"

    seq: Mapped[int] = mapped_column(BigInteger().with_variant(Integer, "sqlite"), primary_key=True, autoincrement=True)
    at: Mapped[datetime] = mapped_column(UTCDateTime)
    actor_id: Mapped[int | None] = mapped_column(Integer)
    actor_username: Mapped[str] = mapped_column(String(64))
    action: Mapped[str] = mapped_column(String(64), index=True)
    object_type: Mapped[str] = mapped_column(String(64))
    object_id: Mapped[str] = mapped_column(String(64))
    ip: Mapped[str] = mapped_column(String(64))
    detail: Mapped[dict[str, Any]] = mapped_column(JSON, default=dict)
    prev_hash: Mapped[str] = mapped_column(String(64))
    hash: Mapped[str] = mapped_column(String(64), unique=True)


def _canonical(ev: AuditEvent) -> str:
    body = {
        "at": iso_z(ev.at),
        "actor_id": ev.actor_id,
        "actor_username": ev.actor_username,
        "action": ev.action,
        "object_type": ev.object_type,
        "object_id": ev.object_id,
        "ip": ev.ip,
        "detail": ev.detail or {},
    }
    return json.dumps(body, sort_keys=True, separators=(",", ":"), ensure_ascii=False, default=str)


def compute_hash(prev_hash: str, ev: AuditEvent) -> str:
    return hashlib.sha256((prev_hash + _canonical(ev)).encode()).hexdigest()


def record(
    db: Session,
    *,
    actor: Any | None,
    action: str,
    object_type: str,
    object_id: Any = "-",
    detail: dict[str, Any] | None = None,
    ip: str = "-",
    actor_username: str | None = None,
) -> AuditEvent:
    """Tambahkan peristiwa audit dalam transaksi yang sama dengan aksi bisnis.

    Pemanggil bertanggung jawab melakukan commit.
    """
    with _lock:
        if db.bind is not None and db.bind.dialect.name == "postgresql":
            db.execute(text("SELECT pg_advisory_xact_lock(424242)"))
        last = db.execute(select(AuditEvent.hash).order_by(AuditEvent.seq.desc()).limit(1)).scalar()
        prev = last or GENESIS_HASH
        ev = AuditEvent(
            at=utcnow(),
            actor_id=getattr(actor, "id", None),
            actor_username=actor_username or getattr(actor, "username", "system"),
            action=action,
            object_type=object_type,
            object_id=str(object_id),
            ip=ip,
            detail=detail or {},
            prev_hash=prev,
        )
        ev.hash = compute_hash(prev, ev)
        db.add(ev)
        db.flush()
        return ev


@dataclass
class VerifyResult:
    ok: bool
    checked: int
    first_broken_seq: int | None = None
    reason: str | None = None
    head_hash: str | None = None


def verify_chain(db: Session, batch: int = 5000) -> VerifyResult:
    prev = GENESIS_HASH
    checked = 0
    last_seq = 0
    while True:
        rows = db.execute(
            select(AuditEvent).where(AuditEvent.seq > last_seq).order_by(AuditEvent.seq).limit(batch)
        ).scalars().all()
        if not rows:
            break
        for ev in rows:
            if ev.prev_hash != prev:
                return VerifyResult(False, checked, ev.seq, "prev_hash tidak cocok (baris hilang/disisipkan)")
            if compute_hash(prev, ev) != ev.hash:
                return VerifyResult(False, checked, ev.seq, "hash tidak cocok (isi peristiwa diubah)")
            prev = ev.hash
            checked += 1
            last_seq = ev.seq
    return VerifyResult(True, checked, head_hash=prev if checked else None)
