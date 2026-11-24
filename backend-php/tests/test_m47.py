"""Uji M4–M7, dashboard eksekutif/analitik, dan asisten AI (mode simulasi, tanpa kunci API)."""
from __future__ import annotations

import json
from pathlib import Path

from conftest import DATA


def test_ai_status_and_settings_never_return_key(db_demo, client, login):
    s = client.get("/api/ai/status", headers=login("redaktur")).json()
    assert s["mode"] == "simulasi" and s["configured"] is False
    assert client.put("/api/ai/settings", headers=login("redaktur"), json={"model": "claude-sonnet-5-5"}).status_code == 403
    assert client.put("/api/ai/settings", headers=login("admin"), json={"api_key": "bukan-kunci"}).status_code == 422
    r = client.put("/api/ai/settings", headers=login("admin"), json={"api_key": "sk-ant-api03-" + "x" * 40, "model": "claude-sonnet-5-5"})
    assert r.status_code == 200, r.text
    body = r.json()
    assert body["configured"] is True and body["model"] == "claude-sonnet-5-5"
    assert "sk-ant" not in r.text  # kunci tidak pernah dikirim balik
    assert oct((DATA / "ai.json").stat().st_mode & 0o777) == "0o600"
    # matikan lagi agar uji lain tetap mode simulasi
    client.put("/api/ai/settings", headers=login("admin"), json={"api_key": ""})
    assert client.get("/api/ai/status", headers=login("admin")).json()["mode"] == "simulasi"


def test_ai_chat_simulation_includes_context(db_demo, client, login):
    r = client.post("/api/ai/chat", headers=login("pimpinan"), json={"page": "dashboard", "messages": [{"role": "user", "content": "Apa yang perlu saya putuskan hari ini?"}]})
    assert r.status_code == 200, r.text
    assert r.json()["mode"] == "simulasi" and "Menunggu keputusan" in r.json()["reply"]
    assert client.post("/api/ai/chat", headers=login("pimpinan"), json={"messages": []}).status_code == 422


def test_m4_brief_generate_draft_editorial_localize(db_demo, client, login):
    h = login("produser")
    meta = client.get("/api/m4/meta", headers=h).json()
    assert {"caption", "video", "carousel"} <= {f["id"] for f in meta["formats"]}
    brief = {"title": "Klarifikasi hoaks bansos", "issue_code": "DIS-HOAKS", "format": "caption", "tone": "tegas", "objective": "Meluruskan hoaks",
             "audience": "Warga", "key_messages": "Bansos tetap disalurkan sesuai jadwal.", "facts": ["Bansos tetap disalurkan (contoh)", "Cek status di laman resmi (contoh)"]}
    assert client.post("/api/m4/briefs", headers=login("operator"), json=brief).status_code == 403
    b = client.post("/api/m4/briefs", headers=h, json=brief).json()
    g = client.post(f"/api/m4/briefs/{b['id']}/generate", headers=h, json={"n": 3})
    assert g.status_code == 200, g.text
    g = g.json()
    assert g["mode"] == "simulasi" and len(g["variants"]) == 3 and g["status"] == "dihasilkan"
    v = g["variants"][1]
    d = client.post(f"/api/m4/variants/{v['id']}/draft", headers=h, json={}).json()
    assert d["status"] == "draf" and d["format"] == "caption" and d["ai_generated"] is True and d["brief_id"] == b["id"]
    assert client.post(f"/api/m4/variants/{v['id']}/draft", headers=h, json={}).status_code == 409
    e = client.post(f"/api/m4/drafts/{d['id']}/editorial", headers=h).json()
    assert 0 <= e["score"] <= 100 and isinstance(e["findings"], list)
    assert client.get(f"/api/drafts/{d['id']}", headers=h).json()["editorial_score"] == e["score"]
    loc = client.post(f"/api/m4/drafts/{d['id']}/localize", headers=h, json={"province_codes": ["35", "73"]})
    assert loc.status_code == 201, loc.text
    assert {x["province_code"] for x in loc.json()["created"]} == {"35", "73"}
    rw = client.post("/api/ai/rewrite", headers=h, json={"text": d["body"], "instruction": "Persingkat"})
    assert rw.status_code == 200 and rw.json()["text"]


def test_m5_board_assign_and_discussion(db_demo, client, login):
    board = client.get("/api/m5/board", headers=login("redaktur")).json()
    assert [c["status"] for c in board["columns"]] == ["draf", "ditinjau", "disetujui", "tayang"]
    assert all(len(c["cards"]) > 0 for c in board["columns"])
    card = board["columns"][0]["cards"][0]
    target = next(t for t in board["team"] if "produser" in t["roles"])
    assert client.patch(f"/api/drafts/{card['id']}/assign", headers=login("operator"), json={"priority": "tinggi"}).status_code == 403
    r = client.patch(f"/api/drafts/{card['id']}/assign", headers=login("redaktur"), json={"assignee_id": target["id"], "due_at": "2030-01-01T10:00:00Z", "priority": "tinggi"})
    assert r.status_code == 200 and r.json()["assignee_name"] == target["name"] and r.json()["priority"] == "tinggi"
    c = client.post(f"/api/drafts/{card['id']}/comments", headers=login("pimpinan"), json={"body": "Tambahkan sumber data."})
    assert c.status_code == 201
    assert any(x["body"] == "Tambahkan sumber data." for x in client.get(f"/api/drafts/{card['id']}/comments", headers=login("redaktur")).json())


