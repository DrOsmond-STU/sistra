# 05 — Daftar Risiko Proyek SISTRA

Skala: Kemungkinan (K) dan Dampak (D) 1–5; Skor = K × D. **≥ 15 = Tinggi**, 8–14 = Sedang, < 8 = Rendah.

| ID | Risiko | K | D | Skor | Mitigasi | Pemilik |
|---|---|---|---|---|---|---|
| RK-01 | Akses data resmi media sosial tidak tersedia untuk sebagian platform | 5 | 5 | **25** | Revisi klausul KAK (D-01); kontrak agregator berlisensi; prioritas pada platform yang tersedia; uji kelayakan sebelum kontrak | PPK + Penyedia |
| RK-02 | Keterlambatan pengiriman server GPU | 4 | 4 | **16** | Pemesanan dini; *staging* sementara; pembayaran per *milestone* | Penyedia |
| RK-03 | Lingkup 75 fitur dalam 180 hari tidak tercapai | 5 | 4 | **20** | Pembagian fase F1/F2/F3; MVP untuk validasi awal; kriteria penerimaan per fitur | PPK + PM |
| RK-04 | Sanggah karena spesifikasi bermerek | 4 | 4 | **16** | Netralisasi spesifikasi (P-01..P-03) | Pokja |
| RK-05 | Kesiapan pusat data (daya/pendingin) tidak memadai | 3 | 5 | **15** | Survei lokasi di bulan 1; paket *facility* bila perlu | Pengguna |
| RK-06 | Keluaran AI keliru dipublikasikan | 3 | 5 | **15** | HITL wajib (S33/S36); verifikator sitasi; label AI; uji negatif | Penyedia + Redaksi |
| RK-07 | Risiko hukum dan reputasi dari operasi akun tidak autentik | 4 | 5 | **20** | Fitur S46/S47 tidak dibangun; Modul 6 dibatasi pada akun resmi dan kemitraan terbuka | Pengguna |
| RK-08 | Pelanggaran PDP pada *profiling*/pengenalan wajah | 3 | 5 | **15** | DPIA; otorisasi target; retensi terbatas; audit | Pengguna + DPO |
| RK-09 | Kebocoran data rahasia (insider) | 2 | 5 | 10 | ABAC; watermark; UEBA; PAM; log WORM | Penyedia + Keamanan |
| RK-10 | Model bias terhadap provinsi/bahasa tertentu | 3 | 3 | 9 | Evaluasi bias per provinsi/bahasa; data latih seimbang | Penyedia |
| RK-11 | Data uji beranotasi dari pengguna terlambat | 4 | 3 | 12 | Jadwal anotasi sejak bulan 2; Label Studio; anotator khusus | Pengguna |
| RK-12 | Penyimpanan video penuh lebih cepat dari rencana | 4 | 3 | 12 | Kebijakan retensi (J-06); transkode arsip 720p | Pengguna |
| RK-13 | Ketergantungan pada personel kunci penyedia | 3 | 3 | 9 | Dokumentasi; TOT; serah kode sumber; *pair programming* dengan tim internal | Penyedia |
| RK-14 | Perubahan API/ketentuan platform | 4 | 3 | 12 | Lapisan adaptor konektor; pemantauan kesehatan konektor | Penyedia |
