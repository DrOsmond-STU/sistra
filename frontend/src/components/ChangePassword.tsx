import { useState, type FormEvent } from "react";
import { api } from "../api";
import { useAuth } from "../auth";
import { Msg } from "./ui";

/** Ganti kata sandi akun sendiri; setelah berhasil, semua sesi lama berakhir dan pengguna masuk kembali. */
export default function ChangePassword({ onClose }: { onClose: () => void }) {
  const { logout } = useAuth();
  const [cur, setCur] = useState("");
  const [nw, setNw] = useState("");
  const [nw2, setNw2] = useState("");
  const [err, setErr] = useState("");
  const [busy, setBusy] = useState(false);
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setErr("");
    if (nw.length < 10) return setErr("Kata sandi baru minimal 10 karakter.");
    if (nw !== nw2) return setErr("Konfirmasi kata sandi baru tidak sama.");
    setBusy(true);
    try {
      await api("/auth/password", { method: "POST", body: { current_password: cur, new_password: nw } });
      alert("Kata sandi berhasil diganti. Silakan masuk kembali dengan kata sandi baru.");
      logout();
    } catch (x) {
      setErr((x as Error).message);
    } finally {
      setBusy(false);
    }
  };
  return (
    <div className="modal-bg" role="dialog" aria-modal="true" aria-label="Ganti kata sandi" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <form className="modal" style={{ width: "min(440px, 100%)" }} onSubmit={submit}>
        <div className="modal-head"><h2 style={{ margin: 0 }}>Ganti kata sandi</h2><button type="button" className="ghost sm" onClick={onClose} aria-label="Tutup">✕</button></div>
        <label className="field"><span>Kata sandi saat ini</span><input type="password" autoComplete="current-password" value={cur} onChange={(e) => setCur(e.target.value)} required /></label>
        <label className="field"><span>Kata sandi baru (min. 10 karakter)</span><input type="password" autoComplete="new-password" value={nw} onChange={(e) => setNw(e.target.value)} required /></label>
        <label className="field"><span>Ulangi kata sandi baru</span><input type="password" autoComplete="new-password" value={nw2} onChange={(e) => setNw2(e.target.value)} required /></label>
        <Msg kind="error">{err}</Msg>
        <div className="row" style={{ justifyContent: "flex-end", gap: 8 }}>
          <button type="button" className="ghost" onClick={onClose}>Batal</button>
          <button className="primary" disabled={busy}>{busy ? "Menyimpan…" : "Simpan"}</button>
        </div>
      </form>
    </div>
  );
}
