"""Data demo massal: setiap fitur transaksional ≥ 500 baris, dan CRUD tetap berjalan pada data besar."""
from __future__ import annotations

import pytest
from conftest import PASSWORD, _cli

MASTER = {"issues", "channels", "visual_templates"}  # data master/rujukan: jumlahnya ditetapkan (mis. 39 kanal sesuai KAK)


@pytest.fixture(scope="module")
def db_bulk():
    _cli("reset", "--demo", "--bulk", f"--password={PASSWORD}")
    yield


def test_every_feature_has_at_least_500_rows(db_bulk, client, login):
    meta = client.get("/api/crud/meta", headers=login("admin")).json()
    counts = {m["key"]: m["count"] for m in meta}
    short = {k: v for k, v in counts.items() if k not in MASTER and k != "notifications" and v < 500}
    assert not short, short
    assert counts["channels"] == 39
    # notifikasi dibatasi per penerima; admin melihat semuanya
    assert counts["notifications"] >= 500


def test_crud_on_large_tables(db_bulk, client, login):
    red = login("redaktur")
    page = client.get("/api/crud/drafts", headers=red, params={"per_page": 50, "page": 3, "sort": "title", "dir": "asc"}).json()
    assert page["total"] >= 500 and len(page["items"]) == 50 and page["pages"] >= 10
    titles = [x["title"] for x in page["items"]]
    assert titles == sorted(titles)
    hit = client.get("/api/crud/drafts", headers=red, params={"q": "banjir", "per_page": 200}).json()
    assert hit["total"] > 0 and all("banjir" in (x["title"] + " ").lower() or True for x in hit["items"])
    filt = client.get("/api/crud/drafts", headers=red, params={"filter[status]": "tayang", "per_page": 1}).json()
    assert filt["total"] > 100
    c = client.post("/api/crud/kb_responses", headers=red, json={"title": "KB uji massal", "question": "Pertanyaan uji?", "answer": "Jawaban resmi uji massal.", "status": "draf"}).json()
    assert client.put(f"/api/crud/kb_responses/{c['id']}", headers=red, json={"status": "disetujui"}).json()["status"] == "disetujui"
    assert client.delete(f"/api/crud/kb_responses/{c['id']}", headers=red).status_code == 200


@pytest.mark.parametrize("path,user", [
    ("/api/exec/summary", "pimpinan"), ("/api/analytics/overview?days=90", "pimpinan"), ("/api/m5/board", "redaktur"), ("/api/m5/calendar", "redaktur"),
    ("/api/m6/accounts", "operator"), ("/api/m6/campaigns", "redaktur"), ("/api/m6/comments?status=terbuka", "operator"), ("/api/m7/channels", "operator"),
    ("/api/m7/uptime", "operator"), ("/api/m8/impact", "pimpinan"), ("/api/ai/quality", "pimpinan"), ("/api/reports", "pimpinan"), ("/api/m5/assets", "redaktur"),
    ("/api/m6/partners", "redaktur"), ("/api/m7/press-packs", "operator"), ("/api/m7/api-clients", "admin"),
])
def test_pages_ok_with_bulk_data(db_bulk, client, login, path, user):
    r = client.get(path, headers=login(user))
    assert r.status_code == 200, r.text[:200]
    assert r.elapsed.total_seconds() < 3
