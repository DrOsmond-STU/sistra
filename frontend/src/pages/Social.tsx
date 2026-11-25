import { useState, type FormEvent } from "react";
import { useSearchParams } from "react-router-dom";
import { CampaignsTab, PacksTab, PartnersTab, QuotaPanel, ThreadView } from "./SocialKak";
import { api } from "../api";
import { useAuth } from "../auth";
import { ModeChip, Prio, Tile, dueText, useBusy } from "../components/kit";
import { Msg, useLoad, CopyButton } from "../components/ui";
import { fmtDateTime, nf, relTime, toLocalInput } from "../format";
import { CATEGORY_LABEL, COMMENT_STATUS, FORMAT_LABEL, type Account, type Card, type KbEntry, type PostTask, type SocialComment } from "../types47";
import SebaranMap from "../components/geo";

type Tab = "komentar" | "tugas" | "akun" | "bahan" | "paket" | "kampanye" | "mitra";
type Note = { kind: "ok" | "error" | "warn"; text: string } | null;
const PLATFORMS: Record<string, string> = { instagram: "Instagram", x: "X", tiktok: "TikTok", facebook: "Facebook", youtube: "YouTube", threads: "Threads" };

// ------------------------------------------------------------------ komentar
function CommentCard({ c, canReply, canTriage, onChange }: { c: SocialComment; canReply: boolean; canTriage: boolean; onChange: (c: SocialComment) => void }) {
  const [reply, setReply] = useState(c.suggested_reply ?? "");
  const [err, setErr] = useState("");
  const [busy, run] = useBusy();
  const call = (key: string, path: string, body?: unknown) => run(key, async () => {
    try { const r = await api<SocialComment>(path, { method: "POST", body }); onChange(r); if (key === "sg") setReply(r.suggested_reply ?? ""); setErr(""); } catch (e) { setErr((e as Error).message); }
  });
  const open = c.status === "baru" || c.status === "ditriase";
  const [thread, setThread] = useState(false);
  return (
    <div className={`cmt${c.bot_flag ? " bot" : c.priority === "tinggi" ? " hi" : ""}`}>
      <div className="who">
        <b style={{ color: "var(--text)" }}>{c.author}</b>
        <span>di {PLATFORMS[c.platform] ?? c.platform} {c.handle}</span>
        <span>{relTime(c.at)}</span>
        {c.issue_code && <span>{c.issue_code}</span>}
        <span className="chip">{COMMENT_STATUS[c.status] ?? c.status}</span>
      </div>
      <div>{c.text}</div>
      <div className="row" style={{ gap: 6 }}>
        {c.bot_flag && <span className="chip prio-tinggi" title="Teks identik dari ≥3 akun dalam 10 menit">■ Indikasi bot terkoordinasi</span>}
        {c.category && <span className="chip">{CATEGORY_LABEL[c.category] ?? c.category}</span>}
        {c.sentiment && <span className="chip">{c.sentiment}</span>}
        <Prio p={c.priority} />
        {c.ai_mode && <ModeChip mode={c.ai_mode} />}
      </div>
      {c.reason && <div className="small muted">Alasan triase: {c.reason}</div>}
      {(c.pii_masked ?? 0) > 0 && <div className="small muted">🔒 {c.pii_masked} data pribadi (NIK/telepon/surel/rekening) disamarkan sesuai UU PDP</div>}
      {((c.replies ?? 0) > 0 || c.parent_id) && <div><button className="sm ghost" onClick={() => setThread((x) => !x)}>{thread ? "Sembunyikan utas" : c.parent_id ? "Lihat utas induk" : `Lihat ${c.replies} balasan (sub-komentar)`}</button></div>}
      {thread && <ThreadView id={c.id} onClose={() => setThread(false)} />}
      {c.status === "dibalas" && c.reply_text && (
        <div className="reply-box"><b>Balasan resmi</b> oleh {c.replied_by_name} · {fmtDateTime(c.replied_at)}<div>{c.reply_text}</div></div>
      )}
      {open && (canReply || canTriage) && (
        <div className="stack" style={{ gap: 6 }}>
          <textarea style={{ minHeight: 64 }} value={reply} onChange={(e) => setReply(e.target.value)} placeholder="Draf balasan resmi…" aria-label="Draf balasan" />
          <Msg kind="error">{err}</Msg>
          <div className="row">
            {canTriage && <button className="sm" disabled={!!busy} onClick={() => call("sg", `/m6/comments/${c.id}/suggest`)}>{busy === "sg" ? "Menyusun…" : "✦ Saran balasan SISTRA AGENT"}</button>}
            {canReply && <CopyButton text={reply.trim() ? reply : ""} />}
            {canReply && <button className="sm primary" disabled={!!busy || reply.trim().length < 2} onClick={() => call("rp", `/m6/comments/${c.id}/reply`, { text: reply })}>Tandai sudah dibalas</button>}
            {canTriage && <button className="sm" disabled={!!busy} onClick={() => call("es", `/m6/comments/${c.id}/status`, { status: "dieskalasi" })}>Eskalasi</button>}
            {canTriage && <button className="sm ghost" disabled={!!busy} onClick={() => call("ab", `/m6/comments/${c.id}/status`, { status: "diabaikan" })}>Abaikan</button>}
          </div>
        </div>
      )}
    </div>
  );
}

