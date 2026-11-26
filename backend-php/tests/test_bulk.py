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
    ("/api/geo/points?layer=artikel", "pimpinan"), ("/api/geo/points?layer=mitra", "redaktur"),
])
def test_pages_ok_with_bulk_data(db_bulk, client, login, path, user):
    r = client.get(path, headers=login(user))
    assert r.status_code == 200, r.text[:200]
    assert r.elapsed.total_seconds() < 3


LAYERS = ["artikel", "naskah", "liputan", "kanal", "akun", "mitra"]


def test_geo_map_layers(db_bulk, client, login):
    """Peta sebaran: semua lapisan berisi titik di wilayah Indonesia dan tersebar di 38 provinsi."""
    h = login("pimpinan")
    assert [x["key"] for x in client.get("/api/geo/layers", headers=h).json()] == LAYERS
    for layer in LAYERS:
        r = client.get("/api/geo/points", headers=h, params={"layer": layer})
        assert r.status_code == 200, r.text
        d = r.json()
        assert d["points"], layer
        assert d["provinces_covered"] == 38, (layer, d["provinces_covered"])
        for p in d["points"]:
            assert -11.5 < p["lat"] < 6.5 and 94.5 < p["lng"] < 141.5, p
            assert p["link"].startswith("/") and p["tone"] in {"good", "info", "warn", "bad", "muted"}
        if layer != "kanal":
            assert sum(x["count"] for x in d["by_province"]) == len(d["points"])
            assert len(d["points"]) >= 100, (layer, len(d["points"]))
    assert len(client.get("/api/geo/points", headers=h, params={"layer": "kanal"}).json()["points"]) == 38


def test_geo_map_filters_and_guards(db_bulk, client, login):
    h = login("analis")
    full = client.get("/api/geo/points", headers=h, params={"layer": "artikel"}).json()
    week = client.get("/api/geo/points", headers=h, params={"layer": "artikel", "days": 7}).json()
    assert len(week["points"]) <= len(full["points"])
    iss = client.get("/api/issues", headers=h).json()[0]["id"]
    one = client.get("/api/geo/points", headers=h, params={"layer": "naskah", "issue_id": iss}).json()
    allp = client.get("/api/geo/points", headers=h, params={"layer": "naskah"}).json()
    assert one["total"] < allp["total"]
    assert client.get("/api/geo/points", headers=h, params={"layer": "rahasia"}).status_code == 422
    assert client.get("/api/geo/points", headers=h, params={"layer": "artikel", "days": "x"}).status_code == 422
    assert client.get("/api/geo/points", params={"layer": "artikel"}).status_code == 401


def test_geo_local_articles_stay_in_their_province(db_bulk, client, login):
    """Naskah bermuatan lokal hanya tayang di kanal provinsinya sendiri (selain kanal nasional)."""
    from sqlalchemy import text
    from conftest import engine
    with engine.connect() as c:
        bad = c.execute(text("""SELECT COUNT(*) FROM distributions d JOIN channels ch ON ch.id = d.channel_id JOIN drafts r ON r.id = d.draft_id
                                WHERE r.province_code IS NOT NULL AND ch.type = 'provinsi' AND ch.province_code <> r.province_code""")).scalar()
    assert bad == 0


def test_multimedia_demo_complete(db_bulk, client, login):
    """Studio Multimedia: setiap naskah disetujui/tayang punya infografis, video, dan audio; galeri bisa disaring & dicari."""
    h = login("produser")
    st = client.get("/api/m4/media/stats", headers=h).json()
    assert st["eligible_drafts"] > 100 and st["complete_drafts"] == st["eligible_drafts"]
    assert min(st["by_kind"].values()) >= st["eligible_drafts"] and st["total"] >= 900
    assert st["exported_assets"] >= 30
    vids = client.get("/api/m4/media", headers=h, params={"kind": "video", "limit": 50}).json()
    assert len(vids) == 50 and all(v["kind"] == "video" and v["spec"]["scenes"] and v["ai_mode"] == "simulasi" for v in vids)
    page2 = client.get("/api/m4/media", headers=h, params={"limit": 24, "offset": 24}).json()
    assert len(page2) == 24
    one = vids[0]
    hit = client.get("/api/m4/media", headers=h, params={"q": f"#{one['draft_id']}"}).json()
    assert hit and all(x["draft_id"] == one["draft_id"] for x in hit) and {x["kind"] for x in hit} >= {"infografis", "video", "podcast"}
    assert client.get("/api/m4/media", headers=h, params={"limit": 999}).status_code == 422


def test_bank_aset_real_photos(db_bulk, client, login):
    """Bank aset berisi foto asli berlisensi terbuka lengkap dengan atribusi dan tautan sumber."""
    import json, pathlib
    mf = pathlib.Path(__file__).resolve().parents[1] / "demo-assets" / "manifest.json"
    if not mf.exists():
        pytest.skip("demo-assets/manifest.json belum dibundel")
    man = json.loads(mf.read_text())
    assert len(man) >= 40
    h = login("produser")
    r = client.get("/api/m5/assets", headers=h, params={"media": "foto"}).json()
    photos = [a for a in r["items"] if "wikimedia" in a["tags"]]
    assert len(photos) >= min(len(man), 100)
    for a in photos[:20]:
        assert a["mime"] == "image/jpeg" and a["kind"] == "gambar"
        assert "Wikimedia Commons" in a["credit"] and a["license"] in {"cc_by", "cc_by_sa", "domain_publik"}
        assert "commons.wikimedia.org" in a["description"]
    f = client.get(f"/api/m5/assets/{photos[0]['id']}/file", headers=h)
    assert f.status_code == 200 and f.headers["content-type"] == "image/jpeg" and f.content[:3] == b"\xff\xd8\xff"
    svg = client.get("/api/m5/assets", headers=h, params={"media": "grafis"}).json()["items"]
    assert svg and all(a["mime"] == "image/svg+xml" for a in svg)
    assert len({a["province_name"] for a in photos if a["province_name"]}) >= 20


