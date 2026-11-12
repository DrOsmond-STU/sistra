# 01 — Telaah Kerangka Acuan Kerja (KAK) SISTRA TA 2027

**Dokumen sumber:** KAK Pembangunan Sistem Informasi dan Aksi Strategis (SISTRA) TA 2027, status *KONSEP*, 27 halaman (KAK utama hlm. 1–8, Lampiran I Spesifikasi Teknis hlm. 9–21, Lampiran II Kajian Teknis hlm. 22–27).
**Tujuan telaah:** mengidentifikasi kekurangan, inkonsistensi, dan risiko pada KAK sebelum difinalkan, serta memberikan usulan perbaikan yang dapat langsung dipakai penyusun.

---

## 1. Ringkasan Eksekutif

KAK sudah kuat pada sisi visi (siklus tertutup hulu–hilir, kedaulatan data *on-premise*) dan rincian spesifikasi perangkat keras. Namun ada **lima kelompok risiko utama** yang berpotensi menimbulkan sanggah, gagal serah terima, atau temuan audit:

| Prioritas | Kelompok risiko | Ringkas |
|---|---|---|
| **Tinggi** | Kepatuhan pengadaan | Spesifikasi mengarah ke merek (Apple iMac, NVIDIA, Dell/HPE); TKDN tidak diatur. |
| **Tinggi** | Kelayakan sumber data | "Firehose resmi 7 platform" tidak tersedia untuk sebagian platform; bertentangan dengan "web scraper anti-blokir". |
| **Tinggi** | Kriteria penerimaan | Banyak target tidak terukur atau tidak realistis ("zero hallucination", WER < 8% untuk 10 bahasa daerah). |
| **Tinggi** | Jadwal & lingkup | 180 hari tanpa fase pengembangan perangkat lunak di Gantt; lead time GPU; uji DRC tanpa situs DR. |
| **Tinggi** | Aspek hukum & etika | Modul 6 (POEM) memuat fitur penyamaran akun/penghindaran deteksi platform; profiling akun tanpa kerangka PDP. |

---

## 2. Temuan Rinci

### 2.1 Kepatuhan Pengadaan Barang/Jasa

