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
    static_dir: Path | None = None
    trusted_proxies: frozenset[str] = frozenset()
    proxy_only: bool = False
    alerts: AlertThresholds = field(default_factory=AlertThresholds)

    @property
    def blob_dir(self) -> Path:
        return self.data_dir / "blobs"

    @property
    def is_sqlite(self) -> bool:
        return self.database_url.startswith("sqlite")


def _auto_secrets(data_dir: Path) -> dict[str, str]:
    """Buat/baca kunci rahasia di DATA_DIR/secrets.json (izin 0600) — untuk hosting tanpa terminal.

    Kunci tidak pernah perlu diketik atau dikirim lewat kanal lain; aman untuk banyak proses (flock).
    """
    import fcntl
    import json
    import secrets as _secrets

    data_dir.mkdir(parents=True, exist_ok=True)
    path = data_dir / "secrets.json"
    with open(data_dir / ".secrets.lock", "w") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        if path.exists():
            return json.loads(path.read_text())
        values = {"secret_key": _secrets.token_urlsafe(48), "signing_key": _secrets.token_urlsafe(48)}
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "w") as fh:
            json.dump(values, fh)
        return values


@lru_cache
def get_settings() -> Settings:
    env = os.getenv("SISTRA_ENV", "development")
    data_dir = Path(os.getenv("SISTRA_DATA_DIR", "./data")).resolve()
    if os.getenv("SISTRA_AUTO_SECRETS") == "1" and not os.getenv("SISTRA_SECRET_KEY"):
        generated = _auto_secrets(data_dir)
        os.environ.setdefault("SISTRA_SECRET_KEY", generated["secret_key"])
        os.environ.setdefault("SISTRA_SIGNING_KEY", generated["signing_key"])
    secret = os.getenv("SISTRA_SECRET_KEY", _DEV_SECRET)
    signing = os.getenv("SISTRA_SIGNING_KEY", secret + "-signing")
    if secret == _DEV_SECRET:
        if env == "production":
            raise RuntimeError("SISTRA_SECRET_KEY wajib diisi pada lingkungan production")
        log.warning("Menggunakan SISTRA_SECRET_KEY bawaan pengembangan — JANGAN dipakai di produksi")

    return Settings(
        env=env,
        database_url=os.getenv("SISTRA_DATABASE_URL", f"sqlite:///{data_dir / 'sistra.db'}"),
        secret_key=secret,
        signing_key=signing,
        data_dir=data_dir,
        token_ttl_minutes=int(os.getenv("SISTRA_TOKEN_TTL_MINUTES", "480")),
        max_upload_mb=int(os.getenv("SISTRA_MAX_UPLOAD_MB", "50")),
        cors_origins=[o.strip() for o in os.getenv("SISTRA_CORS_ORIGINS", "http://localhost:5173").split(",") if o.strip()],
        static_dir=Path(os.environ["SISTRA_STATIC_DIR"]).resolve() if os.getenv("SISTRA_STATIC_DIR") else None,
        trusted_proxies=frozenset(ip.strip() for ip in os.getenv("SISTRA_TRUSTED_PROXIES", "").split(",") if ip.strip()),
        proxy_only=os.getenv("SISTRA_PROXY_ONLY") == "1",
    )
