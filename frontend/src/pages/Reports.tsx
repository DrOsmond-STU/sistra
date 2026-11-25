import { useEffect, useState } from "react";
import { api, downloadFile } from "../api";
import { useAuth } from "../auth";
import { ModeChip, useBusy } from "../components/kit";
import { Msg, useLoad } from "../components/ui";
import { fmtDate, fmtDateTime, nf } from "../format";

interface RepItem { id: number; period: string; title: string; period_start: string; period_end: string; auto: boolean; ai_mode: string | null; created_at: string; author_name: string | null }
interface Rep extends RepItem { summary: string; content: { kpi: { label: string; value: number | string }[]; issues: (string | number)[][]; iekp: { issue: string; score: number }[]; channels_top: (string | number)[][]; alerts: string[]; decisions: string[] } }

function Slides({ r, onClose }: { r: Rep; onClose: () => void }) {
  const [i, setI] = useState(0);
  const slides = [
    <div className="slide" key="t"><div className="small" style={{ opacity: 0.7 }}>SISTRA · Laporan Berkala</div><h1>{r.title}</h1><div style={{ opacity: 0.8 }}>{r.auto ? "Terbit otomatis" : "Disusun manual"} · {fmtDateTime(r.created_at)}</div></div>,
    <div className="slide" key="s"><h1>Ringkasan eksekutif</h1><div style={{ whiteSpace: "pre-wrap", fontSize: "clamp(16px,1.8vw,24px)", lineHeight: 1.5 }}>{r.summary}</div></div>,
    <div className="slide" key="k"><h1>Indikator utama</h1><div className="grid g3">{r.content.kpi.map((k) => <div key={k.label}><div style={{ opacity: 0.7 }}>{k.label}</div><div className="big">{typeof k.value === "number" ? nf.format(k.value) : k.value}</div></div>)}</div></div>,
    <div className="slide" key="i"><h1>Isu prioritas & IEKP</h1><table><thead><tr><th>Isu</th><th>Naskah</th><th>Tayang</th><th>Negatif</th></tr></thead><tbody>{r.content.issues.slice(0, 7).map((x) => <tr key={String(x[0])}><td>{x[0]}</td><td>{x[1]}</td><td>{x[2]}</td><td>{x[4]}</td></tr>)}</tbody></table>
      <div style={{ marginTop: 16 }}>{r.content.iekp.map((k) => <div key={k.issue}>IEKP {k.issue}: <b>{k.score}</b></div>)}</div></div>,
    <div className="slide" key="a"><h1>Perlu perhatian & keputusan</h1><ul style={{ fontSize: "clamp(16px,1.8vw,24px)" }}>{r.content.alerts.map((a) => <li key={a}>{a}</li>)}</ul>
      {!!r.content.decisions.length && <><h2 style={{ color: "#fff" }}>Menunggu keputusan</h2><ul>{r.content.decisions.map((d) => <li key={d}>{d}</li>)}</ul></>}</div>,
  ];
  useEffect(() => {
    const k = (e: KeyboardEvent) => { if (e.key === "ArrowRight" || e.key === " ") setI((x) => Math.min(slides.length - 1, x + 1)); if (e.key === "ArrowLeft") setI((x) => Math.max(0, x - 1)); if (e.key === "Escape") onClose(); };
    window.addEventListener("keydown", k); document.documentElement.requestFullscreen?.().catch(() => undefined);
    return () => { window.removeEventListener("keydown", k); if (document.fullscreenElement) document.exitFullscreen().catch(() => undefined); };
  }, []); // eslint-disable-line react-hooks/exhaustive-deps
  return (
    <div className="slide-deck" role="dialog" aria-label="Mode presentasi">
      {slides[i]}
      <div className="bar"><button onClick={onClose}>✕ Keluar (Esc)</button><span>{i + 1} / {slides.length}</span>
        <span className="row"><button onClick={() => setI(Math.max(0, i - 1))}>‹</button><button onClick={() => setI(Math.min(slides.length - 1, i + 1))}>›</button></span></div>
    </div>
  );
}

