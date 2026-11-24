import { useState } from "react";
import { api } from "../api";
import { nf } from "../format";
import { useLoad } from "./ui";

interface Prov { code: string; name: string; channel: string; health: string; views_7d: number; local_drafts: number; published_7d: number; verified_context: number }

// Tata letak kartogram petak (kolom, baris) yang meniru posisi geografis 38 provinsi.
const POS: Record<string, [number, number]> = {
  "11": [1, 1], "12": [2, 2], "14": [3, 2], "21": [5, 2], "13": [2, 3], "15": [3, 3], "17": [2, 4], "16": [3, 4], "19": [5, 4], "18": [4, 5],
  "36": [5, 6], "31": [6, 6], "32": [6, 7], "33": [7, 7], "34": [7, 8], "35": [8, 7], "51": [9, 7], "52": [10, 7], "53": [12, 7],
  "61": [7, 3], "65": [9, 2], "64": [9, 3], "62": [8, 4], "63": [9, 4],
  "71": [13, 2], "75": [12, 3], "72": [11, 3], "76": [10, 4], "73": [11, 5], "74": [12, 5], "82": [14, 3], "81": [14, 5],
  "96": [15, 4], "92": [16, 4], "94": [17, 4], "91": [18, 4], "95": [17, 5], "93": [18, 6],
};
const SEQ = ["#cfe0f6", "#a9cbf3", "#7fb0ec", "#5598e7", "#2a78d6", "#1c5cab", "#10407a"];

/** S60: peta 38 provinsi (kartogram petak) — tayangan 7 hari dan status kanal. */
export default function ProvinceMap() {
  const d = useLoad(() => api<Prov[]>("/m8/provinces"), []);
  const [hover, setHover] = useState<Prov | null>(null);
  const max = Math.max(1, ...(d.data ?? []).map((p) => p.views_7d));
  const col = (v: number) => SEQ[Math.min(SEQ.length - 1, Math.floor((v / max) * (SEQ.length - 0.01)))];
  return (
    <div className="stack" style={{ gap: 8 }}>
      <div className="tilemap" style={{ gridTemplateRows: "repeat(8, auto)" }} onMouseLeave={() => setHover(null)}>
        {d.data?.map((p) => {
          const [c, r] = POS[p.code] ?? [1, 1];
          const v = col(p.views_7d);
          return (
            <div key={p.code} className={`tile-p h-${p.health}`} style={{ gridColumn: c, gridRow: r, background: v, color: SEQ.indexOf(v) >= 3 ? "#fff" : "#10243f" }}
                 onMouseEnter={() => setHover(p)} onFocus={() => setHover(p)} tabIndex={0} aria-label={`${p.name}: ${nf.format(p.views_7d)} tayangan, kanal ${p.health}`}>
              {p.name.replace(/^(Kepulauan|Papua|Kalimantan|Sulawesi|Sumatera|Nusa Tenggara|Jawa|Maluku|DI|DKI)\s?/, (m) => m.slice(0, 1)).slice(0, 4)}
            </div>
          );
        })}
      </div>
      <div className="row between small">
        <span className="legend"><span><i style={{ background: SEQ[1] }} />rendah</span><span><i style={{ background: SEQ[5] }} />tinggi (tayangan 7 hari)</span>
          <span><i style={{ border: "2px dashed var(--st-warning)", background: "transparent" }} />lambat</span><span><i style={{ border: "2px solid var(--st-critical)", background: "transparent" }} />gangguan</span></span>
      </div>
      <div className="small" style={{ minHeight: 34 }}>
        {hover ? <><b>{hover.name}</b> · {nf.format(hover.views_7d)} tayangan · {hover.published_7d} artikel terkirim · {hover.local_drafts} naskah lokal · {hover.verified_context} konteks lokal terverifikasi · kanal <b>{hover.health}</b></>
          : <span className="muted">Arahkan ke provinsi untuk melihat rincian.</span>}
      </div>
    </div>
  );
}
