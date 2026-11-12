from __future__ import annotations

from datetime import datetime

from sqlalchemy import JSON, Boolean, Float, ForeignKey, String, Text
from sqlalchemy.orm import Mapped, mapped_column

from app.core.db import Base, UTCDateTime
from app.core.timeutil import utcnow


class Issue(Base):
    """Taksonomi isu (S14)."""

    __tablename__ = "issues"

    id: Mapped[int] = mapped_column(primary_key=True)
    code: Mapped[str] = mapped_column(String(32), unique=True)
    name: Mapped[str] = mapped_column(String(200))
    taxonomy_path: Mapped[str] = mapped_column(String(200), default="")
    description: Mapped[str] = mapped_column(Text, default="")
    risk_level: Mapped[str] = mapped_column(String(16), default="sedang")  # rendah | sedang | tinggi
    keywords: Mapped[list[str]] = mapped_column(JSON, default=list)
    active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(UTCDateTime, default=utcnow)


class ContentIssue(Base):
    __tablename__ = "content_issues"

    content_id: Mapped[int] = mapped_column(ForeignKey("content_items.id", ondelete="CASCADE"), primary_key=True)
    issue_id: Mapped[int] = mapped_column(ForeignKey("issues.id"), primary_key=True, index=True)
    confidence: Mapped[float] = mapped_column(Float, default=1.0)
    assigned_by: Mapped[str] = mapped_column(String(16), default="model")  # model | analyst
    corrected_by: Mapped[int | None] = mapped_column(ForeignKey("users.id"))
    created_at: Mapped[datetime] = mapped_column(UTCDateTime, default=utcnow)
