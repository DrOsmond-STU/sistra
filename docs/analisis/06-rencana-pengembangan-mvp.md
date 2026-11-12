# 06 — Rencana Pengembangan & Lingkup MVP

---

## 1. Strategi Penyerahan

| Tahap | Waktu | Tujuan | Keluaran |
|---|---|---|---|
| **MVP** | Sebelum/awal kontrak (±4 minggu) | Memvalidasi alur inti hulu–hilir bersama pengguna tanpa perangkat keras GPU | Aplikasi berjalan di satu server/laptop (Docker Compose); 17 fitur dasar |
| **F1** | Bulan 1–6 (180 hari kontrak) | Infrastruktur terpasang; fondasi platform; 40 fitur wajib siap produksi | *Go-live* terbatas; UAT F1; pentest |
| **F2** | Bulan 7–12 (masa pemeliharaan) | 25 fitur lanjutan | UAT per *release* triwulanan |
| **F3** | Bulan 13–18 | 8 fitur tambahan (ABM, multimedia, anonimisasi) | UAT; optimasi |
| Operasi | s.d. bulan 36 | SLA 99,95%, pembaruan model, dan dukungan 24/7 | Laporan SLA bulanan |

> Bila KAK tetap mensyaratkan 75 fitur selesai dalam 180 hari, rekomendasinya adalah kontrak tahun jamak atau pembayaran per *milestone* fitur (lihat [01-telaah-kak.md](01-telaah-kak.md) J-01).

---

## 2. Lingkup MVP

### 2.1 Tujuan
Membuktikan **siklus tertutup minimal**:

> berita masuk → dibersihkan → diperkaya (sentimen, provinsi, isu) → dapat dicari → memicu peringatan → dikaji/disimpan di repositori berklasifikasi → konten tanggapan disetujui berjenjang → seluruh aksi tercatat di audit berantai hash.

### 2.2 Fitur dalam MVP (17)

