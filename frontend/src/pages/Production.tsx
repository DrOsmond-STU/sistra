import { useEffect, useState, type FormEvent } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { api } from "../api";
import { useAuth } from "../auth";
import DraftTools, { type DraftWithEditorial } from "../components/DraftTools";
import { ModeChip, useBusy } from "../components/kit";
import { Msg, useLoad } from "../components/ui";
import { STATUS_DRAFT, fmtDateTime } from "../format";
import type { Draft } from "../types";
import { FORMAT_LABEL, type Brief, type M4Meta, type Variant } from "../types47";

const BRIEF_STATUS: Record<string, string> = { baru: "Baru", dihasilkan: "Variasi siap", dipakai: "Dijadikan naskah" };

const EXAMPLE = {
  title: "Klarifikasi kabar pemotongan kuota beras SPHP",
  issue_code: "EKO-PANGAN", format: "carousel", tone: "informatif", region_code: "",
  objective: "Meluruskan informasi keliru dan mengarahkan warga ke kanal pengaduan resmi",
  audience: "Ibu rumah tangga dan pedagang pasar",
  key_messages: "Penyaluran beras SPHP tetap berjalan sesuai jadwal. Harga eceran tertinggi tidak berubah. Laporkan penyimpangan ke kanal pengaduan resmi.",
  facts: "Penyaluran beras SPHP tetap berjalan sesuai jadwal (contoh)\nHET beras SPHP tidak berubah (contoh)\nKanal pengaduan: layanan pengaduan resmi pemerintah (contoh)",
};

function BriefForm({ meta, onCreated }: { meta: M4Meta; onCreated: (b: Brief) => void }) {
  const provinces = useLoad(() => api<{ code: string; name: string }[]>("/provinces"), []);
  const [f, setF] = useState({ ...EXAMPLE, title: "", key_messages: "", facts: "", objective: "", audience: "" });
  const [err, setErr] = useState("");
  const [saving, setSaving] = useState(false);
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value });

  const submit = async (e: FormEvent) => {
    e.preventDefault(); setErr(""); setSaving(true);
    try {
      const b = await api<Brief>("/m4/briefs", { method: "POST", body: { ...f, region_code: f.region_code || null, facts: f.facts.split("\n").map((x) => x.trim()).filter(Boolean) } });
      onCreated(b);
    } catch (ex) { setErr((ex as Error).message); } finally { setSaving(false); }
  };

  return (
    <form className="card stack" onSubmit={submit}>
      <div className="row between">
        <h2 style={{ margin: 0 }}>Brief konten baru</h2>
        <button type="button" className="sm ghost" onClick={() => setF({ ...EXAMPLE })}>Isi contoh</button>
      </div>
      <Msg kind="error">{err}</Msg>
      <div className="form-grid">
        <label className="field req full"><span>Judul / topik</span><input value={f.title} onChange={set("title")} required minLength={5} /></label>
        <label className="field req"><span>Isu</span>
          <select value={f.issue_code} onChange={set("issue_code")} required>{meta.issues.map((i) => <option key={i.code} value={i.code}>{i.name}</option>)}</select></label>
        <label className="field req"><span>Format</span>
          <select value={f.format} onChange={set("format")}>{meta.formats.map((x) => <option key={x.id} value={x.id}>{x.label}</option>)}</select></label>
        <label className="field"><span>Nada</span>
          <select value={f.tone} onChange={set("tone")}>{meta.tones.map((t) => <option key={t}>{t}</option>)}</select></label>
        <label className="field"><span>Wilayah</span>
          <select value={f.region_code} onChange={set("region_code")}><option value="">Nasional</option>{provinces.data?.map((p) => <option key={p.code} value={p.code}>{p.name}</option>)}</select></label>
        <label className="field"><span>Tujuan komunikasi</span><input value={f.objective} onChange={set("objective")} /></label>
        <label className="field"><span>Khalayak sasaran</span><input value={f.audience} onChange={set("audience")} /></label>
        <label className="field req full"><span>Pesan kunci</span><textarea style={{ minHeight: 70 }} value={f.key_messages} onChange={set("key_messages")} required minLength={5} /></label>
        <label className="field full"><span>Fakta terverifikasi (satu per baris) — AI hanya boleh memakai fakta ini</span>
          <textarea style={{ minHeight: 70 }} value={f.facts} onChange={set("facts")} /></label>
      </div>
      <div className="row"><button className="primary" disabled={saving}>{saving ? "Menyimpan…" : "Simpan brief"}</button></div>
    </form>
  );
}

