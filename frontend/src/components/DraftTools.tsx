import { useState } from "react";
import { Link } from "react-router-dom";
import { api } from "../api";
import { useAuth } from "../auth";
import type { Draft } from "../types";
import type { EditorialResult } from "../types47";
import { EditorialReport, ModeChip, useBusy } from "./kit";
import { Msg, useLoad } from "./ui";

const REWRITES = [
  "Persingkat menjadi maksimal 80 kata",
  "Ubah nada menjadi lebih empatik",
  "Sederhanakan bahasa untuk pembaca umum",
  "Jadikan utas 5 bagian untuk X/Threads",
  "Ubah menjadi naskah video 45 detik dengan penanda adegan",
];

export type DraftWithEditorial = Draft & {
  editorial?: EditorialResult | null; format?: string; brief_id?: number | null;
  assignee_id?: number | null; due_at?: string | null; priority?: string; editorial_score?: number | null;
};

/** Alat bantu AI untuk satu naskah: cek editorial, tulis ulang, pelokalan ke provinsi. */
export default function DraftTools({ draft, onChanged }: { draft: DraftWithEditorial; onChanged: (d?: Draft) => void }) {
  const { user, hasRole } = useAuth();
  const [busy, run] = useBusy();
  const [msg, setMsg] = useState<{ kind: "ok" | "error"; text: string } | null>(null);
  const [report, setReport] = useState<EditorialResult | null>(draft.editorial ?? null);
  const [ins, setIns] = useState(REWRITES[0]);
  const [rewrite, setRewrite] = useState<{ text: string; mode: string } | null>(null);
  const [provs, setProvs] = useState<string[]>([]);
  const [localized, setLocalized] = useState<{ draft_id: number; province_name: string; title: string; mode: string }[]>([]);
  const provinces = useLoad(() => api<{ code: string; name: string }[]>("/provinces"), []);
  const isAuthor = hasRole("redaktur", "produser", "analis");
  const canAi = hasRole("redaktur", "produser", "analis", "pimpinan");
  const canApply = draft.status === "draf" && (draft.created_by === user?.id || hasRole("redaktur"));

  const fail = (e: unknown) => setMsg({ kind: "error", text: (e as Error).message });
  if (!canAi) return null;

  return (
    <div className="stack" style={{ gap: 14 }}>
      <div className="row between"><h3 style={{ margin: 0 }}>✦ Alat bantu AI</h3></div>
      {msg && <Msg kind={msg.kind}>{msg.text}</Msg>}

      <div className="stack" style={{ gap: 8 }}>
        <div className="row between">
          <b>Cek editorial</b>
          <button className="sm" disabled={!!busy} onClick={() => run("ed", async () => {
            try { setReport(await api<EditorialResult>(`/m4/drafts/${draft.id}/editorial`, { method: "POST" })); onChanged(); } catch (e) { fail(e); }
          })}>{busy === "ed" ? "Memeriksa…" : report ? "Periksa ulang" : "Periksa sekarang"}</button>
        </div>
        {report && <EditorialReport r={report} />}
        {!report && <div className="small muted">Periksa ejaan (PUEBI), sensitivitas SARA, nada, akurasi angka terhadap fakta brief, risiko hukum, dan kejelasan.</div>}
      </div>

      <div className="stack" style={{ gap: 8 }}>
        <b>Tulis ulang</b>
        <div className="row">
          <select value={ins} onChange={(e) => setIns(e.target.value)} style={{ flex: 1 }} aria-label="Instruksi tulis ulang">
            {REWRITES.map((r) => <option key={r}>{r}</option>)}
          </select>
          <button className="sm" disabled={!!busy} onClick={() => run("rw", async () => {
            try { setRewrite(await api<{ text: string; mode: string }>("/ai/rewrite", { method: "POST", body: { text: draft.body, instruction: ins } })); } catch (e) { fail(e); }
          })}>{busy === "rw" ? "Menulis…" : "Tulis ulang"}</button>
        </div>
        {rewrite && (
          <div className="stack" style={{ gap: 6 }}>
            <ModeChip mode={rewrite.mode} />
            <div className="body-text" style={{ maxHeight: 220 }}>{rewrite.text}</div>
            <div className="row">
              {canApply && <button className="sm primary" disabled={!!busy} onClick={() => run("ap", async () => {
                try {
                  const d = await api<Draft>(`/drafts/${draft.id}`, { method: "PUT", body: { title: draft.title, body: rewrite.text } });
                  setRewrite(null); setMsg({ kind: "ok", text: "Hasil tulis ulang disimpan sebagai revisi baru." }); onChanged(d);
                } catch (e) { fail(e); }
              })}>Simpan sebagai revisi</button>}
              <button className="sm" onClick={() => navigator.clipboard?.writeText(rewrite.text)}>Salin</button>
              <button className="sm ghost" onClick={() => setRewrite(null)}>Buang</button>
            </div>
          </div>
        )}
      </div>

      {isAuthor && (
        <div className="stack" style={{ gap: 8 }}>
          <b>Pelokalan ke provinsi <span className="small muted">(maks. 5, menjadi draf baru)</span></b>
          <div className="row">
            <select multiple size={4} value={provs} style={{ flex: 1, minWidth: 200 }} aria-label="Provinsi tujuan pelokalan"
                    onChange={(e) => setProvs(Array.from(e.target.selectedOptions).map((o) => o.value).slice(0, 5))}>
              {provinces.data?.map((p) => <option key={p.code} value={p.code}>{p.name}</option>)}
            </select>
            <button className="sm" disabled={!provs.length || !!busy} onClick={() => run("lc", async () => {
              try {
                const r = await api<{ created: typeof localized }>(`/m4/drafts/${draft.id}/localize`, { method: "POST", body: { province_codes: provs } });
                setLocalized(r.created); setProvs([]); onChanged();
              } catch (e) { fail(e); }
            })}>{busy === "lc" ? "Melokalkan…" : `Lokalkan (${provs.length})`}</button>
          </div>
          {localized.map((l) => (
            <div key={l.draft_id} className="small row" style={{ gap: 6 }}>
              <ModeChip mode={l.mode} /> <b>{l.province_name}</b> — <Link to={`/editorial?id=${l.draft_id}`}>{l.title}</Link>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
