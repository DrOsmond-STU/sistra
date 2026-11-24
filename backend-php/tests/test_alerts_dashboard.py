from __future__ import annotations

from app.core.config import AlertThresholds
from app.modules.alerts.service import classify_level

T = AlertThresholds()


def test_classify_level_rules():
    assert classify_level(30, 4.0, 0.7, T)[0] == "merah"
    assert classify_level(30, 4.0, 0.3, T)[0] == "oranye"   # lonjakan tanpa negatif dominan
    assert classify_level(12, 1.0, 0.65, T)[0] == "oranye"
    assert classify_level(6, 1.6, 0.1, T)[0] == "kuning"
    assert classify_level(3, 9.0, 1.0, T)[0] == "hijau"     # volume terlalu kecil
    assert classify_level(50, 1.0, 0.2, T)[0] == "hijau"    # volume besar tapi stabil & tidak negatif


def test_demo_data_triggers_tiered_alerts(db_demo, client, login):
    alerts = client.get("/api/alerts", headers=login("analis")).json()
    levels = {a["issue_code"]: a["level"] for a in alerts}
    assert levels["EKO-PANGAN"] == "merah"
    assert levels["DIS-HOAKS"] == "oranye"
    assert levels.get("BEN-ALAM") == "kuning"
    assert "POL-PEMILU" not in levels
    # urutan: merah dulu
    assert alerts[0]["level"] == "merah"


def test_alert_lifecycle(db_demo, client, login):
    h = login("analis")
    alert = client.get("/api/alerts", headers=h).json()[0]
    aid = alert["id"]
    assert client.post(f"/api/alerts/{aid}/ack", headers=login("redaktur"), json={}).status_code == 403
    r = client.post(f"/api/alerts/{aid}/ack", headers=h, json={"note": "Diterima"})
    assert r.status_code == 200 and r.json()["status"] == "ack" and r.json()["owner_id"] is not None
    assert client.post(f"/api/alerts/{aid}/ack", headers=h, json={}).status_code == 409
    assert client.post(f"/api/alerts/{aid}/selesai", headers=h, json={"note": ""}).status_code == 422
    r = client.post(f"/api/alerts/{aid}/selesai", headers=login("pimpinan"), json={"note": "Klarifikasi sudah terbit"})
    assert r.status_code == 200 and r.json()["status"] == "resolved"
    assert [e["action"] for e in r.json()["events"]] == ["dibuat", "ack", "selesai"]
    # evaluasi ulang tidak menggandakan peringatan aktif lain, tetapi membuat baru untuk isu yang sudah selesai
    r = client.post("/api/alerts/evaluate", headers=h)
    assert r.status_code == 200
    assert len(r.json()["created"]) == 1


def test_escalation(db_base, client, login):
    import json
    from datetime import timedelta

    from app.core.timeutil import utcnow

    h = login("analis")
    sid = client.post("/api/sources", headers=h, json={"type": "news", "name": "Uji"}).json()["id"]
    now = utcnow()

    def batch(start: int, n: int, negative: bool) -> bytes:
        tone = "Warga kecewa dan resah, kondisi memburuk." if negative else "Petugas melakukan pemantauan."
        return json.dumps([{
            "id": f"e{start + i}", "title": f"Hoaks bantuan sosial nomor {start + i}",
            "body": f"Beredar berita bohong ke-{start + i} di grup percakapan warga {i * 7919 % 1000}. {tone}",
            "published_at": (now - timedelta(minutes=5 + i)).isoformat(),
        } for i in range(n)]).encode()

    r = client.post("/api/ingest/upload", headers=h, data={"source_id": sid},
                    files={"file": ("a.json", batch(0, 6, False), "application/json")})
    assert r.json()["alerts_created"], r.text
    first = client.get("/api/alerts", headers=h).json()[0]
    assert first["level"] == "kuning"
    r = client.post("/api/ingest/upload", headers=h, data={"source_id": sid},
                    files={"file": ("b.json", batch(100, 20, True), "application/json")})
    assert r.json()["alerts_escalated"] == [first["id"]]
    detail = client.get(f"/api/alerts/{first['id']}", headers=h).json()
    assert detail["level"] == "merah"
    assert detail["events"][-1]["action"] == "eskalasi"


def test_issue_metrics(db_demo, client, login):
    h = login("analis")
    issue = next(i for i in client.get("/api/issues", headers=h).json() if i["code"] == "EKO-PANGAN")
    m = client.get(f"/api/issues/{issue['id']}/metrics", headers=h, params={"days": 10}).json()
    assert len(m["series"]) == 10
    assert m["series"][-1]["volume"] > m["series"][0]["volume"]


def test_dashboard_summary(db_demo, client, login):
    s = client.get("/api/dashboard/summary", headers=login("pimpinan")).json()
    assert s["content"]["total_window"] > 0
    assert set(s["content"]["sentiment"]) == {"positif", "netral", "negatif"}
    assert len(s["timeline"]) == 14
    assert len(s["provinces"]) == 38
    assert s["issues"][0]["level"] == "merah"
    assert s["alerts"]["merah"] == 1
    assert s["drafts"]["disetujui"] >= 1  # data demo M4–M7 menambah naskah disetujui
    # pimpinan (SANGAT_RAHASIA, DEP7): 3 dokumen terbuka/terbatas + nota dinas miliknya; dokumen RAHASIA
    # milik DIT-ANL tidak terlihat karena beda unit dan tanpa izin. Redaktur hanya TERBUKA/TERBATAS (3).
    assert s["documents_accessible"] == 4
    assert client.get("/api/dashboard/summary", headers=login("redaktur")).json()["documents_accessible"] == 3
