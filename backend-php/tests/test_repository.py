from __future__ import annotations

import hashlib
import io

from pypdf import PdfReader, PdfWriter

META = {
    "title": "Kajian Uji",
    "classification": "TERBATAS",
    "owner_unit_code": "DIT-ANL",
    "period_start": "2026-11-01",
    "period_end": "2026-11-07",
    "origin": "Analisis internal",
    "tags": "uji, kajian",
}


def upload(client, headers, content: bytes = b"isi dokumen uji tentang harga beras", filename="uji.txt", **meta):
    data = {**META, **meta}
    return client.post("/api/documents", headers=headers, data=data,
                       files={"file": (filename, content, "text/plain")})


def test_upload_requires_mandatory_metadata(db_base, client, login):
    r = client.post("/api/documents", headers=login("analis"), data={"title": "Tanpa metadata"},
                    files={"file": ("a.txt", b"isi", "text/plain")})
    assert r.status_code == 422
    missing = r.json()["detail"]["missing"]
    assert set(missing) == {"classification", "owner_unit_code", "period_start", "period_end", "origin"}


def test_upload_and_read(db_base, client, login):
    r = upload(client, login("analis"))
    assert r.status_code == 201, r.text
    doc = r.json()
    assert doc["current_version"]["sha256"] == hashlib.sha256(b"isi dokumen uji tentang harga beras").hexdigest()
    assert doc["tags"] == ["kajian", "uji"]
    assert doc["permissions"]["write"] is True


def test_cannot_upload_above_own_clearance(db_base, client, login):
    r = upload(client, login("redaktur"), classification="RAHASIA", owner_unit_code="DIT-PRD")
    assert r.status_code == 403


def test_cannot_upload_for_other_unit(db_base, client, login):
    r = upload(client, login("analis"), owner_unit_code="DIT-PRD")
    assert r.status_code == 403


def test_period_validation(db_base, client, login):
    r = upload(client, login("analis"), period_start="2026-11-10", period_end="2026-11-01")
    assert r.status_code == 422


def test_clearance_blocks_read_and_hides_existence(db_base, client, login):
    doc = upload(client, login("analis"), classification="RAHASIA").json()
    # redaktur clearance TERBATAS < RAHASIA
    assert client.get(f"/api/documents/{doc['id']}", headers=login("redaktur")).status_code == 404
    assert client.get(f"/api/documents/{doc['id']}/versions/1/download", headers=login("redaktur")).status_code == 404
    listing = client.get("/api/documents", headers=login("redaktur")).json()
    assert all(d["id"] != doc["id"] for d in listing["items"])


def test_rahasia_limited_to_owner_unit_until_granted(db_base, client, login):
    doc = upload(client, login("analis"), classification="RAHASIA").json()
    # analis2 punya clearance RAHASIA tetapi dari unit lain
    assert client.get(f"/api/documents/{doc['id']}", headers=login("analis2")).status_code == 404
    # pimpinan unit pemilik memberi izin berdurasi
    r = client.post(f"/api/documents/{doc['id']}/grants", headers=login("pimpinan.anl"),
                    json={"username": "analis2", "reason": "Dukungan kajian bersama", "valid_days": 7})
    assert r.status_code == 201, r.text
    assert client.get(f"/api/documents/{doc['id']}", headers=login("analis2")).status_code == 200
    # cabut izin → akses hilang
    grant_id = r.json()["grants"][0]["id"]
    client.delete(f"/api/documents/{doc['id']}/grants/{grant_id}", headers=login("pimpinan.anl"))
    assert client.get(f"/api/documents/{doc['id']}", headers=login("analis2")).status_code == 404


def test_grant_cannot_exceed_grantee_clearance(db_base, client, login):
    doc = upload(client, login("analis"), classification="RAHASIA").json()
    r = client.post(f"/api/documents/{doc['id']}/grants", headers=login("pimpinan.anl"),
                    json={"username": "redaktur", "reason": "Coba beri izin", "valid_days": 7})
    assert r.status_code == 422


def test_only_manager_can_grant(db_base, client, login):
    doc = upload(client, login("analis"), classification="RAHASIA").json()
    r = client.post(f"/api/documents/{doc['id']}/grants", headers=login("analis"),
                    json={"username": "analis2", "reason": "Tidak berwenang", "valid_days": 7})
    assert r.status_code == 403


def test_sangat_rahasia_requires_explicit_grant(db_base, client, login):
    doc = upload(client, login("pimpinan"), classification="SANGAT_RAHASIA", owner_unit_code="DEP7").json()
    assert client.get(f"/api/documents/{doc['id']}", headers=login("pimpinan")).status_code == 200
    # auditor DEP7 (RAHASIA) tidak boleh; arsiparis (RAHASIA) tidak boleh
    assert client.get(f"/api/documents/{doc['id']}", headers=login("auditor")).status_code == 404
    assert client.get(f"/api/documents/{doc['id']}", headers=login("arsiparis")).status_code == 404