| Fitur | Implementasi MVP | Ditingkatkan pada F1 menjadi |
|---|---|---|
| S01 Penyerapan berita | Pembaca RSS/Atom + impor JSON/CSV | *Crawler* > 700 sumber; Kafka |
| S03 Pembersihan & dedup | Normalisasi teks/waktu; dedup hash isi + SimHash | MinHash LSH terdistribusi |
| S07 Entitas & geolokasi | Gazetteer 38 provinsi + ibu kota/kab-kota utama | Model NER + *entity linking* |
| S08 Sentimen | Leksikon Bahasa Indonesia + aturan negasi (antarmuka *pluggable*) | Model encoder *fine-tuned* |
| S14 Taksonomi isu | Klasifikasi berbasis kata kunci per isu + koreksi analis | Model klasifikasi + pelatihan ulang |
| S15 Pencarian arsip | PostgreSQL *full-text search* (bahasa sederhana) | OpenSearch *hybrid* |
| S21 Peringatan berjenjang | Aturan ambang volume dan rasio negatif per isu → level H/K/O/M; tampil di dashboard | Notifikasi *mobile*/SMS, ambang dinamis |
| S34 Kalender | Tanggal rencana tayang per draf dan kanal | Kalender penuh multi-kanal |
| S36 Persetujuan berjenjang | *State machine* Draf→Ditinjau→Disetujui→Tayang→Ditarik dengan aturan peran | Temporal + tanda tangan HSM |
| S60 Dashboard | Ringkasan isu, sentimen, peringatan, sebaran provinsi | *Streaming real-time*, peta, multi-monitor |
| S65 Audit WORM | Tabel *append-only* berantai hash + endpoint verifikasi | Object lock + jangkar HSM |
| S66 Lumbung data | Penyimpanan berkas berbasis hash isi (dedup) | Object storage S3 |
| S67 Siklus dokumen | Versi, *check-in*/*check-out*, SHA-256 | — |
| S68 Metadata wajib | Validasi skema saat unggah | Katalog data |
| S70 Pencarian dokumen | FTS pada metadata dan isi teks | *Dense retrieval* |
| S71 Klasifikasi & watermark | 4 tingkat klasifikasi; watermark teks pada unduhan (nama, NIP, IP, waktu) | Watermark PDF per halaman, enkripsi HSM |
| S72 RBAC/ABAC | Peran + clearance + unit + izin berdurasi | Keycloak + OPA |

### 2.3 Di Luar Lingkup MVP
GPU/LLM, media sosial (karena butuh kontrak data), siaran TV/radio, Modul 4, Modul 6, Modul 7, SSO/HSM nyata, dan *high availability*. Antarmuka AI dibuat *pluggable* agar model nyata dapat dipasang pada F1 tanpa mengubah alur.

### 2.4 Teknologi MVP

| Komponen | Pilihan |
|---|---|
| Backend | Python 3.12, FastAPI, SQLAlchemy 2, Pydantic 2 |
| Basis data | PostgreSQL 16 (produksi/dev); SQLite untuk uji unit cepat |
| Frontend | React + TypeScript (Vite) |
| Autentikasi | JWT lokal (digantikan OIDC Keycloak pada F1) |
| Pengemasan | Docker Compose |
| Uji | pytest (unit + API); target cakupan ≥ 70% pada modul inti |

Struktur repositori (*modular monolith*, batas modul = calon layanan F1):

```
sistra/
├─ docs/analisis/           # dokumen ini
├─ backend/
│  ├─ app/
│  │  ├─ core/              # config, db, security, audit
│  │  ├─ modules/
│  │  │  ├─ ingest/         # M1: S01, S03
│  │  │  ├─ analytics/      # M1: S07, S08, S14, S15
│  │  │  ├─ alerts/         # M2: S21
│  │  │  ├─ workflow/       # M5: S34, S36
│  │  │  ├─ repository/     # M9: S66–S68, S70–S72
│  │  │  └─ dashboard/      # M8: S60
│  │  └─ main.py
│  └─ tests/
├─ frontend/
└─ docker-compose.yml
```

### 2.5 Rencana Kerja MVP

| Minggu | Pekerjaan | Kriteria selesai |
|---|---|---|
| 1 | Fondasi: struktur proyek, DB, autentikasi & RBAC/ABAC, audit berantai hash | Uji ABAC dan verifikasi rantai audit lulus |
| 2 | Modul 9 repositori (unggah, metadata wajib, versi, *check-in/out*, SHA-256, watermark, pencarian) | Uji negatif akses klasifikasi lulus |
| 3 | Modul 1 (RSS/impor, dedup, sentimen, provinsi, isu, pencarian) + Modul 2 peringatan | Impor 1.000 artikel contoh; peringatan terpicu sesuai aturan |
| 4 | Modul 5 persetujuan + kalender; dashboard frontend; Docker Compose; dokumentasi | Demo alur hulu–hilir lengkap |

### 2.6 Kriteria Penerimaan MVP
1. Alur demonstrasi §2.1 berjalan dari awal sampai akhir dengan data contoh.
2. Pengguna dengan clearance lebih rendah **tidak dapat** membaca atau mengunduh dokumen berklasifikasi lebih tinggi (uji otomatis).
3. Penulis **tidak dapat** menyetujui naskahnya sendiri, dan transisi status tidak sah ditolak (uji otomatis).
4. Perubahan satu baris log audit terdeteksi oleh endpoint verifikasi.
5. Seluruh uji otomatis lulus; aplikasi berjalan dengan `docker compose up`.

---

## 3. Rencana Kerja F1 (180 Hari)

| Alur kerja | B1 | B2 | B3 | B4 | B5 | B6 |
|---|---|---|---|---|---|---|
| Analisis rinci & desain (SRS final, survei lokasi) | ■ | ■ | | | | |
| Pengadaan & pengiriman perangkat keras | ■ | ■ | ■ | | | |
| Instalasi pusat data, jaringan, dan keamanan | | | ■ | ■ | | |
| Platform (K8s, Kafka, DB, OpenSearch, Keycloak, CI/CD) | | ■ | ■ | ■ | | |
| Pengembangan modul F1 (di *staging*) | | ■ | ■ | ■ | ■ | |
| Anotasi data & *fine-tuning* model | | ■ | ■ | ■ | ■ | |
| Integrasi sumber data | | | ■ | ■ | ■ | |
| Uji fungsi, beban, pentest, dan *failover* | | | | | ■ | ■ |
| Pelatihan pengguna & TOT | | | | | ■ | ■ |
| UAT & serah terima F1 | | | | | | ■ |

## 4. Perkiraan Tim Penyedia (F1)

| Peran | Jumlah |
|---|---|
| Project Manager | 1 |
| Solution/Enterprise Architect | 1 |
| Backend Engineer | 5 |
| Frontend Engineer | 3 |
| Data Engineer | 3 |
| ML/NLP Engineer | 4 |
| DevOps/SRE | 2 |
| Security Engineer | 2 |
| QA/Test Engineer | 3 |
| Infrastruktur DC & Jaringan | 3 |
| UI/UX Designer | 1 |
| Technical Writer/Trainer | 1 |
| **Total** | **±29 orang** |
