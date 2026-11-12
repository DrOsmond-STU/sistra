# 02 — Spesifikasi Kebutuhan Perangkat Lunak (SRS) SISTRA

Versi 0.1 · Berdasarkan KAK SISTRA TA 2027 (konsep) dan hasil telaah [01-telaah-kak.md](01-telaah-kak.md).

---

## 1. Pendahuluan

### 1.1 Tujuan
Dokumen ini menerjemahkan KAK menjadi kebutuhan yang **dapat diuji**. Isinya: katalog 75 fitur (S01–S75), kriteria penerimaan terukur, kebutuhan non-fungsional, dan fase penyerahan.

### 1.2 Ruang Lingkup Sistem
SISTRA adalah sistem siklus tertutup yang mencakup:
1. **Penginderaan:** Modul 1 (pengumpulan dan analitik data) dan Modul 2 (prediksi dan peringatan dini).
2. **Penilaian:** Modul 3 (kajian pimpinan).
3. **Aksi:** Modul 4 (produksi konten), Modul 5 (editorial dan persetujuan), Modul 6 (kanal resmi), dan Modul 7 (distribusi 39 kanal).
4. **Evaluasi:** Modul 8 (dampak dan dashboard).
5. **Memori institusi:** Modul 9 (repositori pengetahuan).