function IngestForm({ accounts, onDone }: { accounts: Account[]; onDone: (n: string) => void }) {
  const [acc, setAcc] = useState(accounts[0]?.id ?? 0);
  const [issue, setIssue] = useState("");
  const [text, setText] = useState("");
  const [err, setErr] = useState("");
  const issues = useLoad(() => api<{ issues: { code: string; name: string }[] }>("/m4/meta").then((m) => m.issues), []);
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const items = text.split("\n").map((l) => l.trim()).filter(Boolean).map((l) => {
      const i = l.indexOf(":");
      return i > 0 && i < 40 ? { author: l.slice(0, i).trim(), text: l.slice(i + 1).trim() } : { author: "anonim", text: l };
    });
    try {
      const r = await api<{ inserted: number; bot_flagged: number }>("/m6/comments", { method: "POST", body: { account_id: acc, issue_code: issue || null, items } });
      setText(""); onDone(`${r.inserted} komentar dicatat${r.bot_flagged ? `; ${r.bot_flagged} terindikasi bot terkoordinasi` : ""}. Jalankan triase SISTRA AGENT untuk mengelompokkan.`);
    } catch (ex) { setErr((ex as Error).message); }
  };
  return (
    <form className="card stack" onSubmit={submit} style={{ gap: 10 }}>
      <h3 style={{ margin: 0 }}>Catat komentar masuk</h3>
      <div className="small muted">Salin komentar dari platform (satu per baris, format <span className="mono">nama: isi komentar</span>). SISTRA tidak menarik data otomatis dari platform.</div>
      <Msg kind="error">{err}</Msg>
      <div className="row">
        <select value={acc} onChange={(e) => setAcc(Number(e.target.value))} aria-label="Akun">{accounts.map((a) => <option key={a.id} value={a.id}>{a.platform_label} {a.handle}</option>)}</select>
        <select value={issue} onChange={(e) => setIssue(e.target.value)} aria-label="Isu"><option value="">— isu (opsional) —</option>{issues.data?.map((i) => <option key={i.code} value={i.code}>{i.name}</option>)}</select>
      </div>
      <textarea style={{ minHeight: 90 }} value={text} onChange={(e) => setText(e.target.value)} placeholder={"warga_bdg: Kapan bansos tahap 4 cair?\nakun123: Ini hoaks, katanya beras SPHP dihentikan"} />
      <div className="row"><button className="primary" disabled={!text.trim()}>Simpan</button></div>
    </form>
  );
}

