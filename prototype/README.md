# Purwarupa UI/UX SISTRA

Purwarupa interaktif seluruh visi SISTRA (9 modul, 13 layar) untuk pembahasan dengan pengguna. Semua data **sintetis**.

- Sumber: `src/` (`style.css` sistem desain · `data.js` data sintetis · `charts.js` grafik SVG · `app.js` layar & interaksi · `shell.html`)
- Build: `python3 build.py` menghasilkan `dist/prototipe.html` (satu berkas mandiri tanpa dependensi eksternal)
- Live: `https://sistra.semestateknologiutama.com/prototipe/`, dilindungi gerbang login ([`deploy/prototipe/index.php`](../deploy/prototipe/index.php)) yang memverifikasi akun ke API SISTRA. Berkas purwarupa disimpan di luar root dokumen.

| Layar | Modul KAK |
|---|---|
| Peta Alur Sistem | ringkasan siklus |
| Pusat Komando (kartogram 38 provinsi, prioritas isu, arus masuk) | M8 · S60 |
| Monitoring 360° | M1 · S01–S10, S15 |
| Analisis Isu & Jejaring Aktor | M1 · S10–S13, S19 |
| Prediksi & Peringatan (lintasan isu, matriks risiko, siklus peringatan, simulasi skenario) | M2 · S16–S22 |
| Kajian Pimpinan (Graph RAG, sitasi, keandalan sumber) | M3 · S23–S25 |
| Studio Konten (5 variasi, agen editorial, pratinjau platform, label AI) | M4 · S26–S33 |
| Persetujuan & Kalender | M5 · S34–S36 |
| Kanal Resmi (triase komentar, akun resmi, KOL terbuka) | M6 · S40–S43 |
| Distribusi 39 Kanal | M7 · S49–S55 |
| Pengukuran Dampak | M8 · S56–S61 |
| Repositori | M9 · S66–S75 |
| Audit & Tata Kelola AI | M8/M9 · S64, S65, S72 |

Fitur presentasi: pengalih peran (Pimpinan/Analis/Redaktur/Operator), **anotasi fitur S01–S75**, Ctrl+K, mode gelap, tooltip pada semua grafik.