function VariantCard({ v, canMake, onMade }: { v: Variant; canMake: boolean; onMade: (d: Draft) => void }) {
  const [edit, setEdit] = useState<{ title: string; body: string } | null>(null);
  const [err, setErr] = useState("");
  const [busy, run] = useBusy();
  const make = () => run("mk", async () => {
    try { onMade(await api<Draft>(`/m4/variants/${v.id}/draft`, { method: "POST", body: edit ?? {} })); } catch (e) { setErr((e as Error).message); }
  });
  return (
    <div className="variant">
      <div className="row between"><span className="angle">{v.angle}</span><ModeChip mode={v.ai_mode} /></div>
      {edit ? (
        <>
          <input value={edit.title} onChange={(e) => setEdit({ ...edit, title: e.target.value })} aria-label="Judul variasi" />
          <textarea value={edit.body} onChange={(e) => setEdit({ ...edit, body: e.target.value })} aria-label="Isi variasi" />
        </>
      ) : (
        <>
          <b>{v.title}</b>
          <div className="vbody">{v.body}</div>
        </>
      )}
      {v.hashtags.length > 0 && <div className="tags">{v.hashtags.map((h) => <span key={h}>{h.startsWith("#") ? h : `#${h}`}</span>)}</div>}
      <Msg kind="error">{err}</Msg>
      <div className="row">
        {v.draft_id ? <Link className="btn sm" to={`/editorial?id=${v.draft_id}`}>Lihat naskah #{v.draft_id} →</Link> : canMake && (
          <>
            <button className="sm primary" disabled={!!busy} onClick={make}>{busy ? "Membuat…" : "Jadikan naskah"}</button>
            {!edit && <button className="sm" onClick={() => setEdit({ title: v.title, body: v.body })}>Sunting dulu</button>}
            {edit && <button className="sm ghost" onClick={() => setEdit(null)}>Batal sunting</button>}
          </>
        )}
      </div>
    </div>
  );
}

