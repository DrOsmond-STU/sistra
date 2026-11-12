"""Koneksi basis data dan pembuatan skema."""

from __future__ import annotations

from collections.abc import Iterator
from datetime import datetime

from sqlalchemy import DateTime, create_engine, text
from sqlalchemy.engine import Engine
from sqlalchemy.orm import DeclarativeBase, Session, sessionmaker
from sqlalchemy.types import TypeDecorator

from app.core.config import get_settings
from app.core.timeutil import as_utc


class UTCDateTime(TypeDecorator):
    """Datetime yang selalu disimpan dan dikembalikan sebagai UTC sadar-zona."""

    impl = DateTime(timezone=True)
    cache_ok = True

    def process_bind_param(self, value: datetime | None, dialect) -> datetime | None:  # noqa: ANN001
        value = as_utc(value)
        if value is not None and dialect.name == "sqlite":
            return value.replace(tzinfo=None)
        return value

    def process_result_value(self, value: datetime | None, dialect) -> datetime | None:  # noqa: ANN001
        return as_utc(value)


class Base(DeclarativeBase):
    pass


def _make_engine() -> Engine:
    settings = get_settings()
    settings.data_dir.mkdir(parents=True, exist_ok=True)
    kwargs: dict = {"pool_pre_ping": True}
    if settings.is_sqlite:
        kwargs["connect_args"] = {"check_same_thread": False}
    return create_engine(settings.database_url, **kwargs)


engine = _make_engine()
SessionLocal = sessionmaker(bind=engine, expire_on_commit=False, autoflush=False)


def get_db() -> Iterator[Session]:
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()


def import_models() -> None:
    """Impor seluruh model agar terdaftar di metadata."""
    from app.core import audit  # noqa: F401
    from app.modules.alerts import models as _alerts  # noqa: F401
    from app.modules.analytics import models as _analytics  # noqa: F401
    from app.modules.identity import models as _identity  # noqa: F401
    from app.modules.ingest import models as _ingest  # noqa: F401
    from app.modules.repository import models as _repository  # noqa: F401
    from app.modules.workflow import models as _workflow  # noqa: F401


# Pemicu WORM: tabel audit hanya boleh INSERT (S65).
_WORM_TRIGGERS = {
    "sqlite": [
        "CREATE TRIGGER IF NOT EXISTS audit_events_no_update BEFORE UPDATE ON audit_events "
        "BEGIN SELECT RAISE(ABORT, 'audit_events bersifat append-only'); END;",
        "CREATE TRIGGER IF NOT EXISTS audit_events_no_delete BEFORE DELETE ON audit_events "
        "BEGIN SELECT RAISE(ABORT, 'audit_events bersifat append-only'); END;",
    ],
    "postgresql": [
        """
        CREATE OR REPLACE FUNCTION sistra_audit_append_only() RETURNS trigger AS $$
        BEGIN RAISE EXCEPTION 'audit_events bersifat append-only'; END;
        $$ LANGUAGE plpgsql;
        """,
        "DROP TRIGGER IF EXISTS audit_events_append_only ON audit_events;",
        "CREATE TRIGGER audit_events_append_only BEFORE UPDATE OR DELETE ON audit_events "
        "FOR EACH ROW EXECUTE FUNCTION sistra_audit_append_only();",
    ],
}


def init_db() -> None:
    import_models()
    Base.metadata.create_all(engine)
    statements = _WORM_TRIGGERS.get(engine.dialect.name, [])
    with engine.begin() as conn:
        for stmt in statements:
            conn.execute(text(stmt))


def reset_db() -> None:
    """Hapus dan buat ulang skema (hanya untuk uji/pengembangan)."""
    import_models()
    with engine.begin() as conn:
        if engine.dialect.name == "postgresql":
            conn.execute(text("DROP TRIGGER IF EXISTS audit_events_append_only ON audit_events"))
    Base.metadata.drop_all(engine)
    init_db()
