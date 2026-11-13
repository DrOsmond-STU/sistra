/* SISTRA purwarupa — layar & interaksi */
"use strict";

const NAV = [
  { grp: null, items: [["alur", "◎", "Peta Alur Sistem"], ["beranda", "▣", "Pusat Komando"]] },
  { grp: "Penginderaan", items: [["monitoring", "◉", "Monitoring 360°", "M1"], ["aktor", "⬡", "Analisis Isu & Aktor", "M1"], ["prediksi", "↗", "Prediksi & Peringatan", "M2", 3]] },
  { grp: "Penilaian", items: [["kajian", "❏", "Kajian Pimpinan", "M3"]] },
  { grp: "Aksi", items: [["studio", "✎", "Studio Konten", "M4"], ["persetujuan", "✓", "Persetujuan & Kalender", "M5", 2], ["kanal", "◈", "Kanal Resmi", "M6"], ["distribusi", "⇶", "Distribusi 39 Kanal", "M7"]] },
  { grp: "Evaluasi & Memori", items: [["dampak", "◔", "Pengukuran Dampak", "M8"], ["repositori", "▤", "Repositori", "M9"], ["tatakelola", "⚿", "Audit & Tata Kelola AI", "M8/M9"]] },
];
const ROLES = { pimpinan: "Pimpinan", analis: "Analis", redaktur: "Redaktur", operator: "Operator Kanal" };
const ROLE_HOME = { pimpinan: "beranda", analis: "monitoring", redaktur: "studio", operator: "distribusi" };
const S = { route: "alur", role: "pimpinan", annot: false, mapMetric: "vol", src: "semua", sel: STREAM[0].id, horizon: 14,
  scenario: null, studioStep: 0, variant: 1, platform: "portal", draft: 42, docSel: 202, kTab: "kanban", chTab: "grid", issue: "EKO-PANGAN" };

const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
function toast(msg) { const t = $("#toast"); t.textContent = msg; t.classList.add("show"); clearTimeout(toast._t); toast._t = setTimeout(() => t.classList.remove("show"), 2400); }
function head(crumb, title, desc, actions = "") {
  return `<div class="page-head"><div><div class="crumb">${crumb}</div><h1>${title}</h1><p>${desc}</p></div><div class="row">${actions}</div></div>`;
}
const days = (n, end = 12) => Array.from({ length: n }, (_, i) => { const d = new Date(2026, 10, end - (n - 1 - i)); return d.getDate() + " " + ["Jan","Feb","Mar","Apr","Mei","Jun","Jul","Agu","Sep","Okt","Nov","Des"][d.getMonth()]; });
const ago = (m) => m < 1 ? "baru saja" : m < 60 ? m + " mnt lalu" : Math.round(m / 60) + " jam lalu";

/* ================= 0. PETA ALUR ================= */
function screenAlur() {
  const steps = [
    ["Penginderaan", "Monitoring 360° · Analisis Aktor", "M1 · M2", "monitoring", "Menyerap >700 media daring, 7 platform media sosial, 100+ kanal TV/radio, dan media cetak; memperkaya dengan sentimen, isu, wilayah, dan jejaring aktor."],
    ["Peringatan", "Prediksi & Peringatan Dini", "M2", "prediksi", "Memproyeksikan lintasan isu 7/14/30 hari, menerbitkan peringatan berjenjang, dan menguji skenario respons sebelum isu meluas."],
    ["Penilaian", "Kajian Pimpinan", "M3", "kajian", "Menyusun kajian, telaahan staf, dan bahan paparan dengan setiap klaim tertaut ke bukti sumber beserta tingkat keandalannya."],
    ["Aksi", "Studio · Persetujuan · Kanal · Distribusi", "M4–M7", "studio", "Menyusun draf multi-format yang dilokalkan ke 38 provinsi, menyetujuinya berjenjang dengan tanda tangan, lalu menyalurkannya ke 39 kanal dan akun resmi."],
    ["Evaluasi & Memori", "Dampak · Repositori · Audit", "M8–M9", "dampak", "Mengukur pergeseran sentimen dan efektivitas komunikasi, menyimpan semua produk sebagai pengetahuan berklasifikasi, dan menjaga jejak audit."],
  ];
  return head("Purwarupa UI/UX", "SISTRA — Sistem Informasi dan Aksi Strategis", "Satu siklus kerja tertutup dari hulu ke hilir. Klik tahap untuk membuka layarnya.",
    `<button class="btn" data-act="annot">${S.annot ? "Sembunyikan" : "Tampilkan"} anotasi fitur</button><button class="btn pri" data-go="${ROLE_HOME[S.role]}">Mulai sebagai ${ROLES[S.role]} →</button>`) + `
  <div class="flow">${steps.map(([k, t, m, r, d], i) => `<div class="st" data-go="${r}"><div class="k">${i + 1} · ${k}</div><b>${t}</b><div class="mods">${m}</div><p class="small muted" style="margin:8px 0 0">${d}</p></div>`).join("")}</div>
  <div class="grid g-72" style="margin-top:16px">
    <div class="card"><div class="card-h"><h2>Cara memakai purwarupa ini</h2></div>
      <ul style="margin:0;padding-left:18px;line-height:1.9">
        <li><b>Ganti peran</b> di bilah atas (Pimpinan, Analis, Redaktur, Operator). Halaman awal dan tombol yang aktif menyesuaikan peran.</li>
        <li><b>Anotasi fitur</b> menandai setiap komponen dengan kode fitur KAK (S01–S75) agar mudah ditelusuri saat pembahasan.</li>
        <li><b>Ctrl + K</b> membuka pencarian cepat untuk layar, isu, dan dokumen.</li>
        <li>Arahkan kursor ke grafik untuk melihat angka; ikon lonceng menampilkan peringatan aktif.</li>
        <li>Semua angka, nama akun, dan berita adalah <b>data sintetis</b> untuk keperluan desain.</li>
      </ul></div>
    <div class="card"><div class="card-h"><h2>Prinsip desain</h2></div>
      <dl class="kv"><dt>Manusia memutuskan</dt><dd>AI menyiapkan draf dan skor; publikasi dan keputusan selalu melalui persetujuan manusia.</dd>
      <dt>Dapat ditelusuri</dt><dd>Setiap angka dan klaim dapat diklik sampai ke sumbernya.</dd>
      <dt>Status tidak hanya warna</dt><dd>Tingkat peringatan selalu memakai ikon dan teks.</dd>
      <dt>Transparan</dt><dd>Konten berbantuan AI diberi label; kanal media sosial hanya akun resmi dan kemitraan terbuka.</dd></dl></div>
  </div>
  <div class="card" style="margin-top:16px"><div class="card-h"><h2>Peta modul KAK</h2><span class="sub">9 modul · 75 fitur</span></div>
    <div class="grid g3">${Object.entries(FEATURES).map(([k, v]) => `<div class="row" style="align-items:flex-start"><span class="tag mono">${k}</span><span class="small">${v}</span></div>`).join("")}</div></div>`;
}

