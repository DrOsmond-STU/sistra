"""Uji katalog KAK Modul 4–8 (S26–S65) dalam mode simulasi."""
from __future__ import annotations

import io
import zipfile
from datetime import datetime, timedelta, timezone


def _iso(hours: float) -> str:
    return (datetime.now(timezone.utc) + timedelta(hours=hours)).strftime("%Y-%m-%dT%H:%M:%SZ")


def test_s26_s33_fact_sources_and_verification(db_demo, client, login):
    h = login("produser")
    b = client.post("/api/m4/briefs", headers=h, json={"title": "Penjelasan stok beras", "issue_code": "EKO-PANGAN", "format": "editorial", "key_messages": "Stok aman.",
        "facts": [{"text": "Stok beras pemerintah 1,8 juta ton (contoh)", "source": "Rilis lembaga pangan (contoh)", "url": "https://contoh.invalid/a", "reliability": "A"}, "Fakta lama tanpa sumber"]}).json()
    assert b["fact_items"][0]["source"] and b["fact_items"][1]["source"] == "" and b["facts"][0].startswith("Stok beras")
    g = client.post(f"/api/m4/briefs/{b['id']}/generate", headers=h, json={"n": 5}).json()
    assert len(g["variants"]) == 5 and g["gen_ms"] is not None
    # draf dengan klaim tak bersumber → skor < 100, klaim bertanda perlu verifikasi
    draf = next(d for d in client.get("/api/drafts", headers=h, params={"status": "draf"}).json() if "87%" in d["body"])
    v = client.get(f"/api/m4/drafts/{draf['id']}/verify", headers=h).json()
    statuses = {c["status"] for c in v["claims"]}
    assert v["score"] < 100 and "perlu_verifikasi" in statuses and v["fact_items"]


def test_s28_local_context_verified_only_used(db_demo, client, login):
    lc = client.get("/api/m4/local-context", headers=login("produser"), params={"province": "95"}).json()
    assert lc["items"] and any(not x["verified"] for x in lc["items"])
    new = client.post("/api/m4/local-context", headers=login("produser"), json={"province_code": "95", "category": "budaya", "content": "Contoh kearifan lokal", "source": "Tim daerah (contoh)"}).json()
    assert new["verified"] is False
    assert client.post(f"/api/m4/local-context/{new['id']}/verify", headers=login("produser"), json={"verified": True}).status_code == 403
    assert client.post(f"/api/m4/local-context/{new['id']}/verify", headers=login("redaktur"), json={"verified": True}).json()["verified"] is True
    d = client.get("/api/drafts", headers=login("produser"), params={"status": "draf"}).json()[0]
    loc = client.post(f"/api/m4/drafts/{d['id']}/localize", headers=login("produser"), json={"province_codes": ["95"]}).json()["created"][0]
    body = client.get(f"/api/drafts/{loc['draft_id']}", headers=login("produser")).json()["body"]
    assert "Contoh kearifan lokal" in body and "Ibu kota provinsi" in body  # hanya yang terverifikasi


def test_s29_s32_media_titles_feedback(db_demo, client, login):
    h = login("produser")
    d = client.get("/api/drafts", headers=h, params={"status": "tayang"}).json()[0]
    for kind in ("infografis", "video", "podcast"):
        m = client.post(f"/api/m4/drafts/{d['id']}/media", headers=h, json={"kind": kind}).json()
        assert m["kind"] == kind and m["spec"]
    vid = client.get("/api/m4/media", headers=h, params={"draft_id": d["id"], "kind": "video"}).json()[0]
    scenes = vid["spec"]["scenes"]
    assert scenes[-1]["end"] <= 60 and all(s["caption_id"] and s["caption_en"] for s in scenes)
    t = client.post(f"/api/m4/drafts/{d['id']}/titles", headers=login("redaktur")).json()
    assert len(t["titles"]) == 5
    assert client.post(f"/api/m4/drafts/{d['id']}/feedback", headers=login("redaktur"), json={"category": "ejaan", "decision": "ditolak", "excerpt": "x", "note": "baku"}).status_code == 201
    assert client.post(f"/api/m4/drafts/{d['id']}/feedback", headers=login("operator"), json={"decision": "diterima"}).status_code == 403
    assert any(r["decision"] == "ditolak" for r in client.get("/api/m4/feedback/stats", headers=h).json()["recent"])


