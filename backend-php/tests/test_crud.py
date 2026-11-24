"""Uji CRUD generik untuk SEMUA entitas: tambah → baca → cari → ubah → hapus, ditambah penjaga aturan, hak akses, validasi."""
from __future__ import annotations

import random
from datetime import datetime, timedelta, timezone

import pytest

# peran yang dipakai untuk menguji tiap entitas (harus memiliki hak tambah/ubah/hapus)
ACTOR = {
    "issues": "pimpinan", "briefs": "redaktur", "brief_variants": "redaktur", "drafts": "redaktur", "local_context": "redaktur",
    "media_outputs": "redaktur", "editorial_feedback": "redaktur", "draft_comments": "redaktur", "calendar_entries": "redaktur",
    "assets": "redaktur", "visual_templates": "redaktur", "coverage": "redaktur", "notifications": "admin", "social_accounts": "admin",
    "post_tasks": "operator", "social_comments": "operator", "kb_responses": "redaktur", "response_packs": "redaktur", "partners": "redaktur",
    "partner_collabs": "redaktur", "campaigns": "redaktur", "campaign_slots": "redaktur", "channels": "admin", "distributions": "operator",
    "media_partners": "operator", "press_packs": "operator", "api_clients": "admin", "reports": "pimpinan",
}


def _iso(hours: float) -> str:
    return (datetime.now(timezone.utc) + timedelta(hours=hours)).strftime("%Y-%m-%dT%H:%M:%SZ")


def _day(days: int) -> str:
    return (datetime.now(timezone.utc) + timedelta(days=days)).strftime("%Y-%m-%d")


def _value(client, h, ent: str, f: dict, n: int):
    t = f["type"]
    if t == "ref":
        opts = client.get(f"/api/crud/{ent}/options/{f['name']}", headers=h).json()
        assert opts, f"tidak ada pilihan referensi {ent}.{f['name']}"
        return random.choice(opts[:10])["id"]
    if t == "text":
        if f["name"] == "code":
            return f"UJI-{n}"
        base = f"Uji CRUD {f['label']} {n}"
        return base[: f.get("max", 200)] if len(base) >= f.get("min", 0) else base.ljust(f["min"], "x")
    if t == "textarea":
        return ("Isi uji coba CRUD untuk kolom " + f["label"] + f" nomor {n}. ").ljust(max(30, f.get("min", 0)), ".")[: f.get("max", 4000)]
    if t == "url":
        return f"https://contoh.invalid/uji/{n}"
    if t == "int":
        return max(f.get("min", 0), 1) if f.get("max", 10**9) >= 5 else f.get("min", 1)
    if t == "bool":
        return True
    if t == "enum":
        return f["options"][0]["value"]
    if t == "province":
        return "31"
    if t == "provinces":
        return ["NAS", "31"]
    if t == "date":
        return _day(1)
    if t == "datetime":
        return _iso(48 + n % 24)
    if t == "color":
        return "#123456"
    if t == "lines":
        return ["satu", "dua"]
    if t == "facts":
        return "Fakta uji 1,2 juta warga | Sumber uji | https://contoh.invalid/f | A"
    raise AssertionError(f"tipe tak dikenal {t}")


def _payload(client, h, meta: dict, n: int) -> dict:
    body = {}
    for f in meta["fields"]:
        if f.get("readonly") or f["type"] == "file":
            continue
        if f.get("required") or f["type"] in ("text", "textarea") and f["name"] in ("title", "name"):
            body[f["name"]] = _value(client, h, meta["key"], f, n)
    k = meta["key"]
    if k == "partners":
        body.update(contract_start=_day(-1), contract_end=_day(90))
    if k == "campaigns":
        body.update(start_date=_day(2), end_date=_day(20))
    if k == "campaign_slots":
        c = client.get(f"/api/crud/campaigns/{body['campaign_id']}", headers=h).json()
        body.update(date=c["start_date"], account_id=client.get("/api/crud/campaign_slots/options/account_id", headers=h).json()[0]["id"])
    if k == "distributions":
        body.update(scheduled_at=_iso(30), status="terjadwal")
    if k == "post_tasks":
        body.update(planned_at=_iso(24 * (30 + n % 20)))
    if k == "partner_collabs":
        body.update(posted_at=_iso(-1))
    if k == "visual_templates":
        body["code"] = f"UJI-{n}"
    if k == "reports":
        body = {"period": "harian"}
    if k == "drafts":
        body["format"] = "artikel"
    return body


