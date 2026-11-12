import { useState, type FormEvent } from "react";
import { api } from "../api";
import { useAuth } from "../auth";
import { Msg, useLoad } from "../components/ui";
import { ACTION_LABEL, STATUS_DRAFT, fmtDateTime, toLocalInput } from "../format";
import type { Channel, Draft, Issue } from "../types";

const KINDS = ["rilis", "opini", "editorial", "briefing", "infografis", "video", "audio"];
const COMMENT_REQUIRED = new Set(["draf", "ditarik"]);

function NewDraft({ onCreated }: { onCreated: (d: Draft) => void }) {
  const issues = useLoad(() => api<Issue[]>("/issues"), []);
  const provinces = useLoad(() => api<{ code: string; name: string }[]>("/provinces"), []);
  const [f, setF] = useState({ kind: "rilis", title: "", body: "", issue_code: "", province_code: "", ai_generated: false });
  const [err, setErr] = useState("");
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    try {
      const d = await api<Draft>("/drafts", {
        method: "POST",
        body: { ...f, issue_code: f.issue_code || null, province_code: f.province_code || null },
      });
      setF({ ...f, title: "", body: "" });
      onCreated(d);
    } catch (e2) {
      setErr((e2 as Error).message);
    }
  };
  return (
    <form className="card stack" onSubmit={submit}>
      <h2>Naskah baru</h2>
      <div className="form-grid">
        <label className="field"><span>Jenis</span>
          <select value={f.kind} onChange={(e) => setF({ ...f, kind: e.target.value })}>{KINDS.map((k) => <option key={k}>{k}</option>)}</select></label>
        <label className="field"><span>Isu terkait</span>
          <select value={f.issue_code} onChange={(e) => setF({ ...f, issue_code: e.target.value })}>
            <option value="">—</option>{issues.data?.map((i) => <option key={i.code} value={i.code}>{i.name}</option>)}
          </select></label>
        <label className="field full req"><span>Judul</span>
          <input value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} required minLength={5} /></label>
        <label className="field full req"><span>Isi naskah</span>
          <textarea value={f.body} onChange={(e) => setF({ ...f, body: e.target.value })} required minLength={20} /></label>
        <label className="field"><span>Pelokalan provinsi</span>
          <select value={f.province_code} onChange={(e) => setF({ ...f, province_code: e.target.value })}>
            <option value="">Nasional</option>{provinces.data?.map((p) => <option key={p.code} value={p.code}>{p.name}</option>)}
          </select></label>
        <label className="row" style={{ alignSelf: "end" }}>
          <input type="checkbox" checked={f.ai_generated} onChange={(e) => setF({ ...f, ai_generated: e.target.checked })} />
          Disusun dengan bantuan AI
        </label>
      </div>
      <Msg kind="error">{err}</Msg>
      <div><button className="primary">Simpan sebagai draf</button></div>
    </form>
  );
}

