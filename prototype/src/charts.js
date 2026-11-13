/* SISTRA purwarupa — komponen grafik SVG (tanpa pustaka). Spesifikasi: garis 2px, batang ≤24px,
   ujung data membulat 4px, celah permukaan 2px, grid tipis, legenda + tooltip, label teks memakai tinta teks. */
"use strict";

const fmt = new Intl.NumberFormat("id-ID");
const pct = (x) => Math.round(x * 100) + "%";
const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

function niceTicks(max, n = 4) {
  if (max <= 0) return { max: 4, ticks: [0, 1, 2, 3, 4] };
  const raw = max / n, mag = 10 ** Math.floor(Math.log10(raw));
  const step = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((s) => s >= raw);
  const top = Math.ceil(max / step) * step, ticks = [];
  for (let t = 0; t <= top + 1e-9; t += step) ticks.push(+t.toFixed(4));
  return { max: top, ticks };
}
const compact = (v) => v >= 1e6 ? (v / 1e6).toFixed(1).replace(".", ",") + " jt" : v >= 1e4 ? Math.round(v / 1e3) + " rb" : fmt.format(v);

function colPath(x, y, w, h, round = true) {
  if (h <= 0) return "";
  const r = round ? Math.min(4, h, w / 2) : 0;
  return `M${x},${y + h}V${y + r}${r ? `A${r},${r} 0 0 1 ${x + r},${y}` : ""}H${x + w - r}${r ? `A${r},${r} 0 0 1 ${x + w},${y + r}` : ""}V${y + h}Z`;
}
function barPath(x, y, w, h) {
  if (w <= 0) return "";
  const r = Math.min(4, w, h / 2);
  return `M${x},${y}H${x + w - r}A${r},${r} 0 0 1 ${x + w},${y + r}V${y + h - r}A${r},${r} 0 0 1 ${x + w - r},${y + h}H${x}Z`;
}

/* tooltip global: elemen dengan data-tip (HTML) */
const Tip = (() => {
  let el;
  function ensure() { if (!el) { el = document.createElement("div"); el.className = "tip"; document.body.appendChild(el); } return el; }
  document.addEventListener("mousemove", (e) => {
    const t = e.target.closest && e.target.closest("[data-tip]");
    const tip = ensure();
    if (!t) { tip.style.display = "none"; return; }
    tip.innerHTML = t.getAttribute("data-tip");
    tip.style.display = "block";
    const w = tip.offsetWidth, h = tip.offsetHeight;
    let x = e.clientX + 14, y = e.clientY + 14;
    if (x + w > innerWidth - 8) x = e.clientX - w - 14;
    if (y + h > innerHeight - 8) y = e.clientY - h - 14;
    tip.style.left = x + "px"; tip.style.top = y + "px";
  });
  return { row: (k, v, color) => `<div class="r"><span>${color ? `<i style="display:inline-block;width:8px;height:8px;border-radius:2px;background:${color};margin-right:6px"></i>` : ""}${k}</span><b>${v}</b></div>` };
})();

function sparkline(vals, { w = 110, h = 28, color = "var(--c1)" } = {}) {
  const max = Math.max(...vals), min = Math.min(...vals), dx = w / (vals.length - 1);
  const y = (v) => h - 3 - ((v - min) / (max - min || 1)) * (h - 6);
  const d = vals.map((v, i) => `${i ? "L" : "M"}${(i * dx).toFixed(1)},${y(v).toFixed(1)}`).join("");
  return `<svg class="spark" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" aria-hidden="true"><path d="${d}" fill="none" stroke="${color}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/><circle cx="${w}" cy="${y(vals[vals.length - 1])}" r="3" fill="${color}" stroke="var(--surface)" stroke-width="2"/></svg>`;
}

