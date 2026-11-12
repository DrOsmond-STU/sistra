from __future__ import annotations

from datetime import datetime

from sqlalchemy import JSON, ForeignKey, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.db import Base, UTCDateTime
from app.core.timeutil import utcnow

LEVELS = ["hijau", "kuning", "oranye", "merah"]
LEVEL_RANK = {lvl: i for i, lvl in enumerate(LEVELS)}
OPEN_STATUSES = ("open", "ack", "in_progress")


class Alert(Base):
    __tablename__ = "alerts"

    id: Mapped[int] = mapped_column(primary_key=True)
    issue_id: Mapped[int] = mapped_column(ForeignKey("issues.id"), index=True)
    level: Mapped[str] = mapped_column(String(8))
    status: Mapped[str] = mapped_column(String(16), default="open", index=True)  # open|ack|in_progress|resolved
    reason: Mapped[str] = mapped_column(Text, default="")
    metrics: Mapped[dict] = mapped_column(JSON, default=dict)
    owner_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"))
    triggered_at: Mapped[datetime] = mapped_column(UTCDateTime, default=utcnow)
    updated_at: Mapped[datetime] = mapped_column(UTCDateTime, default=utcnow)
    resolved_at: Mapped[datetime | None] = mapped_column(UTCDateTime)

    issue = relationship("Issue", lazy="joined")
    events: Mapped[list["AlertEvent"]] = relationship(order_by="AlertEvent.id", cascade="all, delete-orphan")


class AlertEvent(Base):
    __tablename__ = "alert_events"

    id: Mapped[int] = mapped_column(primary_key=True)
    alert_id: Mapped[int] = mapped_column(ForeignKey("alerts.id"), index=True)
    at: Mapped[datetime] = mapped_column(UTCDateTime, default=utcnow)
    actor_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"))
    action: Mapped[str] = mapped_column(String(32))  # dibuat|eskalasi|ack|tangani|selesai|catatan
    level: Mapped[str] = mapped_column(String(8))
    note: Mapped[str] = mapped_column(Text, default="")
