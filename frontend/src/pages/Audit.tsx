import { useState } from "react";
import { api } from "../api";
import { Msg, useLoad } from "../components/ui";
import { fmtDateTime } from "../format";
import type { AuditEvent } from "../types";

interface VerifyResult { ok: boolean; checked: number; first_broken_seq: number | null; reason: string | null; head_hash: string | null }

export default function AuditPage() {
  const [action, setAction] = useState("");
  const [actor, setActor] = useState("");
  const [filter, setFilter] = useState({ action: "", actor: "" });
  const [verify, setVerify] = useState<VerifyResult | null>(null);
  const [err, setErr] = useState("");
  const list = useLoad(() => api<AuditEvent[]>("/audit", { query: { ...filter, limit: 200 } }), [filter.action, filter.actor]);

  const runVerify = async () => {
    try {
      setVerify(await api<VerifyResult>("/audit/verify"));
      list.reload();
    } catch (e) {
      setErr((e as Error).message);
    }
  };

  return (
    <div className="stack">
      <div className="row between">
        <div>
          <h1>Jejak Audit (WORM)</h1>
          <p className="sub" style={{ marginBottom: 0 }}>Setiap peristiwa berantai hash SHA-256; perubahan atau penghapusan baris terdeteksi saat verifikasi.</p>
        </div>
        <button className="primary" onClick={runVerify}>Verifikasi integritas rantai</button>
      </div>
      <Msg kind="error">{err}</Msg>
      {verify && (
        <Msg kind={verify.ok ? "ok" : "error"}>
          {verify.ok
            ? `✓ Rantai utuh — ${verify.checked} peristiwa diverifikasi. Hash kepala: ${verify.head_hash?.slice(0, 16)}…`
            : `✕ Rantai RUSAK pada peristiwa #${verify.first_broken_seq}: ${verify.reason}`}
        </Msg>
      )}
      <form className="card row" onSubmit={(e) => { e.preventDefault(); setFilter({ action: action.trim(), actor: actor.trim() }); }}>
        <input placeholder="Awalan aksi, mis. dokumen. atau naskah.transisi" value={action} onChange={(e) => setAction(e.target.value)} style={{ flex: "1 1 260px" }} />
        <input placeholder="Pengguna" value={actor} onChange={(e) => setActor(e.target.value)} />
        <button type="submit">Saring</button>
      </form>
      <div className="card table-wrap">
        <table>
          <thead><tr><th>#</th><th>Waktu</th><th>Pengguna</th><th>Aksi</th><th>Objek</th><th>IP</th><th>Rincian</th><th>Hash</th></tr></thead>
          <tbody>{list.data?.map((e) => (
            <tr key={e.seq}>
              <td className="num">{e.seq}</td>
              <td className="small">{fmtDateTime(e.at)}</td>
              <td>{e.actor_username}</td>
              <td className="mono">{e.action}</td>
              <td className="small">{e.object_type}:{e.object_id}</td>
              <td className="mono">{e.ip}</td>
              <td className="mono small" style={{ maxWidth: 320, wordBreak: "break-all" }}>{JSON.stringify(e.detail)}</td>
              <td className="mono" title={e.hash}>{e.hash.slice(0, 10)}…</td>
            </tr>
          ))}</tbody>
        </table>
      </div>
    </div>
  );
}
