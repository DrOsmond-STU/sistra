import { useState } from "react";
import { fmtShortDate, nf } from "../format";
import type { DashboardSummary, Sentiment } from "../types";
import { SENT_COLOR, useWidth } from "./ui";

const GAP = 2; // celah permukaan antar-segmen
const R = 4; // ujung data membulat

function niceMax(v: number): { max: number; ticks: number[] } {
  if (v <= 0) return { max: 4, ticks: [0, 1, 2, 3, 4] };
  const raw = v / 4;
  const mag = 10 ** Math.floor(Math.log10(raw));
  const step = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((s) => s >= raw) ?? raw;
  const max = Math.ceil(v / step) * step;
  const ticks: number[] = [];
  for (let t = 0; t <= max + 1e-9; t += step) ticks.push(Math.round(t * 100) / 100);
  return { max, ticks };
}

/** Kolom: ujung atas membulat, dasar persegi. */
function colPath(x: number, y: number, w: number, h: number, rounded: boolean): string {
  if (h <= 0) return "";
  const r = rounded ? Math.min(R, h, w / 2) : 0;
  return `M${x},${y + h}V${y + r}${r ? `A${r},${r} 0 0 1 ${x + r},${y}` : ""}H${x + w - r}${r ? `A${r},${r} 0 0 1 ${x + w},${y + r}` : ""}V${y + h}Z`;
}

/** Batang horizontal: ujung kanan membulat. */
function barPath(x: number, y: number, w: number, h: number): string {
  if (w <= 0) return "";
  const r = Math.min(R, w, h / 2);
  return `M${x},${y}H${x + w - r}A${r},${r} 0 0 1 ${x + w},${y + r}V${y + h - r}A${r},${r} 0 0 1 ${x + w - r},${y + h}H${x}Z`;
}

const ORDER: Sentiment[] = ["negatif", "netral", "positif"]; // dari dasar ke atas
const LABEL: Record<Sentiment, string> = { negatif: "Negatif", netral: "Netral", positif: "Positif" };

export function SentimentLegend() {
  return (
    <div className="legend" aria-label="Legenda sentimen">
      {[...ORDER].reverse().map((s) => (
        <span key={s}>
          <i style={{ background: SENT_COLOR[s] }} />
          {LABEL[s]}
        </span>
      ))}
    </div>
  );
}

export function SentimentTimeline({ data, height = 220 }: { data: DashboardSummary["timeline"]; height?: number }) {
  const [ref, width] = useWidth<HTMLDivElement>();
  const [hover, setHover] = useState<number | null>(null);
  const m = { top: 14, right: 8, bottom: 24, left: 34 };
  const iw = Math.max(0, width - m.left - m.right);
  const ih = height - m.top - m.bottom;
  const { max, ticks } = niceMax(Math.max(0, ...data.map((d) => d.total)));
  const band = data.length ? iw / data.length : 0;
  const bw = Math.min(24, band * 0.62);
  const y = (v: number) => ih - (v / max) * ih;
  const last = data.length - 1;
  const hd = hover !== null ? data[hover] : null;
  // label sumbu-x: sebanyak yang muat (~56px per label), label terakhir selalu tampil tanpa bertabrakan
  const every = Math.max(1, Math.ceil(data.length / Math.max(2, Math.floor(iw / 56))));
  const showTick = (i: number) => i === last || (i % every === 0 && last - i >= every);

  return (
    <div className="viz" ref={ref} onMouseLeave={() => setHover(null)}>
      {width > 0 && (
        <svg width={width} height={height} role="img" aria-label="Volume konten harian menurut sentimen">
          <g transform={`translate(${m.left},${m.top})`}>
            {ticks.map((t) => (
              <g key={t}>
                <line x1={0} x2={iw} y1={y(t)} y2={y(t)} stroke={t === 0 ? "var(--axis)" : "var(--grid)"} />
                <text x={-8} y={y(t)} dy="0.32em" textAnchor="end">
                  {nf.format(t)}
                </text>
              </g>
            ))}
            {data.map((d, i) => {
              const x = i * band + (band - bw) / 2;
              let acc = 0;
              const present = ORDER.filter((s) => d[s] > 0);
              const top = present[present.length - 1];
              return (
                <g key={d.date} opacity={hover === null || hover === i ? 1 : 0.45}>
                  {ORDER.map((s) => {
                    const v = d[s];
                    if (!v) return null;
                    const y0 = y(acc + v);
                    const h = y(acc) - y0 - (acc > 0 ? GAP : 0);
                    acc += v;
                    return <path key={s} d={colPath(x, y0, bw, h, s === top)} fill={SENT_COLOR[s]} />;
                  })}
                  {showTick(i) && (
                    <text x={x + bw / 2} y={ih + 16} textAnchor="middle">
                      {fmtShortDate(d.date)}
                    </text>
                  )}
                  {i === last && d.total > 0 && (
                    <text className="val" x={x + bw / 2} y={y(d.total) - 5} textAnchor="middle">
                      {nf.format(d.total)}
                    </text>
                  )}
                  <rect x={i * band} y={0} width={band} height={ih} fill="transparent" onMouseEnter={() => setHover(i)}
                        onFocus={() => setHover(i)} tabIndex={0} aria-label={`${fmtShortDate(d.date)}: ${d.total} konten`} />
                </g>
              );
            })}
          </g>
        </svg>
      )}
      {hd && hover !== null && (
        <div className="tooltip" style={{ left: Math.min(m.left + hover * band + band / 2 + 12, width - 160), top: 8 }}>
          <div className="t">{fmtShortDate(hd.date)}</div>
          {[...ORDER].reverse().map((s) => (
            <div className="r" key={s}>
              <span><i style={{ background: SENT_COLOR[s] }} />{LABEL[s]}</span>
              <b className="num">{nf.format(hd[s])}</b>
            </div>
          ))}
          <div className="r"><span>Total</span><b className="num">{nf.format(hd.total)}</b></div>
        </div>
      )}
    </div>
  );
}

