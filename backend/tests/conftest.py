from __future__ import annotations

import os
import tempfile

_TMP = tempfile.mkdtemp(prefix="sistra-test-")
os.environ.setdefault("SISTRA_DATA_DIR", _TMP)
os.environ.setdefault("SISTRA_DATABASE_URL", f"sqlite:///{_TMP}/test.db")
os.environ.setdefault("SISTRA_SECRET_KEY", "test-secret-key-for-pytest-only-000000")

import pytest  # noqa: E402
from fastapi.testclient import TestClient  # noqa: E402

from app.core.db import SessionLocal, reset_db  # noqa: E402
from app.main import app  # noqa: E402
from app.seed import seed_base, seed_demo  # noqa: E402

PASSWORD = "uji-sandi-123"


@pytest.fixture()
def db_base():
    reset_db()
    with SessionLocal() as db:
        seed_base(db, PASSWORD)
        db.commit()
    yield


@pytest.fixture()
def db_demo():
    reset_db()
    with SessionLocal() as db:
        users = seed_base(db, PASSWORD)
        seed_demo(db, users)
        db.commit()
    yield


@pytest.fixture()
def client():
    with TestClient(app) as c:
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
