/* SISTRA purwarupa — data SINTETIS (dibuat program, bukan data nyata) */
"use strict";

function rng(seed) {
  return function () {
    seed |= 0; seed = (seed + 0x6d2b79f5) | 0;
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
const R = rng(2027);
const pick = (a) => a[Math.floor(R() * a.length)];
const between = (a, b) => a + R() * (b - a);
const int = (a, b) => Math.floor(between(a, b + 1));

/* 38 provinsi: kode, nama, singkatan, kolom, baris (kartogram ubin, kira-kira barat→timur) */
const PROV = [
  ["11", "Aceh", "ACE", 0, 0], ["12", "Sumatera Utara", "SUT", 1, 1], ["14", "Riau", "RIA", 2, 1], ["21", "Kepulauan Riau", "KRI", 3, 1],
  ["13", "Sumatera Barat", "SBR", 1, 2], ["15", "Jambi", "JAM", 2, 2], ["19", "Bangka Belitung", "BBL", 3, 2],
  ["17", "Bengkulu", "BKL", 1, 3], ["16", "Sumatera Selatan", "SSL", 2, 3], ["18", "Lampung", "LAM", 2, 4],
  ["36", "Banten", "BAN", 3, 5], ["31", "DKI Jakarta", "DKI", 4, 4], ["32", "Jawa Barat", "JBR", 4, 5], ["33", "Jawa Tengah", "JTG", 5, 5],
  ["34", "DI Yogyakarta", "DIY", 5, 6], ["35", "Jawa Timur", "JTM", 6, 5], ["51", "Bali", "BAL", 7, 6], ["52", "Nusa Tenggara Barat", "NTB", 8, 6],
  ["53", "Nusa Tenggara Timur", "NTT", 9, 6], ["61", "Kalimantan Barat", "KBR", 5, 2], ["62", "Kalimantan Tengah", "KTG", 6, 3],
  ["63", "Kalimantan Selatan", "KSL", 7, 4], ["64", "Kalimantan Timur", "KTM", 7, 2], ["65", "Kalimantan Utara", "KUT", 7, 1],
  ["76", "Sulawesi Barat", "SLB", 8, 3], ["72", "Sulawesi Tengah", "SLT", 9, 2], ["75", "Gorontalo", "GOR", 10, 1], ["71", "Sulawesi Utara", "SLU", 11, 1],
  ["73", "Sulawesi Selatan", "SLS", 8, 4], ["74", "Sulawesi Tenggara", "SLG", 9, 4], ["82", "Maluku Utara", "MLU", 12, 2], ["81", "Maluku", "MAL", 12, 4],
  ["96", "Papua Barat Daya", "PBD", 13, 3], ["92", "Papua Barat", "PBR", 14, 3], ["94", "Papua Tengah", "PTG", 15, 4], ["91", "Papua", "PAP", 16, 3],
  ["95", "Papua Pegunungan", "PPG", 16, 4], ["93", "Papua Selatan", "PSL", 16, 5],
].map(([code, name, short, col, row]) => ({ code, name, short, col, row }));
const PROV_BY = Object.fromEntries(PROV.map((p) => [p.code, p]));

const ISSUES = [
  { code: "EKO-PANGAN", name: "Harga Pangan & Inflasi", level: "merah", vol: 1840, growth: 4.6, neg: 0.78, prov: 31, owner: "Analis Media", base: 380 },
  { code: "DIS-HOAKS", name: "Disinformasi Bantuan Sosial", level: "oranye", vol: 960, growth: 3.1, neg: 0.71, prov: 22, owner: "Analis Media", base: 290 },
  { code: "BEN-ALAM", name: "Banjir & Cuaca Ekstrem", level: "kuning", vol: 610, growth: 1.9, neg: 0.34, prov: 12, owner: "Analis Daerah", base: 300 },
  { code: "EKO-KERJA", name: "PHK Sektor Padat Karya", level: "kuning", vol: 420, growth: 1.7, neg: 0.55, prov: 9, owner: "Analis Ekonomi", base: 240 },
  { code: "POL-PILKADA", name: "Tahapan Pilkada", level: "hijau", vol: 530, growth: 1.1, neg: 0.22, prov: 26, owner: "Analis Politik", base: 480 },
  { code: "KES-PUBLIK", name: "Kasus DBD Musiman", level: "hijau", vol: 260, growth: 1.2, neg: 0.31, prov: 14, owner: "Analis Daerah", base: 220 },
  { code: "LIN-AGRARIA", name: "Sengketa Lahan Adat", level: "hijau", vol: 190, growth: 0.9, neg: 0.48, prov: 6, owner: "Analis Daerah", base: 200 },
  { code: "SOS-KERUKUNAN", name: "Kerukunan Antarumat", level: "hijau", vol: 150, growth: 0.8, neg: 0.18, prov: 8, owner: "Analis Sosial", base: 180 },
];
ISSUES.forEach((iss, k) => {
  const r = rng(100 + k);
  iss.series = Array.from({ length: 30 }, (_, i) => {
    const ramp = i > 22 ? Math.pow(iss.growth, (i - 22) / 7) : 1;
    return Math.round(iss.base * ramp * (0.85 + r() * 0.3));
  });
  iss.series[29] = iss.vol;
});

const LEVEL = {
  merah: { label: "Merah · Kritis", short: "Merah", color: "var(--st-critical)", icon: "■", ink: "#fff", rank: 3 },
  oranye: { label: "Oranye · Siaga", short: "Oranye", color: "var(--st-serious)", icon: "▲", ink: "#fff", rank: 2 },
  kuning: { label: "Kuning · Waspada", short: "Kuning", color: "var(--st-warning)", icon: "!", ink: "#0b0b0b", rank: 1 },
  hijau: { label: "Hijau · Normal", short: "Hijau", color: "var(--st-good)", icon: "✓", ink: "#fff", rank: 0 },
};

/* volume per provinsi (24 jam) */
PROV.forEach((p) => {
  const big = { "31": 3.2, "32": 2.6, "35": 2.4, "33": 1.9, "12": 1.6, "73": 1.4, "36": 1.3, "16": 1.1 }[p.code] || 0.5 + R() * 0.7;
  p.vol = Math.round(90 * big * (0.8 + R() * 0.4));
  p.neg = Math.min(0.92, 0.25 + R() * 0.45 + (["31", "32", "35", "73"].includes(p.code) ? 0.15 : 0));
  p.level = p.neg > 0.72 && p.vol > 150 ? "merah" : p.neg > 0.62 ? "oranye" : p.neg > 0.5 ? "kuning" : "hijau";
  p.topIssue = pick(ISSUES.slice(0, 5)).name;
});

const SOURCES = {
  daring: { label: "Media daring", ic: "WEB", color: "var(--c1)" },
  instagram: { label: "Instagram", ic: "IG", color: "var(--c5)" },
  tiktok: { label: "TikTok", ic: "TT", color: "#111" },
  x: { label: "X", ic: "X", color: "#333" },
  facebook: { label: "Facebook", ic: "FB", color: "var(--c7)" },
  youtube: { label: "YouTube", ic: "YT", color: "var(--c8)" },
  threads: { label: "Threads", ic: "TH", color: "#555" },
  snack: { label: "Snack Video", ic: "SV", color: "var(--c4)" },
  tv: { label: "Siaran TV", ic: "TV", color: "var(--c2)" },
  radio: { label: "Radio", ic: "RD", color: "var(--c3)" },
  cetak: { label: "Media cetak", ic: "PR", color: "var(--c6)" },
};

const HEADLINES = [
  ["EKO-PANGAN", "Harga beras medium di {kota} tembus Rp{n}.{m}00/kg, warga keluhkan beban dapur", "negatif"],
  ["EKO-PANGAN", "Operasi pasar beras di {kota} diserbu warga sejak subuh", "netral"],
  ["EKO-PANGAN", "Pedagang {kota}: pasokan cabai menipis, harga naik dua kali lipat", "negatif"],
  ["EKO-PANGAN", "Pemprov {prov} pastikan stok beras aman hingga akhir tahun", "positif"],
  ["DIS-HOAKS", "Beredar pesan berantai: bansos di {prov} dihentikan — dipastikan hoaks", "negatif"],
  ["DIS-HOAKS", "Cek fakta: video antrean bansos di {kota} ternyata rekaman 2023", "netral"],
  ["DIS-HOAKS", "Akun anonim sebar narasi menyesatkan soal penyaluran bantuan {prov}", "negatif"],
  ["BEN-ALAM", "Banjir rendam {n}00 rumah di {kota}, BPBD dirikan posko", "negatif"],
  ["BEN-ALAM", "BMKG: waspada cuaca ekstrem di {prov} tiga hari ke depan", "netral"],
  ["EKO-KERJA", "Pabrik garmen di {kota} rumahkan {n}00 pekerja", "negatif"],
  ["POL-PILKADA", "KPU {prov} mulai distribusi logistik pilkada", "netral"],
  ["KES-PUBLIK", "Kasus DBD di {kota} turun berkat gerakan 3M", "positif"],
];
const CITIES = [["Jakarta", "31"], ["Bandung", "32"], ["Surabaya", "35"], ["Semarang", "33"], ["Medan", "12"], ["Makassar", "73"],
  ["Palembang", "16"], ["Denpasar", "51"], ["Pontianak", "61"], ["Samarinda", "64"], ["Manado", "71"], ["Kupang", "53"],
  ["Jayapura", "91"], ["Padang", "13"], ["Serang", "36"], ["Mataram", "52"], ["Ambon", "81"], ["Banjarmasin", "63"]];

function makeItem(i) {
  const [issue, tpl, sent] = pick(HEADLINES);
  const [kota, pcode] = pick(CITIES);
  const src = pick(Object.keys(SOURCES));
  const title = tpl.replace("{kota}", kota).replace("{prov}", PROV_BY[pcode].name).replace("{n}", int(2, 9)).replace("{m}", int(0, 9));
  const handle = src === "daring" ? pick(["Portal Nusantara", "Kabar Daerah", "Warta Kini", "Harian Contoh"]) :
    src === "tv" ? pick(["TV Nasional 1", "TV Berita 24", "TV Daerah"]) : src === "radio" ? pick(["RRI Contoh", "Radio Kota FM"]) :
    src === "cetak" ? pick(["Harian Pagi", "Koran Daerah"]) : "@" + pick(["warga_peduli", "infokota", "suara.rakyat", "akun_anonim88", "kabarviral", "jurnalwarga"]);
  return {
    id: 1000 + i, issue, title, sent, src, prov: pcode, kota, handle,
    mins: i * 3 + int(0, 2), reach: int(2, 900) * (src === "tv" ? 1000 : 100),
    eng: int(10, 9000), dup: R() < 0.18 ? int(2, 40) : 0,
    rel: src === "daring" || src === "tv" || src === "cetak" ? pick(["A", "B", "B", "C"]) : pick(["C", "D", "E"]),
    manip: R() < 0.22 ? pick(["Framing", "Fear appeal", "Konteks keliru", "Amplifikasi terkoordinasi"]) : null,
  };
}
const STREAM = Array.from({ length: 48 }, (_, i) => makeItem(i));

/* jejaring aktor untuk isu prioritas */
const ACTORS = (() => {
  const r = rng(77);
  const groups = [
    { g: 0, label: "Media arus utama", color: "var(--c1)" },
    { g: 1, label: "Akun komunitas warga", color: "var(--c3)" },
    { g: 2, label: "Klaster akun anonim (indikasi terkoordinasi)", color: "var(--c2)" },
  ];
  const names = [
    ["Portal Nusantara", 0], ["Warta Kini", 0], ["TV Berita 24", 0], ["Harian Contoh", 0], ["Kabar Daerah", 0], ["Radio Kota FM", 0],
    ["@infokota", 1], ["@jurnalwarga", 1], ["@warga_peduli", 1], ["@forum.pasar", 1], ["@ibu.hemat", 1], ["@kabarviral", 1], ["@suara.rakyat", 1],
    ["@akun_anonim88", 2], ["@akun_anonim12", 2], ["@berita.panas", 2], ["@fakta_kini", 2], ["@akun_baru01", 2], ["@akun_baru02", 2], ["@akun_baru03", 2], ["@narasi.x", 2],
  ];
  const cx = [200, 560, 380], cy = [140, 140, 340];
  const nodes = names.map(([name, g], i) => {
    const ang = (i * 2.399) % (Math.PI * 2), rad = 45 + r() * 95;
    return { id: i, name, g, x: cx[g] + Math.cos(ang) * rad, y: cy[g] + Math.sin(ang) * rad * 0.8,
      score: Math.round(g === 0 ? 55 + r() * 40 : g === 1 ? 30 + r() * 45 : 10 + r() * 50),
      posts: int(3, 140), shift: pick(["Penyebar", "Penguat", "Pembantah", "Penyebar", "Pengamat"]) };
  });
  const edges = [];
  nodes.forEach((n) => {
    const k = n.g === 2 ? 4 : 2;
    for (let j = 0; j < k; j++) {
      const sameG = nodes.filter((m) => m.g === n.g && m.id !== n.id);
      const t = r() < 0.75 ? sameG[Math.floor(r() * sameG.length)] : nodes[Math.floor(r() * nodes.length)];
      if (t && t.id !== n.id) edges.push([n.id, t.id]);
    }
  });
  return { nodes, edges, groups };
})();

const CHANNELS = [{ code: "NAS", name: "Kanal Nasional", prov: null }].concat(
  PROV.map((p) => ({ code: "PROV-" + p.code, name: "Kanal " + p.name, prov: p.code })));
CHANNELS.forEach((c, i) => {
  c.uptime = i === 17 ? 97.8 : i === 33 ? 99.1 : 99.6 + R() * 0.39;
  c.lat = int(80, 420);
  c.status = i === 17 ? "gangguan" : i === 33 ? "lambat" : "normal";
  c.pub = int(2, 30); c.readers = int(800, 52000);
});

const DRAFTS = [
  { id: 41, title: "Pemerintah Pastikan Stok Beras Nasional Aman", kind: "Rilis", status: "draf", author: "Redaktur A", ai: true, prov: null, issue: "EKO-PANGAN" },
  { id: 42, title: "Klarifikasi: Bansos Tetap Disalurkan Sesuai Jadwal", kind: "Briefing", status: "ditinjau", author: "Produser B", ai: true, prov: null, issue: "DIS-HOAKS" },
  { id: 43, title: "Operasi Pasar Diperluas ke 10 Kabupaten Jawa Timur", kind: "Rilis", status: "disetujui", author: "Redaktur A", ai: false, prov: "35", issue: "EKO-PANGAN" },
  { id: 44, title: "Infografis: Cara Cek Status Bantuan Resmi", kind: "Infografis", status: "ditinjau", author: "Produser B", ai: true, prov: null, issue: "DIS-HOAKS" },
  { id: 45, title: "Video 60 detik: Siaga Banjir di Kalimantan Selatan", kind: "Video", status: "draf", author: "Produser C", ai: true, prov: "63", issue: "BEN-ALAM" },
  { id: 46, title: "Opini: Menjaga Daya Beli di Tengah Gejolak Harga", kind: "Opini", status: "tayang", author: "Redaktur D", ai: false, prov: null, issue: "EKO-PANGAN" },
  { id: 47, title: "Warta Audio Harian — 12 November", kind: "Audio", status: "tayang", author: "Produser C", ai: true, prov: null, issue: "EKO-PANGAN" },
  { id: 48, title: "Rilis: Posko Pengaduan Bansos Dibuka di 38 Provinsi", kind: "Rilis", status: "disetujui", author: "Redaktur D", ai: false, prov: null, issue: "DIS-HOAKS" },
];

const ACCOUNTS = [
  { platform: "instagram", handle: "@kanal.resmi", followers: "1,2 jt", status: "Sehat", mfa: true, posts: 4 },
  { platform: "x", handle: "@kanalresmi", followers: "860 rb", status: "Sehat", mfa: true, posts: 9 },
  { platform: "facebook", handle: "Kanal Resmi", followers: "2,1 jt", status: "Sehat", mfa: true, posts: 3 },
  { platform: "youtube", handle: "Kanal Resmi TV", followers: "540 rb", status: "Token kedaluwarsa 3 hari", mfa: true, posts: 1 },
  { platform: "tiktok", handle: "@kanal.resmi", followers: "1,6 jt", status: "Sehat", mfa: true, posts: 2 },
];

const COMMENTS = [
  { who: "@ibu_rina", text: "Kapan operasi pasar ke kecamatan kami? Harga beras sudah berat sekali.", cls: "Pertanyaan", sent: "negatif", sugg: "Jadwal operasi pasar per kecamatan" },
  { who: "@akun_baru02", text: "BANSOS DIHENTIKAN! Pemerintah bohong!!! sebarkan", cls: "Hoaks", sent: "negatif", sugg: "Klarifikasi bansos (paket respons #12)", bot: true },
  { who: "@akun_baru03", text: "BANSOS DIHENTIKAN! sebarkan sebelum dihapus", cls: "Hoaks", sent: "negatif", sugg: "Klarifikasi bansos (paket respons #12)", bot: true },
  { who: "@pak_hadi", text: "Terima kasih infonya, sudah cek di laman resmi dan bantuan saya masuk.", cls: "Apresiasi", sent: "positif", sugg: "Balasan terima kasih standar" },
  { who: "@mahasiswa.ekonomi", text: "Datanya dari mana? Mohon sumber inflasi pangan bulan ini.", cls: "Permintaan data", sent: "netral", sugg: "Tautan rilis statistik resmi" },
  { who: "@akun_baru01", text: "bansos dihentikan sebarkan!!", cls: "Hoaks", sent: "negatif", sugg: "Klarifikasi bansos (paket respons #12)", bot: true },
];

const KOL = [
  { name: "Mitra Edukasi Gizi", cat: "Kesehatan", reach: "640 rb", collab: 6, disclosure: "Berlabel #kemitraan", eff: 0.72 },
  { name: "Komunitas Petani Muda", cat: "Pertanian", reach: "210 rb", collab: 3, disclosure: "Berlabel #kemitraan", eff: 0.64 },
  { name: "Kanal Cek Fakta Mandiri", cat: "Literasi digital", reach: "1,1 jt", collab: 9, disclosure: "Kerja sama terbuka", eff: 0.81 },
  { name: "Influencer Kuliner Daerah", cat: "Gaya hidup", reach: "480 rb", collab: 1, disclosure: "Menunggu label", eff: null },
];

const DOCS = [
  { id: 201, title: "Laporan Situasi Harian Media — 12 Nov", cls: "TERBUKA", unit: "DIT-ANL", ver: 1, upd: "12 Nov 07.10", tags: ["lapsit"] },
  { id: 202, title: "Kajian Isu Harga Pangan Pekan 46", cls: "TERBATAS", unit: "DIT-ANL", ver: 3, upd: "12 Nov 15.42", tags: ["kajian", "pangan"] },
  { id: 203, title: "Telaahan Staf: Pola Disinformasi Bansos", cls: "RAHASIA", unit: "DIT-ANL", ver: 2, upd: "11 Nov 19.05", tags: ["telaahan"] },
  { id: 204, title: "Nota Dinas Arahan Strategi Komunikasi", cls: "SANGAT", unit: "DEP", ver: 1, upd: "10 Nov 09.30", tags: ["nota dinas"] },
  { id: 205, title: "Laporan Berkala Kanal Distribusi Triwulan III", cls: "TERBATAS", unit: "DIT-KNL", ver: 4, upd: "08 Nov 11.20", tags: ["laporan"] },
  { id: 206, title: "Transkrip Siaran Pilihan — Dialog Ekonomi", cls: "TERBUKA", unit: "DIT-ANL", ver: 1, upd: "12 Nov 21.02", tags: ["transkrip", "tv"] },
];
const CLS_LABEL = { TERBUKA: "Terbuka", TERBATAS: "Terbatas", RAHASIA: "Rahasia", SANGAT: "Sangat Rahasia" };

const MODELS = [
  { name: "Klasifikasi sentimen (encoder)", ver: "v1.4", metric: "Makro-F1 0,84", bias: "Selisih F1 antarprovinsi ≤ 0,06", st: "Produksi" },
  { name: "Klasifikasi isu multi-label", ver: "v2.1", metric: "Top-1 88%", bias: "Lulus", st: "Produksi" },
  { name: "ASR siaran (bahasa Indonesia)", ver: "v1.2", metric: "WER 9,1%", bias: "Bahasa daerah WER 22%", st: "Produksi" },
  { name: "LLM sintesis kajian (on-premise)", ver: "v0.9", metric: "Presisi sitasi 92%", bias: "Ditinjau", st: "Uji terbatas" },
  { name: "Peramalan lintasan isu", ver: "v1.0", metric: "Arah tren 86% (7 hari)", bias: "—", st: "Produksi" },
];

const AUDIT = [
  ["12 Nov 21.14", "pimpinan", "naskah.transisi", "Naskah #43 ditinjau → disetujui (bertanda tangan)"],
  ["12 Nov 21.02", "analis", "dokumen.unggah", "Transkrip Siaran Pilihan (Terbuka) v1"],
  ["12 Nov 20.48", "sistem", "peringatan.eskalasi", "EKO-PANGAN kuning → merah"],
  ["12 Nov 20.31", "arsiparis", "dokumen.izin_beri", "Telaahan Staf → analis2 (7 hari)"],
  ["12 Nov 20.05", "redaktur", "dokumen.unduh", "Kajian Isu Harga Pangan v3 · WM-8f21c0"],
  ["12 Nov 19.40", "analis2", "dokumen.akses_ditolak", "Nota Dinas Arahan (Sangat Rahasia)"],
  ["12 Nov 19.12", "operator", "naskah.transisi", "Naskah #46 disetujui → tayang"],
  ["12 Nov 18.55", "admin", "user.ubah", "Peran produser C: + redaktur"],
];

const FEATURES = {
  M1: "S01–S15 Mesin Data & Analitik Multisumber", M2: "S16–S22 Prediksi & Peringatan Dini", M3: "S23–S25 Kajian & Dukungan Keputusan",
  M4: "S26–S33 Produksi Narasi Otomatis", M5: "S34–S39 Editorial, Persetujuan & Aset", M6: "S40–S48 Kanal Media Sosial Resmi",
  M7: "S49–S55 Distribusi 39 Wilayah", M8: "S56–S65 Dampak & Dashboard Eksekutif", M9: "S66–S75 Data & Repositori Pengetahuan",
};