function CommentsTab({ accounts, setMsg }: { accounts: Account[]; setMsg: (n: Note) => void }) {
  const { hasRole } = useAuth();
  const [status, setStatus] = useState("terbuka");
  const [cat, setCat] = useState("");
  const [bot, setBot] = useState(false);
  const [ingest, setIngest] = useState(false);
  const [busy, run] = useBusy();
  const list = useLoad(() => api<SocialComment[]>("/m6/comments", { query: { status: status || undefined, category: cat || undefined, bot: bot ? 1 : undefined } }), [status, cat, bot]);
  const canReply = hasRole("operator");
  const canTriage = hasRole("operator", "analis");
  const untriaged = list.data?.filter((c) => c.status === "baru").length ?? 0;

  const triage = () => run("tr", async () => {
    try {
      const r = await api<{ mode: string; triaged: number; bot_flagged: number }>("/m6/comments/triage", { method: "POST", body: {} });
      setMsg({ kind: r.mode === "claude" ? "ok" : "warn", text: `${r.triaged} komentar ditriase ${r.mode === "claude" ? "oleh SISTRA AGENT" : "(mode simulasi, berbasis kata kunci)"}${r.bot_flagged ? `; ${r.bot_flagged} terindikasi bot` : ""}.` });
      list.reload();
    } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); }
  });

  return (
    <div className="stack">
      <div className="row between">
        <div className="row">
          <select value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Status">
            <option value="terbuka">Belum ditangani</option><option value="dibalas">Dibalas</option><option value="dieskalasi">Dieskalasi</option><option value="diabaikan">Diabaikan</option><option value="">Semua</option>
          </select>
          <select value={cat} onChange={(e) => setCat(e.target.value)} aria-label="Kategori">
            <option value="">Semua kategori</option>{Object.entries(CATEGORY_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
          <label className="row small"><input type="checkbox" checked={bot} onChange={(e) => setBot(e.target.checked)} /> Hanya indikasi bot</label>
        </div>
        <div className="row">
          {canTriage && <button onClick={() => setIngest((x) => !x)}>{ingest ? "Tutup" : "+ Catat komentar"}</button>}
          {canTriage && <button className="primary" disabled={!!busy} onClick={triage}>{busy ? "Mentriase…" : `✦ Triase SISTRA AGENT${untriaged ? ` (${untriaged} baru)` : ""}`}</button>}
        </div>
      </div>
      {ingest && <IngestForm accounts={accounts} onDone={(t) => { setIngest(false); setMsg({ kind: "ok", text: t }); list.reload(); }} />}
      <Msg kind="info">Balasan diketik/ditempel sendiri oleh petugas di platform resmi, lalu ditandai di sini sebagai jejak. SISTRA tidak mengirim balasan otomatis dan tidak memakai akun selain akun resmi.</Msg>
      {list.error && <Msg kind="error">{list.error}</Msg>}
      {list.data?.length === 0 && <div className="card empty">Tidak ada komentar pada filter ini.</div>}
      <div className="stack" style={{ gap: 10 }}>
        {list.data?.map((c) => (
          <CommentCard key={c.id} c={c} canReply={canReply} canTriage={canTriage}
                       onChange={(n) => list.setData((list.data ?? []).map((x) => (x.id === n.id ? n : x)))} />
        ))}
      </div>
    </div>
  );
}

// ------------------------------------------------------------------ tugas unggah
function NewTask({ accounts, onDone }: { accounts: Account[]; onDone: (t: string) => void }) {
  const ready = useLoad(() => api<Card[]>("/m7/ready"), []);
  const [draft, setDraft] = useState(0);
  const [acc, setAcc] = useState<number[]>([]);
  const [at, setAt] = useState(toLocalInput(new Date(Date.now() + 2 * 3600_000)));
  const [caption, setCaption] = useState("");
  const [err, setErr] = useState("");
  const submit = async (e: FormEvent) => {
    e.preventDefault(); setErr("");
    try {
      const r = await api<PostTask[]>("/m6/tasks", { method: "POST", body: { draft_id: draft, account_ids: acc, planned_at: new Date(at).toISOString(), caption } });
      onDone(`${r.length} tugas unggah dibuat untuk petugas akun.`);
    } catch (ex) { setErr((ex as Error).message); }
  };
  return (
    <form className="card stack" onSubmit={submit} style={{ gap: 10 }}>
      <h3 style={{ margin: 0 }}>Jadwalkan unggahan ke akun resmi</h3>
      <Msg kind="error">{err}</Msg>
      <label className="field req"><span>Naskah (hanya yang sudah disetujui)</span>
        <select value={draft} onChange={(e) => setDraft(Number(e.target.value))} required>
          <option value={0} disabled>— pilih naskah —</option>
          {ready.data?.map((d) => <option key={d.id} value={d.id}>#{d.id} {d.title} ({FORMAT_LABEL[d.format] ?? d.format})</option>)}
        </select></label>
      <div className="field"><span>Akun tujuan</span>
        <div className="row" style={{ gap: 12 }}>
          {accounts.filter((a) => a.active).map((a) => (
            <label key={a.id} className="row small" style={{ gap: 4 }}>
              <input type="checkbox" checked={acc.includes(a.id)} onChange={(e) => setAcc(e.target.checked ? [...acc, a.id] : acc.filter((x) => x !== a.id))} />
              {a.platform_label} {a.handle}
            </label>
          ))}
        </div>
      </div>
      <div className="form-grid">
        <label className="field"><span>Waktu unggah</span><input type="datetime-local" value={at} onChange={(e) => setAt(e.target.value)} /></label>
        <label className="field full"><span>Caption (kosong = judul naskah)</span><textarea style={{ minHeight: 64 }} value={caption} onChange={(e) => setCaption(e.target.value)} /></label>
      </div>
      <div className="row"><button className="primary" disabled={!draft || !acc.length}>Buat tugas</button></div>
    </form>
  );
}

function TasksTab({ accounts, setMsg }: { accounts: Account[]; setMsg: (n: Note) => void }) {
  const { hasRole } = useAuth();
  const [status, setStatus] = useState("menunggu");
  const [adding, setAdding] = useState(false);
  const [urls, setUrls] = useState<Record<number, string>>({});
  const list = useLoad(() => api<PostTask[]>("/m6/tasks", { query: { status: status || undefined } }), [status]);
  const canPost = hasRole("operator");
  const canPlan = hasRole("operator", "redaktur", "pimpinan");
  const act = async (path: string, body: unknown, ok: string) => {
    try { await api(path, { method: "POST", body }); setMsg({ kind: "ok", text: ok }); list.reload(); } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); }
  };
  return (
    <div className="stack">
      <div className="row between">
        <div className="tabs" style={{ margin: 0, border: 0 }}>
          {[["menunggu", "Menunggu"], ["diunggah", "Sudah diunggah"], ["batal", "Dibatalkan"], ["", "Semua"]].map(([k, v]) => (
            <button key={k} className={status === k ? "on" : ""} onClick={() => setStatus(k)}>{v}</button>
          ))}
        </div>
        {canPlan && <button className="primary" onClick={() => setAdding((x) => !x)}>{adding ? "Tutup" : "+ Tugas unggah"}</button>}
      </div>
      {adding && <NewTask accounts={accounts} onDone={(t) => { setAdding(false); setMsg({ kind: "ok", text: t }); list.reload(); }} />}
      <div className="card table-wrap">
        {list.data?.length === 0 && <div className="empty">Tidak ada tugas.</div>}
        {!!list.data?.length && (
          <table>
            <thead><tr><th>Jadwal</th><th>Akun</th><th>Naskah & caption</th><th>Status</th><th></th></tr></thead>
            <tbody>{list.data.map((t) => (
              <tr key={t.id}>
                <td className="small">{fmtDateTime(t.planned_at)}<div className={t.overdue ? "late" : "muted"} style={t.overdue ? { color: "var(--danger)", fontWeight: 600 } : undefined}>{t.status === "menunggu" ? dueText(t.planned_at) : ""}</div></td>
                <td className="small"><b>{PLATFORMS[t.platform] ?? t.platform}</b><div>{t.handle}</div><div className="muted">{t.assignee_name ?? "-"}</div></td>
                <td style={{ maxWidth: 420 }}><b>{t.draft_title}</b><div className="small muted" style={{ whiteSpace: "pre-wrap" }}>{t.caption.length > 220 ? `${t.caption.slice(0, 220)}…` : t.caption}</div></td>
                <td className="small">
                  <span className={`chip${t.status === "diunggah" ? " st-ok" : ""}`}>{t.status}</span>
                  {t.posted_url && <div><a href={t.posted_url} target="_blank" rel="noreferrer noopener">tautan</a> · {t.posted_by_name}</div>}
                  {t.status === "diunggah" && <div className="muted">jangkauan {nf.format(t.reach)} · interaksi {nf.format(t.engagement)}</div>}
                </td>
                <td style={{ minWidth: 220 }}>
                  {t.status === "menunggu" && (
                    <div className="stack" style={{ gap: 6 }}>
                      <CopyButton text={t.caption} label="Salin caption" />
                      {canPost && (
                        <div className="row" style={{ gap: 4 }}>
                          <input style={{ flex: 1, minWidth: 120 }} placeholder="https://tautan-unggahan" value={urls[t.id] ?? ""} onChange={(e) => setUrls({ ...urls, [t.id]: e.target.value })} aria-label="Tautan unggahan" />
                          <button className="sm primary" disabled={!(urls[t.id] ?? "").startsWith("http")} onClick={() => act(`/m6/tasks/${t.id}/done`, { posted_url: urls[t.id] }, "Unggahan dicatat.")}>Sudah diunggah</button>
                        </div>
                      )}
                      {canPlan && <button className="sm ghost danger" onClick={() => act(`/m6/tasks/${t.id}/cancel`, {}, "Tugas dibatalkan.")}>Batalkan</button>}
                    </div>
                  )}
                </td>
              </tr>
            ))}</tbody>
          </table>
        )}
      </div>
    </div>
  );
}

// ------------------------------------------------------------------ akun & bahan respons
function AccountsTab({ accounts, reload, setMsg }: { accounts: Account[]; reload: () => void; setMsg: (n: Note) => void }) {
  const { hasRole } = useAuth();
  const [f, setF] = useState({ platform: "instagram", handle: "", name: "", followers: 0, mfa: true });
  const [adding, setAdding] = useState(false);
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    try { await api("/m6/accounts", { method: "POST", body: f }); setAdding(false); setMsg({ kind: "ok", text: "Akun resmi didaftarkan." }); reload(); } catch (ex) { setMsg({ kind: "error", text: (ex as Error).message }); }
  };
  return (
    <div className="stack">
      <div className="row between">
        <Msg kind="info">Inventaris akun resmi: siapa penanggung jawabnya, status MFA, dan beban kerja. SISTRA tidak menyimpan kata sandi akun.</Msg>
        {hasRole("admin", "operator") && <button onClick={() => setAdding((x) => !x)}>{adding ? "Tutup" : "+ Daftarkan akun"}</button>}
      </div>
      {adding && (
        <form className="card form-grid" onSubmit={submit}>
          <label className="field"><span>Platform</span><select value={f.platform} onChange={(e) => setF({ ...f, platform: e.target.value })}>{Object.entries(PLATFORMS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></label>
          <label className="field req"><span>Handle</span><input value={f.handle} onChange={(e) => setF({ ...f, handle: e.target.value })} placeholder="@akunresmi" required /></label>
          <label className="field req"><span>Nama akun</span><input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required /></label>
          <label className="field"><span>Pengikut</span><input type="number" min={0} value={f.followers} onChange={(e) => setF({ ...f, followers: Number(e.target.value) })} /></label>
          <label className="row small"><input type="checkbox" checked={f.mfa} onChange={(e) => setF({ ...f, mfa: e.target.checked })} /> MFA aktif</label>
          <div className="full"><button className="primary">Simpan</button></div>
        </form>
      )}
      <div className="card table-wrap">
        <table>
          <thead><tr><th>Akun</th><th>Cakupan</th><th>Penanggung jawab</th><th className="num">Pengikut</th><th>Keamanan</th><th className="num">Tugas</th><th className="num">Komentar baru</th></tr></thead>
          <tbody>{accounts.map((a) => (
            <tr key={a.id}>
              <td><b>{a.handle}</b><div className="small muted">{a.platform_label} · {a.name}</div></td>
              <td className="small">{a.province_name ?? "Nasional"}</td>
              <td className="small">{a.pic_name ?? "-"}</td>
              <td className="num">{nf.format(a.followers)}</td>
              <td className="small"><span className={`chip${a.mfa ? " st-ok" : " st-bad"}`}>{a.mfa ? "✓ MFA" : "✕ tanpa MFA"}</span> <span className="muted">token {a.token_status}</span></td>
              <td className="num">{a.pending_tasks}</td>
              <td className="num">{a.new_comments}</td>
            </tr>
          ))}</tbody>
        </table>
      </div>
    </div>
  );
}

function KbTab({ setMsg }: { setMsg: (n: Note) => void }) {
  const { hasRole } = useAuth();
  const kb = useLoad(() => api<KbEntry[]>("/m6/kb"), []);
  const [f, setF] = useState({ title: "", question: "", answer: "", sources: "" });
  const [adding, setAdding] = useState(false);
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    try { const r = await api<KbEntry>("/m6/kb", { method: "POST", body: f }); setAdding(false); setMsg({ kind: "ok", text: `Bahan respons disimpan (${r.status}).` }); kb.reload(); } catch (ex) { setMsg({ kind: "error", text: (ex as Error).message }); }
  };
  return (
    <div className="stack">
      <div className="row between">
        <Msg kind="info">Jawaban baku yang sudah disetujui. SISTRA AGENT memakai bahan ini sebagai rujukan saat menyusun saran balasan.</Msg>
        {hasRole("redaktur", "pimpinan", "analis") && <button onClick={() => setAdding((x) => !x)}>{adding ? "Tutup" : "+ Bahan respons"}</button>}
      </div>
      {adding && (
        <form className="card form-grid" onSubmit={submit}>
          <label className="field req full"><span>Judul</span><input value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} required /></label>
          <label className="field req full"><span>Pertanyaan warga</span><input value={f.question} onChange={(e) => setF({ ...f, question: e.target.value })} required /></label>
          <label className="field req full"><span>Jawaban resmi</span><textarea style={{ minHeight: 80 }} value={f.answer} onChange={(e) => setF({ ...f, answer: e.target.value })} required minLength={10} /></label>
          <label className="field full"><span>Sumber</span><input value={f.sources} onChange={(e) => setF({ ...f, sources: e.target.value })} /></label>
          <div className="full"><button className="primary">Simpan</button></div>
        </form>
      )}
      <div className="grid g2">
        {kb.data?.map((k) => (
          <div key={k.id} className="card stack" style={{ gap: 6 }}>
            <div className="row between"><b>{k.title}</b><span className={`chip${k.status === "disetujui" ? " st-ok" : ""}`}>{k.status}</span></div>
            <div className="small muted">T: {k.question}</div>
            <div className="small">{k.answer}</div>
            <div className="small muted">{k.issue_code ?? "umum"}{k.sources ? ` · ${k.sources}` : ""} · {fmtDateTime(k.updated_at)}</div>
          </div>
        ))}
      </div>
    </div>
  );
}

