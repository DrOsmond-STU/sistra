import { useEffect, useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { api } from "../api";
import { useAuth } from "../auth";
import DraftTools, { type DraftWithEditorial } from "../components/DraftTools";
import { Prio, dueText, useBusy } from "../components/kit";
import { Msg, useLoad } from "../components/ui";
import { ACTION_LABEL, STATUS_DRAFT, fmtDateTime, toLocalInput } from "../format";
import type { Channel } from "../types";
import { FORMAT_LABEL, type Board, type Card, type DraftComment } from "../types47";

const COMMENT_REQUIRED = new Set(["draf", "ditarik"]);
const COL_HINT: Record<string, string> = {
  draf: "Disusun penulis", ditinjau: "Menunggu persetujuan redaktur/pimpinan", disetujui: "Bertanda tangan, siap tayang", tayang: "Tayang 14 hari terakhir",
};

function KCard({ c, sel, onOpen }: { c: Card; sel: boolean; onOpen: () => void }) {
  return (
    <button className={`kcard${sel ? " sel" : ""}`} onClick={onOpen}>
      <span className="t">{c.title}</span>
      <span className="meta">
        <span>{FORMAT_LABEL[c.format] ?? c.format}</span>
        {c.province_name && <span>{c.province_name}</span>}
        {c.ai_generated && <span title="Disusun dengan bantuan AI">✦ AI</span>}
      </span>
      <span className="meta">
        <Prio p={c.priority} />
        {c.editorial_score !== null && <span title="Skor editorial AI">skor {c.editorial_score}</span>}
        {c.comments > 0 && <span>💬 {c.comments}</span>}
      </span>
      <span className="meta">
        <span>{c.assignee_name ? `→ ${c.assignee_name}` : c.author_name}</span>
        {c.due_at && <span className={c.overdue ? "late" : ""}>⏱ {dueText(c.due_at)}</span>}
      </span>
    </button>
  );
}

function Detail({ id, team, onClose, onChanged }: { id: number; team: Board["team"]; onClose: () => void; onChanged: () => void }) {
  const { user, hasRole } = useAuth();
  const [d, setD] = useState<DraftWithEditorial | null>(null);
  const [card, setCard] = useState<Card | null>(null);
  const [comments, setComments] = useState<DraftComment[]>([]);
  const [note, setNote] = useState("");
  const [disc, setDisc] = useState("");
  const [edit, setEdit] = useState<{ title: string; body: string } | null>(null);
  const [sched, setSched] = useState({ channels: [] as string[], at: toLocalInput(new Date(Date.now() + 3600_000)) });
  const [msg, setMsg] = useState<{ kind: "ok" | "error" | "warn"; text: string } | null>(null);
  const [busy, run] = useBusy();
  const channels = useLoad(() => api<Channel[]>("/channels"), []);

  const load = async () => {
    const [dd, cc] = await Promise.all([api<DraftWithEditorial>(`/drafts/${id}`), api<DraftComment[]>(`/drafts/${id}/comments`)]);
    setD(dd); setComments(cc);
  };
  useEffect(() => { setMsg(null); setEdit(null); setNote(""); setCard(null); load().catch((e) => setMsg({ kind: "error", text: (e as Error).message })); // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  const act = (key: string, fn: () => Promise<unknown>, ok: string) => run(key, async () => {
    try { await fn(); setMsg({ kind: "ok", text: ok }); await load(); onChanged(); } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); }
  });

  if (!d) return <div className="drawer"><div className="empty">{msg?.text ?? "Memuat naskah…"}</div></div>;
  const canAssign = hasRole("redaktur", "pimpinan");
  const canEdit = d.status === "draf" && (d.created_by === user?.id || hasRole("redaktur"));
  const canSchedule = !["tayang", "ditarik"].includes(d.status) && hasRole("redaktur", "operator", "pimpinan");
  const current = card ?? { assignee_id: d.assignee_id ?? null, due_at: d.due_at ?? null, priority: d.priority ?? "normal" };

  const assign = (patch: Record<string, unknown>) => act("as", async () => setCard(await api<Card>(`/drafts/${d.id}/assign`, { method: "PATCH", body: patch })), "Penugasan diperbarui.");

  return (
    <aside className="drawer" aria-label="Detail naskah">
      <div className="row between">
        <span className="chip">{STATUS_DRAFT[d.status]} · v{d.version}</span>
        <div className="row">
          <Link className="btn sm" to={`/naskah`}>Daftar naskah</Link>
          <button className="sm" onClick={onClose} aria-label="Tutup">✕ Tutup</button>
        </div>
      </div>
      <h2 style={{ margin: 0 }}>{d.title}</h2>
      <div className="small muted">{FORMAT_LABEL[d.format ?? d.kind] ?? d.kind} · {d.author_name} · {d.province_name ?? "Nasional"} · {d.issue_code ?? "tanpa isu"}</div>
      {msg && <Msg kind={msg.kind}>{msg.text}</Msg>}
      {d.ai_label && <Msg kind="warn">{d.ai_label}</Msg>}

      {edit ? (
        <div className="stack" style={{ gap: 8 }}>
          <input value={edit.title} onChange={(e) => setEdit({ ...edit, title: e.target.value })} aria-label="Judul" />
          <textarea style={{ minHeight: 220 }} value={edit.body} onChange={(e) => setEdit({ ...edit, body: e.target.value })} aria-label="Isi" />
          <div className="row">
            <button className="primary" disabled={!!busy} onClick={() => act("ed", async () => { await api(`/drafts/${d.id}`, { method: "PUT", body: edit }); setEdit(null); }, "Revisi tersimpan.")}>Simpan revisi</button>
            <button onClick={() => setEdit(null)}>Batal</button>
          </div>
        </div>
      ) : (
        <>
          <div className="body-text">{d.body}</div>
          {canEdit && <div><button className="sm" onClick={() => setEdit({ title: d.title, body: d.body })}>Sunting naskah</button></div>}
        </>
      )}

      {d.allowed_actions.length > 0 && (
        <div className="card stack" style={{ gap: 8, padding: 12 }}>
          <h3 style={{ margin: 0 }}>Keputusan</h3>
          <label className="field"><span>Catatan (wajib untuk mengembalikan/menarik)</span>
            <textarea style={{ minHeight: 56 }} value={note} onChange={(e) => setNote(e.target.value)} /></label>
          <div className="row">
            {d.allowed_actions.map((a) => (
              <button key={a} disabled={!!busy || (COMMENT_REQUIRED.has(a) && !note.trim())}
                      className={["disetujui", "tayang", "ditinjau"].includes(a) ? "primary" : a === "ditarik" ? "danger" : ""}
                      onClick={() => act("tr", async () => { await api(`/drafts/${d.id}/transition`, { method: "POST", body: { to_status: a, comment: note } }); setNote(""); }, `Status menjadi "${STATUS_DRAFT[a]}".`)}>
                {ACTION_LABEL[a] ?? a}
              </button>
            ))}
          </div>
          {d.allowed_actions.includes("disetujui") && <div className="small muted">Persetujuan dicatat dengan tanda tangan HMAC atas isi versi ini; perubahan isi setelahnya membatalkan tanda tangan.</div>}
        </div>
      )}

      <div className="stack" style={{ gap: 8 }}>
        <h3 style={{ margin: 0 }}>Penugasan</h3>
        <div className="form-grid">
          <label className="field"><span>Penanggung jawab</span>
            <select disabled={!canAssign || !!busy} value={current.assignee_id ?? ""} onChange={(e) => assign({ assignee_id: e.target.value ? Number(e.target.value) : null })}>
              <option value="">— belum ditugaskan —</option>
              {team.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </select></label>
          <label className="field"><span>Prioritas</span>
            <select disabled={!canAssign || !!busy} value={current.priority ?? "normal"} onChange={(e) => assign({ priority: e.target.value })}>
              <option value="tinggi">Tinggi</option><option value="normal">Normal</option><option value="rendah">Rendah</option>
            </select></label>
          <label className="field full"><span>Tenggat {current.due_at ? `(${dueText(current.due_at)})` : ""}</span>
            <input type="datetime-local" disabled={!canAssign || !!busy} value={current.due_at ? toLocalInput(new Date(current.due_at)) : ""}
                   onChange={(e) => assign({ due_at: e.target.value ? new Date(e.target.value).toISOString() : null })} /></label>
        </div>
        {!canAssign && <div className="small muted">Penugasan diatur oleh redaktur/pimpinan.</div>}
      </div>

      {canSchedule && (
        <div className="stack" style={{ gap: 8 }}>
          <h3 style={{ margin: 0 }}>Jadwalkan tayang</h3>
          <div className="row">
            <input type="datetime-local" value={sched.at} onChange={(e) => setSched({ ...sched, at: e.target.value })} aria-label="Waktu tayang" />
            <select multiple size={4} value={sched.channels} style={{ minWidth: 200, flex: 1 }} aria-label="Kanal"
                    onChange={(e) => setSched({ ...sched, channels: Array.from(e.target.selectedOptions).map((o) => o.value) })}>
              {channels.data?.map((c) => <option key={c.code} value={c.code}>{c.name}</option>)}
            </select>
            <button disabled={!sched.channels.length || !!busy} onClick={() => run("sc", async () => {
              try {
                const r = await api<{ conflicts: string[] }>(`/drafts/${d.id}/schedule`, { method: "POST", body: { channel_codes: sched.channels, planned_at: new Date(sched.at).toISOString() } });
                setMsg(r.conflicts.length ? { kind: "warn", text: `Terjadwal, dengan potensi bentrok: ${r.conflicts.join("; ")}` } : { kind: "ok", text: "Jadwal tersimpan di kalender." });
                await load();
              } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); }
            })}>Jadwalkan</button>
          </div>
          {!!d.schedule?.length && d.schedule.map((s) => <div key={s.id} className="small">{fmtDateTime(s.planned_at)} · {s.channel_name} · {s.status}</div>)}
          <div className="small muted">Lihat semua jadwal di <Link to="/kalender">Kalender</Link>. Penyaluran ke 39 kanal web dilakukan operator di <Link to="/distribusi">Distribusi</Link>.</div>
        </div>
      )}

      <div className="stack" style={{ gap: 8 }}>
        <h3 style={{ margin: 0 }}>Diskusi ({comments.length})</h3>
        {comments.map((c) => <div key={c.id} className="small"><b>{c.user_name}</b> <span className="muted">· {fmtDateTime(c.at)}</span><div>{c.body}</div></div>)}
        <div className="row">
          <input style={{ flex: 1 }} value={disc} onChange={(e) => setDisc(e.target.value)} placeholder="Tulis catatan untuk tim…" aria-label="Catatan diskusi" />
          <button className="sm" disabled={!disc.trim() || !!busy} onClick={() => act("cm", async () => { await api(`/drafts/${d.id}/comments`, { method: "POST", body: { body: disc } }); setDisc(""); }, "Catatan terkirim.")}>Kirim</button>
        </div>
      </div>

      <DraftTools key={d.id + ":" + d.version} draft={d} onChanged={() => { load(); onChanged(); }} />

      <div>
        <h3>Riwayat persetujuan</h3>
        {!d.steps?.length && <div className="small muted">Belum ada transisi.</div>}
        <ul className="timeline">
          {d.steps?.map((s, i) => (
            <li key={i}>
              <b>{STATUS_DRAFT[s.from_status]} → {STATUS_DRAFT[s.to_status]}</b> oleh {s.actor_name} ({s.actor_role}), v{s.version}
              <div className="small muted">{fmtDateTime(s.at)}{s.signature ? ` · tanda tangan ${s.signature.slice(0, 12)}…` : ""}</div>
              {s.comment && <div className="small">“{s.comment}”</div>}
            </li>
          ))}
        </ul>
      </div>
    </aside>
  );
}

