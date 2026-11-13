from __future__ import annotations

import json

import pytest

from app.modules.analytics.geo import PROVINCE_NAMES, geotag
from app.modules.analytics.issues import DEFAULT_ISSUES, KeywordIssueClassifier
from app.modules.analytics.sentiment import default_analyzer
from app.modules.ingest import dedup
from app.modules.ingest.parsers import ParseError, parse_any, parse_feed

RSS = """<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel><title>Contoh</title>
<item><title>Harga beras di Surabaya melonjak, warga kecewa</title>
<link>https://contoh.invalid/a</link><guid>a-1</guid>
<description><![CDATA[<p>Harga beras di <b>Surabaya</b> naik tajam. Warga mengeluhkan kondisi yang sangat membebani.</p>]]></description>
<pubDate>Tue, 10 Nov 2026 08:00:00 +0700</pubDate><dc:creator>Redaksi</dc:creator></item>
<item><title>Vaksinasi anak di Makassar berjalan lancar</title>
<link>https://contoh.invalid/b</link><guid>b-1</guid>
<description>Puskesmas di Makassar menyelenggarakan vaksinasi dan warga merasa terbantu.</description>
<pubDate>Tue, 10 Nov 2026 09:00:00 +0700</pubDate></item>
</channel></rss>"""

ATOM = """<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom"><title>Atom</title>
<entry><title>Banjir di Samarinda</title><id>urn:x:1</id><link href="https://contoh.invalid/c"/>
<updated>2026-11-10T03:00:00Z</updated><summary>Banjir merendam permukiman di Samarinda.</summary>
<author><name>Penulis</name></author></entry></feed>"""


# ---------- unit ----------

def test_sentiment_negation_and_intensifier():
    assert default_analyzer.analyze("Pelayanan sangat buruk dan warga kecewa").label == "negatif"
    assert default_analyzer.analyze("Program berhasil, warga puas").label == "positif"
    assert default_analyzer.analyze("Kondisi tidak aman").label == "negatif"
    assert default_analyzer.analyze("Rapat digelar di kantor").label == "netral"


def test_geotag_prefers_longest_alias_and_is_case_sensitive():
    g = geotag("Bantuan tiba di Papua Barat Daya dan Kepulauan Riau; di medan perang tidak ada.")
    assert g.provinces == ["96", "21"] or set(g.provinces) == {"96", "21"}
    assert "12" not in g.provinces  # 'medan' huruf kecil bukan Kota Medan
    assert geotag("Warga Medan dan Surabaya").provinces == ["12", "35"] or set(geotag("Warga Medan dan Surabaya").provinces) == {"12", "35"}


def test_gazetteer_has_38_provinces():
    assert len(PROVINCE_NAMES) == 38


def test_issue_classifier():
    clf = KeywordIssueClassifier({i["code"]: i["keywords"] for i in DEFAULT_ISSUES})
    codes = [m.code for m in clf.classify("Operasi pasar beras digelar Bulog karena harga beras naik")]
    assert codes[0] == "EKO-PANGAN"
    assert clf.classify("Cuaca cerah hari ini") == []


def test_near_duplicate_similarity():
    body = ("Harga beras medium di pasar tradisional Surabaya tercatat naik dalam tiga hari terakhir menurut "
            "pantauan petugas di lapangan hari ini dan warga mengeluhkan kondisi tersebut.")
    a = dedup.normalize("Harga beras naik", body)
    b = dedup.normalize("Harga beras naik!", body + " (salinan portal lain)")
    c = dedup.normalize("Banjir", "Banjir besar merendam ratusan rumah warga di Samarinda sejak pagi hari ini.")
    assert dedup.jaccard(dedup.shingles(a), dedup.shingles(b)) >= dedup.JACCARD_THRESHOLD
    assert dedup.jaccard(dedup.shingles(a), dedup.shingles(c)) < 0.2
    assert dedup.hamming(dedup.simhash(a), dedup.simhash(b)) <= dedup.SIMHASH_PREFILTER
    assert dedup.hamming(dedup.simhash(a), dedup.simhash(a)) == 0


def test_parse_rss_and_atom():
    rss = parse_feed(RSS.encode())
    assert len(rss) == 2
    assert rss[0].title.startswith("Harga beras")
    assert "<b>" not in rss[0].body and "Surabaya" in rss[0].body
    assert rss[0].published_at.isoformat() == "2026-11-10T01:00:00+00:00"
    atom = parse_any(ATOM.encode())
    assert atom[0].url == "https://contoh.invalid/c" and atom[0].author == "Penulis"


