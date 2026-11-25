# Panduan Pemasangan SISTRA (MVP)

Ada dua cara pemasangan:

| Cara | Cocok untuk | Komponen |
|---|---|---|
| **A. Docker Compose** | VPS/server sendiri | PostgreSQL + API + nginx (lihat README utama) |
| **B. Hosting cPanel** | Shared hosting dengan *Setup Python App* (CloudLinux) | Satu aplikasi Python: API dan frontend dalam satu proses (Passenger) |

> MVP berisi **data sintetis**. Jangan memasukkan data nyata ke hosting bersama (*shared hosting*). Data produksi wajib berada di pusat data *on-premise* sesuai prinsip kedaulatan data.

---

## B. Hosting cPanel — sistra.<domain>

### 1. Siapkan paket (di komputer pengembang)

```bash
deploy/build-bundle.sh        # → dist/sistra-bundle.tar.gz
```

### 2. Di cPanel

1. **Subdomain**: buat `sistra.<domain>`. Root dokumen boleh dibiarkan bawaan; aplikasi Python akan mengambil alih URL-nya.
2. **PostgreSQL Databases**: buat basis data dan pengguna, lalu beri hak penuh. Jika PostgreSQL tidak tersedia, MVP dapat memakai SQLite: kosongkan `SISTRA_DATABASE_URL`.
3. **File Manager**: unggah `sistra-bundle.tar.gz` ke `~/sistra-app` lalu ekstrak. Isi folder `sistra-bundle/` dipindahkan ke `~/sistra-app`.
4. **Setup Python App** → *Create Application*:
   - Python version: 3.11 atau lebih baru
   - Application root: `sistra-app`
   - Application URL: `sistra.<domain>`
   - Application startup file: `passenger_wsgi.py`
   - Application entry point: `application`
5. Tambahkan **Environment variables** (buat nilai acak dengan `python3 -c "import secrets; print(secrets.token_urlsafe(48))"`):

   | Variabel | Nilai |
   |---|---|
   | `SISTRA_ENV` | `production` (aplikasi menolak berjalan tanpa kunci rahasia) |
   | `SISTRA_SECRET_KEY` | string acak panjang |
   | `SISTRA_SIGNING_KEY` | string acak panjang lain |
   | `SISTRA_DATABASE_URL` | `postgresql+psycopg://USER:PASS@localhost:5432/DBNAME` |
   | `SISTRA_DATA_DIR` | `/home/<user>/sistra-data` (di luar `public_html`) |
   | `SISTRA_STATIC_DIR` | `/home/<user>/sistra-app/web` |
   | `SISTRA_CORS_ORIGINS` | `https://sistra.<domain>` |

6. Pada halaman aplikasi, jalankan **Run Pip Install** dengan `requirements.txt`.
7. Isi data awal dengan kata sandi acak per akun. Jalankan sekali melalui *Execute python script* atau terminal, di dalam virtualenv aplikasi:

   ```bash
   cd ~/sistra-app && python -m app.seed --random-passwords ~/sistra-data/kredensial.txt
   ```

   Ambil berkas `kredensial.txt` (izin 0600), simpan di pengelola kata sandi, lalu **hapus dari server**.
8. **Restart** aplikasi, lalu aktifkan **SSL/TLS Status → AutoSSL** untuk subdomain.
9. (Disarankan untuk demo publik) **Directory Privacy** pada subdomain sebagai lapis login tambahan.

### 3. Verifikasi

- `https://sistra.<domain>/api/health` → `{"status":"ok"}`
- Login dengan salah satu akun dari `kredensial.txt`.
- Login sebagai `auditor` → *Jejak Audit* → *Verifikasi integritas rantai* → "Rantai utuh".

### Pembaruan versi

Bangun ulang paket, timpa `app/` dan `web/`, jalankan *Run Pip Install* bila `requirements` berubah, lalu *Restart*. Data di `SISTRA_DATA_DIR` dan basis data tidak tersentuh.

---

## C. Hosting cPanel tanpa Python — backend PHP (dipakai untuk sistra.semestateknologiutama.com)

Paket hosting semesta hanya menyediakan PHP. Karena itu backend ditulis ulang ke **PHP 8.3 + SQLite** di [`backend-php/`](../backend-php/). Kontraknya identik dengan backend FastAPI, jadi frontend tidak berubah. Seluruh aplikasi berjalan di satu akun hosting tanpa layanan pihak lain.

