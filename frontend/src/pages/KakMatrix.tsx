import { useState } from "react";
import { Link } from "react-router-dom";

type Status = "ok" | "sim" | "luar" | "x";
interface Row { id: string; mod: string; name: string; kriteria: string; status: Status; where: string; link: string; note: string }
const ST: Record<Status, [string, string]> = { ok: ["Tersedia", "status-ok"], sim: ["Tersedia · integrasi disimulasikan", "status-warn"], luar: ["Di luar cakupan aplikasi ini", "muted"], x: ["Tidak dibangun (etika/hukum)", "status-bad"] };

const ROWS: Row[] = [
  { id: "S26", mod: "M4", name: "Pabrik konten teks", kriteria: "≤ 800 kata, < 15 dtk p95; setiap fakta tertaut sumber", status: "ok", where: "Produksi Narasi → Brief", link: "/produksi", note: "Rilis, artikel, opini, editorial, briefing sheet, dll. Fakta bersumber (sumber, tautan, keandalan A–F); AI wajib menandai [F#]; waktu pembuatan ditampilkan." },
  { id: "S27", mod: "M4", name: "Variasi framing & PUEBI", kriteria: "5 variasi sudut pandang; cek ejaan PUEBI/EYD", status: "ok", where: "Produksi Narasi → Hasilkan variasi (hingga 5)", link: "/produksi", note: "5 sudut pandang; pemeriksaan ejaan oleh agen editorial." },
  { id: "S28", mod: "M4", name: "Pelokalan 38 provinsi", kriteria: "Fakta lokal 100% dari basis data terverifikasi; ditinjau redaktur", status: "ok", where: "Produksi Narasi → Basis konteks lokal", link: "/produksi?tab=lokal", note: "Basis data konteks lokal per provinsi dengan verifikasi redaktur; AI hanya memakai butir terverifikasi." },
  { id: "S29", mod: "M4", name: "Generator infografis", kriteria: "PNG/SVG 4K; data sesuai sumber", status: "ok", where: "Studio Multimedia → Infografis", link: "/studio", note: "Isi disusun AI dari fakta bersumber; templat berversi; ekspor SVG & PNG 3840×2160/2160×3840; disimpan ke bank aset." },
  { id: "S30", mod: "M4", name: "Generator video pendek", kriteria: "9:16 & 16:9 ≤ 60 dtk; takarir dwibahasa .srt/.vtt", status: "sim", where: "Studio Multimedia → Video", link: "/studio", note: "Papan cerita + takarir ID/EN (.srt/.vtt) + pratinjau & rekam WebM di peramban. Render MP4 GPU pada fase F3." },
  { id: "S31", mod: "M4", name: "Audio & podcast", kriteria: "TTS Bahasa Indonesia; MP3/WAV", status: "sim", where: "Studio Multimedia → Naskah audio", link: "/studio", note: "Naskah dua suara + pratinjau TTS peramban. Mesin TTS on-premise (MOS ≥ 4,0) pada fase F3." },
  { id: "S32", mod: "M4", name: "Agen editorial AI", kriteria: "Ejaan, ujaran kebencian, SARA, nada, generator judul; koreksi redaktur tercatat", status: "ok", where: "Editorial → panel naskah → Agen editorial / Judul", link: "/editorial", note: "Temuan dapat diterima/ditolak; keputusan tercatat dan menjadi preferensi redaksi pada pemeriksaan berikutnya." },
  { id: "S33", mod: "M4", name: "Ruang pratinjau HITL", kriteria: "Pratinjau per platform; skor verifikasi fakta; tanpa publikasi tanpa otorisasi; label AI", status: "ok", where: "Editorial → panel naskah → Verifikasi & Pratinjau", link: "/editorial", note: "8 pratinjau platform dengan batas karakter; skor verifikasi klaim; label konten AI; penayangan tanpa persetujuan ditolak (uji negatif)." },
  { id: "S34", mod: "M5", name: "Kalender produksi & distribusi", kriteria: "Tampilan harian/mingguan/bulanan; deteksi bentrok", status: "ok", where: "Kalender Terpadu", link: "/kalender", note: "6 jenis kegiatan; bentrok sumber daya sama < 60 menit ditandai." },
  { id: "S35", mod: "M5", name: "Penugasan & kolaborasi", kriteria: "Diff antarversi; notifikasi tugas", status: "ok", where: "Editorial → panel naskah; ikon 🔔", link: "/editorial", note: "Penugasan, tenggat, prioritas, diskusi, perbandingan versi kata demi kata, notifikasi penugasan/persetujuan." },
  { id: "S36", mod: "M5", name: "Alur persetujuan komando", kriteria: "Draf→ditinjau→disetujui→tayang→ditarik; tanda tangan; log WORM", status: "ok", where: "Editorial & Persetujuan", link: "/editorial", note: "Tanda tangan HMAC atas isi versi; transisi tidak sah ditolak; penarikan menurunkan konten dari kanal. HSM pada fase produksi." },
  { id: "S37", mod: "M5", name: "DAM & hak cipta aset", kriteria: "Pencarian < 1 dtk; tag AI; peringatan aset kedaluwarsa", status: "ok", where: "Aset, Templat & Liputan → Bank aset", link: "/aset", note: "Tag AI (visi), lisensi & masa berlaku, peringatan ≤ 30 hari, aset kedaluwarsa tidak dapat ditautkan. Tanpa pengenalan wajah (UU PDP)." },
  { id: "S38", mod: "M5", name: "Pustaka templat visual", kriteria: "Templat berversi dan terkunci", status: "ok", where: "Aset, Templat & Liputan → Templat", link: "/aset?tab=templat", note: "Setiap perubahan = versi baru; templat dapat dikunci redaktur." },
  { id: "S39", mod: "M5", name: "Penjadwalan liputan lapangan", kriteria: "Penugasan liputan; unggah rekaman ke alur produksi", status: "ok", where: "Aset, Templat & Liputan → Liputan", link: "/aset?tab=liputan", note: "Penugasan + notifikasi, unggah rekaman ke bank aset, jadikan brief." },
  { id: "S40", mod: "M6", name: "Kendali akun resmi terpusat", kriteria: "Token di vault; token tidak tampil; MFA wajib", status: "sim", where: "Kanal Medsos Resmi → Akun & kuota", link: "/kanal-resmi?tab=akun", note: "Inventaris, status MFA, rotasi token tercatat. Integrasi OAuth platform pada fase F1." },
  { id: "S41", mod: "M6", name: "Bahan respons cepat", kriteria: "Paket terbit ≤ 2 jam setelah peringatan Oranye/Merah; pemisahan akses draf/terbit", status: "ok", where: "Kanal Medsos Resmi → Paket respons cepat", link: "/kanal-resmi?tab=paket", note: "Hitung mundur SLA, AI menyusun pernyataan/Q&A/argumen data/petunjuk jubir; operator hanya melihat paket terbit." },
  { id: "S42", mod: "M6", name: "Kemitraan KOL terbuka", kriteria: "Kontrak & status disclosure; wajib label kemitraan", status: "ok", where: "Kanal Medsos Resmi → Mitra KOL", link: "/kanal-resmi?tab=mitra", note: "Kontrak tercatat, label kemitraan, rekam jejak, ER; unggahan tanpa label ditandai tidak patuh." },
  { id: "S43", mod: "M6", name: "Triase komentar & anti bot swarm", kriteria: "Termasuk sub-komentar; deteksi bot terkoordinasi; masking PII", status: "ok", where: "Kanal Medsos Resmi → Kotak masuk", link: "/kanal-resmi", note: "Triase AI, klaster bot, utas sub-komentar, NIK/telepon/surel/rekening disamarkan." },
  { id: "S44", mod: "M6", name: "Perencanaan kampanye resmi", kriteria: "Rencana terhubung ke alur persetujuan", status: "ok", where: "Kanal Medsos Resmi → Kampanye", link: "/kanal-resmi?tab=kampanye", note: "Draf→diajukan→disetujui pimpinan (bertanda tangan, pemisahan tugas)→berjalan; matriks jadwal akun resmi & mitra terbuka." },
  { id: "S45", mod: "M6", name: "Penugasan konten & kuota", kriteria: "Kuota per kanal; publikasi hanya via jalur resmi", status: "ok", where: "Kanal Medsos Resmi → Tugas unggah / Akun & kuota", link: "/kanal-resmi?tab=tugas", note: "Kuota harian/mingguan ditegakkan (409 bila penuh, dapat dilampaui redaktur dengan jejak). Unggah manual oleh operator di aplikasi resmi; API platform fase F2." },
  { id: "S46", mod: "M6", name: "Penayangan “human-like” anti-deteksi", kriteria: "—", status: "x", where: "—", link: "", note: "Perilaku tidak autentik terkoordinasi; diganti penjadwalan transparan S51." },
  { id: "S47", mod: "M6", name: "Pengendali perangkat/sidik jari/rotasi jaringan", kriteria: "—", status: "x", where: "—", link: "", note: "Penyamaran identitas & penghindaran deteksi platform; tidak dibangun." },
  { id: "S48", mod: "M6", name: "Pemantauan kampanye resmi", kriteria: "Jangkauan, impresi, sentimen, laporan evaluasi", status: "ok", where: "Kanal Medsos Resmi → Kampanye → Pemantauan", link: "/kanal-resmi?tab=kampanye", note: "Metrik harian, kemajuan target, pergeseran sentimen, laporan evaluasi AI." },
  { id: "S49", mod: "M7", name: "Pengelolaan 39 kanal", kriteria: "CMS multi-site; geotargeting; sinkron ≤ 5 dtk", status: "sim", where: "Distribusi → Kanal & CMS", link: "/distribusi?tab=cms", note: "Artikel per kanal, waktu sinkron, penarikan per kanal, penargetan geolokasi. Konektor CMS sungguhan pada fase F1." },
  { id: "S50", mod: "M7", name: "Sindikasi lintas kanal", kriteria: "Satu sumber ke banyak kanal; CDN lokal", status: "sim", where: "Distribusi → Salurkan", link: "/distribusi", note: "Satu naskah ke 39 kanal dengan verifikasi tanda tangan; coba ulang kanal gagal." },
  { id: "S51", mod: "M7", name: "Penjadwalan cerdas", kriteria: "Rekomendasi jam tayang per kanal; dapat ditimpa operator", status: "ok", where: "Distribusi → Jadwal cerdas", link: "/distribusi?tab=jadwal", note: "Kurva jam puncak per provinsi (WIB/WITA/WIT); antrean terjadwal dijalankan saat jatuh tempo." },
  { id: "S52", mod: "M7", name: "Konversi aset & metadata", kriteria: "12 profil format; validator OG/Schema.org", status: "sim", where: "Distribusi → Metadata & format", link: "/distribusi?tab=paket", note: "OpenGraph + JSON-LD NewsArticle dengan validator; 12 profil turunan (konversi disimulasikan)." },
  { id: "S53", mod: "M7", name: "Paket media konvensional", kriteria: "Portal unduhan terautentikasi; tercatat per penerima", status: "ok", where: "Distribusi → Portal media; /pers/<token>", link: "/distribusi?tab=pers", note: "Tautan berwaktu per media, unduhan tercatat, penarikan naskah memblokir unduhan." },
  { id: "S54", mod: "M7", name: "Gerbang API eksternal", kriteria: "REST; rate limiting; token berwaktu; WAF", status: "ok", where: "Distribusi → API mitra (admin)", link: "/distribusi?tab=api", note: "Token hash + cakupan + kedaluwarsa + pencabutan, batas laju per menit (429), log setiap panggilan. WAF di produksi." },
  { id: "S55", mod: "M7", name: "Pemantauan uptime kanal", kriteria: "Uji 5 titik; peringatan latensi/gagal; SLA ≥ 99,9%", status: "sim", where: "Distribusi → Uptime", link: "/distribusi?tab=uptime", note: "Riwayat uji 5 titik, uptime per kanal vs SLA, insiden otomatis (≥ 3 titik gagal)." },
  { id: "S56", mod: "M8", name: "Metrik keterlibatan 39 kanal", kriteria: "Klik, pembaca unik, waktu tayang, komentar, retensi video; harian & per jam", status: "ok", where: "Dashboard Analitik → Keterlibatan per jam", link: "/analitik", note: "Seri per jam 24–72 jam & harian, per kanal atau gabungan." },
  { id: "S57", mod: "M8", name: "Pergeseran sentimen", kriteria: "Sebelum vs sesudah kampanye; uji signifikansi", status: "ok", where: "Dashboard Analitik → Dampak & IEKP", link: "/analitik", note: "Uji z dua proporsi atas komentar negatif, p-value & kesimpulan." },
  { id: "S58", mod: "M8", name: "Pergeseran peran aktor", kriteria: "Laporan per isu", status: "luar", where: "Aplikasi analitik jejaring (Modul 1)", link: "", note: "Membutuhkan graf aktor Modul 1 yang dikelola aplikasi lain." },
  { id: "S59", mod: "M8", name: "Analisis efektivitas (IEKP)", kriteria: "Indeks 0–100; metodologi terdokumentasi", status: "ok", where: "Dashboard Analitik → Dampak & IEKP", link: "/analitik", note: "5 komponen berbobot (jangkauan, keterlibatan, sentimen, kecepatan respons, cakupan wilayah); metodologi ditampilkan." },
  { id: "S60", mod: "M8", name: "Executive Command Dashboard", kriteria: "Isu prioritas, peta 38 provinsi, status kanal; tablet", status: "ok", where: "Dashboard Eksekutif", link: "/", note: "KPI, peta sebaran interaktif 38 provinsi (OpenStreetMap + klaster; juga di Analitik, Distribusi, Kanal Resmi, Liputan, dan halaman Peta Sebaran), kesehatan 39 kanal, keputusan menunggu, ringkasan AI; responsif tablet/ponsel." },
  { id: "S61", mod: "M8", name: "Laporan berkala otomatis", kriteria: "Harian/mingguan/bulanan PDF/DOCX; mode presentasi", status: "ok", where: "Laporan Berkala", link: "/laporan", note: "Harian & mingguan terbit otomatis; DOCX, cetak PDF, mode presentasi layar penuh." },
  { id: "S62", mod: "M8", name: "Meja kerja analis", kriteria: "Garis waktu narasi, graf WebGL", status: "luar", where: "Aplikasi analis (Modul 1–3)", link: "", note: "Dikelola aplikasi lain sesuai arahan fokus M4–M7." },
  { id: "S63", mod: "M8", name: "Meja kerja produksi multimedia", kriteria: "Penyuntingan kolaboratif, pratinjau render, antrean publikasi", status: "ok", where: "Studio Multimedia + Editorial + Antrean distribusi", link: "/studio", note: "Pratinjau render di peramban (< 10 dtk), suntingan spesifikasi, antrean terjadwal." },
  { id: "S64", mod: "M8", name: "Penjamin mutu AI", kriteria: "Kualitas keluaran, umpan balik koreksi, evaluasi bias", status: "ok", where: "Dashboard Analitik → Mutu AI", link: "/analitik", note: "Presisi temuan per kategori, hasil naskah AI vs manual, evaluasi bias per wilayah, galat & latensi per fitur." },
  { id: "S65", mod: "M8", name: "Jejak audit WORM", kriteria: "Log immutable berantai hash; auditor terpisah", status: "ok", where: "Jejak Audit (peran auditor)", link: "/audit", note: "Setiap aksi M4–M8 dicatat ke rantai hash; verifikasi rantai tersedia." },
];

