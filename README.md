# SISTRA — Sistem Informasi dan Aksi Strategis

Repositori pengembangan SISTRA: dokumen analisis sistem dan **MVP** yang memvalidasi alur inti hulu–hilir.

- 📄 Dokumen analisis: [`docs/analisis/`](docs/analisis/README.md) — telaah KAK, SRS 75 fitur, arsitektur, model data, risiko, rencana pengembangan
- 🧪 MVP: 17 fitur dasar dari Modul 1, 2, 5, 8, dan 9 (rincian di bawah)

> Seluruh data contoh dalam repositori ini **sintetis** (dibuat program) dan bukan data nyata.

---

## Alur yang dibuktikan MVP

```
berita masuk (RSS/Atom/JSON) → normalisasi & deduplikasi → sentimen · provinsi · isu
   → pencarian → peringatan berjenjang (Hijau/Kuning/Oranye/Merah) → kajian di repositori berklasifikasi
   → naskah tanggapan → persetujuan berjenjang bertanda tangan → jadwal distribusi
   → seluruh aksi tercatat di jejak audit berantai hash (WORM)
```

| Modul | Fitur MVP | Implementasi |
|---|---|---|
| M1 Mesin Data | S01, S03, S07, S08, S14, S15 | Impor RSS/Atom/JSON dan ambil feed (dengan perlindungan SSRF); dedup SHA-256 + SimHash/Jaccard; gazetteer 38 provinsi; leksikon sentimen dengan negasi; klasifikasi isu + koreksi analis; pencarian teks |
| M2 Peringatan Dini | S21 (+ dasar S22) | Ambang volume, pertumbuhan, dan rasio negatif → 4 tingkat; eskalasi otomatis; siklus terima → tangani → selesai |
| M5 Editorial | S34, S36 | Mesin status draf → ditinjau → disetujui → tayang → ditarik; penulis tak dapat menyetujui naskahnya sendiri; tanda tangan atas hash isi; penayangan ditolak bila isi berubah setelah disetujui; label konten AI; kalender dengan deteksi bentrok |
| M8 Dashboard | S60, S65 | Dashboard situasi; jejak audit append-only berantai SHA-256 dengan pemicu DB + verifikasi |
| M9 Repositori | S66–S68, S70–S72 | Penyimpanan berbasis hash (dedup); versi + check-in/out + verifikasi SHA-256; metadata wajib; 4 tingkat klasifikasi; ABAC (clearance + unit + izin berdurasi); watermark dinamis per unduhan (teks & PDF); log unduhan |

Komponen AI (sentimen, geotag, klasifikasi isu) dirancang **pluggable**. Pada F1 komponen ini diganti model encoder/LLM *on-premise* tanpa mengubah alur.

---

## Menjalankan

### Opsi A — Docker Compose (disarankan)

```bash
cp .env.example .env          # lalu ganti semua nilai rahasia
docker compose up --build
```

- Aplikasi: http://localhost:8080
- Dokumentasi API (OpenAPI): http://localhost:8080/api/docs

### Opsi B — Pengembangan lokal

```bash
# Backend (Python 3.12+)
cd backend
python -m venv .venv && . .venv/bin/activate
pip install -r requirements-dev.txt
python -m app.seed                 # SQLite di ./data; set SISTRA_DATABASE_URL untuk PostgreSQL
uvicorn app.main:app --reload --port 8000

# Frontend (Node 20+), terminal lain
cd frontend
npm install
npm run dev                        # http://localhost:5173 (proxy /api → :8000)
```

### Akun demo

Kata sandi: nilai `SISTRA_SEED_PASSWORD` (bawaan `sistra-demo-2027`). **Ganti sebelum dipakai di luar demo.**

| Username | Peran | Unit | Clearance | Coba |
|---|---|---|---|---|
| `pimpinan` | Pimpinan | DEP7 | Sangat Rahasia | Dashboard, setujui naskah, nota dinas sangat rahasia |
| `pimpinan.anl` | Pimpinan | DIT-ANL | Rahasia | Beri izin akses dokumen Rahasia milik unitnya |
| `analis` | Analis | DIT-ANL | Rahasia | Impor feed, koreksi isu, tindak lanjut peringatan |
| `analis2` | Analis | DIT-PRD | Rahasia | Tidak melihat dokumen Rahasia unit lain tanpa izin |
| `redaktur` / `produser` | Redaktur / Produser | DIT-PRD | Terbatas | Menulis dan mengajukan naskah |
| `operator` | Operator Kanal | DIT-KNL | Terbatas | Menayangkan naskah yang disetujui |
| `arsiparis` | Arsiparis | BAG-ARS | Rahasia | Reklasifikasi, izin, dan log unduhan |
| `auditor` | Auditor | DEP7 | Rahasia | Jejak audit dan verifikasi rantai hash |
| `admin` | Administrator | BAG-TI | Terbatas | Manajemen pengguna (tanpa akses dokumen rahasia) |

