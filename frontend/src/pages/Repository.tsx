import { useState, type FormEvent } from "react";
import { api, downloadFile } from "../api";
import { useAuth } from "../auth";
import { ClassChip, Msg, useLoad } from "../components/ui";
import { fmtDate, fmtDateTime, fmtSize } from "../format";
import type { Doc, DownloadLog, Unit } from "../types";

const LEVELS = ["TERBUKA", "TERBATAS", "RAHASIA", "SANGAT_RAHASIA"];
const LEVEL_RANK: Record<string, number> = { TERBUKA: 0, TERBATAS: 1, RAHASIA: 2, SANGAT_RAHASIA: 3 };

function UploadForm({ onDone }: { onDone: (d: Doc) => void }) {
  const { user } = useAuth();
  const units = useLoad(() => api<Unit[]>("/units"), []);
  const today = new Date().toISOString().slice(0, 10);
  const [f, setF] = useState({
    title: "", classification: "TERBATAS", owner_unit_code: user?.unit.code ?? "", period_start: today, period_end: today,
    origin: "", description: "", tags: "", retention_class: "UMUM",
  });
  const [file, setFile] = useState<File | null>(null);
  const [err, setErr] = useState("");
  const allowed = LEVELS.filter((l) => LEVEL_RANK[l] <= LEVEL_RANK[user?.clearance ?? "TERBUKA"]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!file) return;
    const fd = new FormData();
    Object.entries(f).forEach(([k, v]) => fd.append(k, v));
    fd.append("file", file);
    try {
      onDone(await api<Doc>("/documents", { method: "POST", form: fd }));
    } catch (e2) {
      setErr((e2 as Error).message);
    }
  };

  return (
    <form className="card stack" onSubmit={submit}>
      <h2>Unggah dokumen</h2>
      <div className="small muted">Kolom bertanda * adalah metadata wajib; unggahan tanpa metadata lengkap ditolak sistem.</div>
      <div className="form-grid">
        <label className="field full req"><span>Judul</span>
          <input value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} required /></label>
        <label className="field req"><span>Klasifikasi</span>
          <select value={f.classification} onChange={(e) => setF({ ...f, classification: e.target.value })}>
            {allowed.map((l) => <option key={l} value={l}>{l.replace("_", " ")}</option>)}
          </select></label>
        <label className="field req"><span>Unit pembuat</span>
          <select value={f.owner_unit_code} onChange={(e) => setF({ ...f, owner_unit_code: e.target.value })}>
            {units.data?.map((u) => <option key={u.code} value={u.code}>{u.code} — {u.name}</option>)}
          </select></label>
        <label className="field req"><span>Periode mulai</span>
          <input type="date" value={f.period_start} onChange={(e) => setF({ ...f, period_start: e.target.value })} required /></label>
        <label className="field req"><span>Periode akhir</span>
          <input type="date" value={f.period_end} onChange={(e) => setF({ ...f, period_end: e.target.value })} required /></label>
        <label className="field full req"><span>Asal sumber data</span>
          <input value={f.origin} onChange={(e) => setF({ ...f, origin: e.target.value })} required /></label>
        <label className="field full"><span>Deskripsi</span>
          <input value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} /></label>
        <label className="field"><span>Tag (pisahkan koma)</span>
          <input value={f.tags} onChange={(e) => setF({ ...f, tags: e.target.value })} /></label>
        <label className="field"><span>Kelas retensi</span>
          <input value={f.retention_class} onChange={(e) => setF({ ...f, retention_class: e.target.value })} /></label>
        <label className="field full req"><span>Berkas</span>
          <input type="file" onChange={(e) => setFile(e.target.files?.[0] ?? null)} required /></label>
      </div>
      <Msg kind="error">{err}</Msg>
      <div><button className="primary">Unggah</button></div>
    </form>
  );
}

