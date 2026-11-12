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