def _create(client, h, meta, n):
    k = meta["key"]
    if k == "assets":
        png = b"\x89PNG\r\n\x1a\n" + bytes(64)
        return client.post("/api/crud/assets", headers=h, files={"file": ("uji.png", png, "image/png")}, data={"title": f"Aset uji {n}", "license": "milik_sendiri"})
    for attempt in range(12):  # referensi acak dapat bertabrakan (kuota, unik, kontrak) → coba kombinasi lain
        r = client.post(f"/api/crud/{k}", headers=h, json=_payload(client, h, meta, n * 100 + attempt))
        if r.status_code != 409 and not (r.status_code == 422 and k in ("partner_collabs", "campaign_slots")):
            return r
    return r


def _editable(meta):
    for name in ("title", "name", "text", "body", "content", "caption", "note", "excerpt", "question", "summary", "contact", "location"):
        f = next((x for x in meta["fields"] if x["name"] == name), None)
        if f and not f.get("readonly") and not f.get("immutable"):
            return f
    cands = [x for x in meta["fields"] if x["type"] in ("text", "textarea", "int") and not x.get("readonly") and not x.get("immutable")]
    return cands[0] if cands else next(x for x in meta["fields"] if x["type"] == "enum" and not x.get("readonly") and not x.get("immutable"))


def test_crud_meta_covers_all_entities(db_demo, client, login):
    meta = client.get("/api/crud/meta", headers=login("admin")).json()
    keys = {m["key"] for m in meta}
    assert set(ACTOR) <= keys, set(ACTOR) - keys
    for m in meta:
        assert m["fields"] and m["module"] in ("Master", "M4", "M5", "M6", "M7", "M8")


@pytest.mark.parametrize("entity", sorted(ACTOR))
def test_crud_full_cycle(db_demo, client, login, entity):
    h = login(ACTOR[entity])
    meta = next(m for m in client.get("/api/crud/meta", headers=h).json() if m["key"] == entity)
    # baca daftar + paginasi
    lst = client.get(f"/api/crud/{entity}", headers=h, params={"per_page": 5}).json()
    assert lst["per_page"] == 5 and len(lst["items"]) <= 5 and lst["total"] >= len(lst["items"])
    if entity == "media_outputs":
        # dibuat dari Studio (AI); CRUD menolak tambah langsung
        assert client.post("/api/crud/media_outputs", headers=h, json={"title": "x"}).status_code == 403
        d = client.get("/api/drafts", headers=h, params={"status": "tayang"}).json()[0]
        created = client.post(f"/api/m4/drafts/{d['id']}/media", headers=h, json={"kind": "podcast"}).json()
        new_id = created["id"]
    else:
        r = _create(client, h, meta, random.randint(1000, 9999))
        assert r.status_code == 201, f"{entity}: {r.status_code} {r.text[:300]}"
        new_id = r.json()["id"]
        if entity == "api_clients":
            assert r.json()["token"].startswith("sst_")
    got = client.get(f"/api/crud/{entity}/{new_id}", headers=h)
    assert got.status_code == 200, got.text
    # cari berdasarkan ID
    found = client.get(f"/api/crud/{entity}", headers=h, params={"q": str(new_id), "per_page": 200}).json()
    assert any(x["id"] == new_id for x in found["items"]), f"{entity}: hasil cari tidak memuat #{new_id}"
    # ubah
    f = _editable(meta)
    if f["type"] == "enum":
        cur = client.get(f"/api/crud/{entity}/{new_id}", headers=h).json()[f["name"]]
        new_val = next(o["value"] for o in f["options"] if o["value"] != cur)
    else:
        new_val = 7 if f["type"] == "int" else ("Nilai diubah oleh uji CRUD" + "z" * max(0, f.get("min", 0)))[: f.get("max", 300)]
    up = client.put(f"/api/crud/{entity}/{new_id}", headers=h, json={f["name"]: new_val})
    assert up.status_code == 200, f"{entity}: {up.status_code} {up.text[:300]}"
    after = client.get(f"/api/crud/{entity}/{up.json()['id']}", headers=h).json()
    if not f.get("pii"):
        assert after[f["name"]] == new_val
    # hapus
    target = up.json()["id"]
    de = client.delete(f"/api/crud/{entity}/{target}", headers=h)
    assert de.status_code == 200, f"{entity}: {de.status_code} {de.text[:300]}"
    assert client.get(f"/api/crud/{entity}/{target}", headers=h).status_code == 404
    # ekspor CSV
    csv = client.get(f"/api/crud/{entity}/export", headers=h)
    assert csv.status_code == 200 and "text/csv" in csv.headers["content-type"]


