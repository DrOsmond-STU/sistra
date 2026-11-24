import { useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { api } from "../api";
import { Msg, useLoad } from "../components/ui";
import { fmtDateTime } from "../format";

interface Ev { id: string; type: string; title: string; start: string; end?: string; resource: string; status: string; link: string; conflict: boolean }
interface Cal { events: Ev[]; conflicts: { resource: string; a: string; b: string; at: string }[] }
type View = "bulan" | "minggu" | "hari";

const TYPES: Record<string, string> = { tayang: "Jadwal tayang kanal", unggah: "Unggahan akun resmi", distribusi: "Distribusi terjadwal", tenggat: "Tenggat produksi", liputan: "Liputan lapangan", kampanye: "Kampanye" };
const DOW = ["Sen", "Sel", "Rab", "Kam", "Jum", "Sab", "Min"];
const startOfWeek = (d: Date) => { const x = new Date(d); x.setHours(0, 0, 0, 0); x.setDate(x.getDate() - ((x.getDay() + 6) % 7)); return x; };
const sameDay = (a: Date, b: Date) => a.toDateString() === b.toDateString();
const hhmm = (iso: string) => new Date(iso).toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });

/** S34: kalender terpadu produksi & distribusi multi-kanal. */
export default function CalendarPage() {
  const nav = useNavigate();
  const [view, setView] = useState<View>("bulan");
  const [anchor, setAnchor] = useState(() => new Date());
  const [types, setTypes] = useState<string[]>(Object.keys(TYPES));
  const [onlyConflict, setOnlyConflict] = useState(false);
  const range = useMemo(() => {
    if (view === "hari") { const s = new Date(anchor); s.setHours(0, 0, 0, 0); const e = new Date(s); e.setDate(e.getDate() + 1); return [s, e]; }
    if (view === "minggu") { const s = startOfWeek(anchor); const e = new Date(s); e.setDate(e.getDate() + 7); return [s, e]; }
    const first = new Date(anchor.getFullYear(), anchor.getMonth(), 1); const s = startOfWeek(first); const e = new Date(s); e.setDate(e.getDate() + 42); return [s, e];
  }, [view, anchor]);
  const cal = useLoad(() => api<Cal>("/m5/calendar", { query: { from: range[0].toISOString(), to: range[1].toISOString() } }), [range[0].getTime(), range[1].getTime()]);
  const evs = (cal.data?.events ?? []).filter((e) => types.includes(e.type) && (!onlyConflict || e.conflict));
  const onDay = (d: Date) => evs.filter((e) => {
    const s = new Date(e.start); const en = e.end ? new Date(e.end) : s;
    const ds = new Date(d); ds.setHours(0, 0, 0, 0); const de = new Date(ds); de.setDate(de.getDate() + 1);
    return s < de && en >= ds;
  });
  const shift = (k: number) => { const d = new Date(anchor); if (view === "bulan") d.setMonth(d.getMonth() + k); else d.setDate(d.getDate() + (view === "minggu" ? 7 : 1) * k); setAnchor(d); };
  const days = Array.from({ length: view === "bulan" ? 42 : view === "minggu" ? 7 : 1 }, (_, i) => { const d = new Date(range[0]); d.setDate(d.getDate() + i); return d; });
  const today = new Date();
  const title = view === "bulan" ? anchor.toLocaleDateString("id-ID", { month: "long", year: "numeric" })
    : view === "minggu" ? `${range[0].toLocaleDateString("id-ID", { day: "numeric", month: "short" })} – ${new Date(range[1].getTime() - 1).toLocaleDateString("id-ID", { day: "numeric", month: "short", year: "numeric" })}`
    : anchor.toLocaleDateString("id-ID", { weekday: "long", day: "numeric", month: "long", year: "numeric" });
  const counts = Object.fromEntries(Object.keys(TYPES).map((t) => [t, (cal.data?.events ?? []).filter((e) => e.type === t).length]));

  const EvBtn = ({ e, full = false }: { e: Ev; full?: boolean }) => (
    <button className={`ev ev-${e.type}${e.conflict ? " conflict" : ""}`} title={`${TYPES[e.type]} · ${e.title} · ${e.resource} · ${fmtDateTime(e.start)}${e.conflict ? " · BENTROK" : ""}`}
            onClick={() => nav(e.link)} style={full ? { whiteSpace: "normal" } : undefined}>
      {e.type !== "kampanye" && <b>{hhmm(e.start)} </b>}{e.conflict && "⚠ "}{e.title}{full && <span className="muted"> · {e.resource} · {e.status}</span>}
    </button>
  );

  return (
    <div className="stack">
      <div className="row between">
        <div>
          <h1>Kalender Terpadu <span className="muted" style={{ fontWeight: 400 }}>· M5 S34</span></h1>
          <p className="sub" style={{ marginBottom: 0 }}>Rencana produksi, tenggat, liputan, kampanye, unggahan akun resmi, dan jadwal tayang 39 kanal dalam satu tampilan</p>
        </div>
        <div className="row">
          {(["bulan", "minggu", "hari"] as View[]).map((v) => <button key={v} className={view === v ? "primary" : ""} onClick={() => setView(v)}>{v[0].toUpperCase() + v.slice(1)}</button>)}
        </div>
      </div>
      {!!cal.data?.conflicts.length && (
        <Msg kind="warn"><b>{cal.data.conflicts.length} bentrok jadwal</b> (sumber daya sama, selisih &lt; 60 menit): {cal.data.conflicts.slice(0, 3).map((c) => `${c.resource} — “${c.a}” & “${c.b}” (${fmtDateTime(c.at)})`).join("; ")}</Msg>
      )}
      <div className="row between">
        <div className="row"><button onClick={() => shift(-1)} aria-label="Sebelumnya">‹</button><button onClick={() => setAnchor(new Date())}>Hari ini</button><button onClick={() => shift(1)} aria-label="Berikutnya">›</button><b style={{ marginLeft: 6 }}>{title}</b></div>
        <div className="legend">
          {Object.entries(TYPES).map(([k, v]) => (
            <label key={k} className="row small" style={{ gap: 4 }}>
              <input type="checkbox" checked={types.includes(k)} onChange={(e) => setTypes(e.target.checked ? [...types, k] : types.filter((t) => t !== k))} />
              <i className={`ev-${k}`} style={{ width: 10, height: 10, borderLeft: "10px solid", borderRadius: 2 }} />{v} ({counts[k] ?? 0})
            </label>
          ))}
          <label className="row small" style={{ gap: 4 }}><input type="checkbox" checked={onlyConflict} onChange={(e) => setOnlyConflict(e.target.checked)} /> Hanya bentrok</label>
        </div>
      </div>
      {cal.error && <Msg kind="error">{cal.error}</Msg>}
      {view === "hari" ? (
        <div className="card stack" style={{ gap: 6 }}>
          {!onDay(anchor).length && <div className="empty">Tidak ada kegiatan.</div>}
          {onDay(anchor).map((e) => <EvBtn key={e.id} e={e} full />)}
        </div>
      ) : (
        <div className="cal-grid">
          {DOW.map((d) => <div key={d} className="cal-dow">{d}</div>)}
          {days.map((d) => {
            const list = onDay(d);
            return (
              <div key={d.toISOString()} className={`cal-cell${view === "bulan" && d.getMonth() !== anchor.getMonth() ? " out" : ""}${sameDay(d, today) ? " today" : ""}`} style={view === "minggu" ? { minHeight: 320 } : undefined}>
                <button className="cal-date ghost" style={{ padding: 0, textAlign: "left" }} onClick={() => { setAnchor(d); setView("hari"); }}>{d.getDate()}</button>
                {list.slice(0, view === "minggu" ? 14 : 4).map((e) => <EvBtn key={e.id} e={e} />)}
                {list.length > (view === "minggu" ? 14 : 4) && <button className="ghost small" style={{ padding: 0 }} onClick={() => { setAnchor(d); setView("hari"); }}>+{list.length - (view === "minggu" ? 14 : 4)} lainnya</button>}
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
