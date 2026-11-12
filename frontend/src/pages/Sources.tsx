import { useState, type FormEvent } from "react";
import { api } from "../api";
import { Msg, useLoad } from "../components/ui";
import { fmtDateTime, nf } from "../format";
import type { IngestResult, Source } from "../types";

export default function SourcesPage() {
  const sources = useLoad(() => api<Source[]>("/sources"), []);
  const [msg, setMsg] = useState<{ kind: "ok" | "error" | "warn"; text: string } | null>(null);
  const [form, setForm] = useState({ type: "news", name: "", url: "", reliability: "C", access_basis: "Sumber terbuka/publik" });
  const [sourceId, setSourceId] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);

  const report = (r: IngestResult) => {
    const alerts = r.alerts_created.length + r.alerts_escalated.length;
    setMsg({
      kind: alerts ? "warn" : "ok",
      text: `Diterima ${r.received}: ${r.inserted} baru, ${r.exact_duplicates} duplikat tepat, ${r.near_duplicates} hampir-sama, ` +
        `${r.skipped} dilewati.${alerts ? ` ${r.alerts_created.length} peringatan baru, ${r.alerts_escalated.length} eskalasi.` : ""}`,
    });
    sources.reload();
  };

  const create = async (e: FormEvent) => {
    e.preventDefault();
    try {
      await api<Source>("/sources", { method: "POST", body: form });
      setForm({ ...form, name: "", url: "" });
      setMsg({ kind: "ok", text: "Sumber ditambahkan." });
      sources.reload();
    } catch (err) {
      setMsg({ kind: "error", text: (err as Error).message });
    }
  };

  const upload = async (e: FormEvent) => {
    e.preventDefault();
    if (!file || !sourceId) return;
    const fd = new FormData();
    fd.append("source_id", sourceId);
    fd.append("file", file);
    setBusy(true);
    try {
      report(await api<IngestResult>("/ingest/upload", { method: "POST", form: fd }));
    } catch (err) {
      setMsg({ kind: "error", text: (err as Error).message });
    } finally {
      setBusy(false);
    }
  };

  const fetchFeed = async (id: number) => {
    setBusy(true);
    try {
      report(await api<IngestResult>(`/ingest/fetch/${id}`, { method: "POST" }));
    } catch (err) {
      setMsg({ kind: "error", text: (err as Error).message });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="stack">
      <div>
        <h1>Sumber & Impor Data</h1>
        <p className="sub" style={{ marginBottom: 0 }}>
          MVP: feed RSS/Atom dan impor berkas JSON. Konektor media sosial jalur resmi, siaran, dan cetak menyusul pada F1.
        </p>
      </div>
      {msg && <Msg kind={msg.kind}>{msg.text}</Msg>}
      <div className="card table-wrap">
        <table>
          <thead><tr><th>Nama</th><th>Jenis</th><th>Keandalan</th><th>Dasar akses</th><th className="num">Konten</th><th>Terakhir</th><th /></tr></thead>
          <tbody>
            {sources.data?.map((s) => (
              <tr key={s.id}>
                <td><b>{s.name}</b>{s.url && <div className="mono muted">{s.url}</div>}</td>
                <td>{s.type}</td>
                <td>{s.reliability}</td>
                <td className="small">{s.access_basis}</td>
                <td className="num">{nf.format(s.item_count)}</td>
                <td className="small">{fmtDateTime(s.last_fetched_at)}</td>
                <td>{s.url && <button className="sm" disabled={busy} onClick={() => fetchFeed(s.id)}>Ambil feed</button>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="grid g2">
        <form className="card stack" onSubmit={upload}>
          <h2>Impor berkas</h2>
          <label className="field req">
            <span>Sumber</span>
            <select value={sourceId} onChange={(e) => setSourceId(e.target.value)} required>
              <option value="">Pilih sumber…</option>
              {sources.data?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select>
          </label>
          <label className="field req">
            <span>Berkas RSS/Atom (.xml) atau JSON</span>
            <input type="file" accept=".xml,.rss,.atom,.json" onChange={(e) => setFile(e.target.files?.[0] ?? null)} required />
          </label>
          <div className="small muted">
            Format JSON: <span className="mono">[{"{"}"title","body","url","published_at","author","id"{"}"}]</span>. Contoh tersedia di
            <span className="mono"> backend/sample_data/</span>.
          </div>
          <div><button className="primary" disabled={busy || !file || !sourceId}>{busy ? "Memproses…" : "Impor"}</button></div>
        </form>
        <form className="card stack" onSubmit={create}>
          <h2>Tambah sumber</h2>
          <div className="form-grid">
            <label className="field req"><span>Nama</span>
              <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required minLength={2} /></label>
            <label className="field"><span>Jenis</span>
              <select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
                {["news", "social", "tv", "radio", "print", "report"].map((t) => <option key={t}>{t}</option>)}
              </select></label>
            <label className="field full"><span>URL feed (opsional)</span>
              <input value={form.url} onChange={(e) => setForm({ ...form, url: e.target.value })} placeholder="https://…/rss" /></label>
            <label className="field"><span>Keandalan (A–F)</span>
              <select value={form.reliability} onChange={(e) => setForm({ ...form, reliability: e.target.value })}>
                {"ABCDEF".split("").map((r) => <option key={r}>{r}</option>)}
              </select></label>
            <label className="field req"><span>Dasar akses</span>
              <input value={form.access_basis} onChange={(e) => setForm({ ...form, access_basis: e.target.value })} required /></label>
          </div>
          <div><button>Simpan sumber</button></div>
        </form>
      </div>
    </div>
  );
}