/* Kurva riwayat + proyeksi dengan interval kepercayaan */
function forecastChart({ history, forecast, lo, hi, tipping, width = 760, height = 260, labels }) {
  const m = { t: 14, r: 16, b: 26, l: 44 }, iw = width - m.l - m.r, ih = height - m.t - m.b;
  const all = history.concat(forecast, hi);
  const { max, ticks } = niceTicks(Math.max(...all));
  const n = history.length + forecast.length;
  const x = (i) => m.l + (i / (n - 1)) * iw, y = (v) => m.t + ih - (v / max) * ih;
  const hist = history.map((v, i) => `${i ? "L" : "M"}${x(i)},${y(v)}`).join("");
  const f0 = history.length - 1;
  const fc = [history[f0]].concat(forecast).map((v, i) => `${i ? "L" : "M"}${x(f0 + i)},${y(v)}`).join("");
  const band = [history[f0]].concat(hi).map((v, i) => `${i ? "L" : "M"}${x(f0 + i)},${y(v)}`).join("") +
    [history[f0]].concat(lo).map((v, i) => [x(f0 + i), y(v)]).reverse().map(([a, b]) => `L${a},${b}`).join("") + "Z";
  const pts = history.map((v, i) => ({ i, v, k: "Riwayat" })).concat(forecast.map((v, i) => ({ i: f0 + 1 + i, v, k: "Proyeksi", lo: lo[i], hi: hi[i] })));
  return `<div class="viz"><svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Riwayat dan proyeksi volume isu">
    ${ticks.map((t) => `<line x1="${m.l}" x2="${width - m.r}" y1="${y(t)}" y2="${y(t)}" stroke="${t ? "var(--grid)" : "var(--axis)"}"/><text x="${m.l - 8}" y="${y(t)}" dy=".32em" text-anchor="end">${compact(t)}</text>`).join("")}
    <rect x="${x(f0)}" y="${m.t}" width="${x(n - 1) - x(f0)}" height="${ih}" fill="var(--surface-2)" opacity=".6"/>
    <path d="${band}" fill="var(--c2)" opacity=".14"/>
    <path d="${hist}" fill="none" stroke="var(--c1)" stroke-width="2" stroke-linejoin="round"/>
    <path d="${fc}" fill="none" stroke="var(--c2)" stroke-width="2" stroke-dasharray="5 4" stroke-linejoin="round"/>
    <line x1="${x(f0)}" x2="${x(f0)}" y1="${m.t}" y2="${m.t + ih}" stroke="var(--text-2)" stroke-width="1"/>
    <text x="${x(f0) + 6}" y="${m.t + 10}" class="lbl">Hari ini</text>
    ${tipping != null ? `<circle cx="${x(f0 + tipping)}" cy="${y(forecast[tipping - 1] || forecast[0])}" r="5" fill="var(--st-critical)" stroke="var(--surface)" stroke-width="2"/><text x="${x(f0 + tipping) + 8}" y="${y(forecast[tipping - 1] || forecast[0]) - 8}" class="lbl">Titik kritis (tipping point)</text>` : ""}
    ${labels.map((l, i) => i % Math.ceil(n / 8) === 0 || i === n - 1 ? `<text x="${x(i)}" y="${height - 6}" text-anchor="middle">${l}</text>` : "").join("")}
    ${pts.map((p) => `<rect x="${x(p.i) - iw / n / 2}" y="${m.t}" width="${iw / n}" height="${ih}" fill="transparent" data-tip="${esc(`<b>${labels[p.i]}</b>${Tip.row(p.k, fmt.format(p.v))}${p.lo != null ? Tip.row("Interval 80%", fmt.format(p.lo) + "–" + fmt.format(p.hi)) : ""}`)}"/>`).join("")}
  </svg></div>`;
}

/* Kolom bertumpuk sentimen harian */
function sentimentColumns(days, { width = 640, height = 220 } = {}) {
  const m = { t: 12, r: 8, b: 24, l: 40 }, iw = width - m.l - m.r, ih = height - m.t - m.b;
  const { max, ticks } = niceTicks(Math.max(...days.map((d) => d.neg + d.neu + d.pos)));
  const band = iw / days.length, bw = Math.min(24, band * 0.6), y = (v) => m.t + ih - (v / max) * ih;
  const order = [["neg", "var(--neg)", "Negatif"], ["neu", "var(--neu)", "Netral"], ["pos", "var(--pos)", "Positif"]];
  return `<div class="viz"><svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Volume harian menurut sentimen">
    ${ticks.map((t) => `<line x1="${m.l}" x2="${width - m.r}" y1="${y(t)}" y2="${y(t)}" stroke="${t ? "var(--grid)" : "var(--axis)"}"/><text x="${m.l - 8}" y="${y(t)}" dy=".32em" text-anchor="end">${compact(t)}</text>`).join("")}
    ${days.map((d, i) => {
      const x0 = m.l + i * band + (band - bw) / 2; let acc = 0; const tot = d.neg + d.neu + d.pos;
      const segs = order.map(([k, c], j) => { const v = d[k]; const y0 = y(acc + v), h = y(acc) - y0 - (acc ? 2 : 0); acc += v; return `<path d="${colPath(x0, y0, bw, h, j === 2)}" fill="${c}"/>`; }).join("");
      const tip = `<b>${d.label}</b>` + order.slice().reverse().map(([k, c, l]) => Tip.row(l, fmt.format(d[k]), c)).join("") + Tip.row("Total", fmt.format(tot));
      return `<g>${segs}${i % 2 === 0 || i === days.length - 1 ? `<text x="${x0 + bw / 2}" y="${height - 6}" text-anchor="middle">${d.label}</text>` : ""}<rect x="${m.l + i * band}" y="${m.t}" width="${band}" height="${ih}" fill="transparent" data-tip="${esc(tip)}"/></g>`;
    }).join("")}
  </svg></div>`;
}