export default function ProductionPage() {
  const { hasRole } = useAuth();
  const [params, setParams] = useSearchParams();
  const meta = useLoad(() => api<M4Meta>("/m4/meta"), []);
  const briefs = useLoad(() => api<Brief[]>("/m4/briefs"), []);
  const [sel, setSel] = useState<Brief | null>(null);
  const [creating, setCreating] = useState(false);
  const [n, setN] = useState(3);
  const [msg, setMsg] = useState<{ kind: "ok" | "error" | "warn"; text: string } | null>(null);
  const [made, setMade] = useState<DraftWithEditorial | null>(null);
  const [busy, run] = useBusy();
  const isAuthor = hasRole("redaktur", "produser", "analis");

  const open = async (id: number) => {
    setCreating(false); setMade(null); setMsg(null);
    setSel(await api<Brief>(`/m4/briefs/${id}`));
    setParams({ brief: String(id) }, { replace: true });
  };
  useEffect(() => {
    const id = Number(params.get("brief"));
    if (id) open(id).catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const generate = () => run("gen", async () => {
    try {
      const r = await api<Brief>(`/m4/briefs/${sel!.id}/generate`, { method: "POST", body: { n } });
      setSel(r); briefs.reload();
      setMsg(r.mode === "claude" ? { kind: "ok", text: `${n} variasi baru dihasilkan Claude. ${r.notes ?? ""}` }
        : { kind: "warn", text: "Mode simulasi: variasi disusun dari templat. Isi kunci API di Pengaturan AI agar Claude yang menulis." });
    } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); }
  });

  const ai = meta.data?.ai;
  return (
    <div className="stack">
      <div className="row between">
        <div>
          <h1>Produksi Narasi <span className="muted" style={{ fontWeight: 400 }}>· M4</span></h1>
          <p className="sub" style={{ marginBottom: 0 }}>Brief → AI menghasilkan variasi multi-format → pilih & sunting → naskah draf → cek editorial → pelokalan ke provinsi</p>
        </div>
        <div className="row">
          {ai && <ModeChip mode={ai.mode} />}
          {isAuthor && <button className="primary" onClick={() => { setCreating(true); setSel(null); setMade(null); }}>+ Brief baru</button>}
        </div>
      </div>
      {ai?.mode === "simulasi" && <Msg kind="warn">AI berjalan dalam <b>mode simulasi</b> (kunci API Claude belum diisi). Fitur tetap bisa dicoba; hasil berasal dari templat. {hasRole("admin") && <Link to="/pengaturan-ai">Isi kunci API →</Link>}</Msg>}
      {msg && <Msg kind={msg.kind}>{msg.text}</Msg>}

      <div className="split" style={{ gridTemplateColumns: "minmax(0, 0.8fr) minmax(0, 1.2fr)" }}>
        <div className="card table-wrap">
          <h2>Brief konten</h2>
          {briefs.data?.length === 0 && <div className="empty">Belum ada brief.</div>}
          {!!briefs.data?.length && (
            <table>
              <thead><tr><th>Brief</th><th>Status</th></tr></thead>
              <tbody>{briefs.data.map((b) => (
                <tr key={b.id} className={`clickable${sel?.id === b.id ? " selected" : ""}`} onClick={() => open(b.id)}>
                  <td><b>{b.title}</b><div className="small muted">{FORMAT_LABEL[b.format] ?? b.format} · {b.issue_name} · {b.region_name}</div></td>
                  <td className="small"><span className="chip">{BRIEF_STATUS[b.status] ?? b.status}</span><div className="muted">{b.variant_count} variasi</div></td>
                </tr>
              ))}</tbody>
            </table>
          )}
        </div>

        <div className="stack">
          {creating && meta.data && <BriefForm meta={meta.data} onCreated={(b) => { setCreating(false); setSel(b); briefs.reload(); setMsg({ kind: "ok", text: "Brief tersimpan. Klik “Hasilkan variasi” agar AI menyusun draf." }); }} />}
          {!creating && !sel && <div className="card empty">Pilih brief di kiri, atau buat brief baru untuk mulai memproduksi konten dengan AI.</div>}
          {sel && (
            <>
              <div className="card stack" style={{ gap: 10 }}>
                <div className="row between">
                  <h2 style={{ margin: 0 }}>{sel.title}</h2>
                  <span className="chip">{FORMAT_LABEL[sel.format] ?? sel.format}</span>
                </div>
                <dl className="kv">
                  <dt>Isu</dt><dd>{sel.issue_name}</dd>
                  <dt>Wilayah · nada</dt><dd>{sel.region_name} · {sel.tone}</dd>
                  {sel.objective && <><dt>Tujuan</dt><dd>{sel.objective}</dd></>}
                  {sel.audience && <><dt>Khalayak</dt><dd>{sel.audience}</dd></>}
                  <dt>Pesan kunci</dt><dd>{sel.key_messages}</dd>
                  <dt>Fakta</dt><dd>{sel.facts.length ? <ul style={{ margin: 0, paddingLeft: 18 }}>{sel.facts.map((x) => <li key={x}>{x}</li>)}</ul> : <span className="muted">Tidak ada — AI akan menandai klaim dengan [DATA PERLU DIVERIFIKASI]</span>}</dd>
                  <dt>Dibuat</dt><dd>{sel.author_name} · {fmtDateTime(sel.created_at)}</dd>
                </dl>
                {isAuthor && (
                  <div className="row">
                    <label className="row small">Jumlah variasi
                      <select value={n} onChange={(e) => setN(Number(e.target.value))}>{[2, 3, 4, 5].map((x) => <option key={x}>{x}</option>)}</select>
                    </label>
                    <button className="primary" disabled={!!busy} onClick={generate}>{busy === "gen" ? "AI sedang menulis…" : "✦ Hasilkan variasi dengan AI"}</button>
                  </div>
                )}
              </div>

              {made && (
                <div className="card stack">
                  <Msg kind="ok">Naskah <b>#{made.id} “{made.title}”</b> dibuat berstatus {STATUS_DRAFT[made.status]}. Lanjutkan pemeriksaan di bawah, lalu ajukan lewat <Link to={`/editorial?id=${made.id}`}>papan editorial</Link>.</Msg>
                  <DraftTools draft={made} onChanged={(d) => d && setMade(d)} />
                </div>
              )}

              {!!sel.variants?.length && (
                <div className="grid g2">
                  {sel.variants.map((v) => (
                    <VariantCard key={v.id} v={v} canMake={isAuthor} onMade={(d) => { setMade(d); open(sel.id).then(() => setMade(d)); briefs.reload(); }} />
                  ))}
                </div>
              )}
              {sel.variants?.length === 0 && <div className="card empty">Belum ada variasi. Klik “Hasilkan variasi dengan AI”.</div>}
            </>
          )}
        </div>
      </div>
    </div>
  );
}