def test_s34_s35_calendar_conflict_diff_notifications(db_demo, client, login):
    cal = client.get("/api/m5/calendar", headers=login("redaktur"), params={"from": _iso(-24 * 7), "to": _iso(24 * 30)}).json()
    assert cal["conflicts"] and {"tayang", "unggah", "tenggat", "kampanye"} <= {e["type"] for e in cal["events"]}
    # pengajuan naskah → notifikasi ke pimpinan; revisi tersimpan untuk diff
    h = login("produser")
    d = client.post("/api/drafts", headers=h, json={"kind": "artikel", "title": "Uji notifikasi persetujuan", "body": "Isi naskah percobaan yang cukup panjang untuk uji."}).json()
    client.put(f"/api/drafts/{d['id']}", headers=h, json={"title": "Uji notifikasi persetujuan", "body": "Isi naskah percobaan yang sudah direvisi untuk uji diff."})
    revs = client.get(f"/api/m5/drafts/{d['id']}/revisions", headers=h).json()
    assert len(revs) == 2 and revs[1]["body"].endswith("diff.")
    before = client.get("/api/notifications", headers=login("pimpinan")).json()["unread"]
    assert client.post(f"/api/drafts/{d['id']}/transition", headers=h, json={"to_status": "ditinjau"}).status_code == 200
    n = client.get("/api/notifications", headers=login("pimpinan")).json()
    assert n["unread"] == before + 1 and "Uji notifikasi" in n["items"][0]["text"]
    client.post("/api/notifications/read", headers=login("pimpinan"), json={})
    assert client.get("/api/notifications", headers=login("pimpinan")).json()["unread"] == 0


def test_s37_s38_s39_assets_templates_coverage(db_demo, client, login):
    h = login("produser")
    png = b"\x89PNG\r\n\x1a\n" + b"0" * 64
    r = client.post("/api/m5/assets", headers=h, files={"file": ("posko.png", png, "image/png")}, data={"title": "Posko banjir uji", "license": "berlisensi"})
    assert r.status_code == 422  # berlisensi wajib tanggal kedaluwarsa
    a = client.post("/api/m5/assets", headers=h, files={"file": ("posko.png", png, "image/png")}, data={"title": "Posko banjir uji", "license": "milik_sendiri", "tags": "banjir, posko"}).json()
    assert a["license_state"] == "aktif" and "banjir" in a["tags"] and a["ai_tags"]
    assert client.get(f"/api/m5/assets/{a['id']}/file", headers=h).content == png
    lst = client.get("/api/m5/assets", headers=h, params={"state": "kedaluwarsa"}).json()
    expired = lst["items"][0]
    d = client.get("/api/drafts", headers=h, params={"status": "draf"}).json()[0]
    assert client.post(f"/api/m5/assets/{expired['id']}/link", headers=h, json={"draft_id": d["id"]}).status_code == 409
    # templat terkunci tidak bisa diubah; versi baru setelah dibuka
    assert client.post("/api/m5/templates/INF-STD", headers=h, json={"spec": {"accent": "#FF0000"}}).status_code == 409
    client.post("/api/m5/templates/INF-STD/lock", headers=login("redaktur"), json={"locked": False})
    t = client.post("/api/m5/templates/INF-STD", headers=h, json={"spec": {"accent": "#FF0000"}, "note": "uji"}).json()
    assert t["version"] == 3 and t["spec"]["accent"] == "#FF0000" and t["spec"]["bg"]
    assert len(client.get("/api/m5/templates/INF-STD/versions", headers=h).json()) == 3
    cov = client.post("/api/m5/coverage", headers=login("redaktur"), json={"title": "Liputan uji lapangan", "event_at": _iso(5), "location": "Balai kota (contoh)", "reporter_id": None}).json()
    br = client.post(f"/api/m5/coverage/{cov['id']}/brief", headers=h, json={"issue_code": "BEN-ALAM"})
    assert br.status_code == 201 and client.post(f"/api/m5/coverage/{cov['id']}/brief", headers=h, json={"issue_code": "BEN-ALAM"}).status_code == 409


