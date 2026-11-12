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
