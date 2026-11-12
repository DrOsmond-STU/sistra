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

## C. Domain di hosting tanpa Python, aplikasi di hosting lain (proxy PHP)

Dipakai untuk `sistra.semestateknologiutama.com`. Paket hosting semesta tidak mendukung Python, dan proxy Apache `[P]` di sana dinonaktifkan. Karena itu:

```
Pengguna ──HTTPS──▶ sistra.semestateknologiutama.com   (semesta: .htaccess + sistra-proxy.php)
                         │  X-Forwarded-For = IP pengguna
                         ▼
                    sistra.ramasetyamandiri.com          (ramasetyamandiri: Setup Python App)
                    hanya menerima koneksi dari IP semesta (SISTRA_PROXY_ONLY=1)
```

- Berkas proxy: [`deploy/proxy-php/`](proxy-php/), salin ke root dokumen domain publik. Ubah `UPSTREAM` bila perlu.
- Di origin, `sistra.env` mengatur `SISTRA_TRUSTED_PROXIES=<IP keluar server proxy>` dan `SISTRA_PROXY_ONLY=1`.
- Kunci rahasia (`SISTRA_AUTO_SECRETS=1`) dan kredensial akun (`SISTRA_AUTO_SEED=1`) dibuat otomatis di `SISTRA_DATA_DIR`, berizin 0600, dan tidak pernah dikirim lewat kanal lain.
- **Jangan** pakai *Directory Privacy* (HTTP Basic) di depan aplikasi: header `Authorization`-nya bertabrakan dengan token login SISTRA.