/* Batang horizontal tunggal (satu seri) */
function hbars(items, { width = 420, row = 26, label = 150, color = "var(--c1)", fmtv = (v) => fmt.format(v), tip } = {}) {
  const max = Math.max(...items.map((d) => d.value)), iw = width - label - 46, h = items.length * row;
  return `<div class="viz"><svg viewBox="0 0 ${width} ${h}" role="img" aria-label="Peringkat">
    <line x1="${label}" x2="${label}" y1="0" y2="${h}" stroke="var(--axis)"/>
    ${items.map((d, i) => { const w = (d.value / max) * iw, y0 = i * row + 6; return `<g>
      <text x="${label - 8}" y="${y0 + 7}" dy=".32em" text-anchor="end" class="lbl">${esc(d.label)}</text>
      <path d="${barPath(label, y0, w, 14)}" fill="${d.color || color}"/>
      <text x="${label + w + 6}" y="${y0 + 7}" dy=".32em" class="lbl">${fmtv(d.value)}</text>
      <rect x="0" y="${i * row}" width="${width}" height="${row}" fill="transparent" ${tip ? `data-tip="${esc(tip(d))}"` : ""}/></g>`; }).join("")}
  </svg></div>`;
}

/* Kartogram ubin 38 provinsi */
function tileMap(metric = "vol") {
  const s = 44, g = 4, cols = 17, rows = 7, W = cols * (s + g), H = rows * (s + g);
  const vals = PROV.map((p) => p[metric === "neg" ? "neg" : "vol"]);
  const max = Math.max(...vals), min = Math.min(...vals);
  const ramp = ["var(--s1)", "var(--s2)", "var(--s3)", "var(--s4)", "var(--s5)", "var(--s6)", "var(--s7)"];
  const col = (v) => ramp[Math.min(6, Math.floor(((v - min) / (max - min || 1)) * 6.999))];
  return `<div class="viz tilemap"><svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Kartogram 38 provinsi">
    ${PROV.map((p) => { const v = metric === "neg" ? p.neg : p.vol, x = p.col * (s + g), y = p.row * (s + g), c = col(v);
      const dark = ramp.indexOf(c) >= 4; const lv = LEVEL[p.level];
      return `<g><rect class="t" x="${x}" y="${y}" width="${s}" height="${s}" rx="7" fill="${c}" data-prov="${p.code}"
        data-tip="${esc(`<b>${p.name}</b>${Tip.row("Volume 24 jam", fmt.format(p.vol))}${Tip.row("Sentimen negatif", pct(p.neg))}${Tip.row("Isu dominan", p.topIssue)}${Tip.row("Tingkat", lv.label, lv.color)}`)}"/>
        <text class="tl" x="${x + s / 2}" y="${y + s / 2 - 2}" text-anchor="middle" style="fill:${dark ? "#fff" : "var(--text)"}">${p.short}</text>
        ${p.level !== "hijau" ? `<rect x="${x + s - 14}" y="${y + 4}" width="10" height="10" rx="2" fill="${lv.color}" stroke="var(--surface)" stroke-width="1.5"/>` : ""}
        <text class="tl" x="${x + s / 2}" y="${y + s / 2 + 11}" text-anchor="middle" style="fill:${dark ? "#fff" : "var(--text-2)"};font-weight:500">${metric === "neg" ? pct(v) : compact(v)}</text></g>`; }).join("")}
  </svg></div>`;
}

/* Graf jejaring aktor */
function networkGraph(data, { width = 760, height = 470 } = {}) {
  const color = (g) => data.groups[g].color;
  return `<div class="viz" id="netviz"><svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Graf jejaring aktor">
    <g>${data.edges.map(([a, b], i) => { const A = data.nodes[a], B = data.nodes[b]; return `<line class="edge" data-e="${a}-${b}" x1="${A.x}" y1="${A.y}" x2="${B.x}" y2="${B.y}" stroke-width="1"/>`; }).join("")}</g>
    <g>${(() => { const placed = [], labelled = new Set();
      data.nodes.slice().sort((a, b) => b.score - a.score).forEach((n) => {
        if (n.score < 55 || placed.length >= 6) return;
        const ly = n.y - (5 + n.score / 9) - 6;
        if (placed.some(([px, py]) => Math.abs(px - n.x) < 110 && Math.abs(py - ly) < 16)) return;
        placed.push([n.x, ly]); labelled.add(n.id); });
      return data.nodes.map((n) => { const r = 5 + n.score / 9; return `<g class="node" data-n="${n.id}" data-tip="${esc(`<b>${n.name}</b>${Tip.row("Influence Score", n.score)}${Tip.row("Unggahan isu", n.posts)}${Tip.row("Peran", n.shift)}${Tip.row("Klaster", data.groups[n.g].label)}`)}">
      <circle cx="${n.x}" cy="${n.y}" r="${r}" fill="${color(n.g)}"/>${labelled.has(n.id) ? `<text x="${n.x}" y="${n.y - r - 6}" text-anchor="middle" class="lbl">${esc(n.name)}</text>` : ""}</g>`; }).join(""); })()}</g>
  </svg></div>`;
}

/* Batang divergen sebelum vs sesudah */
function beforeAfter(items, { width = 520 } = {}) {
  const row = 34, h = items.length * row + 22, label = 160, mid = label + (width - label) / 2, half = (width - label) / 2 - 30;
  return `<div class="viz"><svg viewBox="0 0 ${width} ${h}" role="img" aria-label="Pergeseran sentimen">
    <line x1="${mid}" x2="${mid}" y1="0" y2="${h - 20}" stroke="var(--axis)"/>
    <text x="${mid - half}" y="${h - 4}" class="small">← lebih negatif</text><text x="${mid + half}" y="${h - 4}" text-anchor="end">lebih positif →</text>
    ${items.map((d, i) => { const y0 = i * row + 4; const b = d.before * half, a = d.after * half;
      return `<g data-tip="${esc(`<b>${d.label}</b>${Tip.row("Indeks sebelum", d.before.toFixed(2))}${Tip.row("Indeks sesudah", d.after.toFixed(2))}${Tip.row("Perubahan", (d.after - d.before > 0 ? "+" : "") + (d.after - d.before).toFixed(2))}`)}">
        <text x="${label - 8}" y="${y0 + 11}" dy=".32em" text-anchor="end" class="lbl">${esc(d.label)}</text>
        <rect x="${Math.min(mid, mid + b)}" y="${y0}" width="${Math.abs(b)}" height="10" rx="3" fill="var(--neu)"/>
        <rect x="${Math.min(mid, mid + a)}" y="${y0 + 12}" width="${Math.abs(a)}" height="10" rx="3" fill="${d.after >= 0 ? "var(--pos)" : "var(--neg)"}"/>
        <rect x="0" y="${i * row}" width="${width}" height="${row}" fill="transparent"/></g>`; }).join("")}
  </svg></div>`;
}

function levelChip(level, short = true) {
  const l = LEVEL[level];
  return `<span class="chip" title="${l.label}"><span class="d" style="background:${l.color};color:${l.ink}">${l.icon}</span>${short ? l.short : l.label}</span>`;
}
function sentChip(s) {
  const m = { negatif: ["var(--neg)", "−"], netral: ["var(--neu)", "·"], positif: ["var(--pos)", "+"] }[s];
  return `<span class="chip"><span class="d" style="background:${m[0]}">${m[1]}</span>${s[0].toUpperCase() + s.slice(1)}</span>`;
}
function srcIcon(src) { const s = SOURCES[src]; return `<span class="src-ic" style="background:${s.color}" title="${s.label}">${s.ic}</span>`; }