export default function KakMatrix() {
  const [mod, setMod] = useState("");
  const [st, setSt] = useState("");
  const rows = ROWS.filter((r) => (!mod || r.mod === mod) && (!st || r.status === st));
  const cnt = (s: Status) => ROWS.filter((r) => r.status === s).length;
  return (
    <div className="stack">
      <div>
        <h1>Matriks Kepatuhan KAK</h1>
        <p className="sub" style={{ marginBottom: 0 }}>Pemetaan fitur KAK SISTRA TA 2027 Modul 4–8 (S26–S65) ke layar aplikasi, mengacu katalog fitur & kriteria penerimaan pada dokumen SRS</p>
      </div>
      <div className="grid g4">
        <div className="card tile tone-good"><div className="label">Tersedia</div><div className="value num">{cnt("ok")}</div></div>
        <div className="card tile tone-warn"><div className="label">Tersedia, integrasi disimulasikan</div><div className="value num">{cnt("sim")}</div><div className="foot">menunggu infrastruktur/kontrak (CMS, OAuth, GPU)</div></div>
        <div className="card tile"><div className="label">Di luar cakupan aplikasi ini</div><div className="value num">{cnt("luar")}</div><div className="foot">dikelola aplikasi lain</div></div>
        <div className="card tile tone-bad"><div className="label">Tidak dibangun</div><div className="value num">{cnt("x")}</div><div className="foot">S46–S47: alasan etika & hukum</div></div>
      </div>
      <div className="row">
        <select value={mod} onChange={(e) => setMod(e.target.value)} aria-label="Modul"><option value="">Semua modul</option>{["M4", "M5", "M6", "M7", "M8"].map((m) => <option key={m}>{m}</option>)}</select>
        <select value={st} onChange={(e) => setSt(e.target.value)} aria-label="Status"><option value="">Semua status</option>{Object.entries(ST).map(([k, [v]]) => <option key={k} value={k}>{v}</option>)}</select>
      </div>
      <div className="card table-wrap">
        <table>
          <thead><tr><th>ID</th><th>Fitur KAK</th><th>Kriteria penerimaan</th><th>Status</th><th>Di aplikasi</th></tr></thead>
          <tbody>{rows.map((r) => (
            <tr key={r.id} className="kak-row">
              <td>{r.id}<div className="small muted">{r.mod}</div></td>
              <td><b>{r.name}</b><div className="small muted">{r.note}</div></td>
              <td className="small">{r.kriteria}</td>
              <td className={`small ${ST[r.status][1]}`}>{ST[r.status][0]}</td>
              <td className="small">{r.link ? <Link to={r.link}>{r.where} →</Link> : r.where}</td>
            </tr>
          ))}</tbody>
        </table>
      </div>
      <div className="small muted">“Integrasi disimulasikan” berarti alur kerja, data, aturan, dan antarmuka sudah berjalan; yang belum adalah sambungan ke sistem eksternal (CMS 39 situs, OAuth platform, server render GPU, HSM) yang bergantung pada pengadaan.</div>
    </div>
  );
}
