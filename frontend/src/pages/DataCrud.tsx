import { Fragment, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { api, downloadFile } from "../api";
import { Msg, useLoad } from "../components/ui";
import { fmtDate, fmtDateTime, nf } from "../format";

type Opt = { value: string; label: string };
interface Field { name: string; label: string; type: string; required?: boolean; readonly?: boolean; list?: boolean; search?: boolean; filter?: boolean; immutable?: boolean;
  min?: number; max?: number; hint?: string; create_only?: boolean; default?: unknown; options?: Opt[]; ref?: string }
interface Meta { key: string; label: string; module: string; title: string; link: string | null; upload: boolean; create_hint: string | null; fields: Field[]; count: number;
  can: { read: boolean; create: boolean; update: boolean; delete: boolean } }
type Row = Record<string, unknown> & { id: number; _link?: string };
interface ListResp { items: Row[]; total: number; page: number; per_page: number; pages: number }

const MODULES: Record<string, string> = { Master: "Data master", M4: "M4 · Produksi narasi", M5: "M5 · Editorial & aset", M6: "M6 · Kanal medsos resmi", M7: "M7 · Distribusi 39 kanal", M8: "M8 · Laporan" };

function display(f: Field, r: Row): React.ReactNode {
  const v = r[f.name];
  if (v === null || v === undefined || v === "") return <span className="muted">—</span>;
  if (f.type === "ref" || f.type === "province") return String(r[`${f.name}__label`] ?? v);
  if (f.type === "bool") return v ? <span className="badge-grad ok">Ya</span> : <span className="badge-grad">Tidak</span>;
  if (f.type === "enum") return <span className="chip">{f.options?.find((o) => o.value === v)?.label ?? String(v)}</span>;
  if (f.type === "datetime") return fmtDateTime(String(v));
  if (f.type === "date") return fmtDate(String(v));
  if (f.type === "int") return nf.format(Number(v));
  if (f.type === "color") return <span className="row" style={{ gap: 4 }}><span style={{ width: 14, height: 14, borderRadius: 3, background: String(v), display: "inline-block" }} />{String(v)}</span>;
  if (Array.isArray(v)) return v.length ? v.join(", ") : <span className="muted">—</span>;
  return String(v);
}
function toLocalInput(iso: string): string { const d = new Date(iso); if (isNaN(d.getTime())) return ""; const p = (n: number) => String(n).padStart(2, "0"); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`; }

/** Pemilih referensi dengan pencarian (opsi dari server, maks. 50). */
function RefPicker({ ent, f, value, label, onChange, disabled }: { ent: string; f: Field; value: number | null; label: string; onChange: (id: number | null, label: string) => void; disabled?: boolean }) {
  const [q, setQ] = useState(label);
  const [open, setOpen] = useState(false);
  const [opts, setOpts] = useState<{ id: number; label: string }[]>([]);
  const t = useRef<number>();
  useEffect(() => setQ(label), [label]);
  const search = (s: string) => { window.clearTimeout(t.current); t.current = window.setTimeout(() => api<{ id: number; label: string }[]>(`/crud/${ent}/options/${f.name}`, { query: { q: s } }).then(setOpts).catch(() => setOpts([])), 200); };
  return (
    <div className="ref-picker">
      <div className="row" style={{ flexWrap: "nowrap" }}>
        <input style={{ flex: 1 }} value={q} disabled={disabled} placeholder="Ketik untuk mencari…" aria-label={f.label}
               onFocus={() => { setOpen(true); search(""); }} onBlur={() => setTimeout(() => setOpen(false), 150)}
               onChange={(e) => { setQ(e.target.value); setOpen(true); search(e.target.value); }} />
        {value && !f.required && !disabled && <button type="button" className="sm ghost" onClick={() => { onChange(null, ""); setQ(""); }} aria-label="Kosongkan">✕</button>}
      </div>
      {open && !disabled && (
        <div className="ref-list" role="listbox">
          {!opts.length && <div className="small muted" style={{ padding: 8 }}>Tidak ada pilihan yang memenuhi syarat.</div>}
          {opts.map((o) => <button type="button" key={o.id} onMouseDown={() => { onChange(o.id, o.label); setQ(o.label); setOpen(false); }}>#{o.id} · {o.label}</button>)}
        </div>
      )}
    </div>
  );
}

function FormModal({ meta, row, onClose, onSaved }: { meta: Meta; row: Row | null; onClose: () => void; onSaved: (r: Row & { token?: string }) => void }) {
  const editing = !!row;
  const fields = meta.fields.filter((f) => !f.readonly && !(editing && f.create_only));
  const init = () => {
    const o: Record<string, unknown> = {};
    for (const f of fields) {
      const v = row ? row[f.name] : f.default;
      o[f.name] = f.type === "lines" || f.type === "facts" ? (Array.isArray(v) ? v.join("\n") : (v ?? "")) : f.type === "datetime" && v ? toLocalInput(String(v)) : (v ?? (f.type === "bool" ? false : f.type === "provinces" ? [] : ""));
      if (f.type === "ref") o[`${f.name}__label`] = row ? row[`${f.name}__label`] ?? "" : "";
    }
    return o;
  };
  const [v, setV] = useState<Record<string, unknown>>(init);
  const [file, setFile] = useState<File | null>(null);
  const [err, setErr] = useState("");
  const [busy, setBusy] = useState(false);
  const set = (k: string, x: unknown) => setV((s) => ({ ...s, [k]: x }));
  const submit = async (e: FormEvent) => {
    e.preventDefault(); setErr(""); setBusy(true);
    try {
      const body: Record<string, unknown> = {};
      for (const f of fields) {
        if (f.type === "file") continue;
        if (editing && f.immutable) continue;
        let x = v[f.name];
        if (f.type === "datetime") x = x ? new Date(String(x)).toISOString() : null;
        if (f.type === "int") x = x === "" || x === null ? null : Number(x);
        if (editing && JSON.stringify(x) === JSON.stringify(f.type === "lines" || f.type === "facts" ? (row![f.name] as string[] ?? []).join("\n") : f.type === "datetime" ? (row![f.name] ? new Date(String(row![f.name])).toISOString() : null) : row![f.name])) continue;
        body[f.name] = x;
      }
      let res: Row & { token?: string };
      if (editing) res = await api(`/crud/${meta.key}/${row!.id}`, { method: "PUT", body });
      else if (meta.upload) {
        if (!file) throw new Error("Pilih berkas untuk diunggah");
        const fd = new FormData(); fd.append("file", file);
        Object.entries(body).forEach(([k, x]) => { if (x !== null && x !== undefined && x !== "") fd.append(k, Array.isArray(x) ? x.join("\n") : String(x)); });
        res = await api(`/crud/${meta.key}`, { method: "POST", form: fd });
      } else res = await api(`/crud/${meta.key}`, { method: "POST", body });
      onSaved(res);
    } catch (ex) { setErr((ex as Error).message); } finally { setBusy(false); }
  };
  return (
    <div className="modal-bg" role="dialog" aria-modal="true" aria-label={editing ? `Ubah ${meta.label}` : `Tambah ${meta.label}`} onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <form className="modal" onSubmit={submit}>
        <div className="modal-head"><h2 style={{ margin: 0 }}>{editing ? `Ubah ${meta.label} #${row!.id}` : `Tambah ${meta.label}`}</h2><button type="button" className="ghost sm" onClick={onClose} aria-label="Tutup">✕</button></div>
        <Msg kind="error">{err}</Msg>
        <div className="form-grid">
          {fields.map((f) => {
            const dis = editing && f.immutable;
            const wide = ["textarea", "lines", "facts", "provinces", "file"].includes(f.type);
            const cls = `field${f.required ? " req" : ""}${wide ? " full" : ""}`;
            const val = v[f.name];
            let input: React.ReactNode;
            switch (f.type) {
              case "textarea": case "lines": case "facts":
                input = <textarea style={{ minHeight: f.type === "textarea" ? 110 : 80 }} value={String(val ?? "")} onChange={(e) => set(f.name, e.target.value)} disabled={dis} maxLength={f.max} />; break;
              case "int": input = <input type="number" value={String(val ?? "")} min={f.min} max={f.max} onChange={(e) => set(f.name, e.target.value)} disabled={dis} />; break;
              case "bool": input = <span className="row"><input type="checkbox" className="switch" checked={!!val} onChange={(e) => set(f.name, e.target.checked)} disabled={dis} /><span className="small muted">{val ? "Ya" : "Tidak"}</span></span>; break;
              case "enum": case "province":
                input = <select value={String(val ?? "")} onChange={(e) => set(f.name, e.target.value)} disabled={dis}>{!f.required && <option value="">—</option>}{f.required && !val && <option value="">Pilih…</option>}{f.options?.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select>; break;
              case "provinces":
                input = <select multiple size={6} value={(val as string[]) ?? []} onChange={(e) => set(f.name, Array.from(e.target.selectedOptions).map((o) => o.value))} disabled={dis}>{f.options?.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select>; break;
              case "date": input = <input type="date" value={String(val ?? "")} onChange={(e) => set(f.name, e.target.value)} disabled={dis} />; break;
              case "datetime": input = <input type="datetime-local" value={String(val ?? "")} onChange={(e) => set(f.name, e.target.value)} disabled={dis} />; break;
              case "color": input = <input type="color" value={String(val || "#000000")} onChange={(e) => set(f.name, e.target.value.toUpperCase())} disabled={dis} />; break;
              case "url": input = <input type="url" placeholder="https://…" value={String(val ?? "")} onChange={(e) => set(f.name, e.target.value)} disabled={dis} />; break;
              case "file": input = <input type="file" onChange={(e) => setFile(e.target.files?.[0] ?? null)} required />; break;
              case "ref": input = <RefPicker ent={meta.key} f={f} value={(val as number) || null} label={String(v[`${f.name}__label`] ?? "")} disabled={dis} onChange={(id, l) => { set(f.name, id); set(`${f.name}__label`, l); }} />; break;
              default: input = <input value={String(val ?? "")} onChange={(e) => set(f.name, e.target.value)} disabled={dis} maxLength={f.max} />;
            }
            return <label key={f.name} className={cls}><span>{f.label}</span>{input}{(f.hint || dis) && <span className="small muted" style={{ fontWeight: 400 }}>{dis ? "Tidak dapat diubah setelah dibuat" : f.hint}</span>}</label>;
          })}
        </div>
        <div className="row" style={{ justifyContent: "flex-end" }}>
          <button type="button" onClick={onClose}>Batal</button>
          <button className="primary" disabled={busy}>{busy ? "Menyimpan…" : editing ? "Simpan perubahan" : "Simpan"}</button>
        </div>
      </form>
    </div>
  );
}

function DetailModal({ meta, row, onClose, onEdit }: { meta: Meta; row: Row; onClose: () => void; onEdit?: () => void }) {
  return (
    <div className="modal-bg" role="dialog" aria-modal="true" aria-label={`Detail ${meta.label}`} onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className="modal">
        <div className="modal-head"><h2 style={{ margin: 0 }}>{meta.label} #{row.id}</h2><button className="ghost sm" onClick={onClose} aria-label="Tutup">✕</button></div>
        <dl className="kv">{meta.fields.filter((f) => f.type !== "file").map((f) => <Fragment key={f.name}><dt>{f.label}</dt><dd style={{ whiteSpace: f.type === "textarea" ? "pre-wrap" : undefined }}>{display(f, row)}</dd></Fragment>)}</dl>
        <div className="row" style={{ justifyContent: "flex-end" }}>
          {row._link && <Link className="btn" to={row._link}>Buka di modul →</Link>}
          {onEdit && <button className="primary" onClick={onEdit}>Ubah</button>}
        </div>
      </div>
    </div>
  );
}

function CrudTable({ meta, onCount }: { meta: Meta; onCount: () => void }) {
  const [q, setQ] = useState(""); const [qq, setQq] = useState("");
  const [page, setPage] = useState(1); const [per, setPer] = useState(25);
  const [sort, setSort] = useState(""); const [dir, setDir] = useState<"asc" | "desc">("desc");
  const [filters, setFilters] = useState<Record<string, string>>({});
  const [form, setForm] = useState<Row | null | "new">(null);
  const [detail, setDetail] = useState<Row | null>(null);
  const [del, setDel] = useState<Row | null>(null);
  const [msg, setMsg] = useState<{ kind: "ok" | "error" | "warn"; text: string } | null>(null);
  useEffect(() => { const t = setTimeout(() => { setQq(q); setPage(1); }, 300); return () => clearTimeout(t); }, [q]);
  const query = useMemo(() => { const o: Record<string, string | number> = { page, per_page: per, q: qq, sort, dir }; Object.entries(filters).forEach(([k, x]) => { if (x !== "") o[`filter[${k}]`] = x; }); return o; }, [page, per, qq, sort, dir, filters]);
  const list = useLoad(() => api<ListResp>(`/crud/${meta.key}`, { query }), [meta.key, JSON.stringify(query)]);
  const cols = meta.fields.filter((f) => f.list);
  const filt = meta.fields.filter((f) => f.filter && (f.options || f.type === "bool"));
  const toggleSort = (n: string) => { if (sort === n) setDir(dir === "asc" ? "desc" : "asc"); else { setSort(n); setDir("asc"); } setPage(1); };
  const doDelete = async () => {
    if (!del) return;
    try { await api(`/crud/${meta.key}/${del.id}`, { method: "DELETE" }); setMsg({ kind: "ok", text: `${meta.label} #${del.id} dihapus.` }); list.reload(); onCount(); }
    catch (e) { setMsg({ kind: "error", text: (e as Error).message }); }
    setDel(null);
  };
  const d = list.data;
  const pages = d?.pages ?? 1;
  const nums = Array.from(new Set([1, page - 2, page - 1, page, page + 1, page + 2, pages])).filter((n) => n >= 1 && n <= pages).sort((a, b) => a - b);
  return (
    <div className="stack">
      <div className="card stack" style={{ gap: 12 }}>
        <div className="row between">
          <div><h2 style={{ margin: 0 }}>{meta.label}</h2><div className="small muted">{MODULES[meta.module]} · {d ? `${nf.format(d.total)} baris` : "memuat…"}</div></div>
          <div className="row">
            {meta.link && <Link className="btn sm" to={meta.link.replace(/\?.*$/, "").replace(/\{.*\}/, "")}>Layar modul →</Link>}
            <button className="sm" onClick={() => downloadFile(`/crud/${meta.key}/export?${new URLSearchParams(Object.entries(query).map(([k, x]) => [k, String(x)])).toString()}`, `${meta.key}.csv`)}>Ekspor CSV</button>
            {meta.can.create ? <button className="primary sm" onClick={() => { setMsg(null); setForm("new"); }}>+ Tambah</button>
              : meta.create_hint ? <span className="small muted">{meta.create_hint}</span> : null}
          </div>
        </div>
        <div className="row">
          <input placeholder={`Cari ${meta.label.toLowerCase()} atau #ID…`} value={q} onChange={(e) => setQ(e.target.value)} style={{ minWidth: 240, flex: 1 }} aria-label="Cari" />
          {filt.map((f) => (
            <select key={f.name} value={filters[f.name] ?? ""} onChange={(e) => { setFilters({ ...filters, [f.name]: e.target.value }); setPage(1); }} aria-label={`Filter ${f.label}`}>
              <option value="">{f.label}: semua</option>
              {f.type === "bool" ? <><option value="1">Ya</option><option value="0">Tidak</option></> : f.options?.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
          ))}
          <select value={per} onChange={(e) => { setPer(Number(e.target.value)); setPage(1); }} aria-label="Baris per halaman">{[10, 25, 50, 100].map((n) => <option key={n} value={n}>{n} / hal</option>)}</select>
        </div>
        {msg && <Msg kind={msg.kind}>{msg.text}</Msg>}
        {list.error && <Msg kind="error">{list.error}</Msg>}
        <div className="table-wrap">
          <table>
            <thead><tr>
              <th><button className="crud-sort" onClick={() => toggleSort("id")}>ID {sort === "id" ? (dir === "asc" ? "▲" : "▼") : ""}</button></th>
              {cols.map((f) => <th key={f.name}><button className="crud-sort" onClick={() => toggleSort(f.name)}>{f.label} {sort === f.name ? (dir === "asc" ? "▲" : "▼") : ""}</button></th>)}
              <th style={{ textAlign: "right" }}>Aksi</th>
            </tr></thead>
            <tbody>
              {d?.items.map((r) => (
                <tr key={r.id}>
                  <td className="small muted">#{r.id}</td>
                  {cols.map((f) => <td key={f.name}><div className="cell-clip" title={typeof r[f.name] === "string" ? String(r[f.name]) : undefined}>{display(f, r)}</div></td>)}
                  <td><div className="row-actions">
                    <button className="sm ghost" onClick={() => setDetail(r)}>Lihat</button>
                    {meta.can.update && <button className="sm" onClick={() => { setMsg(null); setForm(r); }}>Ubah</button>}
                    {meta.can.delete && <button className="sm danger" onClick={() => { setMsg(null); setDel(r); }}>Hapus</button>}
                  </div></td>
                </tr>
              ))}
            </tbody>
          </table>
          {d && !d.items.length && <div className="empty">Tidak ada data yang cocok.</div>}
        </div>
        <div className="row between">
          <span className="small muted">{d ? `Menampilkan ${d.items.length ? (page - 1) * per + 1 : 0}–${(page - 1) * per + d.items.length} dari ${nf.format(d.total)}` : ""}</span>
          <div className="pager">
            <button disabled={page <= 1} onClick={() => setPage(page - 1)} aria-label="Sebelumnya">‹</button>
            {nums.map((n, i) => <span key={n} style={{ display: "contents" }}>{i > 0 && n - nums[i - 1] > 1 && <span className="muted">…</span>}<button className={n === page ? "on" : ""} onClick={() => setPage(n)}>{n}</button></span>)}
            <button disabled={page >= pages} onClick={() => setPage(page + 1)} aria-label="Berikutnya">›</button>
          </div>
        </div>
      </div>
      {form && <FormModal meta={meta} row={form === "new" ? null : form} onClose={() => setForm(null)} onSaved={(r) => {
        setForm(null); list.reload(); onCount();
        setMsg(r.token ? { kind: "warn", text: `Tersimpan. Token API (sekali tampil): ${r.token}` } : { kind: "ok", text: `${meta.label} #${r.id} tersimpan.` });
      }} />}
      {detail && <DetailModal meta={meta} row={detail} onClose={() => setDetail(null)} onEdit={meta.can.update ? () => { setForm(detail); setDetail(null); } : undefined} />}
      {del && (
        <div className="modal-bg" role="dialog" aria-modal="true" aria-label="Konfirmasi hapus" onMouseDown={(e) => e.target === e.currentTarget && setDel(null)}>
          <div className="modal sm">
            <h2 style={{ margin: 0 }}>Hapus {meta.label.toLowerCase()} #{del.id}?</h2>
            <p className="muted" style={{ margin: 0 }}>“{String(del[meta.title] ?? del.id).slice(0, 140)}”. Tindakan ini tercatat di jejak audit. Data yang terkait aturan (mis. sudah disetujui/terkirim) akan ditolak sistem.</p>
            <div className="row" style={{ justifyContent: "flex-end" }}><button onClick={() => setDel(null)}>Batal</button><button className="danger solid" onClick={doDelete}>Hapus</button></div>
          </div>
        </div>
      )}
    </div>
  );
}

/** Data & CRUD: kelola seluruh entitas M4–M8 (tambah, lihat, ubah, hapus, cari, saring, urut, ekspor). */
export default function DataCrudPage() {
  const { entity } = useParams();
  const nav = useNavigate();
  const meta = useLoad(() => api<Meta[]>("/crud/meta"), []);
  const [find, setFind] = useState("");
  const list = meta.data ?? [];
  const cur = list.find((m) => m.key === entity) ?? null;
  useEffect(() => { if (!entity && list.length) nav(`/data/${list.find((m) => m.key === "drafts")?.key ?? list[0].key}`, { replace: true }); }, [entity, list.length]); // eslint-disable-line react-hooks/exhaustive-deps
  const groups = Object.keys(MODULES).map((g) => [g, list.filter((m) => m.module === g && m.label.toLowerCase().includes(find.toLowerCase()))] as const).filter(([, l]) => l.length);
  const total = list.reduce((a, m) => a + m.count, 0);
  return (
    <div className="stack">
      <div>
        <h1>Data & CRUD</h1>
        <p className="sub" style={{ marginBottom: 0 }}>{list.length} entitas · {nf.format(total)} baris. Tambah, lihat, ubah, hapus, cari, saring, urutkan, dan ekspor; aturan bisnis & hak akses per peran tetap berlaku, dan setiap perubahan tercatat di jejak audit.</p>
      </div>
      {meta.error && <Msg kind="error">{meta.error}</Msg>}
      <label className="field crud-pick">
        <span>Entitas</span>
        <select value={entity ?? ""} onChange={(e) => nav(`/data/${e.target.value}`)}>
          {Object.keys(MODULES).map((g) => {
            const items = list.filter((m) => m.module === g);
            return items.length ? <optgroup key={g} label={MODULES[g]}>{items.map((m) => <option key={m.key} value={m.key}>{m.label} ({nf.format(m.count)})</option>)}</optgroup> : null;
          })}
        </select>
      </label>
      <div className="crud-layout">
        <div className="card crud-side">
          <input placeholder="Cari entitas…" value={find} onChange={(e) => setFind(e.target.value)} style={{ width: "100%" }} aria-label="Cari entitas" />
          {groups.map(([g, items]) => (
            <div key={g}>
              <div className="crud-group">{MODULES[g]}</div>
              {items.map((m) => <Link key={m.key} to={`/data/${m.key}`} className={`crud-ent${m.key === entity ? " on" : ""}`}><span>{m.label}</span><span className="n">{nf.format(m.count)}</span></Link>)}
            </div>
          ))}
        </div>
        <div>{cur ? <CrudTable key={cur.key} meta={cur} onCount={meta.reload} /> : <div className="card empty">Pilih entitas di kiri.</div>}</div>
      </div>
    </div>
  );
}
