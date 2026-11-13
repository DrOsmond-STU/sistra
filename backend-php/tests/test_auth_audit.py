from __future__ import annotations

import pytest
from sqlalchemy import text
from sqlalchemy.exc import DatabaseError


from conftest import SessionLocal, audit, engine


def _disable_worm(conn) -> None:
    """Simulasi penyerang berhak DBA yang mematikan pemicu append-only."""
    if engine.dialect.name == "postgresql":
        conn.execute(text("ALTER TABLE audit_events DISABLE TRIGGER audit_events_append_only"))
    else:
        conn.execute(text("DROP TRIGGER audit_events_no_update"))
        conn.execute(text("DROP TRIGGER audit_events_no_delete"))


def test_login_success_and_me(db_base, client, login):
    r = client.get("/api/auth/me", headers=login("analis"))
    assert r.status_code == 200
    body = r.json()
    assert body["username"] == "analis"
    assert body["clearance"] == "RAHASIA"
    assert body["unit"]["code"] == "DIT-ANL"


def test_login_wrong_password_is_rejected_and_audited(db_base, client, login):
    r = client.post("/api/auth/login", json={"username": "analis", "password": "salah-sandi"})
    assert r.status_code == 401
    events = client.get("/api/audit?action=auth.login_gagal", headers=login("auditor")).json()
    assert any(e["object_id"] == "analis" for e in events)


def test_requires_token(db_base, client):
    assert client.get("/api/dashboard/summary").status_code == 401
    assert client.get("/api/dashboard/summary", headers={"Authorization": "Bearer palsu"}).status_code == 401


def test_admin_only_user_management(db_base, client, login):
    assert client.get("/api/users", headers=login("analis")).status_code == 403
    r = client.post("/api/users", headers=login("admin"), json={
        "username": "baru", "full_name": "Pengguna Baru", "unit_code": "DIT-ANL",
        "roles": ["analis"], "clearance": "terbatas", "password": "sandi-panjang-1",
    })
    assert r.status_code == 201, r.text
    assert r.json()["clearance"] == "TERBATAS"
    r = client.post("/api/users", headers=login("admin"), json={
        "username": "x2", "full_name": "X", "unit_code": "DIT-ANL", "roles": ["superuser"], "password": "sandi-panjang-1",
    })
    assert r.status_code == 422


def test_admin_cannot_deactivate_self(db_base, client, login):
    me = client.get("/api/auth/me", headers=login("admin")).json()
    r = client.patch(f"/api/users/{me['id']}", headers=login("admin"), json={"active": False})
    assert r.status_code == 400


def test_audit_only_for_auditor(db_base, client, login):
    assert client.get("/api/audit", headers=login("admin")).status_code == 403
    assert client.get("/api/audit", headers=login("auditor")).status_code == 200


def test_audit_chain_verifies(db_base, client, login):
    login("analis")
    login("redaktur")
    r = client.get("/api/audit/verify", headers=login("auditor"))
    assert r.status_code == 200
    assert r.json()["ok"] is True
    assert r.json()["checked"] >= 3


def test_audit_table_rejects_update_and_delete(db_base, client, login):
    login("analis")
    with engine.begin() as conn:
        with pytest.raises(DatabaseError):
            conn.execute(text("UPDATE audit_events SET action='palsu'"))
    with engine.begin() as conn:
        with pytest.raises(DatabaseError):
            conn.execute(text("DELETE FROM audit_events"))


def test_tampering_is_detected_by_hash_chain(db_base, client, login):
    login("analis")
    login("redaktur")
    login("operator")
    # Simulasi penyerang dengan hak DBA: matikan pemicu lalu ubah satu baris di tengah
    with engine.begin() as conn:
        _disable_worm(conn)
        conn.execute(text("UPDATE audit_events SET actor_username='penyusup' WHERE seq=2"))
    with SessionLocal() as db:
        result = audit.verify_chain(db)
    assert result.ok is False
    assert result.first_broken_seq == 2


def test_deleted_row_is_detected(db_base, client, login):
    for u in ("analis", "redaktur", "operator"):
        login(u)
    with engine.begin() as conn:
        _disable_worm(conn)
        conn.execute(text("DELETE FROM audit_events WHERE seq=2"))
    with SessionLocal() as db:
        result = audit.verify_chain(db)
    assert result.ok is False
    assert result.first_broken_seq == 3
