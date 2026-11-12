from __future__ import annotations

from datetime import datetime, timezone


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


def as_utc(dt: datetime | None) -> datetime | None:
    """Normalisasi datetime ke UTC sadar-zona (SQLite mengembalikan naive)."""
    if dt is None:
        return None
    if dt.tzinfo is None:
        return dt.replace(tzinfo=timezone.utc)
    return dt.astimezone(timezone.utc)


def iso_z(dt: datetime) -> str:
    """Format stabil untuk hashing: 2026-01-01T00:00:00.000000Z."""
    return as_utc(dt).strftime("%Y-%m-%dT%H:%M:%S.%fZ")  # type: ignore[union-attr]
