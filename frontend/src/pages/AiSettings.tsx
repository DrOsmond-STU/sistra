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
        setMsg({ kind: "warn", text: `Memasang pustaka Claude… ${r.packages_present ?? 0}/${r.packages_total ?? 15} paket` });
        r = await api<typeof r>("/ai/library/install", { method: "POST" });
      }
      st.reload();
      setMsg(r.status === "lanjutkan" ? { kind: "warn", text: "Pemasangan belum selesai; klik lagi untuk melanjutkan." } : { kind: "ok", text: "Pustaka Claude terpasang." });
    } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); }
  });
  const test = () => run("ts", async () => {
    try {
      const r = await api<{ model: string; reply: string }>("/ai/test", { method: "POST" });
      setMsg({ kind: "ok", text: `Terhubung ke ${r.model}. Balasan: “${r.reply}”` });
    } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); }
  });

  return (
    <div className="stack" style={{ maxWidth: 760 }}>
      <div>
        <h1>Pengaturan AI</h1>
        <p className="sub" style={{ marginBottom: 0 }}>Hubungkan SISTRA dengan Claude (Anthropic) untuk produksi konten, cek editorial, triase komentar, dan asisten</p>
      </div>
      {st.error && <Msg kind="error">{st.error}</Msg>}
      {msg && <Msg kind={msg.kind}>{msg.text}</Msg>}
      {s && (
        <>
          <div className="card stack" style={{ gap: 10 }}>
            <div className="row between"><h2 style={{ margin: 0 }}>Status</h2><ModeChip mode={s.mode} /></div>
            <dl className="kv">
              <dt>Kunci API</dt><dd>{s.configured ? "Terpasang (tersembunyi)" : "Belum diisi"}</dd>
              <dt>AI aktif</dt><dd>{s.enabled ? "Ya" : "Dinonaktifkan"}</dd>
              <dt>Model</dt><dd>{s.model_label} <span className="mono muted">{s.model}</span></dd>
              <dt>Pustaka Claude (SDK)</dt><dd>{s.library ? "Terpasang" : "Belum terpasang"}</dd>
            </dl>
            {!s.library && (
              <div className="row">
                <button className="primary" disabled={!!busy} onClick={install}>{busy === "in" ? "Memasang…" : "Pasang pustaka Claude"}</button>
                <span className="small muted">Server mengunduh Anthropic PHP SDK dari GitHub pada versi yang dikunci (±1 menit).</span>
              </div>
            )}
            {s.mode === "simulasi" && <div className="small muted">Tanpa kunci API dan pustaka, semua fitur AI tetap berjalan dalam mode simulasi (templat & aturan sederhana) agar alur kerja bisa dicoba.</div>}
          </div>

          <form className="card stack" style={{ gap: 10 }} onSubmit={submitKey}>
            <h2 style={{ margin: 0 }}>Kunci API Anthropic</h2>
            <div className="small muted">Kunci disimpan di server (berkas berizin 0600), tidak pernah dikirim balik ke peramban, dan setiap perubahan tercatat di jejak audit. Jangan bagikan kunci lewat surel atau obrolan.</div>
            <input type="password" autoComplete="off" value={key} onChange={(e) => setKey(e.target.value)} placeholder="sk-ant-…" aria-label="Kunci API" />
            <div className="row">
              <button className="primary" disabled={!!busy || !key.trim()}>Simpan kunci</button>
              {s.configured && <button type="button" className="danger" disabled={!!busy} onClick={() => save({ api_key: "" }, "Kunci API dihapus. AI kembali ke mode simulasi.")}>Hapus kunci</button>}
              {s.configured && <button type="button" disabled={!!busy} onClick={test}>{busy === "ts" ? "Menguji…" : "Uji koneksi"}</button>}
            </div>
          </form>

          <div className="card stack" style={{ gap: 10 }}>
            <h2 style={{ margin: 0 }}>Model & status</h2>
            <label className="field"><span>Model</span>
              <select value={s.model} disabled={!!busy} onChange={(e) => save({ model: e.target.value }, "Model diperbarui.")}>
                {s.models.map((m) => <option key={m.id} value={m.id}>{m.label}</option>)}
              </select></label>
            <label className="row small"><input type="checkbox" checked={s.enabled} disabled={!!busy} onChange={(e) => save({ enabled: e.target.checked }, e.target.checked ? "AI diaktifkan." : "AI dinonaktifkan; fitur memakai mode simulasi.")} /> Aktifkan AI</label>
          </div>

          <div className="card stack" style={{ gap: 6 }}>
            <h2 style={{ margin: 0 }}>Batasan yang ditanamkan</h2>
            <ul className="small" style={{ margin: 0, paddingLeft: 18 }}>
              <li>Hasil AI selalu berstatus draf dan wajib ditinjau serta disetujui manusia sebelum tayang.</li>
              <li>AI hanya boleh memakai fakta dari brief; klaim lain ditandai <span className="mono">[DATA PERLU DIVERIFIKASI]</span>.</li>
              <li>Bahasa Indonesia baku (PUEBI), tanpa SARA, tanpa menyerang pribadi.</li>
              <li>Tidak membuat akun palsu, bot, atau konten untuk mengelabui deteksi platform. Balasan hanya lewat akun resmi oleh petugas.</li>
              <li>Setiap panggilan AI dicatat (fitur, model, token, waktu) dan tampil di Dashboard Analitik.</li>
            </ul>
          </div>
        </>
      )}
    </div>
  );
}