/* ================= 1. PUSAT KOMANDO ================= */
function screenBeranda() {
  const dayLabels = days(14);
  const sentDays = dayLabels.map((label, i) => { const t = ISSUES.reduce((a, s) => a + s.series[16 + i], 0); const negR = 0.38 + (i > 9 ? (i - 9) * 0.06 : 0);
    return { label, neg: Math.round(t * negR), neu: Math.round(t * (0.82 - negR)), pos: Math.round(t * 0.18) }; });
  const decide = DRAFTS.filter((d) => d.status === "ditinjau");
  const right = S.role === "pimpinan" ? `
    <div class="card" data-f="S36"><div class="card-h"><h2>Butuh keputusan Anda</h2><span class="sub">${decide.length} naskah · 1 kajian</span></div>
      ${decide.map((d) => `<div class="list-item" data-go="persetujuan"><b>${d.title}</b><div class="small muted">${d.kind} · ${d.author}${d.ai ? " · dibantu AI" : ""}</div></div>`).join("")}
      <div class="list-item" data-go="kajian"><b>Kajian: Eskalasi Isu Harga Pangan</b><div class="small muted">Telaahan staf · 14 sumber · siap ditandatangani</div></div></div>` : `
    <div class="card"><div class="card-h"><h2>Tugas ${ROLES[S.role]}</h2></div>
      <div class="list-item" data-go="monitoring"><b>Validasi 12 konten berlabel "manipulasi"</b><div class="small muted">Antrean koreksi analis</div></div>
      <div class="list-item" data-go="studio"><b>Siapkan bahan respons isu bansos</b><div class="small muted">Tenggat 2 jam (peringatan Oranye)</div></div></div>`;
  return head("Penginderaan · Evaluasi", "Pusat Komando Situasi Nasional", "Situasi informasi 24 jam terakhir · diperbarui setiap detik",
    `<span class="chip"><span class="d" style="background:var(--st-good)">●</span>Langsung</span><button class="btn" data-act="toast" data-msg="Mode layar penuh untuk video wall (purwarupa)">⛶ Layar komando</button>`) + `
  <div class="grid g4">
    <div class="card tile" data-f="S01"><div class="l">Konten terserap 24 jam</div><div class="v num">5,21 jt</div><div class="f"><span class="up">▲ 18%</span> vs kemarin · 742 media · 7 platform · 104 kanal siaran</div></div>
    <div class="card tile" data-f="S21"><div class="l">Peringatan aktif</div><div class="v num">4</div><div class="f">${levelChip("merah")}${levelChip("oranye")}${levelChip("kuning")} ×2</div></div>
    <div class="card tile" data-f="S08"><div class="l">Sentimen negatif nasional</div><div class="v num">61%</div><div class="f"><span class="up">▲ 9 poin</span> dalam 3 hari</div></div>
    <div class="card tile" data-f="S59"><div class="l">Indeks Efektivitas Komunikasi (IEKP)</div><div class="v num">68<span class="small muted"> /100</span></div><div class="f"><span class="down">▲ 4</span> setelah kampanye operasi pasar</div></div>
  </div>
  <div class="grid g-72" style="margin-top:16px">
    <div class="card" data-f="S60"><div class="card-h"><div><h2>Peta situasi 38 provinsi</h2><div class="sub">Ubin = provinsi (posisi kira-kira geografis) · sudut berwarna = tingkat peringatan</div></div>
      <div class="seg"><button class="${S.mapMetric === "vol" ? "on" : ""}" data-act="map" data-v="vol">Volume</button><button class="${S.mapMetric === "neg" ? "on" : ""}" data-act="map" data-v="neg">% Negatif</button></div></div>
      ${tileMap(S.mapMetric)}
      <div class="row between small muted" style="margin-top:8px"><span>Rendah</span><span style="flex:1;height:8px;border-radius:4px;background:linear-gradient(90deg,var(--s1),var(--s4),var(--s7));margin:0 8px"></span><span>Tinggi</span></div>
      <h3 style="margin-top:18px">Provinsi perlu perhatian</h3>
      <div class="tw"><table><thead><tr><th>Provinsi</th><th>Tingkat</th><th class="num">Volume</th><th class="num">Negatif</th><th>Isu dominan</th></tr></thead><tbody>
      ${PROV.filter((p) => p.level !== "hijau").sort((a, b) => LEVEL[b.level].rank - LEVEL[a.level].rank || b.vol - a.vol).slice(0, 5).map((p) => `<tr><td><b>${p.name}</b></td><td>${levelChip(p.level)}</td><td class="num">${fmt.format(p.vol)}</td><td class="num">${pct(p.neg)}</td><td class="small">${p.topIssue}</td></tr>`).join("")}</tbody></table></div></div>
    <div class="stack">${right}
      <div class="card" data-f="S01"><div class="card-h"><h2>Arus masuk</h2><span class="sub">real-time</span></div><div class="ticker" id="ticker">${STREAM.slice(0, 7).map(tickerItem).join("")}</div></div>
    </div>
  </div>
  <div class="card" style="margin-top:16px" data-f="S14"><div class="card-h"><div><h2>Prioritas isu</h2><div class="sub">Diurutkan menurut tingkat peringatan, lalu volume</div></div><button class="btn sm" data-go="prediksi">Buka prediksi →</button></div>
    <div class="tw"><table><thead><tr><th>Isu</th><th>Tingkat</th><th class="num">Volume 24j</th><th>Tren 30 hari</th><th class="num">Pertumbuhan</th><th class="num">Negatif</th><th class="num">Provinsi</th><th>Proyeksi 7 hari</th></tr></thead>
    <tbody>${ISSUES.map((i) => `<tr class="hov" data-act="issue" data-v="${i.code}"><td><b>${i.name}</b><div class="small muted">${i.code} · ${i.owner}</div></td><td>${levelChip(i.level)}</td><td class="num">${fmt.format(i.vol)}</td>
      <td>${sparkline(i.series, { color: i.level === "merah" ? "var(--st-critical)" : "var(--c1)" })}</td><td class="num">${i.growth.toFixed(1)}×</td><td class="num">${pct(i.neg)}</td><td class="num">${i.prov}/38</td>
      <td class="small">${i.growth > 2 ? '<span class="up">▲ terus naik</span>' : i.growth > 1.3 ? '<span class="up">↗ naik</span>' : '<span class="muted">→ stabil</span>'}</td></tr>`).join("")}</tbody></table></div></div>
  <div class="grid g2" style="margin-top:16px">
    <div class="card" data-f="S08"><div class="card-h"><div><h2>Volume harian menurut sentimen</h2><div class="sub">14 hari · semua sumber</div></div>
      <div class="legend"><span><i style="background:var(--pos)"></i>Positif</span><span><i style="background:var(--neu)"></i>Netral</span><span><i style="background:var(--neg)"></i>Negatif</span></div></div>${sentimentColumns(sentDays)}</div>
    <div class="card" data-f="S55"><div class="card-h"><div><h2>Kesehatan kanal distribusi</h2><div class="sub">39 kanal · uji dari 5 titik</div></div><button class="btn sm" data-go="distribusi">Detail →</button></div>
      <div class="grid g3"><div class="tile"><div class="l">Normal</div><div class="v num">37</div></div><div class="tile"><div class="l">Lambat</div><div class="v num">1</div></div><div class="tile"><div class="l">Gangguan</div><div class="v num">1</div></div></div>
      <div class="note warn" style="margin-top:10px">Kanal Nusa Tenggara Barat mengalami gangguan sejak 20.41 (uptime 97,8%). Tiket otomatis dibuat ke tim teknis.</div></div>
  </div>`;
}
function tickerItem(it) {
  return `<div class="it">${srcIcon(it.src)}<div><b style="font-weight:600">${it.title}</b><div class="small muted">${it.handle} · ${PROV_BY[it.prov].name}</div></div><span class="small muted">${ago(it.mins)}</span></div>`;
}

/* ================= 2. MONITORING 360 ================= */
function screenMonitoring() {
  const groups = { semua: "Semua", daring: "Daring", medsos: "Media sosial", siaran: "TV & radio", cetak: "Cetak" };
  const inGroup = (src) => S.src === "semua" || (S.src === "medsos" ? ["instagram", "tiktok", "x", "facebook", "youtube", "threads", "snack"].includes(src) : S.src === "siaran" ? ["tv", "radio"].includes(src) : src === S.src);
  const items = STREAM.filter((i) => inGroup(i.src));
  const sel = STREAM.find((i) => i.id === S.sel) || items[0];
  const isBroadcast = ["tv", "radio", "youtube", "tiktok"].includes(sel.src);
  return head("Penginderaan · Modul 1", "Monitoring Media 360°", "Satu arus untuk media daring, 7 platform media sosial (jalur resmi/berlisensi), siaran TV/radio, dan media cetak.",
    `<button class="btn" data-act="toast" data-msg="Simpan penyaringan sebagai 'Radar' pribadi (purwarupa)">☆ Simpan sebagai radar</button>`) + `
  <div class="card row" style="margin-bottom:16px" data-f="S15">
    <input class="in" style="flex:1 1 260px" placeholder='Cari semantik, mis. "keluhan harga beras di pasar tradisional"' value="harga beras">
    ${Object.entries(groups).map(([k, v]) => `<span class="chip flt ${S.src === k ? "on" : ""}" data-act="src" data-v="${k}">${v}</span>`).join("")}
    <select class="in"><option>Semua isu</option>${ISSUES.map((i) => `<option>${i.name}</option>`).join("")}</select>
    <select class="in"><option>38 provinsi</option>${PROV.map((p) => `<option>${p.name}</option>`).join("")}</select>
    <button class="btn pri">Cari</button></div>
  <div class="grid g-363">
    <div class="card"><div class="card-h"><h2>Saringan</h2></div>
      <h3>Sentimen</h3>${["negatif", "netral", "positif"].map((s) => `<label class="row small" style="margin-bottom:6px"><input type="checkbox" checked> ${sentChip(s)}</label>`).join("")}
      <h3 style="margin-top:14px">Sumber</h3>${Object.entries(SOURCES).map(([k, s]) => `<label class="row small" style="margin-bottom:6px"><input type="checkbox" checked> ${srcIcon(k)} ${s.label}</label>`).join("")}
      <h3 style="margin-top:14px">Keandalan sumber</h3><div class="row">${"ABCDEF".split("").map((r) => `<span class="rel ${r}" title="Keandalan ${r}">${r}</span>`).join("")}</div>
      <h3 style="margin-top:14px" data-f="S10">Indikasi manipulasi</h3><label class="row small"><input type="checkbox"> Hanya yang terindikasi</label>
      <h3 style="margin-top:14px" data-f="S03">Duplikat</h3><label class="row small"><input type="checkbox" checked> Gabungkan salinan (tampilkan jumlah)</label></div>
    <div class="card"><div class="card-h"><h2>${fmt.format(items.length * 3127)} konten</h2><span class="sub">diurutkan terbaru · 48 ditampilkan</span></div>
      <div>${items.map((i) => `<div class="list-item ${i.id === sel.id ? "on" : ""}" data-act="sel" data-v="${i.id}">
        <div class="row" style="align-items:flex-start;flex-wrap:nowrap">${srcIcon(i.src)}<div style="flex:1;min-width:0"><b>${i.title}</b>
        <div class="small muted">${i.handle} · ${PROV_BY[i.prov].name} · ${ago(i.mins)} · jangkauan ${compact(i.reach)}</div>
        <div class="row" style="margin-top:5px;gap:6px">${sentChip(i.sent)}<span class="tag">${ISSUES.find((x) => x.code === i.issue).name}</span>${i.manip ? `<span class="tag" style="border-color:var(--st-serious)">⚑ ${i.manip}</span>` : ""}${i.dup ? `<span class="tag">+${i.dup} salinan</span>` : ""}</div></div>
        <span class="rel ${i.rel}" title="Keandalan sumber ${i.rel}">${i.rel}</span></div></div>`).join("")}</div></div>
    <div class="stack"><div class="card"><div class="card-h"><h2>Rincian</h2>${srcIcon(sel.src)}</div>
      <b>${sel.title}</b>
      <dl class="kv" style="margin-top:10px"><dt>Sumber</dt><dd>${sel.handle} (${SOURCES[sel.src].label})</dd><dt>Wilayah</dt><dd>${sel.kota}, ${PROV_BY[sel.prov].name}</dd>
      <dt>Keterlibatan</dt><dd>${fmt.format(sel.eng)} interaksi</dd><dt>Sentimen</dt><dd>${sentChip(sel.sent)} <span class="small muted">emosi: ${sel.sent === "negatif" ? "marah, cemas" : sel.sent === "positif" ? "lega" : "—"}</span></dd>
      <dt>Isu</dt><dd>${ISSUES.find((x) => x.code === sel.issue).name} <span class="small muted">(model 87%)</span></dd></dl>
      ${isBroadcast ? `<h3 style="margin-top:14px" data-f="S04">Transkrip otomatis</h3><div class="small" style="background:var(--surface-2);padding:10px;border-radius:8px;line-height:1.8">
        <span class="mono muted">00:12</span> …harga beras di pasar ini sudah naik lagi sejak minggu lalu…<br><span class="mono muted">00:27</span> …warga berharap operasi pasar diperluas ke kecamatan…<br><span class="mono muted">00:41</span> <span class="tag">wajah tokoh: Pejabat Daerah (94%)</span> <span class="tag">logo: Kanal Resmi</span></div>` :
      `<h3 style="margin-top:14px" data-f="S07">Entitas terdeteksi</h3><div class="row"><span class="tag">Lokasi: ${sel.kota}</span><span class="tag">Organisasi: Dinas Perdagangan</span><span class="tag">Komoditas: beras</span></div>`}
      ${sel.manip ? `<div class="note warn" style="margin-top:12px" data-f="S10"><b>Indikasi teknik manipulasi: ${sel.manip}.</b> Pola serupa ditemukan pada 23 unggahan dalam 2 jam. Perlu validasi analis.</div>` : ""}
      <div class="row" style="margin-top:14px"><button class="btn sm" data-act="toast" data-msg="Ditambahkan ke bahan kajian">+ Bahan kajian</button><button class="btn sm" data-act="toast" data-msg="Koreksi tersimpan sebagai data latih">Koreksi label</button><button class="btn sm" data-go="aktor">Lihat jejaring</button></div></div>
      <div class="card" data-f="S06"><div class="card-h"><h2>Radar kanal terbuka</h2></div><p class="small muted" style="margin:0 0 8px">Grup dan kanal yang <b>diakses sah</b> (terbuka atau terotorisasi, dasar akses tercatat).</p>
        <div class="list-item"><b>Kanal publik "Info Harga Pangan"</b><div class="small muted">Sinyal viral: 3,4× rata-rata · 18 mnt lalu</div></div></div></div>
  </div>`;
}

