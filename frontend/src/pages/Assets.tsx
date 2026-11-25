import { useEffect, useState, type FormEvent } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { api, getToken } from "../api";
import { useAuth } from "../auth";
import { Msg, useLoad } from "../components/ui";
import { fmtDate, fmtDateTime, fmtSize, toLocalInput } from "../format";
import type { Draft } from "../types";
import SebaranMap from "../components/geo";

interface Asset { id: number; kind: string; title: string; filename: string; mime: string; size: number; sha256: string; tags: string[]; ai_tags: string[]; description: string; credit: string;
  license: string; license_label: string; license_expires_at: string | null; license_state: string; issue_code: string | null; used_in: number; author_name: string; created_at: string }
interface AssetResp { items: Asset[]; stats: { total: number; expiring: number; expired: number; bytes: number }; licenses: Record<string, string> }
interface Tpl { id: number; code: string; name: string; kind: string; version: number; spec: Record<string, string>; locked: boolean; is_current: boolean; note: string; author_name: string; created_at: string; versions?: number }
interface Cov { id: number; title: string; event_at: string; location: string; province_name: string | null; reporter_id: number | null; reporter_name: string | null; status: string; brief: string; brief_id: number | null; assets: Asset[] }
const STATE: Record<string, [string, string]> = { aktif: ["Aktif", "muted"], segera_kedaluwarsa: ["Segera kedaluwarsa", "status-warn"], kedaluwarsa: ["Kedaluwarsa", "status-bad"] };

/** Muat berkas aset dengan token (img tidak dapat mengirim header Authorization). */
function AssetThumb({ a }: { a: Asset }) {
  const [src, setSrc] = useState<string>("");
  useEffect(() => {
    if (!a.mime.startsWith("image/")) return;
    let url = ""; let live = true;
    fetch(`/api/m5/assets/${a.id}/file`, { headers: { Authorization: `Bearer ${getToken()}` } }).then((r) => r.blob()).then((b) => { if (live) { url = URL.createObjectURL(b); setSrc(url); } }).catch(() => undefined);
    return () => { live = false; if (url) URL.revokeObjectURL(url); };
  }, [a.id, a.mime]);
  return src ? <img src={src} alt={a.title} style={{ width: "100%", aspectRatio: "1.9", objectFit: "cover", borderRadius: 6, background: "var(--surface-2)" }} />
    : <div style={{ width: "100%", aspectRatio: "1.9", borderRadius: 6, background: "var(--surface-2)", display: "grid", placeItems: "center" }} className="muted">{a.kind}</div>;
}