| ID | Lokasi | Temuan | Dampak | Usulan Perbaikan |
|---|---|---|---|---|
| P-01 | Lamp. I item 54 (hlm. 20) | Spesifikasi "Chip M4 … Neural Engine … Layar Retina 4.5K … Center Stage … macOS resmi" identik dengan satu produk (Apple iMac 24"). | Bertentangan dengan prinsip spesifikasi teknis yang tidak mengarah ke merek tertentu (Perpres 16/2018 jo. perubahannya). Rawan sanggah. | Ubah menjadi spesifikasi fungsional: CPU ≥ 10 core, akselerator media H.264/HEVC/AV1 *hardware*, layar ≥ 23,8" resolusi ≥ 4K, gamut ≥ 95% DCI-P3, kamera ≥ 1080p, OS berlisensi resmi. |
| P-02 | Item 30–31 (hlm. 16) | "arsitektur Blackwell", "ConnectX-7/8", "BlueField-3", "NVSwitch", "TensorRT-LLM", "Triton". | Mengunci ke satu vendor GPU. | Nyatakan kinerja: VRAM ≥ 180 GB/GPU, presisi FP4/FP8, interkoneksi intra-node ≥ 1,8 TB/s per GPU, NIC ≥ 400 Gb/s per GPU, kompatibel PyTorch & vLLM. Merek boleh disebut hanya dengan frasa "atau setara". |
| P-03 | Item 32 (hlm. 16) | "iDRAC9/iLO Enterprise". | Menyebut merek Dell/HPE. | "Modul manajemen jarak jauh *out-of-band* mendukung Redfish/IPMI". |
| P-04 | Seluruh KAK | Tidak ada ketentuan TKDN / preferensi produk dalam negeri. | Kewajiban penggunaan produk dalam negeri tidak terdokumentasi. | Tambahkan bagian TKDN: komponen jasa (integrasi, pengembangan perangkat lunak, pelatihan) wajib oleh penyedia dalam negeri; cantumkan nilai TKDN minimum gabungan. |
| P-05 | Bagian B–D | Tidak ada bagian **Biaya/HPS**, **Kualifikasi Penyedia & Tenaga Ahli**, **Cara Pembayaran (termin)**, **Jaminan**, **Kriteria Serah Terima**. | KAK belum lengkap secara administratif. | Tambahkan bagian E–I (lihat §3). |
| P-06 | Hlm. 7 | Tahap "Prakualifikasi teknis" berada pada bulan ke-1 bersama "Perencanaan", padahal 180 hari dihitung sebagai waktu pelaksanaan kontrak. | Mencampur tahap pemilihan penyedia dengan pelaksanaan kontrak. | Pisahkan: jadwal pemilihan penyedia (pra-kontrak) dan jadwal pelaksanaan (pasca-SPMK). |

### 2.2 Kelayakan Teknis Sumber Data

| ID | Lokasi | Temuan | Usulan |
|---|---|---|---|
| D-01 | Item 1, 55 | "Firehose/enterprise API resmi" untuk Instagram, TikTok, Facebook, X, YouTube, Threads, Snack Video. Pada praktiknya: X menyediakan akses enterprise berbayar; YouTube menyediakan Data API berkuota (bukan firehose); Meta (Instagram/Facebook/Threads) membatasi akses data publik melalui program peneliti; TikTok Research API terbatas untuk akademisi; Snack Video tidak memiliki API publik. | Ubah menjadi "akses data melalui jalur resmi yang tersedia per platform **dan/atau** melalui penyedia data agregator berlisensi (*social listening provider*) yang memiliki kontrak resmi dengan platform". Penyedia wajib melampirkan bukti kontrak/izin akses data saat penawaran. |
| D-02 | Item 1 | "web scraper cerdas anti-blokir" bertentangan dengan "jalur resmi" dan berisiko melanggar Ketentuan Layanan platform. | Batasi *crawler* untuk portal berita daring yang mengizinkan (robots.txt / kerja sama), hapus frasa "anti-blokir". |
| D-03 | Item 55 | Kuota "100.000.000 rekaman/bulan" vs. target penyerapan item 1 "5.000.000 unggahan/hari" (≈ 150 juta/bulan). | Inkonsistensi kuota ±50%. Samakan, misal 150 juta rekaman/bulan, atau turunkan target harian. |
| D-04 | Item 2 / S06 | "Radar Kanal Semi-Tertutup" (grup/kanal terotorisasi) tidak menjelaskan dasar akses. | Wajib dinyatakan bahwa akses hanya melalui kanal yang terbuka/terotorisasi sah sesuai UU 17/2011 dan prosedur internal; tidak ada fitur penyusupan akun. |

### 2.3 Kriteria Penerimaan yang Tidak Terukur / Tidak Realistis

| ID | Lokasi | Target di KAK | Masalah | Usulan Target Terukur |
|---|---|---|---|---|
| A-01 | Item 8, Lamp. II C.3 | "zero hallucination" | Tidak dapat dibuktikan secara statistik; pasti gagal uji. | "≥ 95% klaim dalam kajian memiliki sitasi ke dokumen sumber; presisi sitasi ≥ 90% pada *golden set* 300 pertanyaan yang disusun bersama pengguna; kalimat tanpa sumber ditandai otomatis." |
| A-02 | Item 2 | WER < 8% untuk Bahasa Indonesia, **10 bahasa daerah**, dan Inggris | WER < 8% realistis untuk Bahasa Indonesia siaran berita; tidak realistis untuk bahasa daerah dengan data latih terbatas. | Indonesia/Inggris siaran berita: WER ≤ 10%; bahasa daerah: WER ≤ 25% (fase 1), perbaikan bertahap via *fine-tuning*. Tetapkan dataset uji. |
| A-03 | Item 5 | Akurasi arah tren ≥ 85% pada horizon 7 hari | Tidak ada definisi baseline, periode uji, dan jenis isu. | Uji *backtest* 6 bulan data historis; bandingkan dengan baseline naif (persistensi); target ≥ 85% arah dan MAPE ≤ 30% pada volume. |
| A-04 | Item 6 | ABM 1.000.000 agen, < 3 menit/skenario | Realistis secara komputasi, tapi validitas model sosial tidak diatur. | Tambahkan: kalibrasi terhadap ≥ 5 kasus historis; laporan validasi model. |
| A-05 | Item 3 | LLM > 1.500 teks/detik | Kebutuhan rata-rata hanya ±60 teks/detik (5 juta/hari). Target ini mendorong ukuran klaster yang tidak perlu untuk LLM generatif. | Pisahkan: klasifikasi (*encoder model*) ≥ 1.500 teks/detik; analisis LLM generatif ≥ 50 dokumen/detik. |
| A-06 | Item 10 | Draf teks < 15 detik dengan 5 variasi | Realistis; perlu definisi panjang naskah. | "Naskah ≤ 800 kata, 5 variasi, < 15 detik p95." |
| A-07 | Item 19, 21 | SLA uptime 39 kanal 99,9% vs SLA sistem 99,95% (item 60) | Dua angka SLA berbeda tanpa penjelasan. | Tetapkan tabel SLA per layanan (inti 99,95%, kanal publik 99,9%). |

### 2.4 Jadwal, Lingkup, dan Infrastruktur

| ID | Temuan | Dampak | Usulan |
|---|---|---|---|
| J-01 | Gantt (hlm. 7) tidak memuat tahap **analisis kebutuhan rinci, pengembangan perangkat lunak, integrasi, *fine-tuning* model, uji keamanan (pentest), uji beban, uji DRC, UAT**, padahal semuanya ada di ruang lingkup (A.6.g). | 75 fitur dalam ±3 bulan efektif tidak realistis. | Gunakan jadwal pada [06-rencana-pengembangan-mvp.md](06-rencana-pengembangan-mvp.md): perangkat keras + fondasi + modul prioritas dalam 180 hari; modul lanjutan pada masa pemeliharaan dengan *milestone* terukur, atau kontrak tahun jamak. |
| J-02 | *Lead time* server GPU kelas tertinggi umumnya 3–6 bulan. | Instalasi bulan 3–5 berisiko mundur. | Izinkan *staging* sementara di perangkat pengganti; atau pembayaran per *milestone*. |
| J-03 | Uji "DRC *failover*" diminta (A.6.g), tetapi **tidak ada situs DR** dalam daftar barang. | Lingkup uji tidak dapat dipenuhi. | Tambah paket situs DR minimal (penyimpanan replika + server aplikasi), atau ubah uji menjadi "*failover* lokal antar-rak + uji pemulihan dari *backup*/tape". |
| J-04 | Kesiapan pusat data (daya, pendinginan cair, ruang rak, lantai) tidak dalam lingkup. Satu server 8-GPU kelas ini mengonsumsi ±10–14 kW; 4 unit ≈ 40–56 kW hanya untuk GPU. | Instalasi bisa terhambat. | Tambahkan prasyarat/kewajiban survei lokasi (*site survey*) dan, bila perlu, paket *facility upgrade*. |
| J-05 | Tidak ada lingkungan **pengembangan/staging** terpisah; seluruh perangkat keras untuk produksi. | Pengembangan dan uji dilakukan di produksi → risiko keamanan. | Alokasikan partisi MIG/node untuk *staging*, atau tambah 1 server aplikasi + 1 server GPU kecil untuk *staging*. |
| J-06 | Kapasitas *object storage* 2 PB vs. rekaman siaran: 100 kanal × 24 jam × H.265 1080p ±3 Mbps ≈ **3,2 TB/hari ≈ 1,2 PB/tahun**. | Penuh dalam < 2 tahun bila video disimpan penuh. | Tetapkan kebijakan retensi: video penuh 90 hari, klip yang ditandai permanen, transkrip/metadata permanen. Atau resolusi arsip 720p. |
| J-07 | Penomoran fitur tumpang tindih: item 1 "S01–S07" sementara item 2 "S04, S06". Daftar 75 fitur tidak dicantumkan satu per satu. | Ambiguitas saat serah terima per fitur. | Lampirkan **katalog 75 fitur** (lihat [02-spesifikasi-kebutuhan-srs.md](02-spesifikasi-kebutuhan-srs.md)). |

### 2.5 Aspek Hukum, Etika, dan Tata Kelola

| ID | Lokasi | Temuan | Usulan |
|---|---|---|---|
| H-01 | Dasar Hukum (hlm. 1) | Belum mencantumkan **UU 27/2022 Pelindungan Data Pribadi**, **UU 43/2009 Kearsipan** (terkait S73 retensi), **UU 11/2008 jo. UU 1/2024 ITE**, **Perpres 95/2018 SPBE**, **Perpres 82/2022 Pelindungan Infrastruktur Informasi Vital**, serta peraturan **BSSN** tentang keamanan SPBE. | Tambahkan ke Dasar Hukum. |
| H-02 | S13 (item 4) | *Profiling* mendalam > 10.000 akun target + pengenalan wajah (item 2) tanpa ketentuan dasar penetapan target, batas retensi, dan pengawasan. | Tambahkan *guardrail*: penetapan target berbasis surat perintah/otorisasi tercatat, peninjauan berkala (mis. 90 hari), retensi terbatas, log akses tak dapat diubah, laporan pengawasan internal. |
| H-03 | S46, S47, item 51–53, Lamp. II B.4 | Fitur "algoritma penayangan wajar (*human-like*) … guna mencegah deteksi spam dan penangguhan akun oleh platform", armada 100 smartphone dengan "sidik jari perangkat alami", rotasi IP/proksi seluler, "tidak terdeteksi sebagai otomasi terkoordinasi". | Ini adalah rancangan **perilaku tidak autentik terkoordinasi** (*coordinated inauthentic behavior*): menyamarkan komunikasi pemerintah sebagai suara warga dan sengaja mengelabui sistem keamanan platform. Berisiko hukum, reputasi institusi bila terungkap, dan melanggar ketentuan seluruh platform. **Tim pengembang ini tidak akan membangun fitur tersebut.** Usulan pengganti: lihat §4. |
| H-04 | Modul 4 | Konten otomatis tanpa ketentuan **label konten AI** dan identitas penerbit. | Tambahkan kewajiban penanda konten buatan AI dan identitas penerbit resmi pada konten yang dipublikasikan. |
| H-05 | Seluruh KAK | Tidak ada ketentuan **evaluasi bias model** dan **audit independen** atas model AI. | Tambahkan laporan evaluasi bias (per provinsi, kelompok bahasa) dan audit model tahunan. |

### 2.6 Redaksional

| ID | Lokasi | Temuan |
|---|---|---|
| R-01 | Hlm. 6 | Huruf butir "e." ganda (d, e, e, f, g, h). |
| R-02 | Hlm. 3 | "beroperasi **npada**" → "pada"; "opini **public**" → "publik". |
| R-03 | Hlm. 5, A.5.a | "termasuk 39 *platform* media *online*" → seharusnya "39 kanal distribusi" (platform media sosial berjumlah 7). |
| R-04 | Modul 4 | Akronim **RJUMP** / **VIMPRO** tidak dijelaskan. Tambahkan daftar istilah/akronim. |
| R-05 | Hlm. 9 | "Sistem *capture* media 360 derajat" — konsistenkan istilah *media monitoring* 360. |

---

## 3. Usulan Bagian Tambahan pada KAK

1. **E. Biaya dan Sumber Dana** — HPS, mata anggaran, pembebanan tahun jamak (bila ada).
2. **F. Kualifikasi Penyedia & Tenaga Ahli** — mis. Project Manager (PMP/Prince2), Solution Architect (TOGAF), Data/ML Engineer, Security Engineer (CISSP/OSCP), DevOps (CKA), Network (CCIE/setara), Data Center (CDCP); sertifikasi ISO/IEC 27001 badan usaha.
3. **G. Kriteria Serah Terima** — per fitur S01–S75 dengan skenario uji dan target terukur (lihat SRS).
4. **H. Cara Pembayaran** — termin per *milestone* (perangkat keras terpasang; fondasi platform; modul fase 1; UAT; pemeliharaan triwulanan).
5. **I. Hak Kekayaan Intelektual & Kode Sumber** — kepemilikan kode sumber, model hasil *fine-tuning*, dan data oleh BIN; larangan telemetri ke pihak ketiga; *escrow* bila menggunakan perangkat lunak berpemilik.
6. **J. Daftar Istilah dan Akronim.**

---

## 4. Usulan Pengganti untuk Modul 6 (POEM)

Tujuan sah yang ingin dicapai Modul 6 — **menjangkau publik secara cepat dengan kontra-narasi berbasis fakta** — dapat dicapai tanpa akun samaran:

| Fitur KAK | Status | Pengganti yang dibangun |
|---|---|---|
| S40 Kendali akun resmi | Dibangun | Konsol multi-akun **resmi** institusi/K/L, token OAuth di *vault*, MFA wajib. |
| S41 Bahan respons cepat | Dibangun | Paket tanggapan, Q&A, *fact-sheet*, petunjuk juru bicara. |
| S42 Kemitraan KOL | Dibangun dengan syarat | Kemitraan **terbuka**: kontrak tercatat, kewajiban label "kemitraan/berbayar", rekam jejak kolaborasi. |
| S43 Triase komentar & anti-*bot swarm* | Dibangun | Deteksi serangan *bot* terhadap akun resmi, triase komentar, masking PII. |
| S44–S45 Perencanaan kampanye & kuota | Dibangun dengan syarat | Hanya untuk akun resmi dan mitra terbuka; publikasi melalui API resmi platform. |
| **S46 Algoritma penayangan "human-like" anti-deteksi** | **Tidak dibangun** | Penjadwalan cerdas transparan (S51) berbasis jam puncak audiens. |
| **S47 Pengendali perangkat fisik / sidik jari / rotasi jaringan** | **Tidak dibangun** | — (item 51–53 disarankan dihapus dari daftar barang atau dialihfungsikan menjadi perangkat uji tampilan konten di berbagai ponsel). |
| S48 Pemantauan kampanye | Dibangun dengan syarat | Pemantauan kinerja kampanye akun resmi (jangkauan, impresi, sentimen); tanpa "kesehatan armada akun". |

---

## 5. Kesimpulan Telaah

KAK **layak dilanjutkan** setelah perbaikan pada temuan prioritas tinggi (P-01..P-05, D-01..D-03, A-01..A-03, J-01, J-03, J-04, H-01..H-03). Rekomendasi terpenting:

1. Netralkan spesifikasi merek dan atur TKDN.
2. Ganti klausul firehose menjadi "jalur resmi dan/atau agregator berlisensi dengan bukti kontrak".
3. Ganti target tak terukur dengan target berbasis *golden set*/*backtest*.
4. Susun ulang jadwal: fondasi + modul prioritas dalam 180 hari, sisanya bertahap dengan *milestone*.
5. Hapus fitur penyamaran akun (S46, S47, item 51–53) dan ganti dengan komunikasi resmi yang transparan.
6. Lengkapi kerangka hukum PDP, kearsipan, dan pengawasan untuk fitur *profiling*.
