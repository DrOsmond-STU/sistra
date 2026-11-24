import { useState, type FormEvent } from "react";
import { api } from "../api";
import { useAuth } from "../auth";
import { fmtDateTime } from "../format";
import { Msg, useLoad } from "./ui";

interface Item { id: number; province_code: string; province_name: string; category: string; category_label: string; content: string; source: string; verified: boolean; verified_by_name: string | null; verified_at: string | null }
interface Resp { items: Item[]; summary: Record<string, { total: number; verified: number }>; categories: Record<string, string> }

/** S28: basis data konteks lokal terverifikasi — satu-satunya sumber fakta lokal untuk pelokalan AI. */
export default function LocalContext() {
  const { hasRole } = useAuth();
  const provinces = useLoad(() => api<{ code: string; name: string }[]>("/provinces"), []);
  const [prov, setProv] = useState("35");
  const all = useLoad(() => api<Resp>("/m4/local-context"), []);
  const one = useLoad(() => api<Resp>("/m4/local-context", { query: { province: prov } }), [prov]);
  const [f, setF] = useState({ category: "istilah", content: "", source: "" });
  const [msg, setMsg] = useState<{ kind: "ok" | "error"; text: string } | null>(null);
  const canVerify = hasRole("redaktur", "pimpinan");
  const canAdd = hasRole("redaktur", "produser", "analis");
  const sum = all.data?.summary ?? {};
  const verifiedTotal = Object.values(sum).reduce((a, x) => a + x.verified, 0);
  const add = async (e: FormEvent) => {
    e.preventDefault();
    try { await api("/m4/local-context", { method: "POST", body: { ...f, province_code: prov } }); setF({ ...f, content: "", source: "" }); one.reload(); all.reload(); setMsg({ kind: "ok", text: "Ditambahkan — menunggu verifikasi redaktur sebelum dipakai AI." }); }
    catch (ex) { setMsg({ kind: "error", text: (ex as Error).message }); }
  };
  const verify = async (it: Item, v: boolean) => {
    try { await api(`/m4/local-context/${it.id}/verify`, { method: "POST", body: { verified: v } }); one.reload(); all.reload(); } catch (ex) { setMsg({ kind: "error", text: (ex as Error).message }); }
  };
  return (
    <div className="stack">
      <Msg kind="info">Pelokalan AI hanya memakai butir <b>terverifikasi</b> di basis data ini (kriteria KAK S28: fakta lokal 100% dari basis data terverifikasi, ditinjau redaktur). Total {verifiedTotal} butir terverifikasi di {Object.keys(sum).length} provinsi.</Msg>
      <div className="card">
        <h2>Kelengkapan per provinsi</h2>
        <div className="chgrid">
          {(provinces.data ?? []).map((p) => {
            const s = sum[p.code] ?? { total: 0, verified: 0 };
            return (
              <button key={p.code} className={`chtile pick ${s.verified >= 4 ? "h-normal" : s.verified >= 2 ? "h-lambat" : "h-gangguan"}${prov === p.code ? " on" : ""}`} onClick={() => setProv(p.code)}>
                <span className="n">{p.name}</span><span className="muted">{s.verified}/{s.total} terverifikasi</span>
              </button>
            );
          })}
        </div>
      </div>
      {msg && <Msg kind={msg.kind}>{msg.text}</Msg>}
      <div className="split">
        <div className="card table-wrap">
          <h2>{provinces.data?.find((p) => p.code === prov)?.name}</h2>
          <table>
            <thead><tr><th>Kategori</th><th>Isi & sumber</th><th>Status</th></tr></thead>
            <tbody>{one.data?.items.map((it) => (
              <tr key={it.id}>
                <td className="small">{it.category_label}</td>
                <td>{it.content}<div className="small muted">{it.source}</div></td>
                <td className="small">{it.verified ? <><span className="status-ok">✓ Terverifikasi</span><div className="muted">{it.verified_by_name} · {fmtDateTime(it.verified_at)}</div></> : <span className="status-warn">Belum diverifikasi</span>}
                  {canVerify && <div><button className="sm" onClick={() => verify(it, !it.verified)}>{it.verified ? "Batalkan" : "Verifikasi"}</button></div>}</td>
              </tr>
            ))}</tbody>
          </table>
        </div>
        {canAdd && (
          <form className="card stack" onSubmit={add} style={{ gap: 8 }}>
            <h2 style={{ margin: 0 }}>Tambah konteks lokal</h2>
            <label className="field"><span>Kategori</span><select value={f.category} onChange={(e) => setF({ ...f, category: e.target.value })}>{Object.entries(one.data?.categories ?? {}).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></label>
            <label className="field req"><span>Isi</span><textarea style={{ minHeight: 70 }} value={f.content} onChange={(e) => setF({ ...f, content: e.target.value })} required minLength={3} /></label>
            <label className="field req"><span>Sumber</span><input value={f.source} onChange={(e) => setF({ ...f, source: e.target.value })} required minLength={3} /></label>
            <div><button className="primary">Simpan (menunggu verifikasi)</button></div>
          </form>
        )}
      </div>
    </div>
  );
}