/* ================= 3. ANALISIS ISU & AKTOR ================= */
function screenAktor() {
  const iss = ISSUES.find((i) => i.code === S.issue) || ISSUES[0];
  const top = ACTORS.nodes.slice().sort((a, b) => b.score - a.score).slice(0, 7);
  return head("Penginderaan · Modul 1", "Analisis Isu & Jejaring Aktor", `Isu: <b>${iss.name}</b> · ${fmt.format(iss.vol)} konten 24 jam · ${iss.prov} provinsi`,
    `<select class="in" data-act="issuesel">${ISSUES.map((i) => `<option value="${i.code}" ${i.code === iss.code ? "selected" : ""}>${i.name}</option>`).join("")}</select>`) + `
  <div class="grid g-72">
    <div class="card" data-f="S11"><div class="card-h"><div><h2>Graf jejaring penyebaran</h2><div class="sub">Ukuran simpul = Influence Score · klik simpul untuk menyorot hubungannya</div></div>
      <div class="legend">${ACTORS.groups.map((g) => `<span><i style="background:${g.color}"></i>${g.label}</span>`).join("")}</div></div>
      ${networkGraph(ACTORS)}
      <div class="note warn" data-f="S19">Klaster akun anonim (oranye): 8 akun berumur &lt; 30 hari mengunggah teks hampir identik dalam selang 6 menit. Indikasi amplifikasi terkoordinasi, perlu verifikasi analis.</div></div>
    <div class="stack">
      <div class="card" data-f="S11"><div class="card-h"><h2>Aktor paling berpengaruh</h2></div>
        <table><thead><tr><th>Aktor</th><th class="num">Skor</th><th>Peran</th></tr></thead><tbody>${top.map((n) => `<tr><td><span class="d" style="display:inline-block;width:8px;height:8px;border-radius:2px;background:${ACTORS.groups[n.g].color};margin-right:6px"></span>${n.name}</td><td class="num">${n.score}</td><td class="small">${n.shift}</td></tr>`).join("")}</tbody></table></div>
      <div class="card" data-f="S10"><div class="card-h"><h2>Teknik manipulasi kognitif</h2><span class="sub">% konten isu ini</span></div>
        ${hbars([{ label: "Framing ekonomi", value: 31 }, { label: "Fear appeal", value: 22 }, { label: "Konteks keliru", value: 14 }, { label: "Echo chamber", value: 9 }], { width: 360, label: 120, fmtv: (v) => v + "%" })}</div>
      <div class="card" data-f="S13"><div class="card-h"><h2>Profil akun target</h2></div>
        <p class="small muted" style="margin:0">Hanya untuk akun yang ditetapkan lewat <b>otorisasi tercatat</b>, ditinjau ulang setiap 90 hari, dengan retensi terbatas. Setiap akses tercatat di jejak audit.</p>
        <button class="btn sm" style="margin-top:10px" data-act="toast" data-msg="Memerlukan surat otorisasi terlampir (purwarupa)">Ajukan otorisasi profil</button></div>
    </div>
  </div>`;
}

/* ================= 4. PREDIKSI & PERINGATAN ================= */
function screenPrediksi() {
  const iss = ISSUES[0], h = S.horizon;
  const hist = iss.series.slice(-21), last = hist[hist.length - 1];
  const fc = Array.from({ length: h }, (_, i) => Math.round(last * (1 + 0.09 * Math.min(i + 1, 8) - 0.012 * Math.max(0, i - 8) * (i - 8) / 2)));
  const lo = fc.map((v, i) => Math.round(v * (1 - 0.06 - i * 0.012))), hi = fc.map((v, i) => Math.round(v * (1 + 0.06 + i * 0.015)));
  const labels = days(21 + h, 12 + h);
  const sc = { A: [1, 0.86, 0.71, 0.6, 0.52, 0.46, 0.42], B: [1, 0.93, 0.84, 0.77, 0.71, 0.66, 0.62], C: [1, 0.97, 0.95, 0.93, 0.92, 0.9, 0.9] };
  const lanes = [["Baru", [ISSUES[3]]], ["Diterima", [ISSUES[2]]], ["Ditangani", [ISSUES[0], ISSUES[1]]], ["Selesai (7 hari)", [{ name: "Isu kenaikan tarif listrik", level: "kuning", code: "EKO-LISTRIK" }]]];
  return head("Peringatan · Modul 2", "Prediksi & Peringatan Dini", "Proyeksi lintasan isu, peringatan berjenjang, dan uji skenario respons sebelum isu meluas.") + `
  <div class="grid g-72">
    <div class="card" data-f="S16"><div class="card-h"><div><h2>Lintasan isu: ${iss.name}</h2><div class="sub">Model hibrida (deret waktu + dinamika sosial) · akurasi arah 7 hari: 86% pada uji historis</div></div>
      <div class="seg">${[7, 14, 30].map((d) => `<button class="${h === d ? "on" : ""}" data-act="horizon" data-v="${d}">${d} hari</button>`).join("")}</div></div>
      <div class="legend" style="margin-bottom:6px"><span><i class="ln" style="background:var(--c1)"></i>Riwayat</span><span><i class="ln" style="background:var(--c2)"></i>Proyeksi</span><span><i style="background:var(--c2);opacity:.25"></i>Interval 80%</span></div>
      ${forecastChart({ history: hist, forecast: fc, lo, hi, tipping: 4, labels })}
      <div class="note bad" data-f="S17">Diproyeksikan melewati <b>titik kritis</b> dalam 4 hari (volume ±${fmt.format(fc[3])}/hari, sebaran 31 → 35 provinsi) jika tidak ada intervensi.</div></div>
    <div class="stack">
      <div class="card" data-f="S17"><div class="card-h"><h2>Matriks risiko</h2><span class="sub">kemungkinan × dampak</span></div>${riskMatrix()}</div>
      <div class="card" data-f="S21"><div class="card-h"><h2>Saluran notifikasi</h2></div>
        <dl class="kv"><dt>Merah</dt><dd>Dashboard, aplikasi mobile terenkripsi, SMS/WA gateway · &lt; 30 dtk</dd><dt>Oranye</dt><dd>Dashboard + aplikasi mobile</dd><dt>Kuning</dt><dd>Dashboard + ringkasan harian</dd>
        <dt>Eskalasi</dt><dd>Merah tanpa respons 15 menit → atasan berikutnya</dd></dl></div>
    </div>
  </div>
  <div class="card" style="margin-top:16px" data-f="S22"><div class="card-h"><div><h2>Siklus hidup peringatan</h2><div class="sub">Setiap peringatan memiliki penanggung jawab dan riwayat tindak lanjut</div></div></div>
    <div class="kanban">${lanes.map(([t, xs]) => `<div class="kcol"><h4>${t}<span class="muted">${xs.length}</span></h4>${xs.map((x) => `<div class="kcard">${levelChip(x.level)}<div style="margin-top:6px"><b>${x.name}</b></div><div class="small muted">${x.code}${t === "Ditangani" ? " · bahan respons sedang disusun" : ""}</div></div>`).join("")}</div>`).join("")}</div></div>
  <div class="card" style="margin-top:16px" data-f="S18"><div class="card-h"><div><h2>Laboratorium skenario respons</h2><div class="sub">Simulasi berbasis agen (1 juta agen sintetis) · estimasi redaman isu 7 hari</div></div>
    <div class="row"><button class="btn ${S.scenario === "run" ? "" : "pri"}" data-act="sim">${S.scenario === "run" ? "↻ Jalankan ulang" : "▶ Jalankan simulasi"}</button></div></div>
    <div class="grid g3" data-f="S20">${[["A", "Klarifikasi resmi + data harga harian di 39 kanal", "var(--c1)"], ["B", "Klarifikasi resmi saja", "var(--c2)"], ["C", "Tanpa intervensi", "var(--c3)"]].map(([k, d, c]) => `
      <div class="card" style="box-shadow:none"><div class="row between"><b>Skenario ${k}</b><span class="legend"><span><i style="background:${c}"></i></span></span></div><div class="small muted" style="min-height:36px">${d}</div>
      ${S.scenario === "run" ? `${sparkline(sc[k].map((v) => v * 100), { w: 220, h: 44, color: c })}<div class="tile"><div class="v num" style="font-size:22px">${Math.round((1 - sc[k][6]) * 100)}%</div><div class="f">redaman volume isu pada hari ke-7</div></div>` : `<div class="empty small">Belum dijalankan</div>`}</div>`).join("")}</div>
    ${S.scenario === "run" ? `<div class="note" style="margin-top:12px">Rekomendasi: <b>Skenario A</b> meredam 58% volume dalam 7 hari (rentang 49–64%). Waktu komputasi 2 mnt 41 dtk. Hasil disimpan sebagai lampiran kajian.</div>` : ""}</div>`;
}
function riskMatrix() {
  const W = 320, H = 220, m = 30, pts = ISSUES.map((i, k) => ({ i, px: Math.min(0.95, i.growth / 5 + 0.05), py: Math.min(0.95, i.neg * 0.6 + i.prov / 38 * 0.4) }));
  return `<div class="viz"><svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Matriks risiko">
    <rect x="${m}" y="0" width="${W - m}" height="${H - m}" fill="var(--surface-2)" rx="6"/>
    <rect x="${m + (W - m) / 2}" y="0" width="${(W - m) / 2}" height="${(H - m) / 2}" fill="color-mix(in srgb, var(--st-critical) 10%, transparent)" rx="6"/>
    <text x="${W - 6}" y="14" text-anchor="end" class="lbl">Prioritas</text>
    <text x="${m + (W - m) / 2}" y="${H - 8}" text-anchor="middle">Kemungkinan eskalasi →</text>
    <text x="10" y="${(H - m) / 2}" transform="rotate(-90 10 ${(H - m) / 2})" text-anchor="middle">Dampak →</text>
    ${pts.map((p) => { const x = m + p.px * (W - m), y = (1 - p.py) * (H - m); const l = LEVEL[p.i.level];
      return `<g data-tip="${esc(`<b>${p.i.name}</b>${Tip.row("Tingkat", l.label, l.color)}${Tip.row("Pertumbuhan", p.i.growth.toFixed(1) + "×")}${Tip.row("Negatif", pct(p.i.neg))}`)}"><circle cx="${x}" cy="${y}" r="7" fill="${l.color}" stroke="var(--surface)" stroke-width="2"/>${l.rank >= 2 ? `<text x="${p.px > 0.6 ? x - 11 : x + 11}" y="${y + 4}" text-anchor="${p.px > 0.6 ? "end" : "start"}" class="lbl">${p.i.code}</text>` : ""}</g>`; }).join("")}
  </svg></div>`;
}