/** S61: laporan berkala otomatis harian/mingguan/bulanan, ekspor DOCX & PDF, mode presentasi. */
export default function ReportsPage() {
  const { hasRole } = useAuth();
  const list = useLoad(() => api<RepItem[]>("/reports"), []);
  const [sel, setSel] = useState<number | null>(null);
  const rep = useLoad(() => (sel ? api<Rep>(`/reports/${sel}`) : Promise.resolve(null)), [sel]);
  const [busy, run] = useBusy();
  const [msg, setMsg] = useState<{ kind: "ok" | "error"; text: string } | null>(null);
  const [present, setPresent] = useState(false);
  useEffect(() => { if (!sel && list.data?.length) setSel(list.data[0].id); }, [list.data]); // eslint-disable-line react-hooks/exhaustive-deps
  const r = rep.data;
  return (
    <div className="stack">
      <div className="row between no-print">
        <div>
          <h1>Laporan Berkala <span className="muted" style={{ fontWeight: 400 }}>· M8 S61</span></h1>
          <p className="sub" style={{ marginBottom: 0 }}>Laporan harian & mingguan terbit otomatis; laporan bulanan dapat disusun kapan saja. Ringkasan eksekutif disusun SISTRA AGENT dari data.</p>
        </div>
        {hasRole("pimpinan", "analis", "redaktur") && <div className="row">{["harian", "mingguan", "bulanan"].map((p) => (
          <button key={p} disabled={!!busy} onClick={() => run(p, async () => { try { const x = await api<{ id: number }>("/reports", { method: "POST", body: { period: p } }); list.reload(); setSel(x.id); setMsg({ kind: "ok", text: `Laporan ${p} disusun.` }); } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); } })}>
            {busy === p ? "Menyusun…" : `+ ${p[0].toUpperCase() + p.slice(1)}`}</button>))}</div>}
      </div>
      {msg && <Msg kind={msg.kind}>{msg.text}</Msg>}
      <div className="split" style={{ gridTemplateColumns: "minmax(0,.6fr) minmax(0,1.4fr)" }}>
        <div className="card table-wrap no-print">
          <table><tbody>{list.data?.map((x) => (
            <tr key={x.id} className={`clickable${sel === x.id ? " selected" : ""}`} onClick={() => setSel(x.id)}>
              <td><b>{x.title}</b><div className="small muted">{x.auto ? "⚙ terbit otomatis" : `disusun ${x.author_name ?? ""}`} · {fmtDateTime(x.created_at)}</div></td>
            </tr>
          ))}</tbody></table>
        </div>
        {r && (
          <div className="card stack" style={{ gap: 12 }}>
            <div className="row between no-print">
              <ModeChip mode={r.ai_mode} />
              <div className="row">
                <button className="sm" onClick={() => downloadFile(`/reports/${r.id}/docx`, `laporan-${r.id}.docx`)}>Unduh DOCX</button>
                <button className="sm" onClick={() => window.print()}>Cetak / simpan PDF</button>
                <button className="sm primary" onClick={() => setPresent(true)}>▶ Mode presentasi</button>
              </div>
            </div>
            <div><div className="small muted">SISTRA · Laporan Berkala Komunikasi Publik · data contoh sintetis</div><h2 style={{ margin: "4px 0" }}>{r.title}</h2>
              <div className="small muted">Periode {fmtDate(r.period_start)} – {fmtDate(new Date(new Date(r.period_end).getTime() - 1).toISOString())}</div></div>
            <div><h3>Ringkasan eksekutif</h3><div style={{ whiteSpace: "pre-wrap" }}>{r.summary}</div></div>
            <div className="grid g3">{r.content.kpi.map((k) => <div key={k.label} className="tile"><div className="label">{k.label}</div><div className="value num">{typeof k.value === "number" ? nf.format(k.value) : k.value}</div></div>)}</div>
            <div className="table-wrap"><h3>Isu prioritas</h3><table><thead><tr><th>Isu</th><th className="num">Naskah</th><th className="num">Tayang</th><th className="num">Komentar 7h</th><th className="num">Negatif</th></tr></thead>
              <tbody>{r.content.issues.map((x) => <tr key={String(x[0])}><td>{x[0]}</td><td className="num">{x[1]}</td><td className="num">{x[2]}</td><td className="num">{x[3]}</td><td className="num">{x[4]}</td></tr>)}</tbody></table></div>
            <div><h3>IEKP</h3>{r.content.iekp.map((k) => <div key={k.issue} className="row small" style={{ gap: 8 }}><span style={{ width: 220 }}>{k.issue}</span><div className="meter" style={{ flex: 1 }}><span style={{ width: `${k.score}%` }} /></div><b>{k.score}</b></div>)}</div>
            <div className="table-wrap"><h3>Kanal dengan tayangan tertinggi</h3><table><thead><tr><th>Kanal</th><th className="num">Kirim</th><th className="num">Tayangan</th><th className="num">Gagal</th></tr></thead>
              <tbody>{r.content.channels_top.map((x) => <tr key={String(x[0])}><td>{x[0]}</td><td className="num">{x[1]}</td><td className="num">{nf.format(Number(x[2]))}</td><td className="num">{x[3]}</td></tr>)}</tbody></table></div>
            {!!r.content.alerts.length && <div><h3>Perlu perhatian</h3><ul>{r.content.alerts.map((a) => <li key={a}>{a}</li>)}</ul></div>}
          </div>
        )}
      </div>
      {present && r && <Slides r={r} onClose={() => setPresent(false)} />}
    </div>
  );
}
