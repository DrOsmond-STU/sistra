import { useState, type ReactNode } from "react";
import { fmtShortDate, nf } from "../format";
import type { EditorialResult, Pair } from "../types47";
import { useWidth } from "./ui";

export function Tile({ label, value, foot, tone }: { label: string; value: ReactNode; foot?: ReactNode; tone?: "warn" | "bad" | "good" }) {
  return (
    <div className={`card tile${tone ? ` tone-${tone}` : ""}`}>
      <div className="label">{label}</div>
      <div className="value num">{value}</div>
      {foot && <div className="foot">{foot}</div>}
    </div>
  );
}

export function ModeChip({ mode }: { mode?: string | null }) {
  if (!mode) return null;
  return mode === "claude"
    ? <span className="chip ai-live" title="Diproses oleh Claude">✦ AI Claude</span>
    : <span className="chip ai-sim" title="Kunci API belum diisi; hasil berbasis aturan sederhana">◇ Mode simulasi</span>;
}

export function Prio({ p }: { p: string | null | undefined }) {
  if (!p) return null;
  const icon = p === "tinggi" ? "▲" : p === "rendah" ? "▽" : "■";
  return <span className={`chip prio-${p}`}><span aria-hidden>{icon}</span>{p[0].toUpperCase() + p.slice(1)}</span>;
}

/** Batang horizontal sederhana untuk pasangan label–nilai. */
export function BarList({ data, labels, max: maxIn, unit = "", top = 10 }: { data: Pair[]; labels?: Record<string, string>; max?: number; unit?: string; top?: number }) {
  const rows = data.slice(0, top);
  const max = maxIn ?? Math.max(1, ...rows.map((r) => r.value));
  if (!rows.length) return <div className="empty">Belum ada data.</div>;
  return (
    <div className="barlist" role="list">
      {rows.map((r) => (
        <div className="bl-row" role="listitem" key={r.key}>
          <span className="bl-label" title={labels?.[r.key] ?? r.key}>{labels?.[r.key] ?? r.key}</span>
          <span className="bl-track"><span className="bl-fill" style={{ width: `${Math.max(1.5, (r.value / max) * 100)}%` }} /></span>
          <b className="bl-val num">{nf.format(r.value)}{unit}</b>
        </div>
      ))}
    </div>
  );
}

/** Tren harian dua seri (disusun vs tayang) berupa kolom berdampingan. */
export function TrendColumns({ data, height = 200 }: { data: { date: string; produced: number; published: number }[]; height?: number }) {
  const [ref, width] = useWidth<HTMLDivElement>();
  const [hover, setHover] = useState<number | null>(null);
  const m = { top: 12, right: 6, bottom: 22, left: 28 };
  const iw = Math.max(0, width - m.left - m.right);
  const ih = height - m.top - m.bottom;
  const peak = Math.max(1, ...data.map((d) => Math.max(d.produced, d.published)));
  const step = peak <= 4 ? 1 : Math.ceil(peak / 4);
  const max = step * Math.ceil(peak / step);
  const ticks = Array.from({ length: max / step + 1 }, (_, i) => i * step);
  const band = data.length ? iw / data.length : 0;
  const bw = Math.max(2, Math.min(12, band * 0.32));
  const y = (v: number) => ih - (v / max) * ih;
  const every = Math.max(1, Math.ceil(data.length / Math.max(2, Math.floor(iw / 56))));
  const hd = hover !== null ? data[hover] : null;
  const col = (x: number, v: number, fill: string) => {
    if (v <= 0) return null;
    const h = ih - y(v); const r = Math.min(3, h, bw / 2); const yy = y(v);
    return <path d={`M${x},${ih}V${yy + r}A${r},${r} 0 0 1 ${x + r},${yy}H${x + bw - r}A${r},${r} 0 0 1 ${x + bw},${yy + r}V${ih}Z`} fill={fill} />;
  };
  return (
    <div className="viz" ref={ref} onMouseLeave={() => setHover(null)}>
      {width > 0 && (
        <svg width={width} height={height} role="img" aria-label="Tren harian naskah disusun dan tayang">
          <g transform={`translate(${m.left},${m.top})`}>
            {ticks.map((t) => (
              <g key={t}>
                <line x1={0} x2={iw} y1={y(t)} y2={y(t)} stroke={t === 0 ? "var(--axis)" : "var(--grid)"} />
                <text x={-6} y={y(t)} dy="0.32em" textAnchor="end">{t}</text>
              </g>
            ))}
            {data.map((d, i) => {
              const cx = i * band + band / 2;
              return (
                <g key={d.date} opacity={hover === null || hover === i ? 1 : 0.45}>
                  {col(cx - bw - 1, d.produced, "var(--seq-2)")}
                  {col(cx + 1, d.published, "var(--seq)")}
                  {(i % every === 0 || i === data.length - 1) && (data.length - 1 - i >= every || i === data.length - 1) && (
                    <text x={cx} y={ih + 15} textAnchor="middle">{fmtShortDate(d.date)}</text>
                  )}
                  <rect x={i * band} y={0} width={band} height={ih} fill="transparent" onMouseEnter={() => setHover(i)} />
                </g>
              );
            })}
          </g>
        </svg>
      )}
      {hd && hover !== null && (
        <div className="tooltip" style={{ left: Math.min(m.left + hover * band + band / 2 + 10, width - 160), top: 6 }}>
          <div className="t">{fmtShortDate(hd.date)}</div>
          <div className="r"><span><i style={{ background: "var(--seq-2)" }} />Disusun</span><b className="num">{hd.produced}</b></div>
          <div className="r"><span><i style={{ background: "var(--seq)" }} />Tayang</span><b className="num">{hd.published}</b></div>
        </div>
      )}
    </div>
  );
}