export default function DraftsPage() {
  const { user, hasRole } = useAuth();
  const [status, setStatus] = useState("");
  const [sel, setSel] = useState<Draft | null>(null);
  const [comment, setComment] = useState("");
  const [edit, setEdit] = useState<{ title: string; body: string } | null>(null);
  const [sched, setSched] = useState({ channels: [] as string[], at: toLocalInput(new Date(Date.now() + 3600_000)) });
  const [msg, setMsg] = useState<{ kind: "ok" | "error" | "warn"; text: string } | null>(null);
  const [creating, setCreating] = useState(false);
  const list = useLoad(() => api<Draft[]>("/drafts", { query: { status } }), [status]);
  const channels = useLoad(() => api<Channel[]>("/channels"), []);
  const isAuthor = hasRole("redaktur", "produser", "analis");

  const open = async (id: number) => {
    setSel(await api<Draft>(`/drafts/${id}`));
    setComment("");
    setEdit(null);
    setMsg(null);
    setCreating(false);
  };

  const run = async (fn: () => Promise<Draft | void>, ok: string) => {
    try {
      const d = await fn();
      if (d) setSel(d);
      setMsg({ kind: "ok", text: ok });
      list.reload();
    } catch (e) {
      setMsg({ kind: "error", text: (e as Error).message });
    }
  };

  const transition = (to: string) =>
    run(() => api<Draft>(`/drafts/${sel!.id}/transition`, { method: "POST", body: { to_status: to, comment } }).then((d) => {
      setComment("");
      return d;
    }), `Status menjadi "${STATUS_DRAFT[to]}".`);

  const schedule = async () => {
    try {
      const r = await api<{ conflicts: string[] }>(`/drafts/${sel!.id}/schedule`, {
        method: "POST", body: { channel_codes: sched.channels, planned_at: new Date(sched.at).toISOString() },
      });
      setMsg(r.conflicts.length ? { kind: "warn", text: `Terjadwal, dengan potensi bentrok: ${r.conflicts.join("; ")}` }
        : { kind: "ok", text: "Jadwal distribusi tersimpan." });
      setSel(await api<Draft>(`/drafts/${sel!.id}`));
    } catch (e) {
      setMsg({ kind: "error", text: (e as Error).message });
    }
  };

  const canEdit = sel && sel.status === "draf" && (sel.created_by === user?.id || hasRole("redaktur"));
  const canSchedule = sel && !["tayang", "ditarik"].includes(sel.status) && hasRole("redaktur", "operator", "pimpinan");

  return (
    <div className="stack">
      <div className="row between">
        <div>
          <h1>Naskah & Persetujuan Berjenjang</h1>
          <p className="sub" style={{ marginBottom: 0 }}>Draf → Ditinjau → Disetujui (bertanda tangan) → Tayang. Tidak ada publikasi tanpa persetujuan manusia.</p>
        </div>
        {isAuthor && <button className="primary" onClick={() => { setCreating(true); setSel(null); }}>+ Naskah baru</button>}
      </div>
      {msg && <Msg kind={msg.kind}>{msg.text}</Msg>}
      <div className="tabs">
        {["", "draf", "ditinjau", "disetujui", "tayang", "ditarik"].map((s) => (
          <button key={s} className={status === s ? "on" : ""} onClick={() => setStatus(s)}>{s ? STATUS_DRAFT[s] : "Semua"}</button>
        ))}
      </div>
      <div className="split">
        <div className="card table-wrap">
          {list.data?.length === 0 && <div className="empty">Belum ada naskah.</div>}
          {!!list.data?.length && (
            <table>
              <thead><tr><th>Judul</th><th>Status</th><th>Penulis</th><th>Diperbarui</th></tr></thead>
              <tbody>{list.data.map((d) => (
                <tr key={d.id} className={`clickable${sel?.id === d.id ? " selected" : ""}`} onClick={() => open(d.id)}>
                  <td><b>{d.title}</b><div className="small muted">{d.kind}{d.ai_generated ? " · dibantu AI" : ""}{d.province_name ? ` · ${d.province_name}` : ""}</div></td>
                  <td><span className="chip">{STATUS_DRAFT[d.status]}</span>{d.allowed_actions.length > 0 && <div className="small" style={{ color: "var(--accent)" }}>dapat Anda proses</div>}</td>
                  <td className="small">{d.author_name}</td>
                  <td className="small">{fmtDateTime(d.updated_at)}</td>
                </tr>
              ))}</tbody>
            </table>
          )}
        </div>
        <div className="stack">
          {creating && <NewDraft onCreated={(d) => { setCreating(false); setSel(d); list.reload(); setMsg({ kind: "ok", text: "Draf tersimpan." }); }} />}
          {!creating && !sel && <div className="card empty">Pilih naskah untuk meninjau, menyetujui, atau menjadwalkan.</div>}
          {sel && (
            <div className="card stack">
              <div className="row between">
                <h2 style={{ margin: 0 }}>{sel.title}</h2>
                <span className="chip">{STATUS_DRAFT[sel.status]} · v{sel.version}</span>
              </div>
              {sel.ai_label && <Msg kind="warn">{sel.ai_label}</Msg>}
              {edit ? (
                <div className="stack" style={{ gap: 8 }}>
                  <input value={edit.title} onChange={(e) => setEdit({ ...edit, title: e.target.value })} aria-label="Judul" />
                  <textarea value={edit.body} onChange={(e) => setEdit({ ...edit, body: e.target.value })} aria-label="Isi" />
                  <div className="row">
                    <button className="primary" onClick={() => run(() => api<Draft>(`/drafts/${sel.id}`, { method: "PUT", body: edit }).then((d) => { setEdit(null); return d; }), "Revisi tersimpan.")}>Simpan revisi</button>
                    <button onClick={() => setEdit(null)}>Batal</button>
                  </div>
                </div>
              ) : (
                <div className="body-text">{sel.body}</div>
              )}
              <dl className="kv">
                <dt>Penulis</dt><dd>{sel.author_name}</dd>
                <dt>Isu</dt><dd>{sel.issue_code ?? "-"}</dd>
                <dt>Hash isi</dt><dd className="mono">{sel.content_hash.slice(0, 16)}…</dd>
                <dt>Disetujui</dt><dd>{sel.approved_at ? fmtDateTime(sel.approved_at) : "-"}</dd>
              </dl>
              {canEdit && !edit && <div><button onClick={() => setEdit({ title: sel.title, body: sel.body })}>Sunting naskah</button></div>}

              {sel.allowed_actions.length > 0 && (
                <div className="stack" style={{ gap: 8 }}>
                  <label className="field"><span>Catatan (wajib untuk mengembalikan/menarik)</span>
                    <textarea style={{ minHeight: 60 }} value={comment} onChange={(e) => setComment(e.target.value)} /></label>
                  <div className="row">
                    {sel.allowed_actions.map((a) => (
                      <button key={a} className={a === "disetujui" || a === "tayang" || a === "ditinjau" ? "primary" : a === "ditarik" ? "danger" : ""}
                              disabled={COMMENT_REQUIRED.has(a) && !comment.trim()} onClick={() => transition(a)}>
                        {ACTION_LABEL[a] ?? a}
                      </button>
                    ))}
                  </div>
                </div>
              )}

              {canSchedule && (
                <div className="stack" style={{ gap: 8 }}>
                  <h3 style={{ margin: 0 }}>Jadwalkan distribusi</h3>
                  <div className="row">
                    <input type="datetime-local" value={sched.at} onChange={(e) => setSched({ ...sched, at: e.target.value })} aria-label="Waktu tayang" />
                    <select multiple size={4} value={sched.channels} style={{ minWidth: 220 }} aria-label="Kanal"
                            onChange={(e) => setSched({ ...sched, channels: Array.from(e.target.selectedOptions).map((o) => o.value) })}>
                      {channels.data?.map((c) => <option key={c.code} value={c.code}>{c.name}</option>)}
                    </select>
                    <button disabled={!sched.channels.length} onClick={schedule}>Jadwalkan</button>
                  </div>
                </div>
              )}

              {!!sel.schedule?.length && (
                <div>
                  <h3>Jadwal</h3>
                  {sel.schedule.map((s) => <div key={s.id} className="small">{fmtDateTime(s.planned_at)} · {s.channel_name} · {s.status}</div>)}
                </div>
              )}
              <div>
                <h3>Riwayat persetujuan</h3>
                {!sel.steps?.length && <div className="small muted">Belum ada transisi.</div>}
                <ul className="timeline">
                  {sel.steps?.map((s, i) => (
                    <li key={i}>
                      <b>{STATUS_DRAFT[s.from_status]} → {STATUS_DRAFT[s.to_status]}</b> oleh {s.actor_name} ({s.actor_role}), v{s.version}
                      <div className="small muted">{fmtDateTime(s.at)}{s.signature ? ` · tanda tangan ${s.signature.slice(0, 12)}…` : ""}</div>
                      {s.comment && <div className="small">“{s.comment}”</div>}
                    </li>
                  ))}
                </ul>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
