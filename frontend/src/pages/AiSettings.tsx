import { useState, type FormEvent } from "react";
import { api } from "../api";
import { ModeChip, useBusy } from "../components/kit";
import { Msg, useLoad } from "../components/ui";
import type { AiStatus } from "../types47";

export default function AiSettingsPage() {
  const st = useLoad(() => api<AiStatus>("/ai/status"), []);
  const [key, setKey] = useState("");
  const [msg, setMsg] = useState<{ kind: "ok" | "error" | "warn"; text: string } | null>(null);
  const [busy, run] = useBusy();
  const s = st.data;

  const save = (body: Record<string, unknown>, ok: string) => run("sv", async () => {
    try { st.setData(await api<AiStatus>("/ai/settings", { method: "PUT", body })); setMsg({ kind: "ok", text: ok }); setKey(""); } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); }
  });
  const submitKey = (e: FormEvent) => { e.preventDefault(); save({ api_key: key.trim() }, "Kunci API tersimpan. Kunci tidak akan pernah ditampilkan lagi."); };
  const install = () => run("in", async () => {
    try {
      let r: { status: string; packages_present?: number; packages_total?: number } = { status: "lanjutkan" };
      for (let i = 0; i < 12 && r.status === "lanjutkan"; i++) {
        setMsg({ kind: "warn", text: `Memasang pustaka mesin SISTRA AGENT… ${r.packages_present ?? 0}/${r.packages_total ?? 15} paket` });
        r = await api<typeof r>("/ai/library/install", { method: "POST" });
      }
      st.reload();
      setMsg(r.status === "lanjutkan" ? { kind: "warn", text: "Pemasangan belum selesai; klik lagi untuk melanjutkan." } : { kind: "ok", text: "Pustaka mesin SISTRA AGENT terpasang." });
    } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); }
  });
  const test = () => run("ts", async () => {
    try {
      const r = await api<{ model: string; reply: string }>("/ai/test", { method: "POST" });
      setMsg({ kind: "ok", text: `SISTRA AGENT terhubung. Balasan uji: “${r.reply}”` });
    } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); }
  });

  return (
    <div className="stack" style={{ maxWidth: 760 }}>
      <div>
        <h1>Pengaturan SISTRA AGENT</h1>
        <p className="sub" style={{ marginBottom: 0 }}>Hubungkan mesin SISTRA AGENT untuk produksi konten, cek editorial, triase komentar, dan asisten</p>
      </div>
      {st.error && <Msg kind="error">{st.error}</Msg>}
      {msg && <Msg kind={msg.kind}>{msg.text}</Msg>}
      {s && (
        <>
          <div className="card stack" style={{ gap: 10 }}>
            <div className="row between"><h2 style={{ margin: 0 }}>Status</h2><ModeChip mode={s.mode} /></div>
            <dl className="kv">
              <dt>Kunci API</dt><dd>{s.configured ? "Terpasang (tersembunyi)" : "Belum diisi"}</dd>
              <dt>SISTRA AGENT aktif</dt><dd>{s.enabled ? "Ya" : "Dinonaktifkan"}</dd>
              <dt>Tingkat mesin</dt><dd>{s.model_label}</dd>
              <dt>Pustaka mesin SISTRA AGENT</dt><dd>{s.library ? "Terpasang" : "Belum terpasang"}</dd>
            </dl>
            {!s.library && (
              <div className="row">
                <button className="primary" disabled={!!busy} onClick={install}>{busy === "in" ? "Memasang…" : "Pasang pustaka mesin SISTRA AGENT"}</button>
                <span className="small muted">Server mengunduh pustaka resmi mesin dari GitHub pada versi yang dikunci (±1 menit).</span>
              </div>
            )}
            {s.mode === "simulasi" && <div className="small muted">Tanpa kunci API dan pustaka, semua fitur SISTRA AGENT tetap berjalan dalam mode simulasi (templat & aturan sederhana) agar alur kerja bisa dicoba.</div>}
          </div>

          <form className="card stack" style={{ gap: 10 }} onSubmit={submitKey}>
            <h2 style={{ margin: 0 }}>Kunci API SISTRA AGENT</h2>
            <div className="small muted">Kunci disimpan di server (berkas berizin 0600), tidak pernah dikirim balik ke peramban, dan setiap perubahan tercatat di jejak audit. Jangan bagikan kunci lewat surel atau obrolan.</div>
            <input type="password" autoComplete="off" value={key} onChange={(e) => setKey(e.target.value)} placeholder="Tempel kunci API di sini" aria-label="Kunci API" />
            <div className="row">
              <button className="primary" disabled={!!busy || !key.trim()}>Simpan kunci</button>
              {s.configured && <button type="button" className="danger" disabled={!!busy} onClick={() => save({ api_key: "" }, "Kunci API dihapus. SISTRA AGENT kembali ke mode simulasi.")}>Hapus kunci</button>}
              {s.configured && <button type="button" disabled={!!busy} onClick={test}>{busy === "ts" ? "Menguji…" : "Uji koneksi"}</button>}
            </div>
          </form>

          <div className="card stack" style={{ gap: 10 }}>
            <h2 style={{ margin: 0 }}>Tingkat mesin & status</h2>
            <label className="field"><span>Tingkat mesin</span>
              <select value={s.model} disabled={!!busy} onChange={(e) => save({ model: e.target.value }, "Tingkat mesin diperbarui.")}>
                {s.models.map((m) => <option key={m.id} value={m.id}>{m.label}</option>)}
              </select></label>
            <label className="row small"><input type="checkbox" checked={s.enabled} disabled={!!busy} onChange={(e) => save({ enabled: e.target.checked }, e.target.checked ? "SISTRA AGENT diaktifkan." : "SISTRA AGENT dinonaktifkan; fitur memakai mode simulasi.")} /> Aktifkan SISTRA AGENT</label>
          </div>

          <div className="card stack" style={{ gap: 6 }}>
            <h2 style={{ margin: 0 }}>Batasan yang ditanamkan</h2>
            <ul className="small" style={{ margin: 0, paddingLeft: 18 }}>
              <li>Hasil SISTRA AGENT selalu berstatus draf dan wajib ditinjau serta disetujui manusia sebelum tayang.</li>
              <li>SISTRA AGENT hanya boleh memakai fakta dari brief; klaim lain ditandai <span className="mono">[DATA PERLU DIVERIFIKASI]</span>.</li>
              <li>Bahasa Indonesia baku (PUEBI), tanpa SARA, tanpa menyerang pribadi.</li>
              <li>Tidak membuat akun palsu, bot, atau konten untuk mengelabui deteksi platform. Balasan hanya lewat akun resmi oleh petugas.</li>
              <li>Setiap panggilan SISTRA AGENT dicatat (fitur, tingkat mesin, token, waktu) dan tampil di Dashboard Analitik.</li>
            </ul>
          </div>
        </>
      )}
    </div>
  );
}