function Bank() {
  const { hasRole } = useAuth();
  const [params] = useSearchParams();
  const [q, setQ] = useState(""); const [state, setState] = useState(""); const [lic, setLic] = useState("");
  const data = useLoad(() => api<AssetResp>("/m5/assets", { query: { q, state, license: lic } }), [q, state, lic]);
  const drafts = useLoad(() => api<Draft[]>("/drafts"), []);
  const [linkTo, setLinkTo] = useState<number>(Number(params.get("draft")) || 0);
  const [show, setShow] = useState(false);
  const [msg, setMsg] = useState<{ kind: "ok" | "error"; text: string } | null>(null);
  const [f, setF] = useState({ title: "", license: "milik_sendiri", license_expires_at: "", credit: "", tags: "" });
  const [file, setFile] = useState<File | null>(null);
  const canUpload = hasRole("redaktur", "produser", "analis", "operator", "pimpinan");
  const upload = async (e: FormEvent) => {
    e.preventDefault(); if (!file) return;
    const fd = new FormData(); fd.append("file", file); Object.entries(f).forEach(([k, v]) => v && fd.append(k, k === "license_expires_at" ? new Date(v).toISOString() : v));
    if (linkTo) fd.append("draft_id", String(linkTo));
    try { const a = await api<Asset>("/m5/assets", { method: "POST", form: fd }); setMsg({ kind: "ok", text: `Aset “${a.title}” tersimpan. Tag SISTRA AGENT: ${a.ai_tags.join(", ") || "-"}` }); setShow(false); setFile(null); data.reload(); }
    catch (ex) { setMsg({ kind: "error", text: (ex as Error).message }); }
  };
  const link = async (a: Asset) => {
    try { await api(`/m5/assets/${a.id}/link`, { method: "POST", body: { draft_id: linkTo } }); setMsg({ kind: "ok", text: `“${a.title}” ditautkan ke naskah #${linkTo}.` }); data.reload(); }
    catch (ex) { setMsg({ kind: "error", text: (ex as Error).message }); }
  };
  const s = data.data?.stats;
  return (
    <div className="stack">
      <div className="grid g4">
        <div className="card tile"><div className="label">Total aset</div><div className="value num">{s?.total ?? "-"}</div><div className="foot">{s ? fmtSize(s.bytes) : ""} · dedup SHA-256</div></div>
        <div className="card tile tone-warn"><div className="label">Lisensi segera kedaluwarsa (≤ 30 hari)</div><div className="value num">{s?.expiring ?? "-"}</div></div>
        <div className="card tile tone-bad"><div className="label">Lisensi kedaluwarsa</div><div className="value num">{s?.expired ?? "-"}</div><div className="foot">tidak dapat ditautkan ke naskah</div></div>
        <div className="card tile"><div className="label">Tautkan ke naskah</div>
          <select value={linkTo} onChange={(e) => setLinkTo(Number(e.target.value))} style={{ width: "100%", marginTop: 6 }} aria-label="Naskah tujuan">
            <option value={0}>— pilih naskah —</option>{drafts.data?.filter((d) => d.status !== "ditarik").map((d) => <option key={d.id} value={d.id}>#{d.id} {d.title}</option>)}</select></div>
      </div>
      <div className="row between">
        <div className="row">
          <input placeholder="Cari judul, tag, tag SISTRA AGENT, kredit…" value={q} onChange={(e) => setQ(e.target.value)} style={{ minWidth: 260 }} aria-label="Cari aset" />
          <select value={state} onChange={(e) => setState(e.target.value)} aria-label="Status lisensi"><option value="">Semua status</option><option value="aktif">Aktif</option><option value="segera_kedaluwarsa">Segera kedaluwarsa</option><option value="kedaluwarsa">Kedaluwarsa</option></select>
          <select value={lic} onChange={(e) => setLic(e.target.value)} aria-label="Jenis lisensi"><option value="">Semua lisensi</option>{Object.entries(data.data?.licenses ?? {}).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
        </div>
        {canUpload && <button className="primary" onClick={() => setShow((x) => !x)}>{show ? "Tutup" : "+ Unggah aset"}</button>}
      </div>
      {msg && <Msg kind={msg.kind}>{msg.text}</Msg>}
      {show && (
        <form className="card form-grid" onSubmit={upload}>
          <label className="field req full"><span>Berkas (gambar diberi tag otomatis oleh SISTRA AGENT)</span><input type="file" onChange={(e) => setFile(e.target.files?.[0] ?? null)} required /></label>
          <label className="field"><span>Judul</span><input value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} /></label>
          <label className="field"><span>Kredit/pemilik</span><input value={f.credit} onChange={(e) => setF({ ...f, credit: e.target.value })} /></label>
          <label className="field req"><span>Lisensi</span><select value={f.license} onChange={(e) => setF({ ...f, license: e.target.value })}>{Object.entries(data.data?.licenses ?? {}).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></label>
          <label className={`field${f.license === "berlisensi" ? " req" : ""}`}><span>Lisensi berlaku sampai</span><input type="date" value={f.license_expires_at} onChange={(e) => setF({ ...f, license_expires_at: e.target.value })} required={f.license === "berlisensi"} /></label>
          <label className="field full"><span>Tag (pisahkan koma)</span><input value={f.tags} onChange={(e) => setF({ ...f, tags: e.target.value })} /></label>
          <div className="full"><button className="primary" disabled={!file}>Unggah</button></div>
        </form>
      )}
      <div className="grid g4">
        {data.data?.items.map((a) => (
          <div key={a.id} className="card stack" style={{ gap: 6, padding: 10 }}>
            <AssetThumb a={a} />
            <b style={{ fontSize: 13 }}>{a.title}</b>
            <div className="tags">{[...a.tags, ...a.ai_tags.map((t) => `✦${t}`)].slice(0, 7).map((t) => <span key={t}>{t}</span>)}</div>
            <div className="small"><span className={STATE[a.license_state][1]}>{a.license_label} · {STATE[a.license_state][0]}</span>{a.license_expires_at && <span className="muted"> · s.d. {fmtDate(a.license_expires_at)}</span>}</div>
            <div className="small muted">{a.credit} · {fmtSize(a.size)} · dipakai {a.used_in}×</div>
            {linkTo > 0 && <button className="sm" disabled={a.license_state === "kedaluwarsa"} onClick={() => link(a)}>Tautkan ke #{linkTo}</button>}
          </div>
        ))}
      </div>
      {data.data?.items.length === 0 && <div className="card empty">Tidak ada aset pada filter ini.</div>}
    </div>
  );
}

function Templates() {
  const { hasRole } = useAuth();
  const list = useLoad(() => api<Tpl[]>("/m5/templates"), []);
  const [sel, setSel] = useState<string>("");
  const versions = useLoad(() => (sel ? api<Tpl[]>(`/m5/templates/${sel}/versions`) : Promise.resolve([])), [sel]);
  const [edit, setEdit] = useState<Record<string, string> | null>(null);
  const [note, setNote] = useState("");
  const [msg, setMsg] = useState<{ kind: "ok" | "error"; text: string } | null>(null);
  const cur = list.data?.find((t) => t.code === sel);
  const act = async (fn: () => Promise<unknown>, ok: string) => { try { await fn(); setMsg({ kind: "ok", text: ok }); list.reload(); versions.reload(); setEdit(null); } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); } };
  return (
    <div className="split">
      <div className="stack">
        {msg && <Msg kind={msg.kind}>{msg.text}</Msg>}
        {list.data?.map((t) => (
          <button key={t.code} className={`card kcard${sel === t.code ? " sel" : ""}`} onClick={() => { setSel(t.code); setEdit(null); }}>
            <div className="row between"><b>{t.name}</b><span className="chip">{t.locked ? "🔒 Terkunci" : "Terbuka"} · v{t.version}</span></div>
            <div className="row" style={{ gap: 4 }}>{["bg", "fg", "accent"].map((k) => <span key={k} title={k} style={{ width: 28, height: 18, borderRadius: 4, background: t.spec[k], border: "1px solid var(--border)" }} />)}
              <span className="small muted">{t.kind} · {t.spec.logo_text}</span></div>
          </button>
        ))}
      </div>
      <div className="stack">
        {!cur && <div className="card empty">Pilih templat untuk melihat versi atau menyunting.</div>}
        {cur && (
          <div className="card stack" style={{ gap: 10 }}>
            <div className="row between"><h2 style={{ margin: 0 }}>{cur.name} <span className="muted">v{cur.version}</span></h2>
              {hasRole("redaktur", "pimpinan") && <button className="sm" onClick={() => act(() => api(`/m5/templates/${cur.code}/lock`, { method: "POST", body: { locked: !cur.locked } }), cur.locked ? "Kunci dibuka." : "Templat dikunci.")}>{cur.locked ? "Buka kunci" : "Kunci templat"}</button>}</div>
            <div className="small muted">Templat terkunci tidak dapat diubah. Setiap perubahan membuat versi baru; versi lama tetap tersimpan (S38).</div>
            {!edit && hasRole("produser", "redaktur") && <div><button className="sm" disabled={cur.locked} onClick={() => setEdit({ ...cur.spec })}>Sunting (versi baru)</button></div>}
            {edit && (
              <div className="form-grid">
                {["bg", "fg", "accent"].map((k) => <label key={k} className="field"><span>Warna {k}</span><input type="color" value={edit[k]} onChange={(e) => setEdit({ ...edit, [k]: e.target.value.toUpperCase() })} /></label>)}
                <label className="field"><span>Teks logo</span><input value={edit.logo_text} onChange={(e) => setEdit({ ...edit, logo_text: e.target.value })} /></label>
                <label className="field full"><span>Kaki</span><input value={edit.footer} onChange={(e) => setEdit({ ...edit, footer: e.target.value })} /></label>
                <label className="field full"><span>Catatan perubahan</span><input value={note} onChange={(e) => setNote(e.target.value)} /></label>
                <div className="full row"><button className="primary" onClick={() => act(() => api(`/m5/templates/${cur.code}`, { method: "POST", body: { spec: edit, note } }), "Versi baru tersimpan.")}>Simpan versi baru</button><button onClick={() => setEdit(null)}>Batal</button></div>
              </div>
            )}
            <h3 style={{ margin: 0 }}>Riwayat versi</h3>
            <ul className="timeline">{versions.data?.map((v) => <li key={v.id}><b>v{v.version}</b> · {v.author_name} · {fmtDateTime(v.created_at)}{v.is_current && " · versi aktif"}<div className="small">{v.note}</div></li>)}</ul>
          </div>
        )}
      </div>
    </div>
  );
}

function Coverage() {
  const { hasRole } = useAuth();
  const list = useLoad(() => api<Cov[]>("/m5/coverage"), []);
  const team = useLoad(() => api<{ team: { id: number; name: string }[] }>("/m5/board").then((b) => b.team), []);
  const provinces = useLoad(() => api<{ code: string; name: string }[]>("/provinces"), []);
  const meta = useLoad(() => api<{ issues: { code: string; name: string }[] }>("/m4/meta").then((m) => m.issues), []);
  const [f, setF] = useState({ title: "", event_at: toLocalInput(new Date(Date.now() + 86400_000)), location: "", province_code: "", reporter_id: "", brief: "" });
  const [show, setShow] = useState(false);
  const [msg, setMsg] = useState<{ kind: "ok" | "error"; text: string } | null>(null);
  const act = async (fn: () => Promise<unknown>, ok: string) => { try { await fn(); setMsg({ kind: "ok", text: ok }); list.reload(); } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); } };
  const uploadFor = (c: Cov, file: File) => { const fd = new FormData(); fd.append("file", file); fd.append("title", `Rekaman: ${c.title}`); fd.append("coverage_id", String(c.id)); fd.append("license", "milik_sendiri"); fd.append("credit", c.reporter_name ?? "Tim liputan");
    return act(() => api("/m5/assets", { method: "POST", form: fd }), "Rekaman terunggah ke bank aset dan tertaut ke liputan."); };
  return (
    <div className="stack">
      <div className="row between"><span className="small muted">Penugasan liputan; rekaman diunggah langsung ke alur produksi (bank aset) lalu dijadikan brief konten (S39).</span>
        {hasRole("redaktur", "pimpinan") && <button className="primary" onClick={() => setShow((x) => !x)}>{show ? "Tutup" : "+ Liputan"}</button>}</div>
      {msg && <Msg kind={msg.kind}>{msg.text}</Msg>}
      {show && (
        <form className="card form-grid" onSubmit={(e) => { e.preventDefault(); act(() => api("/m5/coverage", { method: "POST", body: { ...f, event_at: new Date(f.event_at).toISOString(), reporter_id: f.reporter_id ? Number(f.reporter_id) : null, province_code: f.province_code || null } }).then(() => setShow(false)), "Liputan dijadwalkan; petugas mendapat notifikasi."); }}>
          <label className="field req full"><span>Kegiatan</span><input value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} required minLength={5} /></label>
          <label className="field req"><span>Waktu</span><input type="datetime-local" value={f.event_at} onChange={(e) => setF({ ...f, event_at: e.target.value })} /></label>
          <label className="field req"><span>Lokasi</span><input value={f.location} onChange={(e) => setF({ ...f, location: e.target.value })} required minLength={3} /></label>
          <label className="field"><span>Provinsi</span><select value={f.province_code} onChange={(e) => setF({ ...f, province_code: e.target.value })}><option value="">—</option>{provinces.data?.map((p) => <option key={p.code} value={p.code}>{p.name}</option>)}</select></label>
          <label className="field"><span>Petugas</span><select value={f.reporter_id} onChange={(e) => setF({ ...f, reporter_id: e.target.value })}><option value="">—</option>{team.data?.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</select></label>
          <label className="field full"><span>Arahan liputan</span><textarea style={{ minHeight: 60 }} value={f.brief} onChange={(e) => setF({ ...f, brief: e.target.value })} /></label>
          <div className="full"><button className="primary">Simpan</button></div>
        </form>
      )}
      <div className="card table-wrap">
        <table>
          <thead><tr><th>Waktu</th><th>Kegiatan</th><th>Petugas</th><th>Status</th><th>Rekaman</th><th></th></tr></thead>
          <tbody>{list.data?.map((c) => (
            <tr key={c.id}>
              <td className="small">{fmtDateTime(c.event_at)}</td>
              <td><b>{c.title}</b><div className="small muted">{c.location}{c.province_name ? ` · ${c.province_name}` : ""}</div><div className="small">{c.brief}</div></td>
              <td className="small">{c.reporter_name ?? "-"}</td>
              <td><select value={c.status} onChange={(e) => act(() => api(`/m5/coverage/${c.id}`, { method: "PATCH", body: { status: e.target.value } }), "Status diperbarui.")} aria-label="Status liputan">
                {["direncanakan", "berlangsung", "selesai", "batal"].map((s) => <option key={s}>{s}</option>)}</select></td>
              <td className="small">{c.assets.length} berkas
                <label className="btn sm" style={{ marginTop: 4 }}>Unggah<input type="file" hidden onChange={(e) => e.target.files?.[0] && uploadFor(c, e.target.files[0])} /></label></td>
              <td>{c.brief_id ? <Link className="btn sm" to={`/produksi?brief=${c.brief_id}`}>Brief #{c.brief_id}</Link>
                : hasRole("redaktur", "produser", "analis") && <select defaultValue="" aria-label="Jadikan brief" onChange={(e) => e.target.value && act(() => api(`/m5/coverage/${c.id}/brief`, { method: "POST", body: { issue_code: e.target.value } }), "Brief dibuat dari liputan.")}>
                  <option value="">Jadikan brief…</option>{meta.data?.map((i) => <option key={i.code} value={i.code}>{i.name}</option>)}</select>}</td>
            </tr>
          ))}</tbody>
        </table>
      </div>
    </div>
  );
}