export function TrendLegend() {
  return (
    <div className="legend">
      <span><i style={{ background: "var(--seq-2)" }} />Disusun</span>
      <span><i style={{ background: "var(--seq)" }} />Tayang</span>
    </div>
  );
}

/** Proporsi bertumpuk satu baris (mis. kesehatan kanal). */
export function StackBar({ parts }: { parts: { label: string; value: number; color: string }[] }) {
  const total = parts.reduce((a, p) => a + p.value, 0) || 1;
  return (
    <div>
      <div className="stackbar" role="img" aria-label={parts.map((p) => `${p.label} ${p.value}`).join(", ")}>
        {parts.filter((p) => p.value > 0).map((p) => <span key={p.label} style={{ width: `${(p.value / total) * 100}%`, background: p.color }} />)}
      </div>
      <div className="legend" style={{ marginTop: 8 }}>
        {parts.map((p) => <span key={p.label}><i style={{ background: p.color }} />{p.label} <b className="num">{p.value}</b></span>)}
      </div>
    </div>
  );
}

const SEV_ICON: Record<string, string> = { kritis: "■", peringatan: "▲", info: "i" };
const CAT_LABEL: Record<string, string> = { ejaan: "Ejaan & tata bahasa", sara: "Sensitivitas SARA", nada: "Nada & diksi", fakta: "Akurasi fakta", hukum: "Risiko hukum", kejelasan: "Kejelasan" };

export function ScoreRing({ score }: { score: number }) {
  const tone = score >= 85 ? "var(--st-good)" : score >= 70 ? "var(--st-warning)" : "var(--st-critical)";
  const c = 2 * Math.PI * 22;
  return (
    <svg width={60} height={60} viewBox="0 0 60 60" role="img" aria-label={`Skor editorial ${score} dari 100`}>
      <circle cx={30} cy={30} r={22} fill="none" stroke="var(--grid)" strokeWidth={6} />
      <circle cx={30} cy={30} r={22} fill="none" stroke={tone} strokeWidth={6} strokeLinecap="round"
              strokeDasharray={`${(score / 100) * c} ${c}`} transform="rotate(-90 30 30)" />
      <text x={30} y={30} dy="0.35em" textAnchor="middle" style={{ fontSize: 15, fontWeight: 700, fill: "var(--text)" }}>{score}</text>
    </svg>
  );
}

export function EditorialReport({ r, onDecide }: { r: EditorialResult; onDecide?: (f: EditorialResult["findings"][number], d: "diterima" | "ditolak") => Promise<void> | void }) {
  const [decided, setDecided] = useState<Record<number, string>>({});
  return (
    <div className="stack" style={{ gap: 10 }}>
      <div className="row" style={{ gap: 12, flexWrap: "nowrap" }}>
        <ScoreRing score={r.score} />
        <div>
          <div className="row" style={{ gap: 6 }}><b>Skor kelayakan editorial</b><ModeChip mode={r.mode} /></div>
          <div className="small muted">{r.summary}</div>
        </div>
      </div>
      {r.findings.length === 0 && <div className="small">Tidak ada temuan. Tetap baca ulang sebelum diajukan.</div>}
      {r.findings.map((f, i) => (
        <div key={i} className={`finding sev-${f.severity}`}>
          <div className="row" style={{ gap: 6 }}>
            <span className="sev" aria-hidden>{SEV_ICON[f.severity] ?? "i"}</span>
            <b>{CAT_LABEL[f.category] ?? f.category}</b>
            <span className="small muted">· {f.severity}</span>
          </div>
          {f.excerpt && <div className="small mono">“{f.excerpt}”</div>}
          <div className="small">{f.suggestion}</div>
          {onDecide && (decided[i] ? <div className="small muted">Keputusan redaktur: <b>{decided[i]}</b> (tercatat untuk pembelajaran agen editorial)</div> : (
            <div className="row" style={{ gap: 6 }}>
              <button className="sm" onClick={async () => { await onDecide(f, "diterima"); setDecided({ ...decided, [i]: "diterima" }); }}>✓ Terima</button>
              <button className="sm ghost" onClick={async () => { await onDecide(f, "ditolak"); setDecided({ ...decided, [i]: "ditolak" }); }}>✕ Tolak temuan</button>
            </div>
          ))}
        </div>
      ))}
    </div>
  );
}

export function useBusy(): [string, (key: string, fn: () => Promise<void>) => Promise<void>] {
  const [busy, setBusy] = useState("");
  const run = async (key: string, fn: () => Promise<void>) => {
    setBusy(key);
    try { await fn(); } finally { setBusy(""); }
  };
  return [busy, run];
}

/** Teks tenggat: "dalam 3 jam" / "lewat 2 hari". */
export function dueText(iso: string | null): string {
  if (!iso) return "-";
  const mins = Math.round((new Date(iso).getTime() - Date.now()) / 60000);
  const a = Math.abs(mins);
  const span = a < 60 ? `${a} menit` : a < 48 * 60 ? `${Math.round(a / 60)} jam` : `${Math.round(a / 1440)} hari`;
  return mins >= 0 ? `dalam ${span}` : `lewat ${span}`;
}