def test_checkout_checkin_versioning(db_base, client, login):
    h = login("analis")
    doc = upload(client, h).json()
    # check-in tanpa check-out ditolak
    r = client.post(f"/api/documents/{doc['id']}/versions", headers=h,
                    files={"file": ("uji.txt", b"versi dua", "text/plain")})
    assert r.status_code == 409
    assert client.post(f"/api/documents/{doc['id']}/checkout", headers=h).json()["status"] == "checked_out"
    # pengguna lain dari unit sama tidak bisa check-out bersamaan
    assert client.post(f"/api/documents/{doc['id']}/checkout", headers=login("pimpinan.anl")).status_code == 409
    r = client.post(f"/api/documents/{doc['id']}/versions", headers=h, data={"note": "revisi"},
                    files={"file": ("uji.txt", b"versi dua", "text/plain")})
    assert r.status_code == 201, r.text
    body = r.json()
    assert body["status"] == "active"
    assert body["current_version"]["version_no"] == 2
    assert [v["version_no"] for v in body["versions"]] == [1, 2]
    # unduh versi 1 tetap tersedia dan utuh
    r = client.get(f"/api/documents/{doc['id']}/versions/1/download", headers=h)
    assert r.status_code == 200
    assert b"isi dokumen uji" in r.content


def test_download_is_watermarked_and_logged(db_base, client, login):
    doc = upload(client, login("analis")).json()
    r = client.get(f"/api/documents/{doc['id']}/versions/1/download", headers=login("redaktur"))
    assert r.status_code == 200
    text = r.content.decode()
    assert "[WATERMARK]" in text and "Redaktur (contoh)" in text and "NIP 19900101" in text
    wm = r.headers["X-SISTRA-Watermark"]
    assert wm in text
    # log unduhan hanya untuk pengelola
    assert client.get(f"/api/documents/{doc['id']}/downloads", headers=login("redaktur")).status_code == 403
    logs = client.get(f"/api/documents/{doc['id']}/downloads", headers=login("arsiparis")).json()
    assert logs[0]["username"] == "redaktur"
    assert f"WM-{logs[0]['watermark_id']}" == wm


def test_pdf_watermark_and_text_extraction(db_base, client, login):
    writer = PdfWriter()
    writer.add_blank_page(width=595, height=842)
    buf = io.BytesIO()
    writer.write(buf)
    r = client.post("/api/documents", headers=login("analis"), data=META,
                    files={"file": ("uji.pdf", buf.getvalue(), "application/pdf")})
    assert r.status_code == 201, r.text
    doc = r.json()
    r = client.get(f"/api/documents/{doc['id']}/versions/1/download", headers=login("analis"))
    assert r.headers["X-SISTRA-Watermark-Embedded"] == "1"
    reader = PdfReader(io.BytesIO(r.content))
    annots = reader.pages[0].get("/Annots")
    assert annots, "anotasi watermark harus ada"
    assert r.headers["X-SISTRA-Watermark"] in reader.metadata.get("/SISTRA-Watermark", "")


def test_search_by_content(db_base, client, login):
    h = login("analis")
    upload(client, h, content=b"laporan tentang operasi pasar di Surabaya", title="Laporan A")
    upload(client, h, content=b"catatan rapat koordinasi bencana", title="Laporan B")
    r = client.get("/api/documents", headers=h, params={"q": "operasi pasar"}).json()
    assert r["total"] == 1
    assert r["items"][0]["title"] == "Laporan A"
    assert "operasi pasar" in r["items"][0]["snippet"]


def test_reclassification_rules(db_base, client, login):
    doc = upload(client, login("analis")).json()
    # analis bukan pengelola → tidak boleh reklasifikasi
    assert client.patch(f"/api/documents/{doc['id']}", headers=login("analis"),
                        json={"classification": "RAHASIA"}).status_code == 403
    r = client.patch(f"/api/documents/{doc['id']}", headers=login("pimpinan.anl"), json={"classification": "RAHASIA"})
    assert r.status_code == 200
    assert r.json()["classification"] == "RAHASIA"
    assert client.get(f"/api/documents/{doc['id']}", headers=login("redaktur")).status_code == 404


def test_content_dedup_in_storage(db_base, client, login):
    h = login("analis")
    upload(client, h, content=b"isi sama persis", title="Dokumen 1")
    upload(client, h, content=b"isi sama persis", title="Dokumen 2")
    events = client.get("/api/audit?action=dokumen.unggah", headers=login("auditor")).json()
    assert [e["detail"]["dedup"] for e in sorted(events, key=lambda e: e["seq"])] == [False, True]