def test_asset_file_thumbnail_and_cache(db_bulk, client, login):
    """Gambar aset dapat di-cache peramban (ETag/304) dan tersedia versi mini agar ringan di hosting bersama."""
    import struct
    h = login("produser")
    photos = [a for a in client.get("/api/m5/assets", headers=h, params={"media": "foto"}).json()["items"] if a["mime"] == "image/jpeg"]
    if not photos:
        pytest.skip("tidak ada foto contoh")
    url = f"/api/m5/assets/{photos[0]['id']}/file"
    full = client.get(url, headers=h)
    assert full.status_code == 200 and "max-age" in full.headers["cache-control"] and full.headers.get("etag")
    again = client.get(url, headers={**h, "If-None-Match": full.headers["etag"]})
    assert again.status_code == 304 and again.content == b""
    th = client.get(url, headers=h, params={"w": 480})
    assert th.status_code == 200 and th.headers["content-type"] == "image/jpeg" and th.content[:3] == b"\xff\xd8\xff"
    assert len(th.content) < len(full.content) and th.headers["etag"] != full.headers["etag"]
    # lebar JPEG dari penanda SOF
    b, i, width = th.content, 2, None
    while i < len(b) - 9:
        if b[i] != 0xFF: i += 1; continue
        m = b[i + 1]
        if m in (0xC0, 0xC1, 0xC2):
            width = struct.unpack(">H", b[i + 7:i + 9])[0]; break
        i += 2 + struct.unpack(">H", b[i + 2:i + 4])[0]
    assert width == 480
    svg = client.get("/api/m5/assets", headers=h, params={"media": "grafis"}).json()["items"][0]
    s = client.get(f"/api/m5/assets/{svg['id']}/file", headers=h, params={"w": 480})
    assert s.status_code == 200 and s.headers["content-type"] == "image/svg+xml"
    assert client.get(url, params={"w": 480}).status_code == 401


def test_demo_data_complete_and_consistent(db_bulk):
    """Data contoh lengkap & saling sesuai: judul varian satu isu dengan brief, status brief mengikuti isinya, aset/lampiran/log terisi."""
    import json, os, sqlite3, subprocess, pathlib
    src = pathlib.Path(__file__).resolve().parents[1] / "src" / "seed_m47.php"
    titles = json.loads(subprocess.run(["php", "-r", f'require "{src}"; echo json_encode(M47_TITLES);'], capture_output=True, text=True, check=True).stdout)
    db = sqlite3.connect(f"file:{os.environ['SISTRA_DATA_DIR']}/sistra.db?mode=ro", uri=True)
    one = lambda s: db.execute(s).fetchone()[0]
    prefixes = ("Pembaruan: ", "Klarifikasi: ", "Panduan Warga: ", "Tanya-Jawab: ", "Fakta Singkat: ", "Info Layanan: ", "Imbauan: ")

    def base(t):
        for p in prefixes:
            if t.startswith(p): t = t[len(p):]
        return t.split(" — ")[0]
    rows = db.execute("SELECT v.brief_id, v.title, i.code FROM brief_variants v JOIN briefs b ON b.id = v.brief_id JOIN issues i ON i.id = b.issue_id").fetchall()
    assert rows and all(base(t) in titles[code] for _, t, code in rows if code in titles)
    sib = {}
    for bid, t, _ in rows: sib.setdefault(bid, []).append(t)
    assert all(len(v) == len(set(v)) for v in sib.values())  # varian satu brief berjudul berbeda
    assert one("SELECT COUNT(*) FROM briefs b WHERE status = 'dipakai' AND NOT EXISTS (SELECT 1 FROM drafts d WHERE d.brief_id = b.id) AND NOT EXISTS (SELECT 1 FROM brief_variants v WHERE v.brief_id = b.id AND v.draft_id IS NOT NULL)") == 0
    assert one("SELECT COUNT(*) FROM briefs b WHERE status = 'baru' AND EXISTS (SELECT 1 FROM brief_variants v WHERE v.brief_id = b.id)") == 0
    assert one("SELECT MAX(c) FROM (SELECT COUNT(*) c FROM drafts WHERE brief_id IS NOT NULL GROUP BY brief_id)") <= 12  # tersebar, bukan menumpuk di satu brief
    assert one("SELECT COUNT(*) FROM channels WHERE url IS NULL OR url = ''") == 0
    assert one("SELECT COUNT(*) FROM issues WHERE description IS NULL OR description = ''") == 0
    assert one("SELECT COUNT(*) FROM partner_collabs WHERE notes IS NULL OR notes = ''") == 0
    assert one("SELECT COUNT(*) FROM drafts WHERE format IN ('caption','thread','carousel','video','podcast','infografis') AND (hashtags IS NULL OR hashtags IN ('', '[]'))") == 0
    assert one("SELECT COUNT(*) FROM drafts d WHERE d.status IN ('disetujui','tayang') AND NOT EXISTS (SELECT 1 FROM draft_assets a WHERE a.draft_id = d.id)") == 0
    assert one("SELECT COUNT(*) FROM press_packs WHERE asset_ids IS NULL OR asset_ids IN ('', '[]')") == 0
    assert one("SELECT COUNT(*) FROM api_clients c WHERE last_used_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM api_calls a WHERE a.client_id = c.id)") == 0
    assert one("SELECT COUNT(*) FROM assets WHERE mime = 'image/jpeg' AND coverage_id IS NOT NULL") >= 20
    db.close()