export default function EditorialPage() {
  const { user } = useAuth();
  const [params, setParams] = useSearchParams();
  const board = useLoad(() => api<Board>("/m5/board"), []);
  const [q, setQ] = useState("");
  const [mine, setMine] = useState(false);
  const [prio, setPrio] = useState("");
  const openId = Number(params.get("id")) || null;
  const setOpen = (id: number | null) => setParams(id ? { id: String(id) } : {}, { replace: true });

  const cols = useMemo(() => (board.data?.columns ?? []).map((c) => ({
    ...c,
    cards: c.cards.filter((k) => (!q || k.title.toLowerCase().includes(q.toLowerCase())) && (!mine || k.assignee_id === user?.id) && (!prio || k.priority === prio)),
  })), [board.data, q, mine, prio, user?.id]);
  const overdue = board.data?.columns.flatMap((c) => c.cards).filter((c) => c.overdue).length ?? 0;

  return (
    <div className="stack">
      <div className="row between">
        <div>
          <h1>Editorial & Persetujuan <span className="muted" style={{ fontWeight: 400 }}>· M5</span></h1>
          <p className="sub" style={{ marginBottom: 0 }}>Papan kerja redaksi: tugaskan, diskusikan, periksa dengan AI, setujui bertanda tangan, dan jadwalkan tayang</p>
        </div>
        <div className="row">
          <Link className="btn" to="/kalender">Kalender</Link>
          <Link className="btn primary" to="/produksi">+ Produksi dengan AI</Link>
        </div>
      </div>
      {board.error && <Msg kind="error">{board.error}</Msg>}
      {overdue > 0 && <Msg kind="warn">{overdue} naskah melewati tenggat.</Msg>}
      <div className="row">
        <input placeholder="Cari judul…" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Cari naskah" style={{ minWidth: 220 }} />
        <select value={prio} onChange={(e) => setPrio(e.target.value)} aria-label="Filter prioritas">
          <option value="">Semua prioritas</option><option value="tinggi">Tinggi</option><option value="normal">Normal</option><option value="rendah">Rendah</option>
        </select>
        <label className="row small"><input type="checkbox" checked={mine} onChange={(e) => setMine(e.target.checked)} /> Tugas saya</label>
        <button className="sm ghost" onClick={board.reload}>Muat ulang</button>
      </div>
      {board.loading && !board.data && <div className="empty">Memuat papan…</div>}
      <div className="board">
        {cols.map((c) => (
          <section className="col" key={c.status} aria-label={STATUS_DRAFT[c.status]}>
            <div className="col-head"><span>{STATUS_DRAFT[c.status]}</span><span className="chip num">{c.cards.length}</span></div>
            <div className="small muted" style={{ padding: "0 4px" }}>{COL_HINT[c.status]}</div>
            {c.cards.map((k) => <KCard key={k.id} c={k} sel={openId === k.id} onOpen={() => setOpen(k.id)} />)}
            {!c.cards.length && <div className="small muted" style={{ padding: 8 }}>Kosong</div>}
          </section>
        ))}
      </div>
      {openId && board.data && (
        <>
          <div className="drawer-bg" onClick={() => setOpen(null)} />
          <Detail id={openId} team={board.data.team} onClose={() => setOpen(null)} onChanged={board.reload} />
        </>
      )}
    </div>
  );
}
