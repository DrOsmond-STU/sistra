/** S33: pratinjau naskah per platform tujuan, lengkap dengan batas karakter dan label konten SISTRA AGENT. */

const PLATFORMS: { id: string; label: string; limit: number; note: string }[] = [
  { id: "web", label: "Portal web (kartu OpenGraph)", limit: 160, note: "Deskripsi kartu dipotong 160 karakter" },
  { id: "instagram", label: "Instagram", limit: 2200, note: "Caption maks. 2.200 karakter" },
  { id: "x", label: "X (utas)", limit: 280, note: "Dipecah per 280 karakter" },
  { id: "facebook", label: "Facebook", limit: 5000, note: "Disarankan ≤ 500 karakter di atas lipatan" },
  { id: "tiktok", label: "TikTok", limit: 2200, note: "Caption maks. 2.200 karakter" },
  { id: "threads", label: "Threads", limit: 500, note: "Maks. 500 karakter per unggahan" },
  { id: "youtube", label: "YouTube (deskripsi)", limit: 5000, note: "Maks. 5.000 karakter" },
  { id: "whatsapp", label: "Saluran WhatsApp", limit: 1024, note: "Disarankan ≤ 1.024 karakter" },
];

function splitThread(text: string, size = 270): string[] {
  const words = text.replace(/\s+/g, " ").trim().split(" ");
  const out: string[] = []; let cur = "";
  for (const w of words) {
    if ((cur + " " + w).trim().length > size) { out.push(cur.trim()); cur = w; } else cur = `${cur} ${w}`;
  }
  if (cur.trim()) out.push(cur.trim());
  return out.map((p, i) => `${i + 1}/${out.length} ${p}`);
}

export default function PlatformPreview({ title, body, hashtags = [], aiLabel }: { title: string; body: string; hashtags?: string[]; aiLabel?: string | null }) {
  const plain = body.replace(/\[F\d+\]/g, "").replace(/\[SIMULASI[^\]]*\]/g, "").trim();
  const tags = hashtags.length ? `\n\n${hashtags.map((h) => (h.startsWith("#") ? h : `#${h}`)).join(" ")}` : "";
  const label = aiLabel ? `\n\nℹ️ ${aiLabel}` : "";
  return (
    <div className="preview-grid">
      {PLATFORMS.map((p) => {
        let text = p.id === "web" ? plain.slice(0, 157) + (plain.length > 157 ? "…" : "") : `${title}\n\n${plain}${tags}${label}`;
        const parts = p.id === "x" ? splitThread(`${title}. ${plain}${aiLabel ? ` (${aiLabel})` : ""}`) : null;
        if (parts) text = parts.slice(0, 4).join("\n\n") + (parts.length > 4 ? `\n\n… (+${parts.length - 4} bagian)` : "");
        const len = p.id === "x" ? Math.max(...(parts ?? [""]).map((x) => x.length)) : text.length;
        const over = len > p.limit;
        return (
          <div className="pv" key={p.id}>
            <div className="pv-head"><span>{p.label}</span><span className={over ? "status-bad" : "muted"}>{len.toLocaleString("id-ID")}/{p.limit.toLocaleString("id-ID")}</span></div>
            {p.id === "web" ? (
              <div className="pv-body"><div style={{ height: 70, background: "linear-gradient(135deg,#10243f,#1c5cab)", borderRadius: 6, marginBottom: 6 }} />
                <b>{title}</b><div className="muted">{text}</div><div className="small muted">contoh.invalid</div></div>
            ) : <div className="pv-body">{text}</div>}
            <div className="pv-foot">{over ? <span className="status-bad">Melebihi batas — perlu dipersingkat. </span> : null}{p.note}{p.id === "x" && parts ? ` · ${parts.length} bagian` : ""}</div>
          </div>
        );
      })}
    </div>
  );
}