def test_s40_s41_s45_quota_packs(db_demo, client, login):
    op = login("operator")
    q = client.get("/api/m6/quota", headers=op).json()
    acc = next(x for x in q if x["platform"] == "YouTube")  # kuota harian 1
    d = next(x for x in client.get("/api/m7/ready", headers=op).json() if x["status"] == "disetujui")
    day = _iso(24 * 3)
    assert client.post("/api/m6/tasks", headers=op, json={"draft_id": d["id"], "account_ids": [acc["account_id"]], "planned_at": day}).status_code == 201
    assert client.post("/api/m6/tasks", headers=op, json={"draft_id": d["id"], "account_ids": [acc["account_id"]], "planned_at": day}).status_code == 409
    assert client.post("/api/m6/tasks", headers=login("redaktur"), json={"draft_id": d["id"], "account_ids": [acc["account_id"]], "planned_at": day, "override_quota": True}).status_code == 201
    rot = client.post(f"/api/m6/accounts/{acc['account_id']}/rotate", headers=op).json()
    assert "token" not in rot or "Token" in rot["message"]
    # paket respons: draf tak terlihat operator, terbit → menjadi bahan respons
    p = client.post("/api/m6/packs", headers=login("analis"), json={"issue_code": "GIZI-MBG", "level": "merah"}).json()
    assert p["status"] == "draf" and p["sla"] == "berjalan"
    assert all(x["id"] != p["id"] for x in client.get("/api/m6/packs", headers=op).json()["items"])
    assert client.post(f"/api/m6/packs/{p['id']}/publish", headers=login("redaktur")).status_code == 422
    g = client.post(f"/api/m6/packs/{p['id']}/generate", headers=login("analis")).json()
    assert g["holding_statement"] and g["qa"]
    pub = client.post(f"/api/m6/packs/{p['id']}/publish", headers=login("redaktur")).json()
    assert pub["status"] == "terbit" and pub["sla"] == "tepat_waktu"
    assert any(x["id"] == p["id"] for x in client.get("/api/m6/packs", headers=op).json()["items"])
    assert any(k["sources"] == f"Paket respons #{p['id']}" for k in client.get("/api/m6/kb", headers=op).json())


def test_s42_s44_s48_partners_campaigns(db_demo, client, login):
    red = login("redaktur")
    partners = client.get("/api/m6/partners", headers=red).json()
    expired = next(p for p in partners if p["status"] == "kontrak_berakhir")
    assert client.post(f"/api/m6/partners/{expired['id']}/collabs", headers=red, json={"reach": 10}).status_code == 409
    c = client.post("/api/m6/campaigns", headers=red, json={"name": "Kampanye uji kepatuhan", "issue_code": "DIG-PDP", "objective": "Edukasi pelindungan data",
        "key_messages": "Jangan bagikan data pribadi.", "start_date": _iso(24)[:10], "end_date": _iso(24 * 10)[:10], "regions": ["NAS", "31"], "kpi": {"reach": 100000}}).json()
    assert client.post(f"/api/m6/campaigns/{c['id']}/slots", headers=red, json={"date": c["start_date"], "account_id": 1}).status_code == 409  # belum disetujui
    client.post(f"/api/m6/campaigns/{c['id']}/transition", headers=red, json={"to": "diajukan"})
    assert client.post(f"/api/m6/campaigns/{c['id']}/transition", headers=red, json={"to": "disetujui"}).status_code == 403
    ok = client.post(f"/api/m6/campaigns/{c['id']}/transition", headers=login("pimpinan"), json={"to": "disetujui"}).json()
    assert ok["status"] == "disetujui" and ok["signature"]
    run = next(x for x in client.get("/api/m6/campaigns", headers=red).json() if x["status"] == "berjalan" and "Hoaks" in x["name"])
    m = client.get(f"/api/m6/campaigns/{run['id']}", headers=red).json()["monitoring"]
    assert m["reach"] > 0 and m["sentiment"]["p_value"] is not None and m["sentiment"]["after"]["neg_ratio"] < m["sentiment"]["before"]["neg_ratio"]
    ev = client.post(f"/api/m6/campaigns/{run['id']}/evaluate", headers=red).json()
    assert ev["evaluation"]