def test_rejects_doctype_entities():
    evil = b'<?xml version="1.0"?><!DOCTYPE r [<!ENTITY a "aaaa">]><rss><channel><item><title>&a;</title></item></channel></rss>'
    with pytest.raises(ParseError):
        parse_feed(evil)


# ---------- API ----------

def _source(client, headers) -> int:
    r = client.post("/api/sources", headers=headers, json={"type": "news", "name": "Portal Uji", "reliability": "B"})
    assert r.status_code == 201, r.text
    return r.json()["id"]


def test_ingest_rss_enriches_and_dedups(db_base, client, login):
    h = login("analis")
    sid = _source(client, h)
    r = client.post("/api/ingest/upload", headers=h, data={"source_id": sid},
                    files={"file": ("feed.xml", RSS.encode(), "application/xml")})
    assert r.status_code == 200, r.text
    assert r.json()["inserted"] == 2
    # unggah ulang → semua duplikat tepat
    r = client.post("/api/ingest/upload", headers=h, data={"source_id": sid},
                    files={"file": ("feed.xml", RSS.encode(), "application/xml")})
    assert r.json()["inserted"] == 0 and r.json()["exact_duplicates"] == 2

    items = client.get("/api/content", headers=h, params={"q": "beras"}).json()["items"]
    assert len(items) == 1
    it = items[0]
    assert it["sentiment_label"] == "negatif"
    assert it["province_code"] == "35"
    assert any(i["code"] == "EKO-PANGAN" for i in it["issues"])

    vaks = client.get("/api/content", headers=h, params={"issue": "KES-PUBLIK"}).json()["items"]
    assert vaks[0]["sentiment_label"] == "positif" and vaks[0]["province_code"] == "73"


def test_ingest_json_and_near_duplicate(db_base, client, login):
    h = login("analis")
    sid = _source(client, h)
    body = ("Harga beras medium di pasar tradisional Surabaya tercatat naik dalam tiga hari terakhir menurut "
            "pantauan petugas di lapangan hari ini dan warga mengeluhkan kondisi tersebut.")
    payload = [
        {"id": "j1", "title": "Harga beras naik", "body": body, "published_at": "2026-11-10T01:00:00Z"},
        {"id": "j2", "title": "Harga beras naik!", "body": body + " (salinan portal lain)", "published_at": "2026-11-10T02:00:00Z"},
    ]
    r = client.post("/api/ingest/upload", headers=h, data={"source_id": sid},
                    files={"file": ("data.json", json.dumps(payload).encode(), "application/json")})
    assert r.status_code == 200, r.text
    assert r.json()["inserted"] == 1 and r.json()["near_duplicates"] == 1
    listing = client.get("/api/content", headers=h).json()
    assert listing["total"] == 1
    assert client.get("/api/content", headers=h, params={"include_duplicates": True}).json()["total"] == 2


def test_ingest_requires_analyst(db_base, client, login):
    r = client.post("/api/ingest/upload", headers=login("redaktur"), data={"source_id": 1},
                    files={"file": ("f.xml", RSS.encode(), "application/xml")})
    assert r.status_code == 403


def test_fetch_blocks_internal_urls(db_base, client, login):
    h = login("analis")
    r = client.post("/api/sources", headers=h, json={"type": "news", "name": "Internal", "url": "http://127.0.0.1/feed"})
    sid = r.json()["id"]
    r = client.post(f"/api/ingest/fetch/{sid}", headers=h)
    assert r.status_code == 422
    assert "internal" in r.json()["detail"]


def test_analyst_correction_is_recorded(db_base, client, login):
    h = login("analis")
    sid = _source(client, h)
    client.post("/api/ingest/upload", headers=h, data={"source_id": sid},
                files={"file": ("feed.xml", RSS.encode(), "application/xml")})
    cid = client.get("/api/content", headers=h, params={"q": "beras"}).json()["items"][0]["id"]
    r = client.put(f"/api/content/{cid}/issues", headers=h, json={"issue_codes": ["EKO-PANGAN", "DIS-HOAKS"]})
    assert r.status_code == 200
    assert {i["code"] for i in r.json()["issues"]} == {"EKO-PANGAN", "DIS-HOAKS"}
    assert all(i["assigned_by"] == "analyst" for i in r.json()["issues"])
    assert client.put(f"/api/content/{cid}/issues", headers=h, json={"issue_codes": ["TIDAK-ADA"]}).status_code == 422
    assert client.put(f"/api/content/{cid}/issues", headers=login("redaktur"), json={"issue_codes": []}).status_code == 403
