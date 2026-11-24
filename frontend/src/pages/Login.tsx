import { useState, type FormEvent } from "react";
import { useAuth } from "../auth";
import { Msg } from "../components/ui";

const DEMO = ["pimpinan", "analis", "redaktur", "operator", "arsiparis", "auditor", "admin"];

export default function LoginPage() {
  const { login } = useAuth();
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      await login(username.trim(), password);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="login-wrap">
      <form className="card login-card stack" onSubmit={submit}>
        <div>
          <div className="brand">SISTRA</div>
          <div className="muted small">Sistem Informasi dan Aksi Strategis — Produksi Sistem Informasi dan Aksi Strategis — MVP Distribusi Narasi</div>
        </div>
        <label className="field">
          <span>Nama pengguna</span>
          <input autoFocus autoComplete="username" value={username} onChange={(e) => setUsername(e.target.value)} required />
        </label>
        <label className="field">
          <span>Kata sandi</span>
          <input type="password" autoComplete="current-password" value={password}
                 onChange={(e) => setPassword(e.target.value)} required />
        </label>
        <Msg kind="error">{error}</Msg>
        <button className="primary" disabled={busy} type="submit">{busy ? "Memeriksa…" : "Masuk"}</button>
        {import.meta.env.DEV && <div className="demo-users">
          Akun demo (kata sandi dari <span className="mono">SISTRA_SEED_PASSWORD</span>, bawaan <span className="mono">sistra-demo-2027</span>):{" "}
          {DEMO.map((u, i) => (
            <span key={u}>
              <code onClick={() => setUsername(u)}>{u}</code>
              {i < DEMO.length - 1 ? ", " : ""}
            </span>
          ))}
        </div>}
      </form>
    </div>
  );
}
