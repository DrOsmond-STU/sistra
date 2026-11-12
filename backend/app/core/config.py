"""Konfigurasi aplikasi dari variabel lingkungan (prefix SISTRA_)."""

from __future__ import annotations

import logging
import os
from dataclasses import dataclass, field
from functools import lru_cache
from pathlib import Path

log = logging.getLogger("sistra.config")

_DEV_SECRET = "dev-only-insecure-secret-change-me"


@dataclass(frozen=True)
class AlertThresholds:
    """Ambang peringatan berjenjang (S21). Volume = jumlah konten dalam jendela 24 jam."""

    red_volume: int = 20
    red_growth: float = 3.0
    red_negative: float = 0.6
    orange_volume: int = 10
    orange_growth: float = 2.0
    orange_negative: float = 0.6
    yellow_volume: int = 5
    yellow_growth: float = 1.5
    yellow_negative: float = 0.5


@dataclass(frozen=True)
class Settings:
    env: str
    database_url: str
    secret_key: str
    signing_key: str
    data_dir: Path
    token_ttl_minutes: int
    max_upload_mb: int
    cors_origins: list[str]
    alerts: AlertThresholds = field(default_factory=AlertThresholds)

    @property
    def blob_dir(self) -> Path:
        return self.data_dir / "blobs"

    @property
    def is_sqlite(self) -> bool:
        return self.database_url.startswith("sqlite")


@lru_cache
def get_settings() -> Settings:
    env = os.getenv("SISTRA_ENV", "development")
    secret = os.getenv("SISTRA_SECRET_KEY", _DEV_SECRET)
    signing = os.getenv("SISTRA_SIGNING_KEY", secret + "-signing")
    if secret == _DEV_SECRET:
        if env == "production":
            raise RuntimeError("SISTRA_SECRET_KEY wajib diisi pada lingkungan production")
        log.warning("Menggunakan SISTRA_SECRET_KEY bawaan pengembangan — JANGAN dipakai di produksi")

    data_dir = Path(os.getenv("SISTRA_DATA_DIR", "./data")).resolve()
    return Settings(
        env=env,
        database_url=os.getenv("SISTRA_DATABASE_URL", f"sqlite:///{data_dir / 'sistra.db'}"),
        secret_key=secret,
        signing_key=signing,
        data_dir=data_dir,
        token_ttl_minutes=int(os.getenv("SISTRA_TOKEN_TTL_MINUTES", "480")),
        max_upload_mb=int(os.getenv("SISTRA_MAX_UPLOAD_MB", "50")),
        cors_origins=[o.strip() for o in os.getenv("SISTRA_CORS_ORIGINS", "http://localhost:5173").split(",") if o.strip()],
    )
