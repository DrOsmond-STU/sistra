from __future__ import annotations

from datetime import timedelta

from sqlalchemy import text

from conftest import engine
from app.core.timeutil import utcnow

DRAFT = {
    "kind": "rilis",
    "title": "Klarifikasi Resmi Harga Beras",
    "body": "Pemerintah memastikan stok beras aman dan operasi pasar diperluas di seluruh provinsi.",
    "issue_code": "EKO-PANGAN",
}


def _create(client, headers, **kw) -> dict:
    r = client.post("/api/drafts", headers=headers, json={**DRAFT, **kw})
    assert r.status_code == 201, r.text
    return r.json()


def _go(client, headers, did, to, comment=""):
    return client.post(f"/api/drafts/{did}/transition", headers=headers, json={"to_status": to, "comment": comment})


def test_full_approval_flow(db_base, client, login):
    d = _create(client, login("redaktur"))
    assert d["status"] == "draf" and d["allowed_actions"] == ["ditinjau"]
    assert _go(client, login("redaktur"), d["id"], "ditinjau").json()["status"] == "ditinjau"
    r = _go(client, login("pimpinan"), d["id"], "disetujui", "Setuju")
    assert r.status_code == 200
    body = r.json()
    assert body["status"] == "disetujui" and body["approved_by"] is not None
    assert body["steps"][-1]["signature"]
    r = _go(client, login("operator"), d["id"], "tayang")
    assert r.status_code == 200 and r.json()["status"] == "tayang"
    r = _go(client, login("redaktur"), d["id"], "ditarik", "Koreksi data")
    assert r.json()["status"] == "ditarik"
    assert [s["to_status"] for s in r.json()["steps"]] == ["ditinjau", "disetujui", "tayang", "ditarik"]


def test_cannot_skip_approval(db_base, client, login):
    d = _create(client, login("redaktur"))
    assert _go(client, login("operator"), d["id"], "tayang").status_code == 409
    _go(client, login("redaktur"), d["id"], "ditinjau")
    assert _go(client, login("operator"), d["id"], "tayang").status_code == 409
    assert _go(client, login("redaktur"), d["id"], "disetujui").status_code == 403  # bukan pimpinan


def test_only_author_submits(db_base, client, login):
    d = _create(client, login("redaktur"))
    assert _go(client, login("produser"), d["id"], "ditinjau").status_code == 403


def test_author_cannot_approve_own_draft(db_base, client, login):
    # Pengguna dengan peran ganda redaktur+pimpinan
    r = client.post("/api/users", headers=login("admin"), json={
        "username": "ganda", "full_name": "Peran Ganda", "unit_code": "DIT-PRD", "roles": ["redaktur", "pimpinan"],
        "clearance": "TERBATAS", "password": "uji-sandi-123",
    })
    assert r.status_code == 201
    h = login("ganda")
    d = _create(client, h)
    _go(client, h, d["id"], "ditinjau")
    r = _go(client, h, d["id"], "disetujui")
    assert r.status_code == 403
    assert "sendiri" in r.json()["detail"]


def test_return_requires_comment_and_clears_approval(db_base, client, login):
    d = _create(client, login("produser"))
    _go(client, login("produser"), d["id"], "ditinjau")
    assert _go(client, login("redaktur"), d["id"], "draf").status_code == 422
    r = _go(client, login("redaktur"), d["id"], "draf", "Perbaiki data pendukung")
    assert r.json()["status"] == "draf"


def test_edit_only_in_draft_and_versioned(db_base, client, login):
    h = login("redaktur")
    d = _create(client, h)
    r = client.put(f"/api/drafts/{d['id']}", headers=h, json={"title": DRAFT["title"] + " (rev)", "body": DRAFT["body"]})
    assert r.json()["version"] == 2 and len(r.json()["revisions"]) == 2
    _go(client, h, d["id"], "ditinjau")
    r = client.put(f"/api/drafts/{d['id']}", headers=h, json={"title": "Ubah saat ditinjau", "body": DRAFT["body"]})
    assert r.status_code == 409


def test_publish_rejected_if_content_tampered_after_approval(db_base, client, login):
    d = _create(client, login("redaktur"))
    _go(client, login("redaktur"), d["id"], "ditinjau")
    _go(client, login("pimpinan"), d["id"], "disetujui")
    with engine.begin() as conn:
        conn.execute(text("UPDATE drafts SET body = body || ' [disisipkan]' WHERE id = :i"), {"i": d["id"]})
    r = _go(client, login("operator"), d["id"], "tayang")
    assert r.status_code == 409
    assert "tanda tangan" in r.json()["detail"]


def test_ai_generated_label(db_base, client, login):
    d = _create(client, login("produser"), ai_generated=True)
    assert d["ai_label"] and "SISTRA AGENT" in d["ai_label"] and "kecerdasan buatan" in d["ai_label"]


def test_schedule_and_conflict_detection(db_base, client, login):
    h = login("redaktur")
    at = (utcnow() + timedelta(days=1)).isoformat()
    d1 = _create(client, h)
    d2 = _create(client, h, title="Naskah kedua untuk jadwal")
    r = client.post(f"/api/drafts/{d1['id']}/schedule", headers=h, json={"channel_codes": ["NAS", "PROV-35"], "planned_at": at})
    assert r.status_code == 201 and r.json()["conflicts"] == []
    r = client.post(f"/api/drafts/{d2['id']}/schedule", headers=h, json={"channel_codes": ["NAS"], "planned_at": at})
    assert len(r.json()["conflicts"]) == 1
    cal = client.get("/api/calendar", headers=h).json()
    assert len(cal) == 3
    assert client.post(f"/api/drafts/{d1['id']}/schedule", headers=h,
                       json={"channel_codes": ["TIDAK-ADA"], "planned_at": at}).status_code == 422
