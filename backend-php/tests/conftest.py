"""Uji kontrak HTTP backend PHP memakai suite uji backend Python (disalin) terhadap server `php -S`.

Jalankan: SISTRA_PHP_URL=http://127.0.0.1:8099 SISTRA_DATA_DIR=... pytest backend-php/tests
"""
from __future__ import annotations

import json
import os
import subprocess
from contextlib import contextmanager
from pathlib import Path
from types import SimpleNamespace

import httpx
import pytest
from sqlalchemy import create_engine
from sqlalchemy.pool import NullPool

PASSWORD = "uji-sandi-123"
BASE = os.environ.get("SISTRA_PHP_URL", "http://127.0.0.1:8099")
DATA = Path(os.environ["SISTRA_DATA_DIR"])
CLI = Path(__file__).resolve().parents[1] / "bin" / "sistra-cli.php"

engine = create_engine(f"sqlite:///{DATA}/sistra.db", poolclass=NullPool)


def _cli(*args: str) -> str:
    return subprocess.run(["php", str(CLI), *args], check=True, capture_output=True, text=True,
                          env={**os.environ, "SISTRA_DATA_DIR": str(DATA)}).stdout


@contextmanager
def SessionLocal():  # noqa: N802 — meniru antarmuka backend Python
    yield None


audit = SimpleNamespace(verify_chain=lambda _db: SimpleNamespace(**json.loads(_cli("verify"))))


@pytest.fixture()
def db_base():
    _cli("reset", f"--password={PASSWORD}")
    yield


@pytest.fixture()
def db_demo():
    _cli("reset", "--demo", f"--password={PASSWORD}")
    yield


@pytest.fixture()
def client():
    with httpx.Client(base_url=BASE, timeout=30) as c:
        yield c


@pytest.fixture()
def login(client):
    cache: dict[str, dict] = {}

    def _login(username: str) -> dict:
        if username not in cache:
            r = client.post("/api/auth/login", json={"username": username, "password": PASSWORD})
            assert r.status_code == 200, r.text
            cache[username] = {"Authorization": f"Bearer {r.json()['access_token']}"}
        return cache[username]

    return _login
