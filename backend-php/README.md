# SISTRA — backend PHP

Port backend FastAPI (`../backend`) ke PHP 8.3 + SQLite untuk hosting cPanel yang hanya menyediakan PHP. Kontrak `/api` identik, jadi frontend React yang sama dapat dipakai tanpa perubahan.

| Berkas | Isi |
|---|---|
| `src/bootstrap.php` | konfigurasi, PDO SQLite (WAL), skema + pemicu WORM, JWT HS256, ABAC dasar, router |
| `src/text.php` | sentimen leksikon, geotag 38 provinsi, klasifikasi isu, dedup SHA-256/SimHash/Jaccard, parser RSS/Atom/JSON (DOCTYPE/ENTITY ditolak) |
| `src/services.php` | audit berantai hash, penyerapan + pengaman SSRF, peringatan berjenjang, alur persetujuan bertanda tangan HMAC, ACL repositori, penyimpanan berbasis hash, watermark |
| `src/routes.php` | seluruh endpoint |
| `src/seed.php` | data dasar, data demo sintetis, `auto_seed` (kredensial acak ke `kredensial.txt` 0600) |
| `src/app.php` | titik masuk; satu transaksi `BEGIN IMMEDIATE` per permintaan tulis |
| `src/ai.php` | integrasi Claude lewat Anthropic PHP SDK resmi (keluaran JSON terstruktur, penanganan penolakan/galat), mode simulasi bila kunci API belum diisi |
| `src/ai_install.php` | pemasang pustaka SDK di server tanpa shell: unduh paket pada commit yang dikunci `composer.lock`, berkas runtime Composer diverifikasi SHA-256 |
| `src/m47.php` | M4 Produksi Narasi, M5 Editorial & Persetujuan, M6 Kanal Medsos Resmi (manual), M7 Distribusi 39 Kanal, dashboard eksekutif & analitik |
| `src/seed_m47.php` | data demo sintetis M4–M7 bertema isu aktual (pangan, MBG, banjir, judol, hoaks bansos, Nataru, PDP, dll.) |
| `vendor-meta/` | berkas autoload hasil `composer dump-autoload`, disalin pemasang ke `vendor/` paling akhir |
| `public/api.php`, `public/router-dev.php` | front controller dan router untuk `php -S` |
| `bin/sistra-cli.php` | `reset` / `verify` untuk pengembangan dan uji (bukan untuk produksi) |

## Menjalankan lokal

```bash
cd backend-php/public
SISTRA_DATA_DIR=/tmp/sistra-php SISTRA_DEV_PASSWORD=sandi-lokal php -S 127.0.0.1:8099 router-dev.php
# buka http://127.0.0.1:8099 (frontend dari ../../frontend/dist)
```

Lokal: `composer install` untuk memasang SDK Claude. Tanpa `vendor/`, fitur AI tetap berjalan dalam mode simulasi.

`SISTRA_DEV_PASSWORD` hanya untuk lokal; tanpa variabel ini setiap akun mendapat kata sandi acak di `kredensial.txt`.

## Uji kontrak

Suite uji backend Python disalin ke `tests/` dan dijalankan lewat HTTP terhadap server PHP:

```bash
SISTRA_DATA_DIR=/tmp/sistra-php PYTHONPATH=../backend ../backend/.venv/bin/python -m pytest tests
```

Hasil saat ini: 62 lulus (termasuk `tests/test_m47.py` untuk M4–M7, dashboard, dan AI). Pemasangan di hosting: lihat `deploy/README.md` bagian C.