### 1.3 Konvensi
- **Fase:** `MVP` = prototipe tanpa GPU untuk validasi alur; `F1` = wajib dalam 180 hari kontrak; `F2` = triwulan 1–2 masa pemeliharaan; `F3` = triwulan 3–4; `X` = tidak dibangun (lihat [01-telaah-kak.md §4](01-telaah-kak.md#4-usulan-pengganti-untuk-modul-6-poem)).
- **Prioritas:** `W` = wajib, `P` = penting, `T` = tambahan.
- Angka target mengikuti KAK, kecuali yang direvisi pada telaah (ditandai †).

---

## 2. Aktor dan Peran

| Kode | Peran | Tanggung jawab utama | Modul dominan |
|---|---|---|---|
| R-PIM | Pimpinan (Deputi/Direktur) | Menerima peringatan, membaca kajian, menyetujui publikasi strategis | 2, 3, 5, 8 |
| R-ANL | Analis Intelijen | Memantau isu, mengoreksi klasifikasi, menyusun kajian | 1, 2, 3, 8 |
| R-RED | Redaktur | Menyunting naskah, meninjau keluaran AI (*human-in-the-loop*) | 4, 5 |
| R-PRD | Produser Multimedia | Menyusun infografis, video, dan audio | 4, 5 |
| R-OPK | Operator Kanal (nasional/provinsi) | Mengelola kanal resmi dan jadwal distribusi | 6, 7 |
| R-ARS | Petugas Arsip/Data | Mengelola metadata, retensi, dan katalog | 9 |
| R-UKP | Unit Kerja Pelapor | Mengunggah laporan berkala wajib | 9 |
| R-ADM | Administrator Sistem | Mengelola pengguna, konfigurasi, dan integrasi | semua |
| R-AUD | Auditor Internal | Membaca jejak audit (*read-only*), terpisah dari admin | 8, 9 |
| R-EXT | Sistem Mitra K/L (via API) | Menarik bahan publikasi resmi | 7 |

Prinsip: *least privilege*, pemisahan tugas (admin ≠ auditor ≠ penyetuju), dan akses berbasis klasifikasi (ABAC).

---

## 3. Katalog Fitur dan Kriteria Penerimaan

> Pemetaan S-ID per fitur disusun oleh tim analis karena KAK hanya memberikan rentang per item (lihat temuan J-07). Pemetaan ini **perlu disahkan** oleh penyusun KAK.

### 3.1 Modul 1 — Mesin Data & Analitik Multisumber (S01–S15)

| ID | Fitur | Kebutuhan inti | Kriteria penerimaan | Fase | Prio |
|---|---|---|---|---|---|
| S01 | Penyerapan media daring | *Crawler*/RSS untuk > 700 portal berita nasional/daerah; ekstraksi judul, isi, penulis, waktu, URL kanonik | ≥ 700 sumber aktif; ≥ 50.000 artikel/hari; latensi terbit→terindeks ≤ 60 dtk p95 | MVP (RSS) → F1 | W |
| S02 | Penyerapan media sosial | Konektor jalur resmi/agregator berlisensi untuk 7 platform, termasuk metadata interaksi | ≥ 5 juta unggahan/hari†; latensi ≤ 60 dtk p95; bukti kontrak akses data | F1 | W |
| S03 | Pembersihan & deduplikasi | Normalisasi waktu (UTC+zona), bahasa, *encoding*; dedup tepat dan *near-duplicate* (MinHash/SimHash) | Presisi dedup ≥ 98% pada set uji 5.000 pasangan | MVP → F1 | W |
| S04 | Pemrosesan audio-visual | STT siaran/video dengan cap waktu; CV wajah tokoh, lambang, dan objek; OCR teks pada citra/video | WER Indonesia ≤ 10%†, bahasa daerah ≤ 25%†; OCR ≥ 95%; CV 1080p30 *real-time* | F2 | W |
| S05 | Integrasi *capture* siaran & media cetak | Asupan dari rak capture TV/radio (100+ kanal) dan pemindai/OCR cetak | 100 kanal simultan tanpa *frame drop*; segmen 60 dtk tersedia ≤ 2 menit | F1 | W |
| S06 | Radar kanal semi-tertutup | Pemantauan kanal/grup yang **diakses secara sah**; deteksi sinyal viral dini | Hanya sumber dengan dasar akses tercatat; log otorisasi per sumber | F2 | P |
| S07 | Pengayaan entitas & geolokasi | NER (tokoh, organisasi, lokasi), *geotag* ke 38 provinsi/kab-kota, penautan entitas | F1 NER ≥ 0,85; akurasi provinsi ≥ 90% | MVP (dasar) → F1 | W |
| S08 | Sentimen & sarkasme | Sentimen 3/5 kelas, deteksi sarkasme, ragam percakapan Indonesia | Makro-F1 sentimen ≥ 0,80; sarkasme ≥ 0,70; ≥ 1.500 teks/dtk (*encoder*) | MVP (leksikon) → F1 | W |
| S09 | Emosi multimodal | Emosi dari teks, ekspresi wajah, dan nada suara | Makro-F1 ≥ 0,70 per modalitas | F2 | P |
| S10 | Deteksi manipulasi kognitif | Klasifikasi *framing*, *fear appeal*, *echo chamber*, narasi terkoordinasi | Makro-F1 ≥ 0,70 pada set uji beranotasi analis | F2 | P |
| S11 | Graf aktor & Influence Score | Graf aktor–konten–isu lintas platform; skor pengaruh; sentralitas | Pembaruan graf ≤ 5 menit; skor reproducible dan terdokumentasi | F1 | W |
| S12 | Visualisasi graf interaktif | Visual graf WebGL hingga jutaan *node*, filter waktu/isu | 1 juta *node* ≥ 30 fps pada *workstation* | F2 | P |
| S13 | Profil akun target | Agregasi histori unggahan, pola interaksi, anomali jam aktif, keterkaitan jejaring | ≥ 10.000 profil; **wajib** otorisasi target tercatat, peninjauan 90 hari, retensi terbatas, log akses | F2 | P |
| S14 | Taksonomi isu & matriks risiko | Klasifikasi isu, tingkat kerawanan, dan matriks risiko otomatis dengan koreksi analis | Akurasi top-1 isu ≥ 85%; koreksi analis tercatat dan dipakai untuk pelatihan ulang | MVP → F1 | W |
| S15 | Pencarian arsip & audit akses | Pencarian *hybrid* (vektor + *inverted index*) atas miliaran dokumen; log akses *immutable* | Latensi < 500 ms p95 pada 1 miliar dokumen; log tidak dapat diubah (WORM) | MVP → F1 | W |

### 3.2 Modul 2 — Prediksi & Peringatan Dini (S16–S22)

| ID | Fitur | Kebutuhan inti | Kriteria penerimaan | Fase | Prio |
|---|---|---|---|---|---|
| S16 | Peramalan lintasan isu | Prediksi volume, topik dominan, dan polarisasi pada horizon 7/14/30 hari | *Backtest* 6 bulan: arah ≥ 85% (7 hari), MAPE volume ≤ 30%† | F1 | W |
| S17 | Penilaian & visualisasi risiko | Skor eskalasi (kecepatan, sebaran provinsi, dampak); kurva dengan interval kepercayaan dan *tipping point* | Skor dapat dijelaskan (kontribusi faktor ditampilkan) | F1 | W |
| S18 | Simulasi berbasis agen (ABM) | Laboratorium simulasi penyebaran narasi hingga 1 juta agen | < 3 menit/skenario; kalibrasi ≥ 5 kasus historis† | F3 | T |
| S19 | Deteksi anomali hubungan | Lonjakan hubungan tak wajar dan indikasi jaringan terkoordinasi | Presisi ≥ 0,80 pada kasus historis berlabel | F2 | P |
| S20 | Uji skenario intervensi | Bandingkan respons A/B/C dan estimasi rasio redaman isu | Laporan komparasi otomatis per skenario | F3 | T |
| S21 | Radar peringatan berjenjang | Hijau/Kuning/Oranye/Merah; notifikasi ke dashboard, aplikasi *mobile* terenkripsi, dan SMS/WA *gateway* terisolasi | Notifikasi terbit ≤ 30 dtk setelah ambang terlampaui | MVP (dashboard) → F1 | W |
| S22 | Siklus hidup ancaman | Evolusi ancaman, histori notifikasi, tindak lanjut; ambang dinamis per wilayah/tokoh/topik | Setiap peringatan memiliki status dan penanggung jawab; jejak lengkap | F1 | W |

### 3.3 Modul 3 — Kajian & Dukungan Keputusan (S23–S25)

| ID | Fitur | Kebutuhan inti | Kriteria penerimaan | Fase | Prio |
|---|---|---|---|---|---|
| S23 | Sintesis Graph RAG | Ekstraksi fakta, klaim, dan opini dari korpus; jawaban berbasis graf pengetahuan dan *retrieval* | Presisi sitasi ≥ 90% pada *golden set* 300 pertanyaan† | F1 | W |
| S24 | Ketertelusuran sumber | Tiap kalimat ditautkan ke bukti (URL/arsip, cap waktu, entitas, skor keyakinan); penanda keandalan A–F | ≥ 95% klaim memiliki sitasi; kalimat tanpa sumber ditandai merah | F1 | W |
| S25 | Generator kajian & paparan | Draf laporan kajian, telaahan staf, nota dinas, dan *slide* sesuai tata naskah dinas; ekspor DOCX/PDF ber-*watermark*/PPTX; editor interaktif | Template tata naskah dinas disahkan pengguna; ekspor ≤ 30 dtk | F1 | W |

### 3.4 Modul 4 — Produksi Narasi Otomatis (S26–S33)

| ID | Fitur | Kebutuhan inti | Kriteria penerimaan | Fase | Prio |
|---|---|---|---|---|---|
| S26 | Pabrik konten teks | Draf rilis, opini, editorial, *briefing sheet* dari fakta terverifikasi | ≤ 800 kata, < 15 dtk p95†; setiap fakta tertaut sumber | F1 | W |
| S27 | Variasi *framing* & PUEBI | 5 variasi sudut pandang; pemeriksaan ejaan PUEBI/EYD | Galat ejaan tersisa ≤ 1 per 500 kata pada set uji | F2 | P |
| S28 | Pelokalan 38 provinsi | Kontekstualisasi rujukan tokoh, istilah, dan budaya lokal dari basis data internal | Fakta lokal 100% berasal dari basis data terverifikasi; ditinjau redaktur | F2 | P |
| S29 | Generator infografis | Infografis statistik 4K dari data/template | PNG/SVG 4K; data sesuai sumber | F3 | P |
| S30 | Generator video pendek | Video 9:16 dan 16:9 ≤ 60 dtk; narator AI; takarir dwibahasa (.srt/.vtt) | Render < 90 dtk per video 60 dtk; MP4 H.264/HEVC | F3 | P |
| S31 | Audio & podcast | TTS Bahasa Indonesia untuk warta harian dan podcast ringkas | MOS ≥ 4,0 (uji 20 pendengar); MP3/WAV | F3 | T |
| S32 | Agen editorial AI | Ejaan, ujaran kebencian, isu SARA/sensitif, nada, generator judul; belajar dari koreksi redaktur | Recall ujaran kebencian ≥ 0,90; koreksi redaktur tercatat | F2 | W |
| S33 | Ruang pratinjau HITL | Pratinjau per platform tujuan; skor verifikasi fakta; **tidak ada publikasi tanpa otorisasi manusia**; label "konten dibantu AI"† | Uji negatif: publikasi tanpa persetujuan selalu ditolak sistem | F1 | W |

### 3.5 Modul 5 — Editorial, Persetujuan & Manajemen Aset (S34–S39)

| ID | Fitur | Kebutuhan inti | Kriteria penerimaan | Fase | Prio |
|---|---|---|---|---|---|
| S34 | Kalender produksi & distribusi | Kalender terpadu rencana produksi dan jadwal tayang multi-kanal | Tampilan harian/mingguan/bulanan; deteksi bentrok jadwal | MVP → F1 | W |
| S35 | Penugasan & kolaborasi redaksi | Penugasan staf, diskusi internal, riwayat revisi naskah | *Diff* antarversi; notifikasi tugas | F1 | W |
| S36 | Alur persetujuan komando | Status draf → ditinjau → disetujui → tayang → ditarik (*takedown*); hierarki dinas; tanda tangan digital; audit log | Transisi status tidak sah ditolak; tanda tangan diverifikasi HSM; log WORM | MVP → F1 | W |
| S37 | DAM & hak cipta aset | > 1 juta aset; indeks metadata (pengenalan wajah, tag AI); lisensi dan kedaluwarsa | Pencarian aset < 1 dtk; peringatan aset kedaluwarsa | F2 | P |
| S38 | Pustaka templat visual | Templat identitas visual institusi | Templat berversi dan terkunci | F2 | P |
| S39 | Penjadwalan liputan lapangan | Penugasan liputan; unggah rekaman langsung ke alur produksi | Unggah resumable; metadata otomatis | F3 | T |

### 3.6 Modul 6 — Orkestrasi Kanal Media Sosial Resmi (S40–S48)

| ID | Fitur | Kebutuhan inti | Kriteria penerimaan | Fase | Prio |
|---|---|---|---|---|---|
| S40 | Kendali akun resmi terpusat | Multi-akun resmi institusi; token OAuth di *vault*/HSM; operator tidak melihat kata sandi; MFA wajib | Uji: token tidak pernah tampil di UI/log | F1 | W |
| S41 | Bahan respons cepat | Paket tanggapan, argumen berbasis data, petunjuk juru bicara; pemisahan akses draf/tayang | Paket terbit ≤ 2 jam setelah peringatan Oranye/Merah | F1 | W |
| S42 | Kemitraan KOL (terbuka) | Rekam jejak kolaborasi, efektivitas, histori; **wajib label kemitraan** | Setiap kemitraan memiliki dokumen kontrak dan status *disclosure* | F2 | P |
| S43 | Triase komentar & anti-*bot swarm* | Pemantauan komentar *real-time* termasuk sub-komentar; deteksi serangan *bot* terkoordinasi; masking PII | Recall *bot swarm* ≥ 0,80 pada set uji | F1 | W |
| S44 | Perencanaan kampanye resmi | Sasaran audiens, wilayah, pesan kunci, matriks jadwal (akun resmi dan mitra terbuka) | Rencana terhubung ke alur persetujuan S36 | F2 | P |
| S45 | Penugasan konten & kuota | Kuota unggahan per kanal resmi; publikasi via API resmi platform | Tidak ada jalur publikasi selain API resmi | F2 | P |
| S46 | ~~Algoritma penayangan "human-like" anti-deteksi~~ | — | — | **X** | — |
| S47 | ~~Pengendali perangkat fisik/sidik jari/rotasi jaringan~~ | — | — | **X** | — |
| S48 | Pemantauan kampanye resmi | Jangkauan, impresi, sentimen, dan laporan evaluasi kampanye akun resmi | Dashboard diperbarui ≤ 5 menit | F2 | P |

### 3.7 Modul 7 — Kanal Distribusi 39 Wilayah (S49–S55)

| ID | Fitur | Kebutuhan inti | Kriteria penerimaan | Fase | Prio |
|---|---|---|---|---|---|
| S49 | Pengelolaan 39 kanal | CMS *multi-site* (38 provinsi dan 1 nasional); *targeting* geolokasi pembaca | Sinkronisasi ke 39 situs ≤ 5 dtk | F1 | W |
| S50 | Sindikasi lintas kanal | Satu sumber ke banyak kanal (*one-to-many*); integrasi CDN lokal | Uji beban sesuai target pembaca simultan yang ditetapkan pengguna | F1 | W |
| S51 | Penjadwalan cerdas | Jadwal berbasis kurva jam puncak per provinsi | Rekomendasi jam tayang per kanal; dapat ditimpa operator | F2 | P |
| S52 | Konversi aset & metadata | Konversi master ke 12 format turunan; metadata OpenGraph/Schema.org | 12 profil format teruji; validator OG/Schema lulus | F2 | P |
| S53 | Paket distribusi media konvensional | Portal unduhan berizin untuk media cetak, TV, dan radio lokal | Unduhan terautentikasi; tercatat per penerima | F2 | T |
| S54 | Gerbang API eksternal | REST/GraphQL; *rate limiting*; token berwaktu; WAF | Lulus uji OWASP API Top 10 | F1 | W |
| S55 | Pemantauan uptime kanal | Uji dari 5 titik di Indonesia; peringatan latensi/gagal | Deteksi gangguan ≤ 1 menit; SLA kanal ≥ 99,9% | F1 | W |

### 3.8 Modul 8 — Pengukuran Dampak & Dashboard Eksekutif (S56–S65)

| ID | Fitur | Kebutuhan inti | Kriteria penerimaan | Fase | Prio |
|---|---|---|---|---|---|
| S56 | Metrik keterlibatan 39 kanal | Klik, pembaca unik, waktu tayang, komentar, retensi video | Metrik terkonsolidasi harian dan per jam | F1 | W |
| S57 | Pergeseran sentimen | Sentimen sebelum vs sesudah kampanye | Uji statistik signifikansi disertakan | F2 | P |
| S58 | Pergeseran peran aktor | Transisi peran aktor dalam jejaring isu | Laporan per isu | F2 | P |
| S59 | Analisis efektivitas (IEKP) | Korelasi multi-variabel; Indeks Efektivitas Komunikasi Publik 0–100 | Metodologi IEKP terdokumentasi dan disahkan | F2 | P |
| S60 | Executive Command Dashboard | Situasi nasional *real-time*: isu prioritas, peta 38 provinsi, status kanal, proyeksi; multi-monitor/tablet | Pembaruan < 1 dtk via *streaming*; tampilan tablet | MVP (dasar) → F1 | W |
| S61 | Laporan berkala otomatis | Laporan harian/mingguan/bulanan PDF/DOCX; mode presentasi | Terbit otomatis sesuai jadwal | F1 | W |
| S62 | Meja kerja analis | Garis waktu narasi, graf relasi WebGL, eksplorasi semantik, multi-monitor | Lihat S12 | F2 | P |
| S63 | Meja kerja produksi multimedia | Penyuntingan kolaboratif, pratinjau render, antrean publikasi | Pratinjau render ≤ 10 dtk | F3 | T |
| S64 | Penjamin mutu AI | Pemantauan kualitas keluaran model, umpan balik koreksi, evaluasi bias† | Laporan mutu model bulanan otomatis | F2 | W |
| S65 | Jejak audit WORM & ISO 27001 | Log seluruh aktivitas (login, akses, mutasi) *immutable* dengan rantai hash; peran auditor terpisah | Uji: perubahan log terdeteksi oleh verifikasi rantai hash | MVP → F1 | W |

### 3.9 Modul 9 — Data Management & Knowledge Repository (S66–S75)

| ID | Fitur | Kebutuhan inti | Kriteria penerimaan | Fase | Prio |
|---|---|---|---|---|---|
| S66 | Lumbung data terpusat | Repositori berkas kajian dan aset; deduplikasi tingkat blok/isi | Unggah dedup berbasis hash isi | MVP → F1 | W |
| S67 | Siklus dokumen & integritas | *Check-in*/*check-out*, versi, himpunan data asli, SHA-256 | Verifikasi hash pada setiap unduhan | MVP → F1 | W |
| S68 | Metadata wajib | Unit pembuat, klasifikasi, rentang waktu, asal sumber; tolak berkas tak lengkap | Unggah tanpa metadata wajib ditolak | MVP → F1 | W |
| S69 | Katalog data | Katalog dataset; pemetaan unit; rujukan silang | Setiap dataset memiliki pemilik dan deskripsi | F1 | P |
| S70 | Pencarian semantik | Teks penuh + *dense retrieval* atas teks, tabel, dan transkrip; cuplikan bersorot dengan halaman sumber | < 300 ms p95 | MVP → F1 | W |
| S71 | Klasifikasi & watermark dinamis | Terbuka/Terbatas/Rahasia/Sangat Rahasia; watermark (nama, NIP, IP, waktu unduh) per halaman; enkripsi AES-256 via HSM | Setiap unduhan ber-*watermark* unik | MVP → F1 | W |
| S72 | RBAC/ABAC granular | Peran + atribut (klasifikasi, unit, *need-to-know*); izin berdurasi; peminjaman antarunit; deteksi anomali unduhan | Akses melebihi klasifikasi selalu ditolak (uji negatif) | MVP → F1 | W |
| S73 | Retensi & pemusnahan | Jadwal retensi, arsip dingin (tape), pemusnahan berita acara sesuai JRA | Pemusnahan butuh persetujuan berjenjang dan berita acara | F2 | W |
| S74 | Penyediaan ulang & anonimisasi | Dataset analitik/latih dengan *anonymization engine* | Uji re-identifikasi pada sampel | F3 | P |
| S75 | Portal wajib lapor | Penerimaan laporan berkala unit kerja; validasi format; dashboard kepatuhan | Validasi instan; rekap kepatuhan per unit | F1 | P |

### 3.10 Rekap

| Modul | Jumlah | MVP | F1 | F2 | F3 | X |
|---|---|---|---|---|---|---|
| 1 | 15 | 6 | 9 | 6 | 0 | 0 |
| 2 | 7 | 1 | 4 | 1 | 2 | 0 |
| 3 | 3 | 0 | 3 | 0 | 0 | 0 |
| 4 | 8 | 0 | 2 | 3 | 3 | 0 |
| 5 | 6 | 2 | 3 | 2 | 1 | 0 |
| 6 | 9 | 0 | 3 | 4 | 0 | 2 |
| 7 | 7 | 0 | 4 | 3 | 0 | 0 |
| 8 | 10 | 2 | 4 | 5 | 1 | 0 |
| 9 | 10 | 6 | 8 | 1 | 1 | 0 |
| **Total** | **75** | **17** | **40** | **25** | **8** | **2** |

Catatan: fitur MVP juga dihitung pada fase produksinya (F1/F2), jadi kolom MVP tidak menambah total. Total F1 + F2 + F3 + X = 75.

---

## 4. Kebutuhan Non-Fungsional

| ID | Kategori | Kebutuhan |
|---|---|---|
| NF-01 | Ketersediaan | Layanan inti ≥ 99,95%/bulan; kanal publik ≥ 99,9%; RPO ≤ 15 menit, RTO ≤ 4 jam |
| NF-02 | Kinerja | Lihat target per fitur; UI interaktif ≤ 2 dtk p95; API internal ≤ 300 ms p95 |
| NF-03 | Skalabilitas | Horizontal pada semua layanan *stateless*; kapasitas 3× beban rata-rata tanpa degradasi |
| NF-04 | Kedaulatan data | 100% *on-premise*; tanpa panggilan ke layanan AI/telemetri eksternal; *egress* diblokir kecuali sumber data terdaftar dan kanal distribusi |
| NF-05 | Keamanan | Enkripsi TLS 1.3 (transit) dan AES-256 (*at-rest*) dengan kunci di HSM FIPS 140-3 L3; SSO OIDC/SAML; MFA FIDO2; PAM untuk akses admin; segmentasi zona |
| NF-06 | Audit | Seluruh akses dan perubahan tercatat; log WORM; retensi log ≥ 12 bulan *online* |
| NF-07 | Privasi | Kepatuhan UU 27/2022 PDP: minimisasi data, dasar pemrosesan, masking PII, DPIA untuk S13/S43/S04-wajah |
| NF-08 | Tata kelola AI | Model tercatat di *registry* (versi, data latih, metrik); evaluasi bias; label konten AI; HITL wajib sebelum publikasi |
| NF-09 | Usabilitas | Antarmuka Bahasa Indonesia; aksesibilitas WCAG 2.1 AA untuk portal publik; responsif tablet |
| NF-10 | Interoperabilitas | API terdokumentasi OpenAPI 3.1; standar S3, OIDC, dan Schema.org |
| NF-11 | Pemeliharaan | Kode sumber, IaC, dan model diserahkan; cakupan uji unit ≥ 70% pada layanan inti; CI/CD *air-gapped* |
| NF-12 | Kepatuhan | ISO/IEC 27001; standar keamanan SPBE BSSN; lulus pentest pihak independen sebelum *go-live* |

---

## 5. Asumsi dan Ketergantungan

1. Kontrak akses data media sosial (jalur resmi atau agregator) tersedia sebelum bulan ke-3.
2. Pusat data memenuhi daya ±60–80 kW untuk klaster GPU beserta pendinginnya.
3. Pengguna menyediakan **set data uji beranotasi** (sentimen, isu, *golden set* RAG) minimal 4 minggu sebelum UAT.
4. Template tata naskah dinas dan identitas visual disediakan pengguna.
5. Kebijakan klasifikasi, JRA (Jadwal Retensi Arsip), dan prosedur otorisasi target ditetapkan pengguna.
