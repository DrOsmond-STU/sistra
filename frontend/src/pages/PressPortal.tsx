import { useEffect, useState } from "react";

interface Pack { partner: string; title: string; note: string; expires_at: string; downloads: number; article: { title: string; body: string; ai_label: string | null; content_hash: string };
  assets: { title: string; mime: string; size: number; credit: string; license: string }[] }

/** S53: portal unduhan berizin untuk media konvensional — tanpa akun, tautan berwaktu milik penerima. */
export default function PressPortal({ token }: { token: string }) {
  const [p, setP] = useState<Pack | null>(null);
  const [err, setErr] = useState("");
  const [done, setDone] = useState("");
  useEffect(() => {
    fetch(`/api/press/${encodeURIComponent(token)}`).then(async (r) => { const j = await r.json(); if (!r.ok) throw new Error(j.detail ?? "Tautan tidak valid"); setP(j); }).catch((e: Error) => setErr(e.message));
  }, [token]);
  const download = async () => {
    const r = await fetch(`/api/press/${encodeURIComponent(token)}/download`, { method: "POST" });
    const j = await r.json(); if (!r.ok) { setErr(j.detail ?? "Gagal mengunduh"); return; }
    const url = URL.createObjectURL(new Blob([j.content], { type: "text/plain;charset=utf-8" }));
    const a = document.createElement("a"); a.href = url; a.download = j.filename; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
    setDone("Unduhan tercatat atas nama media Anda.");
  };
  return (
    <div className="login-wrap" style={{ alignItems: "start", paddingTop: 40 }}>
      <div className="card stack" style={{ width: "100%", maxWidth: 760 }}>
        <div><div className="brand" style={{ color: "var(--text)", padding: 0 }}>Portal Media · Kanal Informasi Resmi (contoh)</div>
          <div className="small muted">Unduhan berizin untuk media cetak, TV, dan radio. Tautan ini khusus untuk penerima tertentu dan dicatat.</div></div>
        {err && <div className="alert-msg error" role="alert">{err}</div>}
        {p && (
          <>
            <div className="small">Penerima: <b>{p.partner}</b> · berlaku s.d. {new Date(p.expires_at).toLocaleString("id-ID")} · {p.downloads} unduhan sebelumnya</div>
            {p.note && <div className="alert-msg">{p.note}</div>}
            <h2 style={{ margin: 0 }}>{p.article.title}</h2>
            <div className="body-text" style={{ maxHeight: 420 }}>{p.article.body}</div>
            {p.article.ai_label && <div className="small muted">ℹ️ {p.article.ai_label}</div>}
            <div className="small mono muted">SHA-256: {p.article.content_hash}</div>
            {!!p.assets.length && <div><b>Aset pendukung</b>{p.assets.map((a) => <div key={a.title} className="small">• {a.title} — {a.license} · {a.credit}</div>)}</div>}
            <div className="row"><button className="primary" onClick={download}>Unduh naskah</button>{done && <span className="small status-ok">{done}</span>}</div>
          </>
        )}
      </div>
    </div>
  );
}