/* ================= 5. KAJIAN ================= */
function screenKajian() {
  const src = [
    ["Data Harga Pangan Harian — Dinas Perdagangan (rilis 12 Nov)", "A", "Harga beras medium naik 11,4% dalam 14 hari di 23 provinsi."],
    ["Portal Nusantara — laporan pasar Surabaya", "B", "Antrean operasi pasar di Surabaya mencapai 1.200 warga."],
    ["Transkrip TV Berita 24, 11 Nov 19.02", "B", "Pedagang menyebut pasokan dari sentra produksi berkurang."],
    ["Klaster unggahan media sosial (412 unggahan)", "D", "Narasi 'beras langka sampai akhir tahun' menyebar di 18 provinsi."],
  ];
  const c = (n) => `<sup class="cite" data-tip="${esc(`<b>[${n}] ${src[n - 1][0]}</b>${Tip.row("Keandalan", src[n - 1][1])}<div style="margin-top:4px;color:var(--text-2)">“${src[n - 1][2]}”</div>`)}">[${n}]</sup>`;
  return head("Penilaian · Modul 3", "Kajian Pimpinan", "Sintesis berbasis Graph RAG on-premise. Setiap klaim tertaut ke bukti, kalimat tanpa sumber ditandai.",
    `<button class="btn" data-act="toast" data-msg="Ekspor DOCX ber-watermark (purwarupa)">DOCX</button><button class="btn" data-act="toast" data-msg="Ekspor PDF ber-watermark (purwarupa)">PDF</button><button class="btn" data-act="toast" data-msg="Bahan paparan PPTX disusun (purwarupa)">Paparan PPTX</button><button class="btn pri" data-go="persetujuan">Ajukan ke pimpinan</button>`) + `
  <div class="grid g-363">
    <div class="card" data-f="S23"><div class="card-h"><h2>Permintaan</h2></div>
      <label class="f">Jenis produk<select class="in"><option>Telaahan staf</option><option>Kajian strategis</option><option>Nota dinas</option><option>Bahan paparan</option></select></label>
      <label class="f" style="margin-top:10px">Pertanyaan pimpinan<textarea class="in">Apa penyebab eskalasi isu harga beras dalam 3 hari terakhir dan langkah komunikasi yang disarankan?</textarea></label>
      <label class="f" style="margin-top:10px">Rentang data<select class="in"><option>14 hari terakhir</option><option>30 hari</option></select></label>
      <h3 style="margin-top:14px">Basis bukti</h3><div class="small">${fmt.format(18421)} dokumen · 3 transkrip siaran · 2 kajian sebelumnya · graf 1.204 entitas</div>
      <button class="btn pri" style="margin-top:12px;width:100%" data-act="toast" data-msg="Draf disusun ulang dalam 24 detik (purwarupa)">Susun draf</button></div>
    <div class="card" data-f="S25"><div class="card-h"><h2>Draf telaahan staf</h2><span class="chip"><span class="d" style="background:var(--c4)">!</span>Draf · v2</span></div>
      <div class="doc-paper wm" data-wm="TERBATAS · Analis Media · 13-11-2026 09.14\nTERBATAS · Analis Media · 13-11-2026 09.14">
        <h4>TELAAHAN STAF</h4><div style="text-align:center;font-family:system-ui;font-size:12px;color:var(--muted);margin-bottom:16px">Eskalasi Isu Harga Beras · Klasifikasi: TERBATAS</div>
        <b style="font-family:system-ui">1. Pokok persoalan</b>
        <p><span class="hl-claim">Harga beras medium naik 11,4% dalam 14 hari dan terjadi di 23 provinsi</span>${c(1)}. <span class="hl-claim">Antrean panjang operasi pasar di kota besar memperkuat persepsi kelangkaan</span>${c(2)}${c(3)}.</p>
        <b style="font-family:system-ui">2. Fakta-fakta</b>
        <p><span class="hl-claim">Narasi "beras langka sampai akhir tahun" menyebar di 18 provinsi melalui klaster akun baru</span>${c(4)}. <span class="hl-nosrc" data-tip="<b>Klaim tanpa sumber</b>Tambahkan bukti atau hapus kalimat ini sebelum diajukan.">Sebagian besar warga sudah mengurangi pembelian beras premium.</span></p>
        <b style="font-family:system-ui">3. Saran tindak</b>
        <p>a. Publikasikan data harga harian per provinsi melalui 39 kanal (skenario A, redaman 58%).<br>b. Perluas operasi pasar ke 10 kabupaten dengan antrean tertinggi.<br>c. Siapkan klarifikasi resmi atas narasi kelangkaan.</p>
      </div></div>
    <div class="stack"><div class="card" data-f="S24"><div class="card-h"><h2>Ketertelusuran</h2></div>
      <div class="grid g2" style="gap:8px"><div class="tile"><div class="l">Klaim bersumber</div><div class="v num" style="font-size:22px">4/5</div></div><div class="tile"><div class="l">Presisi sitasi</div><div class="v num" style="font-size:22px">94%</div></div></div>
      <div class="note bad" style="margin-top:10px">1 kalimat tanpa sumber (ditandai merah).</div>
      <h3 style="margin-top:12px">Sumber</h3>${src.map(([t, r], i) => `<div class="row small" style="flex-wrap:nowrap;align-items:flex-start;margin-bottom:8px"><span class="rel ${r}">${r}</span><span><b>[${i + 1}]</b> ${t}</span></div>`).join("")}
      <div class="small muted">Keandalan A (sangat andal) s.d. F (tidak dapat dinilai).</div></div>
      <div class="card"><div class="card-h"><h2>Lampiran otomatis</h2></div><div class="small">▤ Grafik lintasan isu 30 hari<br>⬡ Peta jejaring aktor<br>◔ Hasil simulasi skenario A/B/C</div></div></div>
  </div>`;
}