export default function SocialPage() {
  const [params, setParams] = useSearchParams();
  const tab = (params.get("tab") as Tab) || "komentar";
  const setTab = (t: Tab) => setParams({ tab: t }, { replace: true });
  const [msg, setMsg] = useState<Note>(null);
  const accounts = useLoad(() => api<Account[]>("/m6/accounts"), []);
  const acc = accounts.data ?? [];
  const totals = {
    followers: acc.reduce((a, x) => a + x.followers, 0), tasks: acc.reduce((a, x) => a + x.pending_tasks, 0),
    comments: acc.reduce((a, x) => a + x.new_comments, 0), noMfa: acc.filter((x) => !x.mfa).length,
  };
  return (
    <div className="stack">
      <div>
        <h1>Kanal Media Sosial Resmi <span className="muted" style={{ fontWeight: 400 }}>· M6</span></h1>
        <p className="sub" style={{ marginBottom: 0 }}>Unggahan dan balasan dilakukan manual oleh petugas di platform; SISTRA mengatur tugas, membantu dengan SISTRA AGENT, dan mencatat jejaknya</p>
      </div>
      <div className="grid g4">
        <Tile label="Akun resmi" value={acc.length} foot={`${nf.format(totals.followers)} pengikut`} />
        <Tile label="Tugas unggah menunggu" value={totals.tasks} tone={totals.tasks ? "warn" : undefined} />
        <Tile label="Komentar belum ditangani" value={totals.comments} tone={totals.comments ? "bad" : undefined} />
        <Tile label="Akun tanpa MFA" value={totals.noMfa} tone={totals.noMfa ? "bad" : "good"} foot="wajib MFA untuk akun resmi" />
      </div>
      <SebaranMap title="Sebaran akun resmi & mitra" layers={["akun", "mitra"]} initial="akun" height={360} />
      {msg && <Msg kind={msg.kind}>{msg.text}</Msg>}
      <div className="tabs" role="tablist">
        {([["komentar", "Kotak masuk (S43)"], ["paket", "Paket respons cepat (S41)"], ["tugas", "Tugas unggah (S45)"], ["kampanye", "Kampanye (S44, S48)"], ["mitra", "Mitra KOL (S42)"], ["akun", "Akun & kuota (S40)"], ["bahan", "Bahan respons"]] as [Tab, string][]).map(([k, v]) => (
          <button key={k} role="tab" aria-selected={tab === k} className={tab === k ? "on" : ""} onClick={() => { setTab(k); setMsg(null); }}>{v}</button>
        ))}
      </div>
      {tab === "komentar" && accounts.data && <CommentsTab accounts={acc} setMsg={setMsg} />}
      {tab === "tugas" && accounts.data && <TasksTab accounts={acc} setMsg={setMsg} />}
      {tab === "akun" && <><QuotaPanel setMsg={setMsg} /><AccountsTab accounts={acc} reload={accounts.reload} setMsg={setMsg} /></>}
      {tab === "paket" && <PacksTab setMsg={setMsg} />}
      {tab === "kampanye" && <CampaignsTab setMsg={setMsg} />}
      {tab === "mitra" && <PartnersTab setMsg={setMsg} />}
      {tab === "bahan" && <KbTab setMsg={setMsg} />}
    </div>
  );
}
