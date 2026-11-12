import { useState, type FormEvent } from "react";
import { api } from "../api";
import { ClassChip, Msg, useLoad } from "../components/ui";
import { ROLE_LABEL } from "../format";
import type { Unit, User } from "../types";

const ROLES = Object.keys(ROLE_LABEL);
const LEVELS = ["TERBUKA", "TERBATAS", "RAHASIA", "SANGAT_RAHASIA"];

export default function UsersPage() {
  const users = useLoad(() => api<User[]>("/users"), []);
  const units = useLoad(() => api<Unit[]>("/units"), []);
  const [msg, setMsg] = useState<{ kind: "ok" | "error"; text: string } | null>(null);
  const [f, setF] = useState({ username: "", full_name: "", nip: "", unit_code: "", roles: [] as string[], clearance: "TERBATAS", password: "" });

  const create = async (e: FormEvent) => {
    e.preventDefault();
    try {
      await api<User>("/users", { method: "POST", body: f });
      setMsg({ kind: "ok", text: `Pengguna ${f.username} dibuat.` });
      setF({ ...f, username: "", full_name: "", nip: "", password: "" });
      users.reload();
    } catch (err) {
      setMsg({ kind: "error", text: (err as Error).message });
    }
  };

  const toggleActive = async (u: User) => {
    try {
      await api<User>(`/users/${u.id}`, { method: "PATCH", body: { active: !u.active } });
      users.reload();
    } catch (err) {
      setMsg({ kind: "error", text: (err as Error).message });
    }
  };

  return (
    <div className="stack">
      <div>
        <h1>Manajemen Pengguna</h1>
        <p className="sub" style={{ marginBottom: 0 }}>MVP: akun lokal. F1: SSO OIDC/SAML (Keycloak) dengan MFA FIDO2 dan PAM.</p>
      </div>
      {msg && <Msg kind={msg.kind}>{msg.text}</Msg>}
      <div className="card table-wrap">
        <table>
          <thead><tr><th>Pengguna</th><th>Unit</th><th>Peran</th><th>Clearance</th><th>Status</th><th /></tr></thead>
          <tbody>{users.data?.map((u) => (
            <tr key={u.id}>
              <td><b>{u.username}</b><div className="small muted">{u.full_name}</div></td>
              <td>{u.unit.code}</td>
              <td className="small">{u.roles.map((r) => ROLE_LABEL[r] ?? r).join(", ")}</td>
              <td><ClassChip code={u.clearance} label={u.clearance_label} /></td>
              <td>{u.active ? "Aktif" : "Nonaktif"}</td>
              <td><button className="sm" onClick={() => toggleActive(u)}>{u.active ? "Nonaktifkan" : "Aktifkan"}</button></td>
            </tr>
          ))}</tbody>
        </table>
      </div>
      <form className="card stack" onSubmit={create}>
        <h2>Tambah pengguna</h2>
        <div className="form-grid">
          <label className="field req"><span>Username</span><input value={f.username} pattern="[a-z0-9._\-]+" onChange={(e) => setF({ ...f, username: e.target.value })} required /></label>
          <label className="field req"><span>Nama lengkap</span><input value={f.full_name} onChange={(e) => setF({ ...f, full_name: e.target.value })} required /></label>
          <label className="field"><span>NIP</span><input value={f.nip} onChange={(e) => setF({ ...f, nip: e.target.value })} /></label>
          <label className="field req"><span>Unit</span>
            <select value={f.unit_code} onChange={(e) => setF({ ...f, unit_code: e.target.value })} required>
              <option value="">Pilih…</option>{units.data?.map((u) => <option key={u.code} value={u.code}>{u.code} — {u.name}</option>)}
            </select></label>
          <label className="field req"><span>Clearance</span>
            <select value={f.clearance} onChange={(e) => setF({ ...f, clearance: e.target.value })}>{LEVELS.map((l) => <option key={l}>{l}</option>)}</select></label>
          <label className="field req"><span>Kata sandi awal (min. 8)</span>
            <input type="password" value={f.password} minLength={8} onChange={(e) => setF({ ...f, password: e.target.value })} required /></label>
          <div className="full row">
            {ROLES.map((r) => (
              <label key={r} className="chip" style={{ cursor: "pointer" }}>
                <input type="checkbox" style={{ margin: 0 }} checked={f.roles.includes(r)}
                       onChange={(e) => setF({ ...f, roles: e.target.checked ? [...f.roles, r] : f.roles.filter((x) => x !== r) })} />
                {ROLE_LABEL[r]}
              </label>
            ))}
          </div>
        </div>
        <div><button className="primary" disabled={!f.roles.length}>Buat pengguna</button></div>
      </form>
    </div>
  );
}