/* ================= 6. STUDIO KONTEN ================= */
function screenStudio() {
  const variants = ["Informatif berbasis data", "Empatik pada warga", "Tegas meluruskan hoaks", "Solutif & ajakan aksi", "Lokal: bahasa sehari-hari"];
  const text = [
    "Pemerintah memastikan stok beras nasional mencukupi hingga akhir tahun. Data harian menunjukkan pasokan di gudang mencapai 1,6 juta ton. Operasi pasar diperluas ke 10 kabupaten mulai Senin.",
    "Kami memahami kenaikan harga beras terasa berat bagi keluarga. Karena itu operasi pasar diperluas ke 10 kabupaten mulai Senin, dengan harga Rp12.500/kg.",
    "Kabar bahwa beras akan langka sampai akhir tahun tidak benar. Stok nasional mencapai 1,6 juta ton dan penyaluran berjalan normal.",
    "Cek lokasi operasi pasar terdekat di kanal resmi provinsi Anda. Mulai Senin, 10 kabupaten tambahan melayani pembelian beras Rp12.500/kg.",
    "Dulur-dulur, beras aman! Operasi pasar bakal ada di 10 kabupaten tambahan mulai Senin, harganya Rp12.500 sekilo.",
  ];
  const plat = { portal: "Portal kanal", instagram: "Instagram", x: "X", tiktok: "TikTok" };
  const v = S.variant;
  return head("Aksi · Modul 4", "Studio Konten", "Draf multi-format yang dilokalkan ke 38 provinsi, diperiksa agen editorial AI, lalu ditinjau manusia.") + `
  <div class="grid g-27">
    <div class="card" data-f="S26"><div class="card-h"><h2>Brief</h2></div>
      <label class="f">Isu<select class="in">${ISSUES.slice(0, 4).map((i) => `<option>${i.name}</option>`).join("")}</select></label>
      <label class="f" style="margin-top:10px">Format</label><div class="row" data-f="S29">${["Rilis", "Opini", "Infografis", "Video 9:16", "Podcast"].map((f, i) => `<span class="chip flt ${i === 0 ? "on" : ""}">${f}</span>`).join("")}</div>
      <label class="f" style="margin-top:10px" data-f="S28">Pelokalan<select class="in"><option>Nasional</option><option>Jawa Timur</option><option>Sulawesi Selatan</option><option>Semua 38 provinsi (batch)</option></select></label>
      <label class="f" style="margin-top:10px">Pesan kunci<textarea class="in">Stok aman · operasi pasar diperluas · cek kanal resmi</textarea></label>
      <label class="f" style="margin-top:10px">Fakta terverifikasi yang dipakai</label>
      <div class="small"><label class="row"><input type="checkbox" checked> Stok gudang 1,6 juta ton <span class="rel A">A</span></label><label class="row"><input type="checkbox" checked> Harga OP Rp12.500/kg <span class="rel A">A</span></label><label class="row"><input type="checkbox" checked> 10 kabupaten tambahan <span class="rel B">B</span></label></div>
      <button class="btn pri" style="margin-top:12px;width:100%" data-act="toast" data-msg="5 variasi disusun dalam 11 detik (purwarupa)">Hasilkan 5 variasi</button></div>
    <div class="stack">
      <div class="card" data-f="S27"><div class="card-h"><h2>Variasi sudut pandang</h2><span class="sub">pilih untuk disunting</span></div>
        <div class="grid g3" style="grid-template-columns:repeat(5,minmax(0,1fr))">${variants.map((t, i) => `<div class="kcard ${i === v ? "" : ""}" style="${i === v ? "border-color:var(--accent);box-shadow:0 0 0 2px var(--accent-soft)" : ""}" data-act="variant" data-v="${i}"><b class="small">${i + 1}. ${t}</b><div class="small muted" style="margin-top:4px">${text[i].slice(0, 70)}…</div></div>`).join("")}</div></div>
      <div class="grid g2">
        <div class="card"><div class="card-h"><h2>Penyuntingan</h2><span class="chip">v3 · disimpan otomatis</span></div>
          <input class="in" style="width:100%;font-weight:600;margin-bottom:8px" value="Stok Beras Aman, Operasi Pasar Diperluas ke 10 Kabupaten">
          <textarea class="in" style="min-height:150px">${text[v]}</textarea>
          <h3 style="margin-top:12px" data-f="S32">Agen editorial AI</h3>
          <div class="small" style="line-height:2">
            <div class="row between"><span>✓ Ejaan & PUEBI</span><span class="muted">0 temuan</span></div>
            <div class="row between"><span>✓ Ujaran kebencian / SARA</span><span class="muted">aman</span></div>
            <div class="row between"><span>! Nada</span><span class="muted">1 saran: "bakal" → "akan" (varian resmi)</span></div>
            <div class="row between"><span>✓ Fakta vs sumber terverifikasi</span><span class="muted">3/3 cocok</span></div>
            <div class="row between"><span>Usulan judul</span><span class="muted">4 alternatif</span></div></div></div>
        <div class="card" data-f="S33"><div class="card-h"><h2>Pratinjau per platform</h2></div>
          <div class="seg" style="margin-bottom:12px">${Object.entries(plat).map(([k, l]) => `<button class="${S.platform === k ? "on" : ""}" data-act="platform" data-v="${k}">${l}</button>`).join("")}</div>
          ${previewFrame(S.platform, text[v])}
          <div class="row between" style="margin-top:12px"><span class="small muted">Skor verifikasi fakta: <b>96</b>/100</span><button class="btn pri" data-go="persetujuan" data-f="S36">Ajukan untuk ditinjau</button></div>
          <div class="note" style="margin-top:10px">Tidak ada konten yang dapat dipublikasikan tanpa persetujuan manusia.</div></div>
      </div>
    </div>
  </div>`;
}
function previewFrame(p, t) {
  const label = `<div class="ai-label">◇ Disusun dengan bantuan AI dan telah ditinjau redaksi</div>`;
  if (p === "portal") return `<div class="preview-frame" style="max-width:none"><div style="height:120px;background:linear-gradient(135deg,var(--s3),var(--s6))"></div><div style="padding:12px"><div class="small muted">Kanal Nasional · Rilis</div><b>Stok Beras Aman, Operasi Pasar Diperluas ke 10 Kabupaten</b><p class="small" style="margin:6px 0 0">${t}</p></div>${label}</div>`;
  if (p === "instagram") return `<div class="preview-frame"><div class="row small" style="padding:8px"><span class="avatar" style="width:24px;height:24px">KR</span><b>kanal.resmi</b></div><div style="aspect-ratio:1;background:linear-gradient(135deg,var(--c1),var(--c3));color:#fff;display:grid;place-items:center;text-align:center;padding:24px;font-weight:800;font-size:20px">STOK BERAS AMAN<br><span style="font-size:13px;font-weight:600">Operasi pasar +10 kabupaten</span></div><div class="small" style="padding:8px">${t.slice(0, 120)}…</div>${label}</div>`;
  if (p === "x") return `<div class="preview-frame" style="padding:12px"><div class="row small"><span class="avatar" style="width:28px;height:28px">KR</span><b>Kanal Resmi</b><span class="muted">@kanalresmi</span></div><p style="margin:8px 0">${t.slice(0, 260)}</p><div class="small muted">${t.length} karakter</div>${label}</div>`;
  return `<div class="preview-frame" style="max-width:220px"><div style="aspect-ratio:9/16;background:linear-gradient(180deg,#111,var(--s6));color:#fff;padding:14px;display:flex;flex-direction:column;justify-content:flex-end"><b>Stok beras aman ✓</b><span class="small">Takarir otomatis · 0:42</span></div>${label}</div>`;
}

/* ================= 7. PERSETUJUAN & KALENDER ================= */
function screenPersetujuan() {
  const cols = [["draf", "Draf"], ["ditinjau", "Ditinjau"], ["disetujui", "Disetujui"], ["tayang", "Tayang"]];
  const d = DRAFTS.find((x) => x.id === S.draft) || DRAFTS[1];
  const canApprove = S.role === "pimpinan" && d.status === "ditinjau";
  return head("Aksi · Modul 5", "Persetujuan Berjenjang & Kalender", "Satu rantai persetujuan dari draf hingga tayang, dengan tanda tangan digital dan jejak audit yang tidak dapat diubah.",
    `<div class="seg"><button class="${S.kTab === "kanban" ? "on" : ""}" data-act="ktab" data-v="kanban">Alur</button><button class="${S.kTab === "kalender" ? "on" : ""}" data-act="ktab" data-v="kalender">Kalender</button></div>`) +
  (S.kTab === "kanban" ? `<div class="grid g-72"><div class="kanban" data-f="S36">${cols.map(([k, l]) => { const xs = DRAFTS.filter((x) => x.status === k);
    return `<div class="kcol"><h4>${l}<span class="muted">${xs.length}</span></h4>${xs.map((x) => `<div class="kcard" data-act="draft" data-v="${x.id}" style="${x.id === d.id ? "border-color:var(--accent)" : ""}"><div class="row between"><span class="tag">${x.kind}</span>${x.ai ? '<span class="tag">◇ AI</span>' : ""}</div><b style="display:block;margin-top:6px">${x.title}</b><div class="small muted">${x.author}${x.prov ? " · " + PROV_BY[x.prov].name : " · Nasional"}</div></div>`).join("")}</div>`; }).join("")}</div>
    <div class="card"><div class="card-h"><h2>${d.title}</h2><span class="chip">${d.status}</span></div>
      ${d.ai ? '<div class="note" style="margin-bottom:10px">◇ Konten dibantu AI · wajib melalui tahap Ditinjau</div>' : ""}
      <h3>Jejak persetujuan</h3><ul class="timeline">
        <li class="ok"><b>Draf dibuat</b> · ${d.author}<div class="small muted">12 Nov 15.10 · hash isi 9f2c…a71e</div></li>
        <li class="${["ditinjau", "disetujui", "tayang"].includes(d.status) ? "ok" : ""}"><b>Diajukan untuk ditinjau</b><div class="small muted">12 Nov 15.48</div></li>
        <li class="${["disetujui", "tayang"].includes(d.status) ? "ok" : d.status === "ditinjau" ? "cur" : ""}"><b>Persetujuan pimpinan</b><div class="small muted">${["disetujui", "tayang"].includes(d.status) ? "Ditandatangani 12 Nov 21.14 · sertifikat HSM · 3b8e…c0d2" : "Menunggu"}</div></li>
        <li class="${d.status === "tayang" ? "ok" : ""}"><b>Tayang di kanal</b><div class="small muted">${d.status === "tayang" ? "Nasional + 12 provinsi" : "Terjadwal setelah disetujui"}</div></li></ul>
      <label class="f" style="margin-top:6px">Catatan (wajib bila dikembalikan)<textarea class="in" style="min-height:60px"></textarea></label>
      <div class="row" style="margin-top:10px">
        <button class="btn pri" ${canApprove ? "" : "disabled style='opacity:.5;cursor:not-allowed'"} data-act="toast" data-msg="Disetujui & ditandatangani (purwarupa)">Setujui & tandatangani</button>
        <button class="btn" ${canApprove ? "" : "disabled style='opacity:.5'"}>Kembalikan</button></div>
      <div class="small muted" style="margin-top:8px">${S.role === "pimpinan" ? (d.status === "ditinjau" ? "Anda dapat menyetujui naskah ini." : "Pilih naskah berstatus Ditinjau.") : "Hanya peran Pimpinan yang dapat menyetujui. Ganti peran di bilah atas."}</div></div></div>`
  : `<div class="card" data-f="S34"><div class="card-h"><h2>Kalender distribusi · 13–19 Nov</h2><div class="legend"><span><i style="background:var(--c1)"></i>Rilis</span><span><i style="background:var(--c3)"></i>Infografis/video</span><span><i style="background:var(--c4)"></i>Audio</span></div></div>
     <div class="tw"><table><thead><tr><th>Kanal</th>${["Kam 13", "Jum 14", "Sab 15", "Min 16", "Sen 17", "Sel 18", "Rab 19"].map((x) => `<th>${x}</th>`).join("")}</tr></thead><tbody>
     ${["Kanal Nasional", "Kanal Jawa Timur", "Kanal Jawa Barat", "Kanal Sulawesi Selatan", "Akun resmi Instagram", "Akun resmi X"].map((c, r) => `<tr><td><b>${c}</b></td>${Array.from({ length: 7 }, (_, i) => { const has = (r + i) % 3 === 0 || (r === 0 && i < 5); const col = ["var(--c1)", "var(--c3)", "var(--c4)"][(r + i) % 3];
       return `<td>${has ? `<div style="border-left:3px solid ${col};background:var(--surface-2);padding:4px 6px;border-radius:4px" class="small"><b>${["09.00", "12.30", "19.00"][(i + r) % 3]}</b> ${DRAFTS[(r + i) % DRAFTS.length].title.slice(0, 26)}…</div>` : ""}${r === 1 && i === 4 ? '<div class="small" style="color:var(--bad-text);margin-top:3px">⚠ bentrok jadwal</div>' : ""}</td>`; }).join("")}</tr>`).join("")}</tbody></table></div></div>`);
}