def test_s43_pii_masking_and_threads(db_demo, client, login):
    op = login("operator")
    cm = client.get("/api/m6/comments", headers=op).json()
    masked = [c for c in cm if c["pii_masked"]]
    assert masked and not any("3201123456789012" in c["text"] or "081234567890" in c["text"] for c in cm)
    child = next(c for c in cm if c["parent_id"])
    th = client.get(f"/api/m6/comments/{child['id']}/thread", headers=op).json()
    assert th["root"]["replies"] >= 1 and any(r["id"] == child["id"] for r in th["replies"])


def test_s49_s51_s52_schedule_takedown_package(db_demo, client, login):
    op = login("operator")
    rec = client.get("/api/m7/schedule/recommend", headers=op).json()
    assert len(rec) == 39 and all(r["at"] and r["tz"] in ("WIB", "WITA", "WIT") for r in rec)
    d = next(x for x in client.get("/api/m7/ready", headers=op).json() if x["status"] == "disetujui")
    past = _iso(-1)
    assert client.post("/api/m7/schedule", headers=op, json={"draft_id": d["id"], "channel_codes": ["NAS"], "times": {"NAS": past}}).status_code == 422
    assert client.post("/api/m7/schedule", headers=op, json={"draft_id": d["id"], "channel_codes": ["PROV-31", "PROV-73"]}).json()["scheduled"] == 2
    q = client.get("/api/m7/queue", headers=op).json()
    assert any(i["draft_id"] == d["id"] for i in q["items"])
    ch = client.get("/api/m7/channels/PROV-35", headers=op).json()
    sent = next(a for a in ch["articles"] if a["status"] == "terkirim")
    assert sent["sync_ms"] is not None and sent["sync_ms"] <= 5000
    assert client.post(f"/api/m7/distributions/{sent['id']}/takedown", headers=op, json={"reason": "Koreksi data uji"}).json()["status"] == "ditarik"
    pkg = client.get(f"/api/m7/drafts/{d['id']}/package", headers=op).json()
    assert pkg["valid"] and pkg["opengraph"]["og:locale"] == "id_ID" and pkg["jsonld"]["@type"] == "NewsArticle" and len(pkg["renditions"]) == 12


def test_s53_press_portal_token(db_demo, client, login):
    op = login("operator")
    d = next(x for x in client.get("/api/m7/ready", headers=op).json() if x["status"] == "tayang")
    pk = client.post("/api/m7/press-packs", headers=op, json={"draft_id": d["id"], "days": 3}).json()
    partners = client.get("/api/m7/media-partners", headers=op).json()
    links = client.post(f"/api/m7/press-packs/{pk['id']}/links", headers=op, json={"partner_ids": [partners[0]["id"]]}).json()["links"]
    tok = links[0]["token"]
    pub = client.get(f"/api/press/{tok}")  # tanpa login
    assert pub.status_code == 200 and pub.json()["article"]["title"] == d["title"]
    dl = client.post(f"/api/press/{tok}/download").json()
    assert d["title"] in dl["content"] and partners[0]["name"] in dl["content"]
    assert client.get("/api/press/pers_tidakadaaaaaaaaaaaaaaaaaaaaa").status_code == 404
    packs = client.get("/api/m7/press-packs", headers=op).json()
    assert next(p for p in packs if p["id"] == pk["id"])["downloads"][0]["partner"] == partners[0]["name"]


