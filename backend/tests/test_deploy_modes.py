from __future__ import annotations

import os
import stat

from fastapi.testclient import TestClient

from app.core.config import get_settings


def test_single_app_mode_serves_spa_and_blocks_traversal(tmp_path, monkeypatch, db_base):
    dist = tmp_path / "dist"
    (dist / "assets").mkdir(parents=True)
    (dist / "index.html").write_text("<!doctype html><title>SISTRA</title>")
    (dist / "assets" / "app.js").write_text("console.log(1)")
    (tmp_path / "rahasia.txt").write_text("jangan bocor")
    monkeypatch.setenv("SISTRA_STATIC_DIR", str(dist))
    get_settings.cache_clear()
    try:
        from app.main import create_app

        with TestClient(create_app()) as c:
            assert "SISTRA" in c.get("/").text
            assert "SISTRA" in c.get("/repositori").text          # fallback SPA
            assert c.get("/assets/app.js").status_code == 200
            assert c.get("/api/health").json()["status"] == "ok"
            assert c.get("/api/tidak-ada").status_code == 404
            r = c.get("/..%2Frahasia.txt")
            assert "jangan bocor" not in r.text
            assert "Content-Security-Policy" in c.get("/").headers
    finally:
        monkeypatch.delenv("SISTRA_STATIC_DIR")
        get_settings.cache_clear()


def test_random_passwords_written_with_private_permissions(tmp_path, monkeypatch):
    from app import seed

    out = tmp_path / "cred.txt"
    monkeypatch.setattr("sys.argv", ["seed", "--reset", "--no-demo", "--random-passwords", str(out)])
    seed.main()
    mode = stat.S_IMODE(os.stat(out).st_mode)
    assert mode == 0o600
    lines = [ln.split("\t") for ln in out.read_text().splitlines() if not ln.startswith("#")]
    creds = dict(lines)
    assert set(creds) == {u[0] for u in seed.USERS}
    assert len(set(creds.values())) == len(creds)

    with TestClient(__import__("app.main", fromlist=["app"]).app) as c:
        assert c.post("/api/auth/login", json={"username": "admin", "password": "sistra-demo-2027"}).status_code == 401
        assert c.post("/api/auth/login", json={"username": "admin", "password": creds["admin"]}).status_code == 200


def test_auto_seed_writes_credentials_once(tmp_path, monkeypatch):
    from app.core.db import reset_db
    from app.seed import auto_seed

    reset_db()
    get_settings.cache_clear()
    monkeypatch.setenv("SISTRA_DATA_DIR", str(tmp_path))
    monkeypatch.setenv("SISTRA_SEED_DEMO", "0")
    try:
        auto_seed()
        cred = tmp_path / "kredensial.txt"
        first = cred.read_text()
        assert stat.S_IMODE(os.stat(cred).st_mode) == 0o600
        auto_seed()  # kedua kali: tidak menimpa
        assert cred.read_text() == first
    finally:
        get_settings.cache_clear()


def test_trusted_proxy_and_proxy_only(monkeypatch, db_base):
    monkeypatch.setenv("SISTRA_TRUSTED_PROXIES", "testclient")
    get_settings.cache_clear()
    try:
        from app.main import create_app

        with TestClient(create_app()) as c:
            r = c.post("/api/auth/login", json={"username": "analis", "password": "uji-sandi-123"},
                       headers={"X-Forwarded-For": "203.0.113.7, 10.0.0.1"})
            assert r.status_code == 200
            tok = {"Authorization": f"Bearer {c.post('/api/auth/login', json={'username': 'auditor', 'password': 'uji-sandi-123'}).json()['access_token']}"}
            ev = c.get("/api/audit?action=auth.login", headers=tok).json()
            assert any(e["ip"] == "203.0.113.7" and e["actor_username"] == "analis" for e in ev)
        monkeypatch.setenv("SISTRA_TRUSTED_PROXIES", "198.51.100.1")
        monkeypatch.setenv("SISTRA_PROXY_ONLY", "1")
        get_settings.cache_clear()
        with TestClient(create_app()) as c:
            r = c.get("/api/health", headers={"X-Forwarded-For": "198.51.100.1"})
            assert r.status_code == 403  # peer langsung bukan proxy; XFF palsu tidak dipercaya
    finally:
        monkeypatch.delenv("SISTRA_PROXY_ONLY", raising=False)
        monkeypatch.delenv("SISTRA_TRUSTED_PROXIES", raising=False)
        get_settings.cache_clear()


def test_auto_secrets_persist(tmp_path, monkeypatch):
    from app.core.config import _auto_secrets

    a = _auto_secrets(tmp_path)
    b = _auto_secrets(tmp_path)
    assert a == b and len(a["secret_key"]) > 40
    assert stat.S_IMODE(os.stat(tmp_path / "secrets.json").st_mode) == 0o600
