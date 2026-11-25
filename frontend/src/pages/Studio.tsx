import { useEffect, useMemo, useRef, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { api } from "../api";
import { useAuth } from "../auth";
import { ModeChip, useBusy } from "../components/kit";
import { Msg, useLoad } from "../components/ui";
import { STATUS_DRAFT, fmtDateTime } from "../format";
import type { Draft } from "../types";

interface Tpl { code: string; name: string; kind: string; version: number; locked: boolean; spec: { bg: string; fg: string; accent: string; font: string; logo_text: string; footer: string } }
interface Media { id: number; draft_id: number; kind: "infografis" | "video" | "podcast"; title: string; spec: any; template_code: string | null; ai_mode: string; asset_id: number | null; ms: number; created_at: string }
interface InfSpec { title: string; subtitle: string; stats: { value: string; label: string }[]; points: string[]; source: string; cta: string }
interface Scene { start: number; end: number; visual: string; narration: string; caption_id: string; caption_en: string }

const SIZES = { lanskap: { w: 1920, h: 1080, label: "Lanskap 16:9 · 3840×2160 (4K)" }, potret: { w: 1080, h: 1920, label: "Potret 9:16 · 2160×3840 (4K)" }, feed: { w: 1080, h: 1350, label: "Feed 4:5 · 2160×2700" } };
type SizeKey = keyof typeof SIZES;
const FALLBACK_TPL: Tpl["spec"] = { bg: "#10243F", fg: "#FFFFFF", accent: "#F2B705", font: "Arial, sans-serif", logo_text: "KANAL INFORMASI RESMI (contoh)", footer: "Sumber resmi" };

function wrap(text: string, max: number): string[] {
  const out: string[] = []; let cur = "";
  for (const w of (text || "").split(/\s+/)) { if ((cur + " " + w).trim().length > max && cur) { out.push(cur); cur = w; } else cur = `${cur} ${w}`.trim(); }
  if (cur) out.push(cur);
  return out;
}
function esc(s: string) { return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;"); }

/** S29: susun SVG infografis dari spesifikasi + templat. */
function infographicSvg(spec: InfSpec, t: Tpl["spec"], size: SizeKey): string {
  const { w, h } = SIZES[size]; const port = h > w; const pad = w * 0.06; const u = Math.min(w, h) / 100;
  const titleLines = wrap(spec.title, port ? 22 : 34).slice(0, 3);
  let y = pad + 7 * u;
  const parts: string[] = [`<rect width="${w}" height="${h}" fill="${t.bg}"/>`, `<rect x="0" y="0" width="${w}" height="${1.4 * u}" fill="${t.accent}"/>`,
    `<text x="${pad}" y="${pad + 2 * u}" font-size="${2.6 * u}" font-weight="700" letter-spacing="2" fill="${t.accent}">${esc(t.logo_text)}</text>`];
  titleLines.forEach((l, i) => parts.push(`<text x="${pad}" y="${y + 5 * u + i * 7.4 * u}" font-size="${6.4 * u}" font-weight="800" fill="${t.fg}">${esc(l)}</text>`));
  y += 5 * u + titleLines.length * 7.4 * u;
  wrap(spec.subtitle, port ? 40 : 70).slice(0, 2).forEach((l, i) => parts.push(`<text x="${pad}" y="${y + i * 3.6 * u}" font-size="${3 * u}" fill="${t.fg}" opacity=".8">${esc(l)}</text>`));
  y += 7 * u;
  const stats = spec.stats.slice(0, 4); const cols = port ? 2 : stats.length || 1; const cw = (w - pad * 2 - (cols - 1) * 2 * u) / cols; const ch = port ? 18 * u : 22 * u;
  stats.forEach((s, i) => {
    const cx = pad + (i % cols) * (cw + 2 * u); const cy = y + Math.floor(i / cols) * (ch + 2 * u);
    parts.push(`<rect x="${cx}" y="${cy}" width="${cw}" height="${ch}" rx="${1.6 * u}" fill="${t.fg}" fill-opacity=".08" stroke="${t.accent}" stroke-opacity=".6"/>`,
      `<text x="${cx + 2.4 * u}" y="${cy + ch * 0.46}" font-size="${(port ? 7 : 7.5) * u}" font-weight="800" fill="${t.accent}">${esc(s.value)}</text>`);
    wrap(s.label, Math.floor(cw / (1.55 * u))).slice(0, 2).forEach((l, k) => parts.push(`<text x="${cx + 2.4 * u}" y="${cy + ch * 0.68 + k * 3 * u}" font-size="${2.5 * u}" fill="${t.fg}">${esc(l)}</text>`));
  });
  y += Math.ceil(stats.length / cols) * (ch + 2 * u) + 3 * u;
  spec.points.slice(0, 4).forEach((p) => {
    const lines = wrap(p, port ? 44 : 90).slice(0, 2);
    parts.push(`<circle cx="${pad + 1 * u}" cy="${y - 0.9 * u}" r="${0.9 * u}" fill="${t.accent}"/>`);
    lines.forEach((l, k) => parts.push(`<text x="${pad + 3.4 * u}" y="${y + k * 3.4 * u}" font-size="${2.7 * u}" fill="${t.fg}">${esc(l)}</text>`));
    y += lines.length * 3.4 * u + 1.8 * u;
  });
  const ctaChars = Math.floor((w - pad * 2) / (1.75 * u)); const srcChars = Math.floor((w - pad * 2) / (1.15 * u));
  const src = `Sumber: ${spec.source} · ${t.footer}`;
  parts.push(`<rect x="0" y="${h - 13 * u}" width="${w}" height="${13 * u}" fill="${t.accent}"/>`,
    `<text x="${pad}" y="${h - 7.6 * u}" font-size="${3.1 * u}" font-weight="700" fill="${t.bg}">${esc(spec.cta.length > ctaChars ? spec.cta.slice(0, ctaChars - 1) + "…" : spec.cta)}</text>`,
    `<text x="${pad}" y="${h - 3.4 * u}" font-size="${2.1 * u}" fill="${t.bg}">${esc(src.length > srcChars ? src.slice(0, srcChars - 1) + "…" : src)}</text>`);
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${w * 2}" height="${h * 2}" viewBox="0 0 ${w} ${h}" font-family="${esc(t.font)}">${parts.join("")}</svg>`;
}
function download(name: string, data: BlobPart, type: string) {
  const url = URL.createObjectURL(new Blob([data], { type }));
  const a = document.createElement("a"); a.href = url; a.download = name; document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1500);
}
async function svgToPng(svg: string): Promise<Blob> {
  const img = new Image(); const url = URL.createObjectURL(new Blob([svg], { type: "image/svg+xml" }));
  await new Promise<void>((res, rej) => { img.onload = () => res(); img.onerror = () => rej(new Error("Gagal merender")); img.src = url; });
  const c = document.createElement("canvas"); c.width = img.naturalWidth || img.width; c.height = img.naturalHeight || img.height;
  c.getContext("2d")!.drawImage(img, 0, 0); URL.revokeObjectURL(url);
  return new Promise((res) => c.toBlob((b) => res(b!), "image/png"));
}
const ts = (s: number, sep: string) => { const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = Math.floor(s % 60), ms = Math.round((s % 1) * 1000);
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}:${String(x).padStart(2, "0")}${sep}${String(ms).padStart(3, "0")}`; };
function toSrt(sc: Scene[], lang: "id" | "en") { return sc.map((s, i) => `${i + 1}\n${ts(s.start, ",")} --> ${ts(s.end, ",")}\n${lang === "id" ? s.caption_id : s.caption_en}\n`).join("\n"); }
function toVtt(sc: Scene[], lang: "id" | "en") { return "WEBVTT\n\n" + sc.map((s) => `${ts(s.start, ".")} --> ${ts(s.end, ".")}\n${lang === "id" ? s.caption_id : s.caption_en}\n`).join("\n"); }

// ---------------------------------------------------------------- infografis
function InfographicEditor({ m, tpls, draftId, onSaved }: { m: Media; tpls: Tpl[]; draftId: number; onSaved: () => void }) {
  const [spec, setSpec] = useState<InfSpec>(m.spec);
  const [tpl, setTpl] = useState(m.template_code ?? "INF-STD");
  const [size, setSize] = useState<SizeKey>("feed");
  const [busy, run] = useBusy();
  const [msg, setMsg] = useState<{ kind: "ok" | "error"; text: string } | null>(null);
  const t = tpls.find((x) => x.code === tpl)?.spec ?? FALLBACK_TPL;
  const svg = useMemo(() => infographicSvg(spec, t, size), [spec, t, size]);
  const src = useMemo(() => "data:image/svg+xml;charset=utf-8," + encodeURIComponent(svg), [svg]);
  const setStat = (i: number, k: "value" | "label", v: string) => setSpec({ ...spec, stats: spec.stats.map((s, j) => (j === i ? { ...s, [k]: v } : s)) });
  return (
    <div className="split">
      <div className="stack" style={{ gap: 8 }}>
        <img src={src} alt={`Pratinjau infografis: ${spec.title}`} style={{ width: "100%", borderRadius: 8, border: "1px solid var(--border)" }} />
        <div className="row">
          <select value={size} onChange={(e) => setSize(e.target.value as SizeKey)} aria-label="Ukuran">{Object.entries(SIZES).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}</select>
          <select value={tpl} onChange={(e) => setTpl(e.target.value)} aria-label="Templat">{tpls.filter((x) => x.kind === "infografis").map((x) => <option key={x.code} value={x.code}>{x.name} v{x.version}</option>)}</select>
        </div>
        <div className="row">
          <button className="sm" onClick={() => download(`infografis-${m.id}.svg`, svg, "image/svg+xml")}>Unduh SVG</button>
          <button className="sm" disabled={!!busy} onClick={() => run("png", async () => download(`infografis-${m.id}-${size}.png`, await svgToPng(svg), "image/png"))}>{busy === "png" ? "Merender…" : "Unduh PNG 4K"}</button>
          <button className="sm primary" disabled={!!busy} onClick={() => run("sv", async () => {
            try {
              await api(`/m4/media/${m.id}`, { method: "PATCH", body: { spec, template_code: tpl } });
              const fd = new FormData(); fd.append("file", await svgToPng(svg), `infografis-${m.id}.png`);
              fd.append("title", `Infografis: ${spec.title}`); fd.append("license", "milik_sendiri"); fd.append("credit", "Tim produksi (dibantu SISTRA AGENT)");
              fd.append("draft_id", String(draftId)); fd.append("media_id", String(m.id)); fd.append("tags", "infografis");
              await api("/m5/assets", { method: "POST", form: fd });
              setMsg({ kind: "ok", text: "Tersimpan dan dimasukkan ke Bank Aset (tertaut ke naskah)." }); onSaved();
            } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); }
          })}>{busy === "sv" ? "Menyimpan…" : "Simpan & masukkan ke Bank Aset"}</button>
        </div>
        {msg && <Msg kind={msg.kind}>{msg.text}</Msg>}
      </div>
      <div className="stack" style={{ gap: 8 }}>
        <label className="field"><span>Judul</span><input value={spec.title} onChange={(e) => setSpec({ ...spec, title: e.target.value })} /></label>
        <label className="field"><span>Subjudul</span><input value={spec.subtitle} onChange={(e) => setSpec({ ...spec, subtitle: e.target.value })} /></label>
        <div className="field"><span>Angka kunci (hanya dari fakta bersumber)</span>
          {spec.stats.map((s, i) => (
            <div className="row" key={i}><input style={{ width: 120 }} value={s.value} onChange={(e) => setStat(i, "value", e.target.value)} aria-label={`Nilai ${i + 1}`} />
              <input style={{ flex: 1 }} value={s.label} onChange={(e) => setStat(i, "label", e.target.value)} aria-label={`Label ${i + 1}`} />
              <button className="sm ghost" onClick={() => setSpec({ ...spec, stats: spec.stats.filter((_, j) => j !== i) })} aria-label="Hapus">✕</button></div>
          ))}
          {spec.stats.length < 4 && <button className="sm" onClick={() => setSpec({ ...spec, stats: [...spec.stats, { value: "", label: "" }] })}>+ Angka</button>}
        </div>
        <label className="field"><span>Poin penjelas (satu per baris)</span><textarea style={{ minHeight: 90 }} value={spec.points.join("\n")} onChange={(e) => setSpec({ ...spec, points: e.target.value.split("\n") })} /></label>
        <label className="field"><span>Sumber</span><input value={spec.source} onChange={(e) => setSpec({ ...spec, source: e.target.value })} /></label>
        <label className="field"><span>Ajakan</span><input value={spec.cta} onChange={(e) => setSpec({ ...spec, cta: e.target.value })} /></label>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------- video
function VideoStudio({ m, tpls }: { m: Media; tpls: Tpl[] }) {
  const spec = m.spec as { title: string; aspect: string; duration: number; scenes: Scene[]; music: string };
  const t = tpls.find((x) => x.code === (m.template_code ?? "VID-STD"))?.spec ?? FALLBACK_TPL;
  const port = spec.aspect === "9:16"; const W = port ? 540 : 960, H = port ? 960 : 540;
  const canvas = useRef<HTMLCanvasElement>(null);
  const [time, setTime] = useState(0);
  const [playing, setPlaying] = useState(false);
  const [lang, setLang] = useState<"id" | "en">("id");
  const [rec, setRec] = useState(false);
  const raf = useRef(0); const t0 = useRef(0);
  const dur = spec.duration || spec.scenes[spec.scenes.length - 1]?.end || 30;

  const draw = (tt: number) => {
    const c = canvas.current; if (!c) return; const g = c.getContext("2d")!;
    const sc = spec.scenes.find((s) => tt >= s.start && tt < s.end) ?? spec.scenes[spec.scenes.length - 1];
    const k = sc ? Math.min(1, (tt - sc.start) / 0.6) : 1;
    g.fillStyle = t.bg; g.fillRect(0, 0, W, H);
    g.fillStyle = t.accent; g.globalAlpha = 0.18; g.beginPath(); g.arc(W * 0.85, H * 0.18, W * 0.22 + tt * 2, 0, Math.PI * 2); g.fill(); g.globalAlpha = 1;
    g.fillStyle = t.accent; g.font = `700 ${W * 0.03}px Arial`; g.fillText(t.logo_text, W * 0.06, H * 0.07);
    if (sc) {
      g.globalAlpha = k; g.fillStyle = t.fg; g.font = `800 ${W * (port ? 0.075 : 0.05)}px Arial`;
      wrap(sc.narration, port ? 18 : 34).slice(0, 5).forEach((l, i) => g.fillText(l, W * 0.07, H * (port ? 0.3 : 0.32) + i * W * (port ? 0.09 : 0.062) + (1 - k) * 20));
      g.globalAlpha = 1; g.font = `${W * 0.026}px Arial`; g.fillStyle = t.accent; g.fillText(`🎬 ${sc.visual}`.slice(0, 70), W * 0.07, H * 0.86);
      const cap = lang === "id" ? sc.caption_id : sc.caption_en;
      g.font = `600 ${W * 0.034}px Arial`;
      const lines = wrap(cap, port ? 30 : 60).slice(0, 2);
      lines.forEach((l, i) => { const tw = g.measureText(l).width; g.fillStyle = "rgba(0,0,0,.65)"; g.fillRect((W - tw) / 2 - 8, H * 0.9 + i * W * 0.045 - W * 0.034, tw + 16, W * 0.045); g.fillStyle = "#fff"; g.fillText(l, (W - tw) / 2, H * 0.9 + i * W * 0.045); });
    }
    g.fillStyle = t.accent; g.fillRect(0, H - 6, (tt / dur) * W, 6);
  };
  useEffect(() => { draw(time); });
  const tick = (now: number) => {
    const tt = (now - t0.current) / 1000;
    if (tt >= dur) { setPlaying(false); setTime(dur); draw(dur); return; }
    setTime(tt); draw(tt); raf.current = requestAnimationFrame(tick);
  };
  const play = () => { t0.current = performance.now() - (time >= dur ? 0 : time) * 1000; if (time >= dur) setTime(0); setPlaying(true); raf.current = requestAnimationFrame(tick); };
  const pause = () => { cancelAnimationFrame(raf.current); setPlaying(false); };
  useEffect(() => () => cancelAnimationFrame(raf.current), []);
  const record = () => {
    const c = canvas.current; if (!c || !("MediaRecorder" in window)) return;
    const stream = c.captureStream(30); const mr = new MediaRecorder(stream, { mimeType: MediaRecorder.isTypeSupported("video/webm;codecs=vp9") ? "video/webm;codecs=vp9" : "video/webm" });
    const chunks: Blob[] = []; mr.ondataavailable = (e) => chunks.push(e.data);
    mr.onstop = () => { download(`video-${m.id}-${spec.aspect.replace(":", "x")}.webm`, new Blob(chunks, { type: "video/webm" }), "video/webm"); setRec(false); };
    setRec(true); setTime(0); t0.current = performance.now(); setPlaying(true); mr.start();
    const loop = (now: number) => { const tt = (now - t0.current) / 1000; draw(Math.min(tt, dur)); setTime(tt); if (tt < dur) requestAnimationFrame(loop); else { setPlaying(false); mr.stop(); } };
    requestAnimationFrame(loop);
  };
  return (
    <div className="split">
      <div className="stack" style={{ gap: 8, alignItems: "center" }}>
        <canvas ref={canvas} width={W} height={H} className="studio-canvas" style={{ maxWidth: port ? 320 : "100%" }} aria-label="Pratinjau video" />
        <div className="row">
          {playing ? <button className="sm" onClick={pause} disabled={rec}>⏸ Jeda</button> : <button className="sm primary" onClick={play}>▶ Putar</button>}
          <span className="small mono">{time.toFixed(1)} / {dur}s</span>
          <select value={lang} onChange={(e) => setLang(e.target.value as "id" | "en")} aria-label="Bahasa takarir"><option value="id">Takarir ID</option><option value="en">Takarir EN</option></select>
        </div>
        <div className="row">
          <button className="sm" onClick={() => download(`takarir-${m.id}-id.srt`, toSrt(spec.scenes, "id"), "text/plain")}>.srt ID</button>
          <button className="sm" onClick={() => download(`takarir-${m.id}-en.srt`, toSrt(spec.scenes, "en"), "text/plain")}>.srt EN</button>
          <button className="sm" onClick={() => download(`takarir-${m.id}-id.vtt`, toVtt(spec.scenes, "id"), "text/vtt")}>.vtt ID</button>
          <button className="sm" onClick={() => download(`takarir-${m.id}-en.vtt`, toVtt(spec.scenes, "en"), "text/vtt")}>.vtt EN</button>
          <button className="sm" disabled={rec || playing} onClick={record}>{rec ? "Merekam…" : "⏺ Rekam WebM"}</button>
        </div>
        <div className="small muted">Render final MP4 H.264/HEVC dikerjakan server render GPU pada fase produksi (F3); purwarupa merekam WebM di peramban.</div>
      </div>
      <div className="table-wrap">
        <table>
          <thead><tr><th>Waktu</th><th>Visual & narasi</th><th>Takarir ID / EN</th></tr></thead>
          <tbody>{spec.scenes.map((s, i) => (
            <tr key={i} className={time >= s.start && time < s.end ? "selected" : ""}>
              <td className="small mono">{s.start}–{s.end}s</td>
              <td className="small"><b>{s.visual}</b><div>{s.narration}</div></td>
              <td className="small">{s.caption_id}<div className="muted">{s.caption_en}</div></td>
            </tr>
          ))}</tbody>
        </table>
        <div className="small muted" style={{ marginTop: 6 }}>Musik: {spec.music}</div>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------- podcast
function PodcastStudio({ m }: { m: Media }) {
  const spec = m.spec as { title: string; minutes: number; segments: { speaker: string; text: string }[] };
  const [speaking, setSpeaking] = useState(-1);
  const voices = typeof window !== "undefined" && "speechSynthesis" in window;
  const words = spec.segments.reduce((a, s) => a + s.text.split(/\s+/).length, 0);
  const speak = (from: number) => {
    if (!voices) return; window.speechSynthesis.cancel();
    const id = window.speechSynthesis.getVoices().filter((v) => v.lang.toLowerCase().startsWith("id"));
    spec.segments.slice(from).forEach((s, k) => {
      const u = new SpeechSynthesisUtterance(s.text); u.lang = "id-ID"; if (id.length) u.voice = id[(from + k) % id.length];
      u.pitch = s.speaker === "Narasumber" ? 0.85 : 1.05; u.onstart = () => setSpeaking(from + k); u.onend = () => setSpeaking((x) => (x === from + k ? -1 : x));
      window.speechSynthesis.speak(u);
    });
  };
  useEffect(() => () => { if (voices) window.speechSynthesis.cancel(); }, [voices]);
  return (
    <div className="stack" style={{ gap: 8 }}>
      <div className="row">
        <button className="sm primary" disabled={!voices} onClick={() => speak(0)}>▶ Dengarkan (TTS peramban)</button>
        <button className="sm" disabled={!voices} onClick={() => { window.speechSynthesis.cancel(); setSpeaking(-1); }}>■ Hentikan</button>
        <button className="sm" onClick={() => download(`podcast-${m.id}.txt`, `${spec.title}\n\n` + spec.segments.map((s) => `${s.speaker.toUpperCase()}:\n${s.text}`).join("\n\n"), "text/plain")}>Unduh naskah</button>
        <span className="small muted">±{Math.max(1, Math.round(words / 130))} menit · {words} kata</span>
      </div>
      {!voices && <Msg kind="warn">Peramban ini tidak mendukung sintesis suara.</Msg>}
      {spec.segments.map((s, i) => (
        <div key={i} className={`claim ${speaking === i ? "ok" : ""}`}><b>{s.speaker}</b><div>{s.text}</div>
          {voices && <button className="sm ghost" onClick={() => speak(i)}>▶ dari sini</button>}</div>
      ))}
      <div className="small muted">Produksi MP3/WAV dengan TTS Bahasa Indonesia ber-MOS ≥ 4,0 dilakukan mesin TTS on-premise pada fase F3.</div>
    </div>
  );
}

// ---------------------------------------------------------------- halaman
export default function StudioPage() {
  const { hasRole } = useAuth();
  const [params, setParams] = useSearchParams();
  const drafts = useLoad(() => api<Draft[]>("/drafts"), []);
  const tpls = useLoad(() => api<Tpl[]>("/m5/templates"), []);
  const [draftId, setDraftId] = useState<number>(Number(params.get("draft")) || 0);
  const media = useLoad(() => (draftId ? api<Media[]>("/m4/media", { query: { draft_id: draftId } }) : api<Media[]>("/m4/media")), [draftId]);
  const [sel, setSel] = useState<number | null>(null);
  const [opt, setOpt] = useState({ aspect: "9:16", seconds: 45, minutes: 2, template: "INF-STD" });
  const [busy, run] = useBusy();
  const [err, setErr] = useState("");
  const isAuthor = hasRole("redaktur", "produser", "analis");
  const usable = (drafts.data ?? []).filter((d) => d.status !== "ditarik");
  useEffect(() => { if (!draftId && usable.length) setDraftId(usable.find((d) => d.status === "tayang")?.id ?? usable[0].id); }, [drafts.data]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { if (draftId) setParams({ draft: String(draftId) }, { replace: true }); setSel(null); }, [draftId]); // eslint-disable-line react-hooks/exhaustive-deps
  const current = media.data?.find((m) => m.id === sel) ?? media.data?.[0] ?? null;
  const gen = (kind: string) => run(kind, async () => {
    setErr("");
    try {
      const m = await api<Media>(`/m4/drafts/${draftId}/media`, { method: "POST", body: { kind, aspect: opt.aspect, seconds: opt.seconds, minutes: opt.minutes, template_code: kind === "video" ? "VID-STD" : opt.template } });
      media.reload(); setSel(m.id);
    } catch (e) { setErr((e as Error).message); }
  });
  const d = usable.find((x) => x.id === draftId);
  return (
    <div className="stack">
      <div>
        <h1>Studio Multimedia <span className="muted" style={{ fontWeight: 400 }}>· M4 S29–S31</span></h1>
        <p className="sub" style={{ marginBottom: 0 }}>Ubah naskah menjadi infografis 4K, video pendek bertakarir dwibahasa, dan naskah audio — memakai templat identitas visual berversi</p>
      </div>
      <div className="card stack" style={{ gap: 10 }}>
        <div className="row">
          <label className="field" style={{ flex: 1, minWidth: 260 }}><span>Naskah sumber</span>
            <select value={draftId} onChange={(e) => setDraftId(Number(e.target.value))}>{usable.map((x) => <option key={x.id} value={x.id}>#{x.id} {x.title} · {STATUS_DRAFT[x.status]}</option>)}</select></label>
          {d && <span className="small muted">{d.ai_generated ? "Dibantu SISTRA AGENT · " : ""}{d.author_name}</span>}
        </div>
        {isAuthor && (
          <div className="row" style={{ gap: 14 }}>
            <div className="row"><select value={opt.template} onChange={(e) => setOpt({ ...opt, template: e.target.value })} aria-label="Templat infografis">
              {(tpls.data ?? []).filter((t) => t.kind === "infografis").map((t) => <option key={t.code} value={t.code}>{t.name}</option>)}</select>
              <button className="primary" disabled={!draftId || !!busy} onClick={() => gen("infografis")}>{busy === "infografis" ? "Menyusun…" : "✦ Infografis"}</button></div>
            <div className="row"><select value={opt.aspect} onChange={(e) => setOpt({ ...opt, aspect: e.target.value })} aria-label="Rasio video"><option>9:16</option><option>16:9</option></select>
              <select value={opt.seconds} onChange={(e) => setOpt({ ...opt, seconds: Number(e.target.value) })} aria-label="Durasi">{[15, 30, 45, 60].map((s) => <option key={s} value={s}>{s} detik</option>)}</select>
              <button className="primary" disabled={!draftId || !!busy} onClick={() => gen("video")}>{busy === "video" ? "Menyusun…" : "✦ Video pendek"}</button></div>
            <div className="row"><select value={opt.minutes} onChange={(e) => setOpt({ ...opt, minutes: Number(e.target.value) })} aria-label="Durasi audio">{[1, 2, 3, 5].map((s) => <option key={s} value={s}>{s} menit</option>)}</select>
              <button className="primary" disabled={!draftId || !!busy} onClick={() => gen("podcast")}>{busy === "podcast" ? "Menyusun…" : "✦ Naskah audio"}</button></div>
          </div>
        )}
        {err && <Msg kind="error">{err}</Msg>}
      </div>
      <div className="subtabs">
        {media.data?.map((m) => (
          <button key={m.id} className={current?.id === m.id ? "on" : ""} onClick={() => setSel(m.id)}>{m.kind === "infografis" ? "🖼" : m.kind === "video" ? "🎬" : "🎙"} {m.kind} #{m.id} · {fmtDateTime(m.created_at)}</button>
        ))}
        {!media.data?.length && <span className="small muted">Belum ada keluaran multimedia untuk naskah ini.</span>}
      </div>
      {current && (
        <div className="card stack" style={{ gap: 10 }}>
          <div className="row between"><h2 style={{ margin: 0 }}>{current.title}</h2><span className="row"><ModeChip mode={current.ai_mode} /><span className="small muted">disusun dalam {(current.ms / 1000).toFixed(1)} dtk</span></span></div>
          {current.kind === "infografis" && <InfographicEditor key={current.id} m={current} tpls={tpls.data ?? []} draftId={current.draft_id} onSaved={media.reload} />}
          {current.kind === "video" && <VideoStudio key={current.id} m={current} tpls={tpls.data ?? []} />}
          {current.kind === "podcast" && <PodcastStudio key={current.id} m={current} />}
        </div>
      )}
    </div>
  );
}
