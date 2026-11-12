# Dokumen Analisis SISTRA

Analisis sistem atas **KAK Pembangunan Sistem Informasi dan Aksi Strategis (SISTRA) TA 2027** (status konsep).

| No | Dokumen | Isi |
|---|---|---|
| 01 | [Telaah KAK](01-telaah-kak.md) | Temuan, inkonsistensi, risiko kepatuhan, dan usulan perbaikan KAK |
| 02 | [Spesifikasi Kebutuhan (SRS)](02-spesifikasi-kebutuhan-srs.md) | Peran pengguna, katalog 75 fitur dengan kriteria penerimaan terukur, kebutuhan non-fungsional |
| 03 | [Arsitektur Sistem](03-arsitektur-sistem.md) | Arsitektur logis, layanan, tumpukan teknologi, zona keamanan, pemetaan perangkat keras, arsitektur AI, ADR |
| 04 | [Model Data](04-model-data.md) | Entitas inti, klasifikasi dan aturan akses, siklus status |
| 05 | [Daftar Risiko](05-manajemen-risiko.md) | Risiko proyek dan mitigasinya |
| 06 | [Rencana Pengembangan & MVP](06-rencana-pengembangan-mvp.md) | Strategi fase MVP/F1/F2/F3, lingkup MVP, jadwal, dan perkiraan tim |

## Ringkasan

- **Kelayakan:** layak setelah perbaikan temuan prioritas tinggi di dokumen 01.
- **Fase:** 75 fitur dibagi menjadi 40 (F1, 180 hari), 25 (F2), 8 (F3), dan 2 tidak dibangun.
- **Tidak dibangun:** S46 dan S47, yaitu operasi akun samaran yang dirancang menghindari deteksi platform. Penggantinya adalah komunikasi melalui akun resmi dan kemitraan terbuka.
- **Risiko terbesar:** ketersediaan akses data media sosial resmi, lingkup terhadap waktu, spesifikasi bermerek, dan kesiapan pusat data.
- **Status MVP:** 17 fitur selesai dan teruji (53 uji otomatis di SQLite dan PostgreSQL, cakupan 90%). Cara menjalankan dan skenario demo ada di [README utama](../../README.md).
