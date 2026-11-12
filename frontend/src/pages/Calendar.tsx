import { api } from "../api";
import { useAuth } from "../auth";
import { Msg, useLoad } from "../components/ui";
import { STATUS_DRAFT } from "../format";
import type { CalendarEntry } from "../types";

function dayKey(iso: string): string {
  const d = new Date(iso);
  return d.toLocaleDateString("id-ID", { weekday: "long", day: "numeric", month: "long", year: "numeric" });
}

export default function CalendarPage() {
  const { hasRole } = useAuth();
  const cal = useLoad(() => api<CalendarEntry[]>("/calendar"), []);
  const groups = new Map<string, CalendarEntry[]>();
  cal.data?.forEach((e) => {
    const k = dayKey(e.planned_at);
    groups.set(k, [...(groups.get(k) ?? []), e]);
  });

  const cancel = async (id: number) => {
    await api(`/calendar/${id}`, { method: "DELETE" });
    cal.reload();
  };

  return (
    <div className="stack">
      <div>
        <h1>Kalender Produksi & Distribusi</h1>
        <p className="sub" style={{ marginBottom: 0 }}>7 hari terakhir hingga 30 hari ke depan · 39 kanal (nasional + 38 provinsi) dan akun resmi</p>
      </div>
      {cal.error && <Msg kind="error">{cal.error}</Msg>}
      {cal.data?.length === 0 && <div className="card empty">Belum ada jadwal. Jadwalkan dari halaman Naskah.</div>}
      {[...groups.entries()].map(([day, entries]) => (
        <div className="card" key={day}>
          <h2>{day}</h2>
          <div className="table-wrap">
            <table>
              <thead><tr><th>Jam</th><th>Naskah</th><th>Kanal</th><th>Status naskah</th><th>Jadwal</th><th /></tr></thead>
              <tbody>{entries.map((e) => (
                <tr key={e.id}>
                  <td className="num">{new Date(e.planned_at).toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" })}</td>
                  <td>{e.draft_title}</td>
                  <td>{e.channel_name}</td>
                  <td><span className="chip">{STATUS_DRAFT[e.draft_status]}</span></td>
                  <td>{e.status}</td>
                  <td>{e.status === "rencana" && hasRole("redaktur", "operator", "pimpinan") &&
                    <button className="sm danger" onClick={() => cancel(e.id)}>Batalkan</button>}</td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        </div>
      ))}
    </div>
  );
}
