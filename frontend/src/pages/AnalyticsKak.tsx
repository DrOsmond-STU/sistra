import { useState } from "react";
import { api } from "../api";
import { BarList, Tile } from "../components/kit";
import { Msg, useLoad, useWidth } from "../components/ui";
import { nf } from "../format";
import { AI_FEATURE_LABEL } from "../types47";

// ------------------------------------------------------------ S56 metrik keterlibatan per jam
interface Eng { totals: { views: number; uniques: number; clicks: number; comments: number; avg_time_sec: number; video_retention: number; ctr: number };
  series: { hour: string; views: number; uniques: number; clicks: number; comments: number; avg_time_sec: number; retention: number }[]; daily: { date: string; views: number; uniques: number; clicks: number; comments: number }[] }

function HourChart({ data, height = 190 }: { data: Eng["series"]; height?: number }) {
  const [ref, width] = useWidth<HTMLDivElement>();
  const [hover, setHover] = useState<number | null>(null);
  const m = { t: 10, r: 6, b: 22, l: 46 };
  const iw = Math.max(0, width - m.l - m.r), ih = height - m.t - m.b;
  const max = Math.max(1, ...data.map((d) => d.views));
  const x = (i: number) => (data.length > 1 ? (i / (data.length - 1)) * iw : 0);
  const y = (v: number) => ih - (v / max) * ih;
  const line = (k: "views" | "uniques") => data.map((d, i) => `${i ? "L" : "M"}${x(i).toFixed(1)},${y(d[k]).toFixed(1)}`).join("");
  const hd = hover !== null ? data[hover] : null;
  const ticks = [0, 0.5, 1].map((f) => Math.round(max * f));
  return (
    <div className="viz" ref={ref} onMouseLeave={() => setHover(null)}>
      {width > 0 && (
        <svg width={width} height={height} role="img" aria-label="Tayangan dan pembaca unik per jam">
          <g transform={`translate(${m.l},${m.t})`}>
            {ticks.map((t) => <g key={t}><line x1={0} x2={iw} y1={y(t)} y2={y(t)} stroke={t === 0 ? "var(--axis)" : "var(--grid)"} /><text x={-6} y={y(t)} dy="0.32em" textAnchor="end">{nf.format(t)}</text></g>)}
            <path d={`${line("views")}L${iw},${ih}L0,${ih}Z`} fill="var(--seq)" opacity={0.12} />
            <path d={line("views")} fill="none" stroke="var(--seq)" strokeWidth={2} />
            <path d={line("uniques")} fill="none" stroke="var(--seq-2)" strokeWidth={2} strokeDasharray="4 3" />
            {data.map((d, i) => (i % Math.max(1, Math.ceil(data.length / 8)) === 0 ? <text key={d.hour} x={x(i)} y={ih + 15} textAnchor="middle">{new Date(d.hour).toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" })}</text> : null))}
            {hd && hover !== null && <line x1={x(hover)} x2={x(hover)} y1={0} y2={ih} stroke="var(--axis)" />}
            <rect width={iw} height={ih} fill="transparent" onMouseMove={(e) => { const r = (e.currentTarget as SVGRectElement).getBoundingClientRect(); setHover(Math.max(0, Math.min(data.length - 1, Math.round(((e.clientX - r.left) / iw) * (data.length - 1))))); }} />
          </g>
        </svg>
      )}
      {hd && hover !== null && (
        <div className="tooltip" style={{ left: Math.min(m.l + x(hover) + 10, width - 170), top: 4 }}>
          <div className="t">{new Date(hd.hour).toLocaleString("id-ID", { weekday: "short", hour: "2-digit", minute: "2-digit" })}</div>
          <div className="r"><span>Tayangan</span><b className="num">{nf.format(hd.views)}</b></div><div className="r"><span>Pembaca unik</span><b className="num">{nf.format(hd.uniques)}</b></div>
          <div className="r"><span>Klik</span><b className="num">{nf.format(hd.clicks)}</b></div><div className="r"><span>Waktu baca</span><b className="num">{hd.avg_time_sec} dtk</b></div>
        </div>
      )}
    </div>
  );
}

export function EngagementTab() {
  const [hours, setHours] = useState(48);
  const [ch, setCh] = useState("");
  const chans = useLoad(() => api<{ code: string; name: string }[]>("/m7/channels"), []);
  const e = useLoad(() => api<Eng>("/m8/engagement", { query: { hours, channel: ch || undefined } }), [hours, ch]);
  const t = e.data?.totals;
  return (
    <div className="stack">
      <div className="row">
        <select value={ch} onChange={(x) => setCh(x.target.value)} aria-label="Kanal"><option value="">Seluruh 39 kanal</option>{chans.data?.map((c) => <option key={c.code} value={c.code}>{c.name}</option>)}</select>
        <select value={hours} onChange={(x) => setHours(Number(x.target.value))} aria-label="Rentang"><option value={24}>24 jam</option><option value={48}>48 jam</option><option value={72}>72 jam</option></select>
        <span className="small muted">Metrik terkonsolidasi per jam dan harian (S56)</span>
      </div>
      {t && (
        <div className="grid g6">
          <Tile label="Tayangan" value={nf.format(t.views)} /><Tile label="Pembaca unik" value={nf.format(t.uniques)} /><Tile label="Klik (CTR)" value={nf.format(t.clicks)} foot={`${(t.ctr * 100).toFixed(1)}%`} />
          <Tile label="Rerata waktu baca" value={`${t.avg_time_sec} dtk`} /><Tile label="Komentar" value={nf.format(t.comments)} /><Tile label="Retensi video" value={`${t.video_retention}%`} />
        </div>
      )}
      <div className="card"><div className="viz-head"><h2 style={{ margin: 0 }}>Per jam</h2><div className="legend"><span><i style={{ background: "var(--seq)" }} />Tayangan</span><span><i style={{ background: "var(--seq-2)" }} />Pembaca unik</span></div></div>
        {e.data && <HourChart data={e.data.series} />}</div>
      <div className="card table-wrap"><h2>Harian (7 hari)</h2>
        <table><thead><tr><th>Tanggal</th><th className="num">Tayangan</th><th className="num">Pembaca unik</th><th className="num">Klik</th><th className="num">Komentar</th></tr></thead>
          <tbody>{e.data?.daily.map((d) => <tr key={d.date}><td>{new Date(d.date).toLocaleDateString("id-ID", { weekday: "short", day: "numeric", month: "short" })}</td><td className="num">{nf.format(d.views)}</td><td className="num">{nf.format(d.uniques)}</td><td className="num">{nf.format(d.clicks)}</td><td className="num">{nf.format(d.comments)}</td></tr>)}</tbody></table></div>
    </div>
  );
}

// ------------------------------------------------------------ S57/S59 dampak
interface Impact { weights: Record<string, number>; method: string; national: number;
  issues: { code: string; name: string; score: number; components: Record<string, number>; raw: { reach: number; engagement_rate: number; negative_ratio: number; avg_reply_hours: number | null; channels: number; drafts: number } }[];
  campaigns: { id: number; name: string; status: string; shift: { before: { n: number; neg_ratio: number }; after: { n: number; neg_ratio: number }; z: number | null; p_value: number | null; significant: boolean; interpretation: string } }[] }
const COMP: Record<string, string> = { jangkauan: "Jangkauan", keterlibatan: "Keterlibatan", sentimen: "Sentimen", kecepatan_respons: "Kecepatan respons", cakupan_wilayah: "Cakupan wilayah" };
export function ImpactTab() {
  const d = useLoad(() => api<Impact>("/m8/impact"), []);
  const [sel, setSel] = useState<string | null>(null);
  if (!d.data) return <div className="empty">Memuat…</div>;
  const it = d.data.issues.find((x) => x.code === sel) ?? d.data.issues[0];
  return (
    <div className="stack">
      <div className="grid g-main">
        <div className="card">
          <h2>Indeks Efektivitas Komunikasi Publik (IEKP) per isu</h2>
          <BarList data={d.data.issues.map((i) => ({ key: i.name, value: i.score }))} max={100} />
          <div className="small muted" style={{ marginTop: 8 }}>{d.data.method}</div>
        </div>
        <div className="card stack" style={{ gap: 8 }}>
          <div className="tile"><div className="label">IEKP nasional (rerata isu)</div><div className="value num">{d.data.national}<span className="small muted"> / 100</span></div></div>
          <select value={it?.code} onChange={(e) => setSel(e.target.value)} aria-label="Isu">{d.data.issues.map((i) => <option key={i.code} value={i.code}>{i.name}</option>)}</select>
          {it && <>
            {Object.entries(it.components).map(([k, v]) => <div key={k}><div className="row between small"><span>{COMP[k]} <span className="muted">({Math.round(d.data!.weights[k] * 100)}%)</span></span><b>{v}</b></div><div className="meter"><span style={{ width: `${v}%` }} /></div></div>)}
            <div className="small muted">Jangkauan {nf.format(it.raw.reach)} · ER {(it.raw.engagement_rate * 100).toFixed(1)}% · negatif {Math.round(it.raw.negative_ratio * 100)}% · respons {it.raw.avg_reply_hours ?? "-"} jam · {it.raw.channels}/39 kanal</div>
          </>}
        </div>
      </div>
      <div className="card table-wrap">
        <h2>Pergeseran sentimen sebelum vs selama kampanye (S57)</h2>
        <table><thead><tr><th>Kampanye</th><th className="num">Negatif sebelum</th><th className="num">Negatif selama</th><th className="num">z</th><th className="num">p-value</th><th>Kesimpulan</th></tr></thead>
          <tbody>{d.data.campaigns.map((c) => (
            <tr key={c.id}><td><b>{c.name}</b><div className="small muted">{c.status}</div></td>
              <td className="num">{Math.round(c.shift.before.neg_ratio * 100)}% <span className="muted small">n={c.shift.before.n}</span></td>
              <td className="num">{Math.round(c.shift.after.neg_ratio * 100)}% <span className="muted small">n={c.shift.after.n}</span></td>
              <td className="num">{c.shift.z ?? "-"}</td><td className="num">{c.shift.p_value ?? "-"}</td>
              <td className={`small ${c.shift.significant ? "status-ok" : "muted"}`}>{c.shift.interpretation}</td></tr>
          ))}</tbody></table>
        <div className="small muted" style={{ marginTop: 6 }}>Uji z dua proporsi atas rasio komentar negatif; signifikan bila p &lt; 0,05.</div>
      </div>
    </div>
  );
}

// ------------------------------------------------------------ S64 penjamin mutu SISTRA AGENT
interface Quality { features: { feature: string; calls: number; live: number; error_rate: number; avg_ms: number }[]; feedback: { category: string; total: number; accepted: number; precision: number }[];
  outcomes: Record<"ai" | "human", { drafts: number; approved: number; returned: number; avg_score: number | null }>;
  bias: { group: string; provinces: number; drafts: number; avg_score: number | null; context_verified: number; context_per_province: number }[]; bias_flag: string | null; model: string; mode: string }
export function QualityTab() {
  const q = useLoad(() => api<Quality>("/ai/quality"), []);
  const d = q.data; if (!d) return <div className="empty">Memuat…</div>;
  const row = (k: "ai" | "human", label: string) => { const o = d.outcomes[k]; return <tr><td>{label}</td><td className="num">{o.drafts}</td><td className="num">{o.approved}</td><td className="num">{o.returned}</td><td className="num">{o.drafts ? Math.round((o.approved / o.drafts) * 100) : 0}%</td><td className="num">{o.avg_score ?? "-"}</td></tr>; };
  return (
    <div className="stack">
      <Msg kind="info">Mesin: <b>SISTRA AGENT</b> · {d.mode === "claude" ? "aktif" : "mode simulasi"}. Laporan mutu bulanan otomatis disertakan pada Laporan Berkala (S61).</Msg>
      {d.bias_flag && <Msg kind="warn"><b>Evaluasi bias:</b> {d.bias_flag}</Msg>}
      <div className="grid g2">
        <div className="card table-wrap"><h2>Hasil naskah: dibantu SISTRA AGENT vs manual (30 hari)</h2>
          <table><thead><tr><th></th><th className="num">Naskah</th><th className="num">Disetujui</th><th className="num">Dikembalikan</th><th className="num">Lolos</th><th className="num">Skor rerata</th></tr></thead><tbody>{row("ai", "Dibantu SISTRA AGENT")}{row("human", "Manual")}</tbody></table></div>
        <div className="card"><h2>Presisi temuan agen editorial (umpan balik redaktur)</h2>
          <BarList data={d.feedback.map((f) => ({ key: `${f.category} (${f.accepted}/${f.total})`, value: Math.round(f.precision * 100) }))} max={100} unit="%" /></div>
      </div>
      <div className="card table-wrap"><h2>Evaluasi bias per kelompok wilayah</h2>
        <table><thead><tr><th>Wilayah</th><th className="num">Provinsi</th><th className="num">Naskah lokal</th><th className="num">Skor editorial</th><th className="num">Konteks lokal terverifikasi / provinsi</th></tr></thead>
          <tbody>{d.bias.map((b) => <tr key={b.group}><td>{b.group}</td><td className="num">{b.provinces}</td><td className="num">{b.drafts}</td><td className="num">{b.avg_score ?? "-"}</td>
            <td className="num"><span className={b.context_per_province < 2 ? "status-bad" : ""}>{b.context_per_province}</span></td></tr>)}</tbody></table></div>
      <div className="card table-wrap"><h2>Pemakaian & keandalan per fitur SISTRA AGENT</h2>
        <table><thead><tr><th>Fitur</th><th className="num">Panggilan</th><th className="num">Oleh SISTRA AGENT</th><th className="num">Tingkat galat</th><th className="num">Latensi</th></tr></thead>
          <tbody>{d.features.map((f) => <tr key={f.feature}><td>{AI_FEATURE_LABEL[f.feature] ?? f.feature}</td><td className="num">{f.calls}</td><td className="num">{f.live}</td><td className="num">{(f.error_rate * 100).toFixed(1)}%</td><td className="num">{f.avg_ms ? `${(f.avg_ms / 1000).toFixed(1)} dtk` : "-"}</td></tr>)}</tbody></table></div>
    </div>
  );
}