export default function RepositoryPage() {
  const { user } = useAuth();
  const [q, setQ] = useState("");
  const [query, setQuery] = useState({ q: "", classification: "" });
  const [sel, setSel] = useState<Doc | null>(null);
  const [logs, setLogs] = useState<DownloadLog[] | null>(null);
  const [uploading, setUploading] = useState(false);
  const [msg, setMsg] = useState<{ kind: "ok" | "error" | "warn"; text: string } | null>(null);
  const [grant, setGrant] = useState({ username: "", reason: "", valid_days: 7 });
  const [newFile, setNewFile] = useState<File | null>(null);
  const [note, setNote] = useState("");
  const list = useLoad(() => api<{ total: number; items: Doc[] }>("/documents", { query }), [query.q, query.classification]);

  const open = async (id: number) => {
    setSel(await api<Doc>(`/documents/${id}`));
    setLogs(null);
    setMsg(null);
    setUploading(false);
  };

  const act = async (fn: () => Promise<Doc>, ok: string) => {
    try {
      setSel(await fn());
      setMsg({ kind: "ok", text: ok });
      list.reload();
    } catch (e) {
      setMsg({ kind: "error", text: (e as Error).message });
    }
  };

  const download = async (versionNo: number, filename: string) => {
    try {
      const wm = await downloadFile(`/documents/${sel!.id}/versions/${versionNo}/download`, filename);
      setMsg({ kind: "ok", text: `Diunduh dengan watermark ${wm ?? ""}. Unduhan tercatat di log.` });
    } catch (e) {
      setMsg({ kind: "error", text: (e as Error).message });
    }
  };

  const checkin = () => {
    if (!newFile) return;
    const fd = new FormData();
    fd.append("file", newFile);
    fd.append("note", note);
    act(() => api<Doc>(`/documents/${sel!.id}/versions`, { method: "POST", form: fd }), "Versi baru tersimpan (check-in).");
    setNewFile(null);
    setNote("");
  };

  return (
    <div className="stack">
      <div className="row between">
        <div>
          <h1>Repositori Dokumen</h1>
          <p className="sub" style={{ marginBottom: 0 }}>Klasifikasi berjenjang, kendali akses ABAC, versi + SHA-256, dan watermark dinamis per unduhan</p>
        </div>
        <button className="primary" onClick={() => { setUploading(true); setSel(null); }}>+ Unggah dokumen</button>
      </div>
      <form className="card row" onSubmit={(e) => { e.preventDefault(); setQuery({ ...query, q: q.trim() }); }}>
        <input placeholder="Cari judul atau isi dokumen…" value={q} onChange={(e) => setQ(e.target.value)} style={{ flex: "1 1 240px" }} aria-label="Cari" />
        <select value={query.classification} onChange={(e) => setQuery({ ...query, classification: e.target.value })} aria-label="Klasifikasi">
          <option value="">Semua klasifikasi yang dapat Anda akses</option>
          {LEVELS.map((l) => <option key={l} value={l}>{l.replace("_", " ")}</option>)}
        </select>
        <button className="primary" type="submit">Cari</button>
      </form>
      {msg && <Msg kind={msg.kind}>{msg.text}</Msg>}
      <div className="split">
        <div className="card table-wrap">
          <div className="small muted" style={{ marginBottom: 8 }}>
            {list.data ? `${list.data.total} dokumen dapat Anda akses (clearance ${user?.clearance_label})` : "Memuat…"}
          </div>
          {list.data?.items.length === 0 && <div className="empty">Tidak ada dokumen.</div>}
          {!!list.data?.items.length && (
            <table>
              <thead><tr><th>Dokumen</th><th>Klasifikasi</th><th>Unit</th><th>Versi</th></tr></thead>
              <tbody>{list.data.items.map((d) => (
                <tr key={d.id} className={`clickable${sel?.id === d.id ? " selected" : ""}`} onClick={() => open(d.id)}>
                  <td><b>{d.title}</b>{d.snippet && <div className="small muted">{d.snippet}</div>}
                    {d.status === "checked_out" && <div className="small" style={{ color: "var(--danger)" }}>sedang di-check-out</div>}</td>
                  <td><ClassChip code={d.classification} label={d.classification_label} /></td>
                  <td className="small" style={{ whiteSpace: "nowrap" }}>{d.owner_unit_code}</td>
                  <td className="num">v{d.current_version.version_no}</td>
                </tr>
              ))}</tbody>
            </table>
          )}
        </div>
        <div className="stack">
          {uploading && <UploadForm onDone={(d) => { setUploading(false); setSel(d); list.reload(); setMsg({ kind: "ok", text: "Dokumen tersimpan." }); }} />}
          {!uploading && !sel && <div className="card empty">Pilih dokumen untuk melihat metadata, versi, dan izin akses.</div>}
          {sel && (
            <div className="card stack">
              <div className="row between">
                <h2 style={{ margin: 0 }}>{sel.title}</h2>
                <ClassChip code={sel.classification} label={sel.classification_label} />
              </div>
              <dl className="kv">
                <dt>Unit pembuat</dt><dd>{sel.owner_unit_name}</dd>
                <dt>Periode</dt><dd>{fmtDate(sel.period_start)} – {fmtDate(sel.period_end)}</dd>
                <dt>Asal sumber</dt><dd>{sel.origin}</dd>
                <dt>Retensi</dt><dd>{sel.retention_class}</dd>
                <dt>Tag</dt><dd>{sel.tags.join(", ") || "-"}</dd>
                <dt>Status</dt><dd>{sel.status === "checked_out" ? "Di-check-out" : "Aktif"}</dd>
              </dl>
              <div>
                <h3>Versi</h3>
                <div className="table-wrap">
                  <table>
                    <thead><tr><th>V</th><th>Berkas</th><th>SHA-256</th><th>Waktu</th><th /></tr></thead>
                    <tbody>{[...(sel.versions ?? [])].reverse().map((v) => (
                      <tr key={v.version_no}>
                        <td>{v.version_no}</td>
                        <td>{v.filename}<div className="small muted">{fmtSize(v.size)} · {v.note}</div></td>
                        <td className="mono" title={v.sha256}>{v.sha256.slice(0, 12)}…</td>
                        <td className="small">{fmtDateTime(v.created_at)}</td>
                        <td><button className="sm" onClick={() => download(v.version_no, v.filename)}>Unduh</button></td>
                      </tr>
                    ))}</tbody>
                  </table>
                </div>
              </div>
              {sel.permissions.write && (
                <div className="stack" style={{ gap: 8 }}>
                  <h3 style={{ margin: 0 }}>Check-out / check-in</h3>
                  {sel.status === "active" && <div><button onClick={() => act(() => api<Doc>(`/documents/${sel.id}/checkout`, { method: "POST" }), "Dokumen di-check-out untuk Anda.")}>Check-out untuk revisi</button></div>}
                  {sel.status === "checked_out" && sel.checked_out_by === user?.id && (
                    <div className="row">
                      <input type="file" onChange={(e) => setNewFile(e.target.files?.[0] ?? null)} aria-label="Berkas versi baru" />
                      <input placeholder="Catatan versi" value={note} onChange={(e) => setNote(e.target.value)} />
                      <button className="primary" disabled={!newFile} onClick={checkin}>Check-in</button>
                      <button onClick={() => act(() => api<Doc>(`/documents/${sel.id}/cancel-checkout`, { method: "POST" }), "Check-out dibatalkan.")}>Batal</button>
                    </div>
                  )}
                  {sel.status === "checked_out" && sel.checked_out_by !== user?.id && <div className="small muted">Sedang direvisi pengguna lain.</div>}
                </div>
              )}
              {sel.permissions.manage && (
                <div className="stack" style={{ gap: 8 }}>
                  <h3 style={{ margin: 0 }}>Izin akses berdurasi</h3>
                  {sel.grants?.map((g) => (
                    <div key={g.id} className="row between small">
                      <span><b>{g.username}</b> s.d. {fmtDateTime(g.valid_until)} — {g.reason}{g.revoked_at ? " (dicabut)" : ""}</span>
                      {!g.revoked_at && <button className="sm danger" onClick={() => act(() => api<Doc>(`/documents/${sel.id}/grants/${g.id}`, { method: "DELETE" }), "Izin dicabut.")}>Cabut</button>}
                    </div>
                  ))}
                  <div className="row">
                    <input placeholder="username" value={grant.username} onChange={(e) => setGrant({ ...grant, username: e.target.value })} style={{ width: 130 }} />
                    <input placeholder="alasan / dasar" value={grant.reason} onChange={(e) => setGrant({ ...grant, reason: e.target.value })} style={{ flex: 1 }} />
                    <input type="number" min={1} max={90} value={grant.valid_days} onChange={(e) => setGrant({ ...grant, valid_days: Number(e.target.value) })} style={{ width: 70 }} aria-label="Hari" />
                    <button disabled={!grant.username || grant.reason.length < 5}
                            onClick={() => act(() => api<Doc>(`/documents/${sel.id}/grants`, { method: "POST", body: grant }), "Izin diberikan.")}>Beri izin</button>
                  </div>
                  <div>
                    <button className="sm" onClick={async () => setLogs(await api<DownloadLog[]>(`/documents/${sel.id}/downloads`))}>Lihat log unduhan</button>
                  </div>
                  {logs && (logs.length === 0 ? <div className="small muted">Belum ada unduhan.</div> : (
                    <table>
                      <thead><tr><th>Waktu</th><th>Pengguna</th><th>V</th><th>IP</th><th>Watermark</th></tr></thead>
                      <tbody>{logs.map((l) => <tr key={l.id}><td className="small">{fmtDateTime(l.at)}</td><td>{l.username}</td><td>{l.version_no}</td><td className="mono">{l.ip}</td><td className="mono">WM-{l.watermark_id}</td></tr>)}</tbody>
                    </table>
                  ))}
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