def test_crud_business_guards(db_demo, client, login):
    red, pim, op = login("redaktur"), login("pimpinan"), login("operator")
    approved = client.get("/api/crud/drafts", headers=red, params={"filter[status]": "disetujui"}).json()["items"][0]
    r = client.delete(f"/api/crud/drafts/{approved['id']}", headers=red)
    assert r.status_code == 409 and "Draf" in r.json()["detail"]
    r = client.put(f"/api/crud/drafts/{approved['id']}", headers=red, json={"body": "Isi diubah setelah disetujui — harus ditolak sistem."})
    assert r.status_code == 409
    camp = client.get("/api/crud/campaigns", headers=red, params={"filter[status]": "berjalan"}).json()["items"][0]
    assert client.put(f"/api/crud/campaigns/{camp['id']}", headers=red, json={"name": "Ubah kampanye bertanda tangan"}).status_code == 409
    sent = client.get("/api/crud/distributions", headers=op, params={"filter[status]": "terkirim"}).json()["items"][0]
    assert client.delete(f"/api/crud/distributions/{sent['id']}", headers=op).status_code == 409
    bad = client.put(f"/api/crud/distributions/{sent['id']}", headers=op, json={"status": "terjadwal"})
    assert bad.status_code == 409
    issue = client.get("/api/crud/issues", headers=pim, params={"q": "EKO-PANGAN"}).json()["items"][0]
    assert client.delete(f"/api/crud/issues/{issue['id']}", headers=pim).status_code == 409
    pack = client.get("/api/crud/response_packs", headers=red, params={"filter[status]": "terbit"}).json()["items"][0]
    assert client.put(f"/api/crud/response_packs/{pack['id']}", headers=red, json={"title": "Ubah paket terbit"}).status_code == 409
    # templat terkunci tidak dapat disunting; perubahan isi membuat versi baru
    tpl = client.get("/api/crud/visual_templates", headers=red, params={"q": "INF-STD"}).json()["items"][0]
    assert tpl["locked"] is True and client.put(f"/api/crud/visual_templates/{tpl['id']}", headers=red, json={"name": "x" * 5}).status_code == 409
    client.put(f"/api/crud/visual_templates/{tpl['id']}", headers=red, json={"locked": False})
    v = client.put(f"/api/crud/visual_templates/{tpl['id']}", headers=red, json={"spec_accent": "#AA0000"}).json()
    assert v["version"] == tpl["version"] + 1 and v["spec_accent"] == "#AA0000" and v["id"] != tpl["id"]


def test_crud_permissions_and_validation(db_demo, client, login):
    assert client.post("/api/crud/briefs", headers=login("operator"), json={"title": "Tidak boleh"}).status_code == 403
    assert client.get("/api/crud/api_clients", headers=login("redaktur")).status_code == 403
    assert client.delete("/api/crud/social_accounts/1", headers=login("operator")).status_code == 403
    red = login("redaktur")
    r = client.post("/api/crud/briefs", headers=red, json={"title": "Brief validasi", "issue_id": 1, "format": "bukan-format", "key_messages": "Pesan kunci uji"})
    assert r.status_code == 422 and "Format" in r.json()["detail"]
    r = client.post("/api/crud/partners", headers=red, json={"name": "Mitra", "handle": "@m", "platform": "x", "contract_no": "PKS-1", "contract_start": _day(5), "contract_end": _day(1), "disclosure": "Kemitraan berbayar"})
    assert r.status_code == 422 and "Kontrak" in r.json()["detail"]
    assert client.post("/api/crud/issues", headers=login("pimpinan"), json={"code": "EKO-PANGAN", "name": "Duplikat", "risk_level": "tinggi"}).status_code == 409
    # komentar warga: data pribadi disamarkan di daftar; isi komentar tidak dapat diubah
    cm = client.get("/api/crud/social_comments", headers=login("operator"), params={"q": "NIK", "per_page": 50}).json()["items"]
    assert cm and not any("3201123456789012" in c["text"] for c in cm)
    assert client.put(f"/api/crud/social_comments/{cm[0]['id']}", headers=login("operator"), json={"text": "diubah"}).status_code == 422
    # notifikasi: hanya milik sendiri
    mine = client.get("/api/crud/notifications", headers=login("operator")).json()
    assert all(x["user_id"] == client.get("/api/auth/me", headers=login("operator")).json()["id"] for x in mine["items"])


def test_crud_search_respects_scope(db_demo, client, login):
    """Pencarian dengan angka ID tidak boleh memperluas cakupan baca (notifikasi milik orang lain)."""
    admin_list = client.get("/api/crud/notifications", headers=login("admin"), params={"per_page": 200}).json()["items"]
    me = client.get("/api/auth/me", headers=login("operator")).json()["id"]
    other = next(x for x in admin_list if x["user_id"] != me)
    r = client.get("/api/crud/notifications", headers=login("operator"), params={"q": str(other["id"])}).json()
    assert all(x["user_id"] == me for x in r["items"])
    assert client.get(f"/api/crud/notifications/{other['id']}", headers=login("operator")).status_code == 404