/* ================= 8. KANAL RESMI ================= */
function screenKanal() {
  return head("Aksi · Modul 6", "Kanal Media Sosial Resmi", "Pengelolaan akun resmi institusi, triase komentar, dan kemitraan yang transparan.") + `
  <div class="note" style="margin-bottom:16px">Modul ini hanya mengelola <b>akun resmi</b> dan <b>kemitraan yang diungkapkan</b> (berlabel). Publikasi selalu lewat API resmi platform. Fitur akun samaran, sidik jari perangkat, dan penghindaran deteksi platform <b>tidak termasuk</b> dalam rancangan.</div>
  <div class="grid g-72">
    <div class="card" data-f="S43"><div class="card-h"><div><h2>Triase komentar</h2><div class="sub">Semua akun resmi · masking data pribadi aktif</div></div>
      <div class="note bad" style="margin:0;padding:6px 10px">⚠ Serangan <i>bot swarm</i> terdeteksi: 3 akun, teks identik, 4 menit</div></div>
      <table><thead><tr><th>Komentar</th><th>Klasifikasi</th><th>Saran respons</th><th></th></tr></thead><tbody>
      ${COMMENTS.map((c) => `<tr><td><b class="small">${c.who}</b>${c.bot ? ' <span class="tag" style="border-color:var(--st-critical)">bot?</span>' : ""}<div>${c.text}</div></td><td>${sentChip(c.sent)}<div class="small muted" style="margin-top:3px">${c.cls}</div></td><td class="small">${c.sugg}</td>
        <td><button class="btn sm" data-act="toast" data-msg="${c.bot ? "Ditandai untuk dilaporkan ke platform" : "Draf balasan dibuka (perlu persetujuan)"}">${c.bot ? "Laporkan" : "Balas"}</button></td></tr>`).join("")}</tbody></table></div>
    <div class="stack">
      <div class="card" data-f="S40"><div class="card-h"><h2>Akun resmi</h2><span class="sub">token tersimpan di vault · MFA wajib</span></div>
        ${ACCOUNTS.map((a) => `<div class="row between" style="padding:7px 0;border-bottom:1px solid var(--grid)"><span class="row">${srcIcon(a.platform)}<span><b class="small">${a.handle}</b><div class="small muted">${a.followers} pengikut · ${a.posts} jadwal hari ini</div></span></span><span class="small ${a.status === "Sehat" ? "muted" : "up"}">${a.status === "Sehat" ? "✓ " : "⚠ "}${a.status}</span></div>`).join("")}</div>
      <div class="card" data-f="S41"><div class="card-h"><h2>Bahan respons cepat</h2></div>
        <div class="list-item"><b>#12 Klarifikasi bansos</b><div class="small muted">Q&A 6 butir · fact-sheet · petunjuk juru bicara · disetujui</div></div>
        <div class="list-item"><b>#13 Harga beras & operasi pasar</b><div class="small muted">Draf · menunggu data Dinas</div></div></div>
    </div>
  </div>
  <div class="card" style="margin-top:16px" data-f="S42"><div class="card-h"><div><h2>Kemitraan pemuka opini (KOL)</h2><div class="sub">Setiap kemitraan wajib memiliki kontrak tercatat dan label kemitraan pada konten</div></div><button class="btn sm">+ Kemitraan</button></div>
    <table><thead><tr><th>Mitra</th><th>Kategori</th><th class="num">Jangkauan</th><th class="num">Kolaborasi</th><th>Pengungkapan</th><th>Efektivitas</th></tr></thead><tbody>
    ${KOL.map((k) => `<tr><td><b>${k.name}</b></td><td>${k.cat}</td><td class="num">${k.reach}</td><td class="num">${k.collab}</td><td>${k.disclosure.startsWith("Menunggu") ? `<span class="up">⚠ ${k.disclosure}</span>` : `✓ ${k.disclosure}`}</td>
      <td>${k.eff == null ? '<span class="muted small">—</span>' : `<div class="bar-in" style="width:120px" data-tip="${esc(`<b>${k.name}</b>${Tip.row("Efektivitas", pct(k.eff))}`)}"><i style="width:${k.eff * 100}%"></i></div>`}</td></tr>`).join("")}</tbody></table></div>`;
}

/* ================= 9. DISTRIBUSI ================= */
function screenDistribusi() {
  const hours = ["06", "08", "10", "12", "14", "16", "18", "20", "22"];
  const provs = ["DKI Jakarta", "Jawa Barat", "Jawa Timur", "Sumatera Utara", "Sulawesi Selatan", "Papua"];
  const heat = provs.map((p, r) => hours.map((h, c) => Math.round(30 + 60 * Math.exp(-Math.pow(c - (r % 2 ? 6 : 2), 2) / 4) + (r * 7 + c * 3) % 11)));
  return head("Aksi · Modul 7", "Distribusi 39 Kanal Wilayah", "Satu sumber ke 38 kanal provinsi dan 1 kanal nasional, dengan penjadwalan cerdas dan pemantauan ketersediaan 24/7.",
    `<div class="seg"><button class="${S.chTab === "grid" ? "on" : ""}" data-act="chtab" data-v="grid">Status kanal</button><button class="${S.chTab === "sindikasi" ? "on" : ""}" data-act="chtab" data-v="sindikasi">Sindikasi</button></div>`) +
  (S.chTab === "grid" ? `<div class="grid g4" style="margin-bottom:16px">
    <div class="card tile" data-f="S55"><div class="l">Ketersediaan rata-rata</div><div class="v num">99,93%</div><div class="f">target SLA 99,9%</div></div>
    <div class="card tile" data-f="S50"><div class="l">Publikasi 24 jam</div><div class="v num">412</div><div class="f">dari 37 naskah sumber</div></div>
    <div class="card tile" data-f="S49"><div class="l">Sinkronisasi ke 39 situs</div><div class="v num">3,2 dtk</div><div class="f">p95 · target &lt; 5 dtk</div></div>
    <div class="card tile" data-f="S56"><div class="l">Pembaca unik 24 jam</div><div class="v num">1,9 jt</div><div class="f"><span class="down">▲ 12%</span></div></div></div>
  <div class="card" data-f="S55"><div class="card-h"><h2>Status 39 kanal</h2><div class="legend"><span><i style="background:var(--st-good)"></i>Normal</span><span><i style="background:var(--st-warning)"></i>Lambat</span><span><i style="background:var(--st-critical)"></i>Gangguan</span></div></div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px">${CHANNELS.map((c) => { const st = c.status === "normal" ? ["var(--st-good)", "✓"] : c.status === "lambat" ? ["var(--st-warning)", "!"] : ["var(--st-critical)", "■"];
      return `<div class="kcard" style="margin:0" data-tip="${esc(`<b>${c.name}</b>${Tip.row("Uptime 30 hari", c.uptime.toFixed(2) + "%")}${Tip.row("Latensi", c.lat + " ms")}${Tip.row("Terbit 24 jam", c.pub)}${Tip.row("Pembaca", fmt.format(c.readers))}`)}">
        <div class="row between"><b class="small">${c.name.replace("Kanal ", "")}</b><span class="chip" style="padding:0 6px"><span class="d" style="background:${st[0]};color:${c.status === "lambat" ? "#0b0b0b" : "#fff"}">${st[1]}</span></span></div><div class="small muted num">${c.uptime.toFixed(2)}% · ${c.lat} ms</div></div>`; }).join("")}</div></div>`
  : `<div class="grid g2"><div class="card" data-f="S52"><div class="card-h"><h2>Aset master → 12 format turunan</h2></div>
      <div class="row" style="align-items:stretch;flex-wrap:nowrap;gap:12px"><div class="kcard" style="flex:0 0 150px;margin:0"><b>Master</b><div class="small muted">Rilis + infografis 4K + video 16:9</div></div><div style="display:grid;place-items:center">→</div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;flex:1">${["Portal 16:9", "IG feed 1:1", "IG story 9:16", "TikTok 9:16", "X 16:9", "YouTube 16:9", "Shorts 9:16", "Facebook 4:5", "Thumbnail", "Radio 60 dtk", "Cetak A4", "OpenGraph"].map((f) => `<span class="tag" style="text-align:center;padding:4px">${f}</span>`).join("")}</div></div>
      <div class="note" style="margin-top:12px">Metadata OpenGraph/Schema.org dibuat otomatis per platform tujuan.</div></div>
    <div class="card" data-f="S51"><div class="card-h"><div><h2>Jam tayang terbaik</h2><div class="sub">Kunjungan pembaca per jam (WIB) · makin gelap makin ramai</div></div></div>
      <div class="tw"><table class="heat"><thead><tr><th></th>${hours.map((h) => `<th style="text-align:center">${h}</th>`).join("")}</tr></thead><tbody>
      ${heat.map((r, i) => `<tr><td class="small" style="padding:0 8px;border:0;text-align:left">${provs[i]}</td>${r.map((v, j) => { const k = Math.min(6, Math.floor(v / 15)); const bg = ["var(--s1)", "var(--s2)", "var(--s3)", "var(--s4)", "var(--s5)", "var(--s6)", "var(--s7)"][k];
        return `<td style="background:${bg};color:${k >= 4 ? "#fff" : "var(--text)"}" data-tip="${esc(`<b>${provs[i]} · ${hours[j]}.00</b>${Tip.row("Indeks kunjungan", v)}`)}">${k >= 5 ? "★" : ""}</td>`; }).join("")}</tr>`).join("")}</tbody></table></div>
      <div class="small muted" style="margin-top:6px">★ = rekomendasi jadwal otomatis (dapat diubah operator)</div></div></div>
    <div class="card" style="margin-top:16px" data-f="S54"><div class="card-h"><h2>Gerbang API mitra</h2></div><div class="grid g4"><div class="tile"><div class="l">Mitra K/L terhubung</div><div class="v num">14</div></div><div class="tile"><div class="l">Permintaan 24 jam</div><div class="v num">82 rb</div></div><div class="tile"><div class="l">Ditolak WAF</div><div class="v num">312</div></div><div class="tile"><div class="l">Token berwaktu aktif</div><div class="v num">27</div></div></div></div>`);
}