export function ProvinceBars({ data, top = 10 }: { data: DashboardSummary["provinces"]; top?: number }) {
  const [ref, width] = useWidth<HTMLDivElement>();
  const [hover, setHover] = useState<number | null>(null);
  const rows = data.filter((p) => p.volume > 0).slice(0, top);
  const rowH = 28;
  const bh = 14;
  const labelW = Math.min(150, Math.max(96, width * 0.36));
  const valW = 34;
  const iw = Math.max(0, width - labelW - valW);
  const max = Math.max(1, ...rows.map((r) => r.volume));
  const height = rows.length * rowH;
  const hr = hover !== null ? rows[hover] : null;

  if (!rows.length) return <div className="empty">Belum ada konten bergeotag.</div>;
  return (
    <div className="viz" ref={ref} onMouseLeave={() => setHover(null)}>
      {width > 0 && (
        <svg width={width} height={height} role="img" aria-label="Provinsi dengan volume konten tertinggi">
          <line x1={labelW} x2={labelW} y1={0} y2={height} stroke="var(--axis)" />
          {rows.map((r, i) => {
            const w = (r.volume / max) * iw;
            const yy = i * rowH + (rowH - bh) / 2;
            return (
              <g key={r.code} opacity={hover === null || hover === i ? 1 : 0.5}>
                <text x={labelW - 8} y={yy + bh / 2} dy="0.32em" textAnchor="end" className="val">
                  {r.name.length > 22 ? `${r.name.slice(0, 21)}…` : r.name}
                </text>
                <path d={barPath(labelW, yy, w, bh)} fill="var(--seq)" />
                <text x={labelW + w + 6} y={yy + bh / 2} dy="0.32em" className="val">
                  {nf.format(r.volume)}
                </text>
                <rect x={0} y={i * rowH} width={width} height={rowH} fill="transparent" onMouseEnter={() => setHover(i)} />
              </g>
            );
          })}
        </svg>
      )}
      {hr && hover !== null && (
        <div className="tooltip" style={{ left: Math.min(labelW + 40, width - 170), top: hover * rowH + rowH }}>
          <div className="t">{hr.name}</div>
          <div className="r"><span>Volume</span><b className="num">{nf.format(hr.volume)}</b></div>
          <div className="r"><span>Negatif</span><b className="num">{nf.format(hr.negative)}</b></div>
        </div>
      )}
    </div>
  );
}