### Skenario demo (±10 menit)

1. Masuk sebagai **pimpinan** → dashboard menampilkan isu *Harga Pangan* **Merah**, *Disinformasi* **Oranye**, *Bencana* **Kuning**.
2. Masuk sebagai **analis** → *Sumber & Impor* → impor `backend/sample_data/contoh_feed.xml` atau `contoh_medsos.json`; lihat hasil dedup dan peringatan.
3. *Peringatan Dini* → terima dan tangani peringatan Merah. *Konten & Isu* → koreksi klasifikasi isu.
4. **redaktur** membuat naskah → ajukan; **pimpinan** menyetujui (tanda tangan); **operator** menayangkan.
5. *Repositori* → unggah dokumen tanpa metadata (ditolak); unduh dokumen (watermark nama, NIP, IP, waktu).
6. **analis2** tidak melihat dokumen Rahasia DIT-ANL → **pimpinan.anl** memberi izin 7 hari → kini terlihat.
7. **auditor** → *Jejak Audit* → *Verifikasi integritas rantai*.

---

## Pengujian

```bash
cd backend
pytest                                    # SQLite
SISTRA_DATABASE_URL="postgresql+psycopg://user:pass@host/db" pytest   # PostgreSQL
pytest --cov=app                          # cakupan
```

Hasil saat ini: **53 uji lulus** di SQLite dan PostgreSQL 16, cakupan **90%**. Uji mencakup kriteria penerimaan MVP:
- akses melebihi clearance ditolak;
- penulis tidak dapat menyetujui naskahnya sendiri;
- isi yang diubah setelah disetujui tidak dapat ditayangkan;
- perubahan atau penghapusan baris audit terdeteksi;
- metadata wajib ditegakkan;
- unggahan duplikat dikenali;
- impor feed ber-DOCTYPE/XXE ditolak.

---

## Struktur

```
backend/app/
  core/            konfigurasi, DB, keamanan (JWT, peran), audit berantai hash
  modules/
    identity/      pengguna, unit, login
    ingest/        sumber, parser RSS/Atom/JSON, dedup, alur penyerapan
    analytics/     sentimen, geotag 38 provinsi, taksonomi isu, konten
    alerts/        peringatan berjenjang & siklus hidup
    workflow/      naskah, persetujuan bertanda tangan, kalender, kanal
    repository/    dokumen, versi, ABAC, watermark, izin, log unduhan
    dashboard/     ringkasan situasi & API audit
  seed.py          data dasar + contoh sintetis
frontend/src/      React + TypeScript (Vite)
docs/analisis/     dokumen analisis sistem
```

## Batasan MVP & langkah F1

| Area | MVP | F1 (produksi) |
|---|---|---|
| Identitas | JWT lokal | SSO OIDC/SAML (Keycloak), MFA FIDO2, PAM |
| Kunci & tanda tangan | HMAC dengan kunci di variabel lingkungan | HSM FIPS 140-3 (PKCS#11) |
| Audit | Tabel append-only + rantai hash | + *object lock* WORM + jangkar hash harian bertanda tangan HSM (mendeteksi pemotongan ekor log) |
| Penyimpanan berkas | Sistem berkas lokal | Object storage S3 dengan *object lock* |
| Pencarian | PostgreSQL FTS / LIKE | OpenSearch *hybrid* (BM25 + vektor) |
| AI | Leksikon & kata kunci | Model encoder (sentimen, isu, NER), LLM *on-premise*, Graph RAG |
| Penyerapan | RSS/JSON sinkron | Kafka + konektor jalur resmi/agregator berlisensi, siaran TV/radio, OCR cetak |
| Skema DB | `create_all` | Migrasi Alembic |
| Otorisasi | Aturan di kode | Open Policy Agent (kebijakan sebagai kode) |
| Peringatan | Ambang global; eskalasi saat evaluasi | Ambang dinamis per wilayah/tokoh/topik; eskalasi waktu (Merah 15 menit); notifikasi *mobile*/SMS |

Fitur S46 dan S47 (operasi akun samaran yang menghindari deteksi platform) **tidak dibangun**. Lihat [telaah KAK §4](docs/analisis/01-telaah-kak.md#4-usulan-pengganti-untuk-modul-6-poem).