def test_s54_api_gateway(db_demo, client, login):
    assert client.post("/api/m7/api-clients", headers=login("operator"), json={"name": "x", "org": "y"}).status_code == 403
    c = client.post("/api/m7/api-clients", headers=login("admin"), json={"name": "Klien uji", "org": "K/L uji", "rate_per_min": 2, "days": 1}).json()
    tok = c["token"]
    assert tok.startswith("sst_") and "token" not in client.get("/api/m7/api-clients", headers=login("admin")).json()[0]
    assert client.get("/api/public/v1/publications").status_code == 401
    h = {"Authorization": f"Bearer {tok}"}
    r1 = client.get("/api/public/v1/publications", headers=h)
    assert r1.status_code == 200 and r1.json()["data"] and r1.headers["X-RateLimit-Limit"] == "2"
    assert client.get("/api/public/v1/publications", headers=h).status_code == 200
    assert client.get("/api/public/v1/publications", headers=h).status_code == 429
    client.post(f"/api/m7/api-clients/{c['id']}/revoke", headers=login("admin"))
    assert client.get("/api/public/v1/publications", headers=h).status_code == 401
    calls = client.get(f"/api/m7/api-clients/{c['id']}/calls", headers=login("admin")).json()
    assert {x["status"] for x in calls} >= {200, 429, 401}


def test_s55_uptime_points(db_demo, client, login):
    up = client.get("/api/m7/uptime", headers=login("operator")).json()
    assert up["sla_target"] == 99.9 and len(up["channels"]) == 39 and len(up["points"]) == 5 and up["incidents"]
    pg = next(c for c in up["channels"] if c["code"] == "PROV-95")
    assert pg["uptime"] < 99.9
    client.post("/api/m7/health-check", headers=login("operator"))
    up2 = client.get("/api/m7/uptime", headers=login("operator")).json()
    assert next(c for c in up2["channels"] if c["code"] == "NAS")["checks"] > next(c for c in up["channels"] if c["code"] == "NAS")["checks"]


def test_s56_s64_dashboards_reports(db_demo, client, login):
    h = login("pimpinan")
    e = client.get("/api/m8/engagement", headers=h, params={"hours": 48}).json()
    assert len(e["series"]) >= 47 and e["totals"]["uniques"] > 0 and e["totals"]["avg_time_sec"] > 0
    imp = client.get("/api/m8/impact", headers=h).json()
    assert 0 <= imp["national"] <= 100 and abs(sum(imp["weights"].values()) - 1) < 1e-9
    assert any(c["shift"]["significant"] for c in imp["campaigns"])
    prov = client.get("/api/m8/provinces", headers=h).json()
    assert len(prov) == 38
    qa = client.get("/api/ai/quality", headers=h).json()
    assert qa["bias"] and qa["bias_flag"] and qa["feedback"] and qa["outcomes"]["ai"]["drafts"] > 0
    reps = client.get("/api/reports", headers=h).json()
    assert {r["period"] for r in reps} >= {"harian", "mingguan"} and all(r["auto"] for r in reps)
    rid = client.post("/api/reports", headers=h, json={"period": "bulanan"}).json()["id"]
    rep = client.get(f"/api/reports/{rid}", headers=h).json()
    assert rep["summary"] and rep["content"]["kpi"]
    dx = client.get(f"/api/reports/{rid}/docx", headers=h)
    assert dx.status_code == 200
    z = zipfile.ZipFile(io.BytesIO(dx.content))
    assert "Ringkasan eksekutif" in z.read("word/document.xml").decode()
