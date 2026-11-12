from __future__ import annotations

from datetime import datetime

from sqlalchemy import JSON, Boolean, ForeignKey, String
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.classification import Level
from app.core.db import Base, UTCDateTime
from app.core.timeutil import utcnow


class Unit(Base):
    __tablename__ = "units"

    id: Mapped[int] = mapped_column(primary_key=True)
    code: Mapped[str] = mapped_column(String(32), unique=True)
    name: Mapped[str] = mapped_column(String(200))
    parent_id: Mapped[int | None] = mapped_column(ForeignKey("units.id"))


class User(Base):
    __tablename__ = "users"

    id: Mapped[int] = mapped_column(primary_key=True)
    username: Mapped[str] = mapped_column(String(64), unique=True)
    full_name: Mapped[str] = mapped_column(String(200))
    nip: Mapped[str] = mapped_column(String(32), default="")
    unit_id: Mapped[int] = mapped_column(ForeignKey("units.id"))
    roles: Mapped[list[str]] = mapped_column(JSON, default=list)
    clearance: Mapped[str] = mapped_column(String(20), default=Level.TERBUKA.name)
    password_hash: Mapped[str] = mapped_column(String(255))
    active: Mapped[bool] = mapped_column(Boolean, default=True)
    created_at: Mapped[datetime] = mapped_column(UTCDateTime, default=utcnow)

    unit: Mapped[Unit] = relationship(lazy="joined")

    @property
    def clearance_level(self) -> Level:
        return Level.parse(self.clearance)

    def has_role(self, *roles: str) -> bool:
        return any(r in (self.roles or []) for r in roles)
