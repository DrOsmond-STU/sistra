from __future__ import annotations

from datetime import datetime

from sqlalchemy import Boolean, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.db import Base, UTCDateTime
from app.core.timeutil import utcnow

STATUSES = ["draf", "ditinjau", "disetujui", "tayang", "ditarik"]


class Channel(Base):
    """Kanal distribusi: 1 nasional + 38 provinsi, serta akun media sosial resmi."""

    __tablename__ = "channels"

    id: Mapped[int] = mapped_column(primary_key=True)
    code: Mapped[str] = mapped_column(String(32), unique=True)
    name: Mapped[str] = mapped_column(String(200))
    type: Mapped[str] = mapped_column(String(16))  # nasional | provinsi | medsos_resmi
    province_code: Mapped[str | None] = mapped_column(String(4))
    url: Mapped[str] = mapped_column(String(500), default="")
    active: Mapped[bool] = mapped_column(Boolean, default=True)


class Draft(Base):
    __tablename__ = "drafts"

    id: Mapped[int] = mapped_column(primary_key=True)
    kind: Mapped[str] = mapped_column(String(16))  # rilis | opini | editorial | briefing | infografis | video | audio
    title: Mapped[str] = mapped_column(String(300))
    body: Mapped[str] = mapped_column(Text)
    province_code: Mapped[str | None] = mapped_column(String(4))
    issue_id: Mapped[int | None] = mapped_column(ForeignKey("issues.id"))
    ai_generated: Mapped[bool] = mapped_column(Boolean, default=False)
    status: Mapped[str] = mapped_column(String(16), default="draf", index=True)
    version: Mapped[int] = mapped_column(Integer, default=1)
    content_hash: Mapped[str] = mapped_column(String(64))
    created_by: Mapped[int] = mapped_column(ForeignKey("users.id"))
    created_at: Mapped[datetime] = mapped_column(UTCDateTime, default=utcnow)
    updated_at: Mapped[datetime] = mapped_column(UTCDateTime, default=utcnow)
    approved_by: Mapped[int | None] = mapped_column(ForeignKey("users.id"))
    approved_at: Mapped[datetime | None] = mapped_column(UTCDateTime)
    approval_signature: Mapped[str | None] = mapped_column(String(128))

    steps: Mapped[list["ApprovalStep"]] = relationship(order_by="ApprovalStep.id", cascade="all, delete-orphan")
    revisions: Mapped[list["DraftRevision"]] = relationship(order_by="DraftRevision.id", cascade="all, delete-orphan")


class ApprovalStep(Base):
    """Riwayat transisi status — append-only, tercatat juga di audit WORM."""

    __tablename__ = "approval_steps"

    id: Mapped[int] = mapped_column(primary_key=True)
    draft_id: Mapped[int] = mapped_column(ForeignKey("drafts.id"), index=True)
    from_status: Mapped[str] = mapped_column(String(16))
    to_status: Mapped[str] = mapped_column(String(16))
    actor_id: Mapped[int] = mapped_column(ForeignKey("users.id"))
    actor_role: Mapped[str] = mapped_column(String(32))
    comment: Mapped[str] = mapped_column(Text, default="")
    version: Mapped[int] = mapped_column(Integer)
    content_hash: Mapped[str] = mapped_column(String(64))
    signature: Mapped[str | None] = mapped_column(String(128))
    at: Mapped[datetime] = mapped_column(UTCDateTime, default=utcnow)


class DraftRevision(Base):
    __tablename__ = "draft_revisions"

    id: Mapped[int] = mapped_column(primary_key=True)
    draft_id: Mapped[int] = mapped_column(ForeignKey("drafts.id"), index=True)
    version: Mapped[int] = mapped_column(Integer)
    title: Mapped[str] = mapped_column(String(300))
    body: Mapped[str] = mapped_column(Text)
    edited_by: Mapped[int] = mapped_column(ForeignKey("users.id"))
    at: Mapped[datetime] = mapped_column(UTCDateTime, default=utcnow)


class CalendarEntry(Base):
    """Kalender produksi & distribusi (S34)."""

    __tablename__ = "calendar_entries"

    id: Mapped[int] = mapped_column(primary_key=True)
    draft_id: Mapped[int] = mapped_column(ForeignKey("drafts.id"), index=True)
    channel_id: Mapped[int] = mapped_column(ForeignKey("channels.id"), index=True)
    planned_at: Mapped[datetime] = mapped_column(UTCDateTime, index=True)
    status: Mapped[str] = mapped_column(String(16), default="rencana")  # rencana | tayang | batal
    created_by: Mapped[int] = mapped_column(ForeignKey("users.id"))
    created_at: Mapped[datetime] = mapped_column(UTCDateTime, default=utcnow)

    channel: Mapped[Channel] = relationship(lazy="joined")
