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
| `public/api.php`, `public/router-dev.php` | front controller dan router untuk `php -S` |
| `bin/sistra-cli.php` | `reset` / `verify` untuk pengembangan dan uji (bukan untuk produksi) |

## Menjalankan lokal

```bash
cd backend-php/public
SISTRA_DATA_DIR=/tmp/sistra-php SISTRA_DEV_PASSWORD=sandi-lokal php -S 127.0.0.1:8099 router-dev.php
# buka http://127.0.0.1:8099 (frontend dari ../../frontend/dist)
```

`SISTRA_DEV_PASSWORD` hanya untuk lokal; tanpa variabel ini setiap akun mendapat kata sandi acak di `kredensial.txt`.

## Uji kontrak

Suite uji backend Python disalin ke `tests/` dan dijalankan lewat HTTP terhadap server PHP:

```bash
SISTRA_DATA_DIR=/tmp/sistra-php PYTHONPATH=../backend ../backend/.venv/bin/python -m pytest tests
```

Hasil saat ini: 53 lulus. Pemasangan di hosting: lihat `deploy/README.md` bagian C.