/* ================= 10. DAMPAK ================= */
function screenDampak() {
  const ch = [{ label: "Kanal Nasional", value: 820 }, { label: "Instagram resmi", value: 640 }, { label: "TikTok resmi", value: 590 }, { label: "Kanal Jawa Timur", value: 210 }, { label: "X resmi", value: 180 }, { label: "Kanal Jawa Barat", value: 160 }];
  return head("Evaluasi · Modul 8", "Pengukuran Dampak Komunikasi", "Kampanye: <b>Operasi Pasar & Stok Beras</b> · 5–12 November",
    `<select class="in"><option>Kampanye Operasi Pasar & Stok Beras</option><option>Klarifikasi Bansos</option></select><button class="btn" data-act="toast" data-msg="Laporan mingguan PDF/DOCX disusun (purwarupa)">Laporan berkala</button>`) + `
  <div class="grid g4">
    <div class="card tile" data-f="S59"><div class="l">IEKP kampanye</div><div class="v num">72<span class="small muted">/100</span></div><div class="f">kategori: efektif</div></div>
    <div class="card tile" data-f="S56"><div class="l">Jangkauan unik</div><div class="v num">4,8 jt</div><div class="f">39 kanal + 5 akun resmi</div></div>
    <div class="card tile" data-f="S57"><div class="l">Pergeseran sentimen</div><div class="v num">+0,21</div><div class="f">indeks −0,46 → −0,25 · signifikan (p &lt; 0,01)</div></div>
    <div class="card tile" data-f="S59"><div class="l">Biaya per 1.000 jangkauan</div><div class="v num">Rp 1.840</div><div class="f"><span class="down">▼ 23%</span> vs kampanye sebelumnya</div></div></div>
  <div class="grid g2" style="margin-top:16px">
    <div class="card" data-f="S57"><div class="card-h"><div><h2>Sentimen sebelum vs sesudah kampanye</h2><div class="sub">Indeks −1 (negatif) s.d. +1 (positif)</div></div>
      <div class="legend"><span><i style="background:var(--neu)"></i>Sebelum</span><span><i style="background:var(--pos)"></i>Sesudah (positif)</span><span><i style="background:var(--neg)"></i>Sesudah (negatif)</span></div></div>
      ${beforeAfter([{ label: "DKI Jakarta", before: -0.52, after: -0.21 }, { label: "Jawa Timur", before: -0.61, after: -0.18 }, { label: "Jawa Barat", before: -0.44, after: -0.3 }, { label: "Sulawesi Selatan", before: -0.38, after: 0.05 }, { label: "Sumatera Utara", before: -0.29, after: -0.24 }, { label: "Nasional", before: -0.46, after: -0.25 }])}</div>
    <div class="card" data-f="S56"><div class="card-h"><div><h2>Keterlibatan per kanal</h2><div class="sub">ribu interaksi · 7 hari</div></div></div>${hbars(ch, { width: 520, label: 150, fmtv: (v) => v + " rb" })}</div></div>
  <div class="grid g2" style="margin-top:16px">
    <div class="card" data-f="S58"><div class="card-h"><h2>Pergeseran peran aktor</h2></div><table><thead><tr><th>Aktor</th><th>Sebelum</th><th>Sesudah</th></tr></thead><tbody>
      <tr><td>@infokota</td><td>Penyebar keluhan</td><td><b>Pembagi informasi resmi</b></td></tr><tr><td>Warta Kini</td><td>Netral</td><td>Mengutip data resmi</td></tr><tr><td>Klaster akun anonim</td><td>Penguat narasi kelangkaan</td><td class="muted">Aktivitas turun 64%</td></tr></tbody></table></div>
    <div class="card" data-f="S61"><div class="card-h"><h2>Laporan otomatis</h2></div>
      ${["Laporan harian situasi · 07.00", "Ringkasan mingguan pimpinan · Senin 08.00", "Laporan bulanan efektivitas · tanggal 1"].map((t) => `<div class="row between" style="padding:8px 0;border-bottom:1px solid var(--grid)"><span>${t}</span><span class="row"><span class="tag">PDF</span><span class="tag">DOCX</span></span></div>`).join("")}</div></div>`;
}

/* ================= 11. REPOSITORI ================= */
function screenRepositori() {
  const d = DOCS.find((x) => x.id === S.docSel) || DOCS[1];
  return head("Memori · Modul 9", "Repositori Pengetahuan", "Lumbung resmi semua kajian, laporan, transkrip, dan aset, dengan klasifikasi, versi, dan watermark per unduhan.",
    `<button class="btn pri" data-act="toast" data-msg="Formulir unggah dengan metadata wajib (purwarupa)">+ Unggah</button>`) + `
  <div class="card row" style="margin-bottom:16px" data-f="S70"><input class="in" style="flex:1" value="antrean operasi pasar Surabaya" placeholder="Pencarian teks penuh & semantik"><select class="in"><option>Semua klasifikasi yang dapat Anda akses</option></select><select class="in"><option>Semua unit</option></select><button class="btn pri">Cari</button></div>
  <div class="grid g-72">
    <div class="card"><table><thead><tr><th>Dokumen</th><th>Klasifikasi</th><th>Unit</th><th class="num">Versi</th><th>Diperbarui</th></tr></thead><tbody>
      ${DOCS.map((x) => `<tr class="hov ${x.id === d.id ? "sel" : ""}" data-act="doc" data-v="${x.id}"><td><b>${x.title}</b>${x.id === 202 ? '<div class="small muted">…<mark>antrean operasi pasar</mark> di <mark>Surabaya</mark> mencapai 1.200 warga… (hal. 3)</div>' : ""}</td><td><span class="chip cls-${x.cls}">${CLS_LABEL[x.cls]}</span></td><td class="small">${x.unit}</td><td class="num">v${x.ver}</td><td class="small">${x.upd}</td></tr>`).join("")}
    </tbody></table><div class="small muted" style="margin-top:8px">Dokumen di atas clearance Anda tidak ditampilkan sama sekali.</div></div>
    <div class="stack"><div class="card" data-f="S71"><div class="card-h"><h2>${d.title}</h2><span class="chip cls-${d.cls}">${CLS_LABEL[d.cls]}</span></div>
      <div class="wm" data-wm="${CLS_LABEL[d.cls].toUpperCase()} · ${ROLES[S.role]} · NIP 1990…0005\nIP 10.20.4.17 · 13-11-2026 09.20 · WM-8f21c0" style="background:var(--surface-2);border-radius:8px;height:140px;display:grid;place-items:center;color:var(--muted)">Pratinjau dokumen</div>
      <dl class="kv" style="margin-top:12px" data-f="S68"><dt>Unit pembuat</dt><dd>${d.unit}</dd><dt>Periode</dt><dd>5–12 Nov 2026</dd><dt>Asal sumber</dt><dd>Analisis internal</dd><dt>Retensi</dt><dd>5 tahun aktif, lalu musnah (JRA)</dd><dt>Integritas</dt><dd class="mono">SHA-256 ✓ 4a30…c5488</dd></dl>
      <div class="row" style="margin-top:12px"><button class="btn sm pri" data-act="toast" data-msg="Diunduh dengan watermark WM-8f21c0 · tercatat di log">Unduh (ber-watermark)</button><button class="btn sm" data-act="toast" data-msg="Dokumen di-check-out untuk Anda">Check-out</button></div></div>
      <div class="card" data-f="S67"><div class="card-h"><h2>Versi</h2></div><ul class="timeline">${Array.from({ length: d.ver }, (_, i) => d.ver - i).map((v) => `<li class="${v === d.ver ? "cur" : "ok"}"><b>v${v}</b> · ${v === d.ver ? "versi aktif" : "arsip"}<div class="small muted">Analis Media · ${12 - (d.ver - v)} Nov</div></li>`).join("")}</ul></div>
      <div class="card" data-f="S72"><div class="card-h"><h2>Izin akses berdurasi</h2></div><div class="small">analis2 (DIT-PRD) · s.d. 19 Nov · “Dukungan kajian bersama”</div></div></div>
  </div>
  <div class="card" style="margin-top:16px" data-f="S75"><div class="card-h"><div><h2>Portal wajib lapor unit kerja</h2><div class="sub">Kepatuhan laporan berkala 2026</div></div></div>
    <div class="tw"><table><thead><tr><th>Unit</th>${["Jul", "Agu", "Sep", "Okt", "Nov"].map((m) => `<th style="text-align:center">${m}</th>`).join("")}</tr></thead><tbody>
    ${["Direktorat Analisis Media", "Direktorat Produksi", "Direktorat Kanal & Distribusi", "Bagian Arsip & Data"].map((u, r) => `<tr><td>${u}</td>${[0, 1, 2, 3, 4].map((m) => { const st = r === 2 && m === 4 ? ["!", "Terlambat", "var(--st-warning)"] : m === 4 && r === 3 ? ["…", "Belum jatuh tempo", "var(--neu)"] : ["✓", "Tepat waktu", "var(--st-good)"];
      return `<td style="text-align:center"><span class="chip" style="padding:0 7px" title="${st[1]}"><span class="d" style="background:${st[2]};color:${st[0] === "!" ? "#0b0b0b" : "#fff"}">${st[0]}</span>${st[1]}</span></td>`; }).join("")}</tr>`).join("")}</tbody></table></div></div>`;
}

