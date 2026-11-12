import { useState } from "react";
import { Link } from "react-router-dom";
import { api } from "../api";
import { useAuth } from "../auth";
import { LevelChip, Msg, useLoad } from "../components/ui";
import { ALERT_STATUS, fmtDateTime, fmtPct, nf } from "../format";
import type { Alert } from "../types";

const ACTIONS: { key: string; label: string; from: string[]; noteRequired?: boolean }[] = [
  { key: "ack", label: "Terima", from: ["open"] },
  { key: "tangani", label: "Tangani", from: ["open", "ack"] },
  { key: "selesai", label: "Selesaikan", from: ["open", "ack", "in_progress"], noteRequired: true },
  { key: "catatan", label: "Tambah catatan", from: ["open", "ack", "in_progress", "resolved"], noteRequired: true },
];

export default function AlertsPage() {
  const { hasRole } = useAuth();
  const [state, setState] = useState("aktif");
  const [selected, setSelected] = useState<Alert | null>(null);
  const [note, setNote] = useState("");
  const [msg, setMsg] = useState<{ kind: "ok" | "error"; text: string } | null>(null);
  const list = useLoad(() => api<Alert[]>("/alerts", { query: { state } }), [state]);
  const canAct = hasRole("analis", "pimpinan");

  const open = async (id: number) => {
    setMsg(null);
    setNote("");
    setSelected(await api<Alert>(`/alerts/${id}`));
  };

  const act = async (action: string) => {
    if (!selected) return;
    try {
      const updated = await api<Alert>(`/alerts/${selected.id}/${action}`, { method: "POST", body: { note } });
      setSelected(updated);
      setNote("");
      setMsg({ kind: "ok", text: "Status peringatan diperbarui." });
      list.reload();
    } catch (e) {
      setMsg({ kind: "error", text: (e as Error).message });
    }
  };

  const evaluate = async () => {
    try {
      const r = await api<{ created: number[]; escalated: number[] }>("/alerts/evaluate", { method: "POST" });
      setMsg({ kind: "ok", text: `Evaluasi selesai: ${r.created.length} peringatan baru, ${r.escalated.length} eskalasi.` });
      list.reload();
    } catch (e) {
      setMsg({ kind: "error", text: (e as Error).message });
    }
  };

  return (
    <div className="stack">
      <div className="row between">
        <div>
          <h1>Peringatan Dini Berjenjang</h1>
          <p className="sub" style={{ marginBottom: 0 }}>Hijau · Kuning · Oranye · Merah — dengan siklus hidup penanganan</p>
        </div>
        {hasRole("analis", "admin") && <button onClick={evaluate}>Evaluasi sekarang</button>}
      </div>
      {msg && <Msg kind={msg.kind}>{msg.text}</Msg>}
      <div className="tabs" role="tablist">
        {["aktif", "selesai", "semua"].map((s) => (
          <button key={s} className={state === s ? "on" : ""} onClick={() => setState(s)} role="tab" aria-selected={state === s}>
            {s[0].toUpperCase() + s.slice(1)}
          </button>
        ))}
      </div>
      <div className="split">
        <div className="card table-wrap">
          {list.error && <Msg kind="error">{list.error}</Msg>}
          {list.data && list.data.length === 0 && <div className="empty">Tidak ada peringatan.</div>}
          {list.data && list.data.length > 0 && (
            <table>
              <thead><tr><th>Isu</th><th>Tingkat</th><th>Status</th><th>Diperbarui</th></tr></thead>
              <tbody>
                {list.data.map((a) => (
                  <tr key={a.id} className={`clickable${selected?.id === a.id ? " selected" : ""}`} onClick={() => open(a.id)}>
                    <td><b>{a.issue_name}</b><div className="small muted">{a.reason}</div></td>
                    <td><LevelChip level={a.level} short /></td>
                    <td>{ALERT_STATUS[a.status] ?? a.status}</td>
                    <td className="small">{fmtDateTime(a.updated_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
        <div className="card">
          {!selected ? (
            <div className="empty">Pilih peringatan untuk melihat rincian dan menindaklanjuti.</div>
          ) : (
            <div className="stack">
              <div className="row between">
                <h2 style={{ margin: 0 }}>{selected.issue_name}</h2>
                <LevelChip level={selected.level} />
              </div>
              <dl className="kv">
                <dt>Status</dt><dd>{ALERT_STATUS[selected.status]}</dd>
                <dt>Dipicu</dt><dd>{fmtDateTime(selected.triggered_at)}</dd>
                <dt>Volume 24 jam</dt><dd>{nf.format(selected.metrics.volume_24h ?? 0)}</dd>
                <dt>Rerata harian</dt><dd>{selected.metrics.baseline_daily}</dd>
                <dt>Pertumbuhan</dt><dd>{selected.metrics.growth}×</dd>
                <dt>Sentimen negatif</dt><dd>{fmtPct(selected.metrics.negative_ratio ?? 0)}</dd>
                <dt>Sebaran provinsi</dt><dd>{selected.metrics.province_spread}</dd>
              </dl>
              <Link to={`/konten?issue=${selected.issue_code}`}>Lihat konten terkait →</Link>
              <div>
                <h3>Riwayat</h3>
                <ul className="timeline">
                  {selected.events.map((e, i) => (
                    <li key={i}>
                      <b>{e.action}</b> <span className="small muted">{fmtDateTime(e.at)}</span>
                      {e.note && <div className="small">{e.note}</div>}
                    </li>
                  ))}
                </ul>
              </div>
              {canAct && (
                <div className="stack" style={{ gap: 8 }}>
                  <label className="field">
                    <span>Catatan tindak lanjut</span>
                    <textarea style={{ minHeight: 70 }} value={note} onChange={(e) => setNote(e.target.value)} />
                  </label>
                  <div className="row">
                    {ACTIONS.filter((a) => a.from.includes(selected.status)).map((a) => (
                      <button key={a.key} className={a.key === "selesai" ? "primary" : ""} onClick={() => act(a.key)}
                              disabled={a.noteRequired && !note.trim()}>
                        {a.label}
                      </button>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
