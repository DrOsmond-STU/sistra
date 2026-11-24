import { useState, type FormEvent } from "react";
import { useAuth } from "../auth";
import { Msg } from "../components/ui";

const DEMO = ["pimpinan", "analis", "redaktur", "produser", "operator", "auditor", "admin"];

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
    <div className="signin">
      <div className="signin-bar"><span className="brand-mark" aria-hidden>S</span><b>SISTRA</b><span className="muted small">Sistem Informasi dan Aksi Strategis</span></div>
      <div className="signin-grid">
        <form className="signin-form stack" onSubmit={submit}>
          <div>
            <h1 className="text-gradient-info">Selamat datang</h1>
            <p className="muted" style={{ margin: 0 }}>Masukkan nama pengguna dan kata sandi untuk masuk ke ruang kerja produksi & distribusi narasi.</p>
          </div>
          <label className="field">
            <span>Nama pengguna</span>
            <input autoFocus autoComplete="username" placeholder="nama pengguna" value={username} onChange={(e) => setUsername(e.target.value)} required />
          </label>
          <label className="field">
            <span>Kata sandi</span>
            <input type="password" autoComplete="current-password" placeholder="kata sandi" value={password} onChange={(e) => setPassword(e.target.value)} required />
          </label>
          <Msg kind="error">{error}</Msg>
          <button className="primary info block" disabled={busy} type="submit">{busy ? "Memeriksa…" : "Masuk"}</button>
          <div className="small muted">Akses diatur per peran (pimpinan, redaktur, produser, analis, operator kanal, admin, auditor). Semua aktivitas tercatat di jejak audit.</div>
          {import.meta.env.DEV && <div className="demo-users">
            Akun demo: {DEMO.map((u, i) => (
              <span key={u}><code onClick={() => setUsername(u)}>{u}</code>{i < DEMO.length - 1 ? ", " : ""}</span>
            ))}
          </div>}
        </form>
        <div className="signin-art" aria-hidden>
          <div className="signin-art-img" />
        </div>
      </div>
      <div className="signin-foot small muted">© 2026 SISTRA · Tema Soft UI Dashboard Tailwind (Creative Tim, MIT) · Data contoh bersifat sintetis</div>
    </div>
  );
}