def test_m6_tasks_comments_and_bot_swarm(db_demo, client, login):
    op = login("operator")
    accounts = client.get("/api/m6/accounts", headers=op).json()
    assert len(accounts) >= 6 and all("(contoh)" in a["name"] for a in accounts)
    tasks = client.get("/api/m6/tasks", headers=op, params={"status": "menunggu"}).json()
    assert tasks, "data contoh harus memuat tugas unggah menunggu"
    t = tasks[0]
    assert client.post(f"/api/m6/tasks/{t['id']}/done", headers=op, json={"posted_url": "bukan-url"}).status_code == 422
    done = client.post(f"/api/m6/tasks/{t['id']}/done", headers=op, json={"posted_url": "https://contoh.invalid/p/1"})
    assert done.status_code == 200 and done.json()["status"] == "diunggah"
    # naskah draf tidak boleh dijadwalkan ke akun resmi
    draf = client.get("/api/drafts", headers=op, params={"status": "draf"}).json()[0]
    assert client.post("/api/m6/tasks", headers=op, json={"draft_id": draf["id"], "account_ids": [accounts[0]["id"]]}).status_code == 409
    bots = client.get("/api/m6/comments", headers=op, params={"bot": 1}).json()
    assert len(bots) >= 3 and all(c["priority"] == "tinggi" for c in bots)
    tri = client.post("/api/m6/comments/triage", headers=op, json={}).json()
    assert tri["mode"] == "simulasi" and tri["triaged"] > 0
    open_ = client.get("/api/m6/comments", headers=op, params={"status": "terbuka"}).json()
    hoaks = next(c for c in open_ if c["category"] == "hoaks")
    assert hoaks["suggested_reply"]
    assert client.post(f"/api/m6/comments/{hoaks['id']}/reply", headers=login("analis"), json={"text": "Tidak benar."}).status_code == 403
    rep = client.post(f"/api/m6/comments/{hoaks['id']}/reply", headers=op, json={"text": hoaks["suggested_reply"]})
    assert rep.status_code == 200 and rep.json()["status"] == "dibalas"
    ins = client.post("/api/m6/comments", headers=op, json={"account_id": accounts[1]["id"], "issue_code": "EKO-PANGAN", "items": [{"author": f"u{i}", "text": "PROMO GACOR klik link!!"} for i in range(4)]})
    assert ins.status_code == 201 and ins.json()["bot_flagged"] >= 4
    kb = client.post("/api/m6/kb", headers=login("redaktur"), json={"title": "Uji", "question": "Apa?", "answer": "Jawaban resmi uji coba."})
    assert kb.status_code == 201 and kb.json()["status"] == "disetujui"


def test_m7_distribute_publishes_and_handles_outage(db_demo, client, login):
    op = login("operator")
    chans = client.get("/api/m7/channels", headers=op).json()
    assert len(chans) == 39
    assert {c["health"] for c in chans} >= {"normal", "gangguan"}
    ready = client.get("/api/m7/ready", headers=op).json()
    approved = next(d for d in ready if d["status"] == "disetujui")
    assert client.post("/api/m7/distribute", headers=login("redaktur"), json={"draft_id": approved["id"], "scope": "semua"}).status_code == 403
    r = client.post("/api/m7/distribute", headers=op, json={"draft_id": approved["id"], "scope": "semua"})
    assert r.status_code == 200, r.text
    res = r.json()
    assert res["draft_status"] == "tayang" and res["sent"] == 38 and res["failed"] == 1
    failed = next(x for x in res["items"] if x["status"] == "gagal")
    assert client.post(f"/api/m7/distributions/{failed['id']}/retry", headers=op).status_code == 409
    assert client.get(f"/api/drafts/{approved['id']}", headers=op).json()["status"] == "tayang"
    hc = client.post("/api/m7/health-check", headers=op).json()
    assert sum(hc.values()) == 39


def test_m7_rejects_tampered_draft(db_demo, client, login):
    from conftest import engine
    from sqlalchemy import text
    ready = client.get("/api/m7/ready", headers=login("operator")).json()
    approved = next(d for d in ready if d["status"] == "disetujui")
    with engine.begin() as conn:
        conn.execute(text("UPDATE drafts SET body = body || ' [disisipkan]' WHERE id = :i"), {"i": approved["id"]})
    r = client.post("/api/m7/distribute", headers=login("operator"), json={"draft_id": approved["id"], "scope": "nasional"})
    assert r.status_code == 409 and "tanda tangan" in r.json()["detail"]


def test_exec_and_analytics_dashboards(db_demo, client, login):
    s = client.get("/api/exec/summary", headers=login("pimpinan")).json()
    k = s["kpi"]
    assert k["produced_30d"] > 0 and k["published_30d"] > 0 and k["channels_total"] == 39
    assert k["comments_bot"] > 0 and len(s["trend"]) == 14 and s["issues"] and s["alerts"]
    a = client.get("/api/analytics/overview", headers=login("analis"), params={"days": 30}).json()
    assert a["production"]["total"] > 0 and a["distribution"]["views"] > 0 and len(a["distribution"]["channels"]) == 39
    assert a["social"]["accounts"] and {"stage", "hours"} <= set(a["editorial"]["stage_hours"][0])
    assert client.get("/api/analytics/overview", headers=login("analis"), params={"days": 3}).status_code == 422


def test_ai_library_status_admin_only(db_demo, client, login):
    assert client.get("/api/ai/library", headers=login("redaktur")).status_code == 403
    assert client.post("/api/ai/library/install", headers=login("redaktur")).status_code == 403
    st = client.get("/api/ai/library", headers=login("admin")).json()
    assert st["packages_total"] == 15 and st["ready"] is True  # vendor lokal terpasang via composer
    assert client.get("/api/ai/status", headers=login("admin")).json()["library"] is True
    r = client.post("/api/ai/library/install", headers=login("admin"))
    assert r.status_code == 200 and r.json()["status"] == "sudah_terpasang"
