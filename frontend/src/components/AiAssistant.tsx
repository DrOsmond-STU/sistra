import { useEffect, useRef, useState } from "react";
import { useLocation } from "react-router-dom";
import { api } from "../api";
import { ModeChip } from "./kit";

type Msg = { role: "user" | "assistant"; content: string };

const SUGGEST: Record<string, string[]> = {
  "/": ["Apa yang perlu saya putuskan hari ini?", "Risiko terbesar minggu ini?", "Kanal mana yang bermasalah?"],
  "/analitik": ["Tahap persetujuan mana yang paling lambat?", "Format apa yang paling banyak diproduksi?"],
  "/produksi": ["Beri ide 3 sudut pandang untuk isu hoaks bansos", "Bagaimana menulis caption yang empatik untuk korban banjir?"],
  "/editorial": ["Naskah mana yang mendesak disetujui?", "Apa saja yang harus dicek sebelum menyetujui naskah?"],
  "/kanal-resmi": ["Bagaimana menanggapi serangan komentar bot?", "Susun pedoman membalas komentar keluhan"],
  "/distribusi": ["Apa yang harus dilakukan bila kanal provinsi gangguan?", "Berapa kanal yang sehat sekarang?"],
};
const PAGE_NAME: Record<string, string> = {
  "/": "dashboard-eksekutif", "/analitik": "dashboard-analitik", "/produksi": "m4-produksi", "/editorial": "m5-editorial",
  "/kanal-resmi": "m6-kanal-resmi", "/distribusi": "m7-distribusi", "/kalender": "kalender", "/naskah": "naskah",
};
const STORE = "sistra.ai.chat";

export default function AiAssistant() {
  const loc = useLocation();
  const [open, setOpen] = useState(false);
  const [msgs, setMsgs] = useState<Msg[]>(() => {
    try { return JSON.parse(sessionStorage.getItem(STORE) ?? "[]") as Msg[]; } catch { return []; }
  });
  const [text, setText] = useState("");
  const [busy, setBusy] = useState(false);
  const [mode, setMode] = useState<string | null>(null);
  const [err, setErr] = useState("");
  const logRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    try { sessionStorage.setItem(STORE, JSON.stringify(msgs.slice(-20))); } catch { /* abaikan */ }
    logRef.current?.scrollTo({ top: logRef.current.scrollHeight, behavior: "smooth" });
  }, [msgs, open]);
  useEffect(() => {
    if (open && !mode) api<{ mode: string }>("/ai/status").then((s) => setMode(s.mode)).catch(() => undefined);
  }, [open, mode]);

  const send = async (q: string) => {
    const content = q.trim();
    if (!content || busy) return;
    const next = [...msgs, { role: "user" as const, content }];
    setMsgs(next); setText(""); setBusy(true); setErr("");
    try {
      const r = await api<{ reply: string; mode: string }>("/ai/chat", { method: "POST", body: { page: PAGE_NAME[loc.pathname] ?? loc.pathname, messages: next.slice(-12) } });
      setMode(r.mode);
      setMsgs([...next, { role: "assistant", content: r.reply }]);
    } catch (e) {
      setErr((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  if (!open) return <button className="ai-fab" onClick={() => setOpen(true)} aria-label="Buka SISTRA AGENT">✦ SISTRA AGENT</button>;
  const sugg = SUGGEST[loc.pathname] ?? SUGGEST["/"];
  return (
    <section className="ai-panel" aria-label="SISTRA AGENT">
      <div className="ai-head">
        <div>
          <b>✦ SISTRA AGENT</b>
          <div className="small muted">Mengetahui ringkasan data produksi, persetujuan, kanal, dan komentar</div>
        </div>
        <div className="row" style={{ gap: 6 }}>
          <ModeChip mode={mode} />
          {msgs.length > 0 && <button className="sm ghost" onClick={() => setMsgs([])} title="Mulai percakapan baru">Baru</button>}
          <button className="sm ghost" onClick={() => setOpen(false)} aria-label="Tutup asisten">✕</button>
        </div>
      </div>
      <div className="ai-log" ref={logRef} aria-live="polite">
        {msgs.length === 0 && (
          <div className="stack" style={{ gap: 8 }}>
            <div className="small muted">Tanyakan kondisi terkini, minta ide konten, atau minta saran langkah. Jawaban SISTRA AGENT adalah bahan pertimbangan, bukan keputusan.</div>
            <div className="ai-sugg">{sugg.map((s) => <button key={s} onClick={() => send(s)}>{s}</button>)}</div>
          </div>
        )}
        {msgs.map((m, i) => <div key={i} className={`ai-msg ${m.role}`}>{m.content}</div>)}
        {busy && <div className="ai-msg assistant muted">Sedang berpikir…</div>}
        {err && <div className="alert-msg error" role="alert">{err}</div>}
      </div>
      <form className="ai-input" onSubmit={(e) => { e.preventDefault(); send(text); }}>
        <textarea value={text} onChange={(e) => setText(e.target.value)} placeholder="Tulis pertanyaan…" aria-label="Pertanyaan untuk asisten"
                  onKeyDown={(e) => { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); send(text); } }} />
        <button className="primary" disabled={busy || !text.trim()}>Kirim</button>
      </form>
    </section>
  );
}