/* ================= 12. AUDIT & TATA KELOLA ================= */
function screenTatakelola() {
  return head("Tata Kelola · Modul 8 & 9", "Audit & Tata Kelola AI", "Jejak audit WORM berantai hash, kendali akses, dan registri model AI.",
    `<button class="btn pri" data-act="verify">Verifikasi integritas rantai</button>`) + `
  <div id="verify-res"></div>
  <div class="grid g-72">
    <div class="card" data-f="S65"><div class="card-h"><div><h2>Jejak audit</h2><div class="sub">Append-only · setiap baris mengunci hash baris sebelumnya</div></div><input class="in" placeholder="Saring aksi/pengguna"></div>
      <table><thead><tr><th>Waktu</th><th>Pengguna</th><th>Aksi</th><th>Rincian</th><th>Hash</th></tr></thead><tbody>
      ${AUDIT.map((a, i) => `<tr><td class="small">${a[0]}</td><td>${a[1]}</td><td class="mono">${a[2]}</td><td class="small">${a[3]}${a[2].includes("ditolak") ? ' <span class="tag" style="border-color:var(--st-critical)">ditolak</span>' : ""}</td><td class="mono muted">${(0x9f2ca71e ^ (i * 2654435761)).toString(16).slice(-8)}…</td></tr>`).join("")}</tbody></table></div>
    <div class="stack">
      <div class="card" data-f="S64"><div class="card-h"><h2>Registri model AI</h2><span class="sub">semua on-premise</span></div>
        ${MODELS.map((m) => `<div style="padding:8px 0;border-bottom:1px solid var(--grid)"><div class="row between"><b class="small">${m.name}</b><span class="tag">${m.ver}</span></div><div class="small muted">${m.metric} · bias: ${m.bias} · <b>${m.st}</b></div></div>`).join("")}</div>
      <div class="card" data-f="S72"><div class="card-h"><h2>Matriks akses</h2></div>
        <table class="small"><thead><tr><th>Peran</th><th>Terbuka</th><th>Terbatas</th><th>Rahasia</th><th>S. Rahasia</th></tr></thead><tbody>
        ${[["Pimpinan", 1, 1, 1, 1], ["Analis", 1, 1, 1, 0], ["Redaktur", 1, 1, 0, 0], ["Operator", 1, 1, 0, 0], ["Auditor", "log", "log", "log", "log"]].map((r) => `<tr><td>${r[0]}</td>${r.slice(1).map((v) => `<td>${v === 1 ? "✓" : v === 0 ? '<span class="muted">—</span>' : '<span class="muted">log saja</span>'}</td>`).join("")}</tr>`).join("")}</tbody></table>
        <div class="small muted" style="margin-top:6px">Rahasia: unit pemilik atau izin berdurasi. Sangat Rahasia: izin eksplisit.</div></div>
    </div>
  </div>`;
}

/* ================= kerangka & interaksi ================= */
const SCREENS = { alur: screenAlur, beranda: screenBeranda, monitoring: screenMonitoring, aktor: screenAktor, prediksi: screenPrediksi, kajian: screenKajian,
  studio: screenStudio, persetujuan: screenPersetujuan, kanal: screenKanal, distribusi: screenDistribusi, dampak: screenDampak, repositori: screenRepositori, tatakelola: screenTatakelola };

function renderNav() {
  $("#nav-items").innerHTML = NAV.map((g) => (g.grp ? `<div class="grp">${g.grp}</div>` : "") + g.items.map(([r, ic, l, mod, pill]) =>
    `<a href="#${r}" class="${S.route === r ? "on" : ""}" title="${mod ? "Modul " + mod : ""}"><span class="ic">${ic}</span>${l}${pill ? `<span class="pill">${pill}</span>` : ""}</a>`).join("")).join("");
}
function render() {
  const r = location.hash.slice(1) || "alur";
  S.route = SCREENS[r] ? r : "alur";
  renderNav();
  $("#content").innerHTML = SCREENS[S.route]();
  $$(".seg[data-roles] button").forEach((b) => b.classList.toggle("on", b.dataset.v === S.role));
  document.body.classList.toggle("annot", S.annot);
  $("#nav").classList.remove("open");
  if (S.route === "aktor") bindGraph();
  window.scrollTo(0, 0);
}
function rerender() { const y = window.scrollY; $("#content").innerHTML = SCREENS[S.route](); if (S.route === "aktor") bindGraph(); window.scrollTo(0, y); }

function bindGraph() {
  const svg = $("#netviz svg"); if (!svg) return;
  svg.addEventListener("click", (e) => {
    const n = e.target.closest(".node");
    $$(".hl", svg).forEach((x) => x.classList.remove("hl"));
    if (!n) { svg.classList.remove("dim"); return; }
    const id = n.dataset.n; svg.classList.add("dim"); n.classList.add("hl");
    $$(".edge", svg).forEach((ed) => { const [a, b] = ed.dataset.e.split("-"); if (a === id || b === id) { ed.classList.add("hl"); svg.querySelector(`.node[data-n="${a === id ? b : a}"]`).classList.add("hl"); } });
  });
}

document.addEventListener("click", (e) => {
  const go = e.target.closest("[data-go]");
  if (go) { location.hash = go.dataset.go; return; }
  const a = e.target.closest("[data-act]"); if (!a) return;
  const v = a.dataset.v;
  switch (a.dataset.act) {
    case "role": S.role = v; $$("[data-act=role]").forEach((b) => b.classList.toggle("on", b.dataset.v === v)); $("#who-role").textContent = ROLES[v]; $("#avatar").textContent = ROLES[v].slice(0, 2).toUpperCase(); toast("Peran: " + ROLES[v]); rerender(); break;
    case "annot": S.annot = !S.annot; document.body.classList.toggle("annot", S.annot); $("#annot-btn").classList.toggle("on", S.annot); if (S.route === "alur") rerender(); break;
    case "theme": { const cur = document.documentElement.getAttribute("data-theme"); const dark = cur ? cur === "dark" : matchMedia("(prefers-color-scheme: dark)").matches; document.documentElement.setAttribute("data-theme", dark ? "light" : "dark"); break; }
    case "map": S.mapMetric = v; rerender(); break;
    case "src": S.src = v; rerender(); break;
    case "sel": S.sel = +v; rerender(); break;
    case "issue": S.issue = v; location.hash = "aktor"; break;
    case "horizon": S.horizon = +v; rerender(); break;
    case "sim": S.scenario = null; rerender(); toast("Simulasi berjalan… 1.000.000 agen"); setTimeout(() => { S.scenario = "run"; rerender(); }, 900); break;
    case "variant": S.variant = +v; rerender(); break;
    case "platform": S.platform = v; rerender(); break;
    case "ktab": S.kTab = v; rerender(); break;
    case "draft": S.draft = +v; rerender(); break;
    case "chtab": S.chTab = v; rerender(); break;
    case "doc": S.docSel = +v; rerender(); break;
    case "bell": openDrawer(); break;
    case "close": closeDrawer(); break;
    case "menu": $("#nav").classList.toggle("open"); break;
    case "palette": openPalette(); break;
    case "verify": $("#verify-res").innerHTML = `<div class="note" style="margin-bottom:16px">Memverifikasi 1.284.502 peristiwa…</div>`; setTimeout(() => { $("#verify-res").innerHTML = `<div class="note" style="margin-bottom:16px;border-color:var(--st-good)">✓ Rantai utuh: 1.284.502 peristiwa diverifikasi · hash kepala 9f2c…a71e · jangkar harian ditandatangani HSM</div>`; }, 900); break;
    case "toast": toast(a.dataset.msg); break;
  }
});
document.addEventListener("change", (e) => { if (e.target.matches("[data-act=issuesel]")) { S.issue = e.target.value; rerender(); } });

function openDrawer() {
  $("#drawer").innerHTML = `<div class="row between" style="margin-bottom:12px"><h2>Peringatan aktif</h2><button class="btn ghost" data-act="close">✕</button></div>` +
    ISSUES.filter((i) => i.level !== "hijau").map((i) => `<div class="list-item" data-go="prediksi">${levelChip(i.level, false)}<div style="margin-top:6px"><b>${i.name}</b></div><div class="small muted">Volume ${fmt.format(i.vol)} · ${i.growth.toFixed(1)}× · negatif ${pct(i.neg)}</div></div>`).join("") +
    `<div class="note" style="margin-top:12px">Notifikasi Merah juga dikirim ke aplikasi mobile terenkripsi dan gateway SMS.</div>`;
  $("#drawer").classList.add("open"); $("#scrim").classList.add("open");
}
function closeDrawer() { $("#drawer").classList.remove("open"); $("#scrim").classList.remove("open"); $("#palette").classList.remove("open"); }

function openPalette() {
  const items = NAV.flatMap((g) => g.items.map(([r, , l]) => ({ l, k: "Layar", go: r }))).concat(ISSUES.map((i) => ({ l: i.name, k: "Isu", go: "aktor", issue: i.code })), DOCS.map((d) => ({ l: d.title, k: "Dokumen", go: "repositori", doc: d.id })));
  const p = $("#palette"); p.classList.add("open"); $("#scrim").classList.add("open");
  const input = $("input", p), res = $(".res", p);
  const draw = () => { const q = input.value.toLowerCase(); res.innerHTML = items.filter((x) => x.l.toLowerCase().includes(q)).slice(0, 12).map((x, i) => `<div data-i="${items.indexOf(x)}" class="${i === 0 ? "on" : ""}"><span>${x.l}</span><span class="small muted">${x.k}</span></div>`).join("") || '<div class="muted">Tidak ditemukan</div>'; };
  input.value = ""; draw(); input.focus();
  input.oninput = draw;
  const choose = (x) => { if (x.issue) S.issue = x.issue; if (x.doc) S.docSel = x.doc; closeDrawer(); if (location.hash.slice(1) === x.go) rerender(); else location.hash = x.go; };
  res.onclick = (e) => { const d = e.target.closest("[data-i]"); if (d) choose(items[+d.dataset.i]); };
  input.onkeydown = (e) => { if (e.key === "Enter") { const d = $("[data-i]", res); if (d) choose(items[+d.dataset.i]); } if (e.key === "Escape") closeDrawer(); };
}
document.addEventListener("keydown", (e) => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k") { e.preventDefault(); openPalette(); } if (e.key === "Escape") closeDrawer(); });
$("#scrim").addEventListener("click", closeDrawer);

/* arus masuk berjalan di Pusat Komando */
let tick = 7;
setInterval(() => {
  const t = $("#ticker"); if (!t) return;
  const it = STREAM[tick++ % STREAM.length];
  t.insertAdjacentHTML("afterbegin", tickerItem({ ...it, mins: 0 }));
  if (t.children.length > 8) t.lastElementChild.remove();
}, 3500);

window.addEventListener("hashchange", render);
render();
