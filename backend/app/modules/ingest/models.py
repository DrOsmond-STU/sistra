from __future__ import annotations

from datetime import datetime

from sqlalchemy import JSON, BigInteger, Boolean, Float, ForeignKey, Index, String, Text, UniqueConstraint
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.db import Base, UTCDateTime
from app.core.timeutil import utcnow


class Source(Base):
    __tablename__ = "sources"

    id: Mapped[int] = mapped_column(primary_key=True)
    type: Mapped[str] = mapped_column(String(16))  # news | social | tv | radio | print | report
    name: Mapped[str] = mapped_column(String(200))
    url: Mapped[str] = mapped_column(String(500), default="")
    platform: Mapped[str] = mapped_column(String(64), default="")
    province_code: Mapped[str | None] = mapped_column(String(4))
    reliability: Mapped[str] = mapped_column(String(1), default="C")  # A..F
    access_basis: Mapped[str] = mapped_column(String(300), default="Sumber terbuka/publik")
    active: Mapped[bool] = mapped_column(Boolean, default=True)
    last_fetched_at: Mapped[datetime | None] = mapped_column(UTCDateTime)
    created_at: Mapped[datetime] = mapped_column(UTCDateTime, default=utcnow)


class ContentItem(Base):
    __tablename__ = "content_items"
    __table_args__ = (
        UniqueConstraint("source_id", "external_id", name="uq_content_source_external"),
        Index("ix_content_published", "published_at"),
    )

    id: Mapped[int] = mapped_column(primary_key=True)
    source_id: Mapped[int] = mapped_column(ForeignKey("sources.id"), index=True)
    external_id: Mapped[str] = mapped_column(String(300))
    url: Mapped[str] = mapped_column(String(1000), default="")
    title: Mapped[str] = mapped_column(String(500))
    body: Mapped[str] = mapped_column(Text, default="")
    lang: Mapped[str] = mapped_column(String(8), default="id")
    author: Mapped[str] = mapped_column(String(200), default="")
    published_at: Mapped[datetime] = mapped_column(UTCDateTime)
    ingested_at: Mapped[datetime] = mapped_column(UTCDateTime, default=utcnow)
    content_hash: Mapped[str] = mapped_column(String(64), unique=True)
    simhash: Mapped[int] = mapped_column(BigInteger)
    duplicate_of: Mapped[int | None] = mapped_column(ForeignKey("content_items.id"))
    sentiment_label: Mapped[str] = mapped_column(String(8), default="netral", index=True)
    sentiment_score: Mapped[float] = mapped_column(Float, default=0.0)
    province_code: Mapped[str | None] = mapped_column(String(4), index=True)
    provinces: Mapped[list[str]] = mapped_column(JSON, default=list)

    source: Mapped[Source] = relationship(lazy="joined")