```
/home/<user>/
├── sistra-api/src/            kode aplikasi (di luar root dokumen)
├── sistra-data/  (0700)       sistra.db, blobs/, secrets.json, kredensial.txt (0600) — dibuat otomatis
├── sistra-prototipe/          prototipe.html (dikirim oleh gerbang login)
└── sistra.<domain>/           root dokumen
    ├── index.html, assets/    hasil `frontend/dist`
    ├── api.php                front controller  ← deploy/php/api.php
    ├── .htaccess              /api/* → api.php, selain itu SPA  ← deploy/php/.htaccess
    ├── .user.ini              batas unggah 55 MB  ← deploy/php/.user.ini
    └── prototipe/index.php    gerbang login purwarupa  ← deploy/prototipe/
```

Langkah:

1. Unggah `backend-php/src/` ke `~/sistra-api/src/`.
2. Salin isi `deploy/php/` dan hasil `frontend/dist` ke root dokumen subdomain.
3. Buka `https://sistra.<domain>/api/health`. Permintaan pertama membuat skema, kunci rahasia, data demo sintetis, dan kata sandi acak per akun di `~/sistra-data/kredensial.txt` (izin 0600).
4. Ambil `kredensial.txt` lewat File Manager cPanel, simpan di pengelola kata sandi, lalu **hapus dari server**.
5. Aktifkan AutoSSL untuk subdomain.

Catatan:

- Set `SISTRA_SEED_DEMO=0` (variabel lingkungan) bila tidak ingin data demo.
- Respons API mengirim `Cache-Control: no-store` dan `X-Accel-Expires: 0` agar tidak disimpan cache tepi hosting (terverifikasi `x-cache-status: BYPASS`). Nama aset frontend diberi akhiran versi (`.r2`) di `vite.config.ts`.
- Watermark unduhan: berkas teks diberi header/footer; PDF diberi anotasi di setiap halaman dan metadata `/SISTRA-Watermark` (pembaruan inkremental). Format lain dicatat di log unduhan.
- **Jangan** pakai *Directory Privacy* (HTTP Basic) di depan aplikasi: header `Authorization`-nya bertabrakan dengan token login SISTRA.
- Uji kontrak: suite uji backend Python dijalankan terhadap server PHP (`php -S`), lihat [`backend-php/README.md`](../backend-php/README.md).

### C.1 Pembaruan M4–M7 + AI (tanpa shell)

1. Klon branch rilis ke folder **di luar** root dokumen (cPanel *Git Version Control*, misalnya `~/sistra-src`).
2. Salin `backend-php/src/*` → `~/sistra-api/src/` dan `backend-php/vendor-meta/` → `~/sistra-api/vendor-meta/`.
3. Ekstrak `deploy/release/sistra-web.tar.bz2` (cek `SHA256SUMS`) ke root dokumen subdomain.
4. Permintaan pertama menjalankan migrasi M4–M7 dan katalog KAK S26–S65 beserta data demo sintetis secara otomatis (penanda `~/sistra-data/.m47-v1` dan `.kak-v1`).
5. Masuk sebagai admin → **Pengaturan AI** → *Pasang pustaka Claude* (server mengunduh Anthropic PHP SDK pada commit yang dikunci), lalu isi kunci API. Kunci disimpan di `~/sistra-data/ai.json` (0600) dan tidak pernah dikirim balik ke peramban. Tanpa kunci, AI berjalan dalam mode simulasi.
6. Hapus klon sementara bila tidak dipakai lagi.

### C.2 Lupa kata sandi akun bawaan (tanpa shell)

1. cPanel → File Manager → folder `~/sistra-data/` → buat berkas kosong bernama `RESET-KATA-SANDI`.
2. Buka situs SISTRA sekali (halaman apa saja). Server membuat kata sandi acak baru untuk semua akun bawaan, menulisnya ke `~/sistra-data/kredensial.txt` (izin 0600), mengakhiri semua sesi lama, mencatat `auth.reset_sandi_server` di jejak audit, lalu menghapus berkas pemicu.
3. Buka `kredensial.txt` di File Manager, simpan kata sandinya di tempat aman, lalu hapus berkas tersebut.

Kirim lewat email (praktis dari HP): isi berkas pemicu dengan satu alamat email. Server mengirim kata sandi baru ke alamat itu memakai `mail()` hosting; alamat tidak disimpan di kode maupun basis data, dan jejak audit hanya mencatat alamat tersamar. Periksa folder Spam bila email tidak tampak.

Hanya berkas biasa yang diproses (tautan simbolik diabaikan). Akun yang dibuat admin lewat menu **Pengguna** tidak ikut diatur ulang.