export default function AssetsPage() {
  const [params, setParams] = useSearchParams();
  const tab = params.get("tab") ?? "aset";
  const set = (t: string) => { const p = new URLSearchParams(params); p.set("tab", t); setParams(p, { replace: true }); };
  return (
    <div className="stack">
      <div>
        <h1>Aset, Templat & Liputan <span className="muted" style={{ fontWeight: 400 }}>· M5 S37–S39</span></h1>
        <p className="sub" style={{ marginBottom: 0 }}>Bank aset ber-lisensi dengan tag SISTRA AGENT, pustaka templat identitas visual berversi, dan penjadwalan liputan lapangan</p>
      </div>
      <div className="tabs" role="tablist">
        {[["aset", "Bank aset (S37)"], ["templat", "Templat visual (S38)"], ["liputan", "Liputan lapangan (S39)"]].map(([k, v]) => <button key={k} role="tab" aria-selected={tab === k} className={tab === k ? "on" : ""} onClick={() => set(k)}>{v}</button>)}
      </div>
      {tab === "aset" && <Bank />}
      {tab === "templat" && <Templates />}
      {tab === "liputan" && <><SebaranMap title="Sebaran liputan lapangan" layers={["liputan"]} height={360} /><Coverage /></>}
    </div>
  );
}
