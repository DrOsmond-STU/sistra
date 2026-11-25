import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { api } from "../api";
import { useAuth } from "../auth";
import type { Draft } from "../types";
import type { EditorialResult } from "../types47";
import { EditorialReport, ModeChip, useBusy } from "./kit";
import PlatformPreview from "./PlatformPreview";
import { Msg, useLoad } from "./ui";

const REWRITES = [
  "Persingkat menjadi maksimal 80 kata",
  "Ubah nada menjadi lebih empatik",
  "Sederhanakan bahasa untuk pembaca umum",
  "Jadikan utas 5 bagian untuk X/Threads",
  "Ubah menjadi naskah video 45 detik dengan penanda adegan",
];

export type DraftWithEditorial = Draft & {
  editorial?: EditorialResult | null; format?: string; brief_id?: number | null; hashtags?: string[] | string | null;
  assignee_id?: number | null; due_at?: string | null; priority?: string; editorial_score?: number | null;
};
interface Claim { text: string; status: "terverifikasi" | "tidak_bersumber" | "perlu_verifikasi"; source: string | null; fact?: string }
interface Verify { score: number; claims: Claim[]; verified: number; total: number; facts: number; note: string; word_count: number; ai_label: string | null;
  fact_items: { text: string; source: string; url: string; reliability: string }[] }
interface AssetLite { id: number; title: string; license_label: string; license_state: string; mime: string }

const CLAIM_LABEL = { terverifikasi: "Terverifikasi", tidak_bersumber: "Tidak bersumber", perlu_verifikasi: "Ditandai perlu verifikasi" };
type Tab = "verifikasi" | "pratinjau" | "editorial" | "judul" | "tulis" | "lokal" | "aset";

/** Alat bantu SISTRA AGENT untuk satu naskah (S27–S33, S37). */
export default function DraftTools({ draft, onChanged }: { draft: DraftWithEditorial; onChanged: (d?: Draft) => void }) {
  const { user, hasRole } = useAuth();
  const [busy, run] = useBusy();
  const [tab, setTab] = useState<Tab>("verifikasi");
  const [msg, setMsg] = useState<{ kind: "ok" | "error"; text: string } | null>(null);
  const [report, setReport] = useState<EditorialResult | null>(draft.editorial ?? null);
  const [ins, setIns] = useState(REWRITES[0]);
  const [rewrite, setRewrite] = useState<{ text: string; mode: string } | null>(null);
  const [titles, setTitles] = useState<{ titles: { title: string; style: string }[]; mode: string } | null>(null);
  const [provs, setProvs] = useState<string[]>([]);
  const [localized, setLocalized] = useState<{ draft_id: number; province_name: string; title: string; mode: string; notes: string }[]>([]);
  const [verify, setVerify] = useState<Verify | null>(null);
  const provinces = useLoad(() => api<{ code: string; name: string }[]>("/provinces"), []);
  const assets = useLoad(() => api<AssetLite[]>(`/m5/drafts/${draft.id}/assets`), [draft.id]);
  const isAuthor = hasRole("redaktur", "produser", "analis");
  const canAi = hasRole("redaktur", "produser", "analis", "pimpinan");
  const canApply = draft.status === "draf" && (draft.created_by === user?.id || hasRole("redaktur"));
  const tags = Array.isArray(draft.hashtags) ? draft.hashtags : [];

  useEffect(() => { api<Verify>(`/m4/drafts/${draft.id}/verify`).then(setVerify).catch(() => setVerify(null)); }, [draft.id, draft.version]);
  const fail = (e: unknown) => setMsg({ kind: "error", text: (e as Error).message });
  const save = (title: string, body: string, ok: string) => run("ap", async () => {
    try { const d = await api<Draft>(`/drafts/${draft.id}`, { method: "PUT", body: { title, body } }); setMsg({ kind: "ok", text: ok }); onChanged(d); } catch (e) { fail(e); }
  });

  const TABS: [Tab, string][] = [["verifikasi", "Verifikasi fakta"], ["pratinjau", "Pratinjau platform"], ...(canAi ? [["editorial", "Agen editorial"], ["judul", "Judul"], ["tulis", "Tulis ulang"]] as [Tab, string][] : []),
    ...(isAuthor ? [["lokal", "Pelokalan"]] as [Tab, string][] : []), ["aset", `Aset (${assets.data?.length ?? 0})`]];

  return (
    <div className="stack" style={{ gap: 10 }}>
      <h3 style={{ margin: 0 }}>✦ Ruang pratinjau & alat bantu SISTRA AGENT</h3>
      <div className="subtabs" role="tablist">
        {TABS.map(([k, v]) => <button key={k} role="tab" aria-selected={tab === k} className={tab === k ? "on" : ""} onClick={() => setTab(k)}>{v}</button>)}
      </div>
      {msg && <Msg kind={msg.kind}>{msg.text}</Msg>}

      {tab === "verifikasi" && (
        verify ? (
          <div className="stack" style={{ gap: 8 }}>
            <div className="row" style={{ gap: 16 }}>
              <div><div className="small muted">Skor verifikasi fakta</div><div style={{ fontSize: 26, fontWeight: 700 }} className={verify.score >= 90 ? "status-ok" : verify.score >= 60 ? "status-warn" : "status-bad"}>{verify.score}%</div></div>
              <div className="small">{verify.verified} dari {verify.total} klaim cocok dengan {verify.facts} fakta bersumber<br />{verify.word_count} kata {verify.word_count > 800 ? <span className="status-bad">(melebihi 800 kata)</span> : <span className="muted">(≤ 800 kata)</span>}</div>
            </div>
            {verify.ai_label && <Msg kind="warn">Label wajib saat tayang: “{verify.ai_label}”</Msg>}
            {verify.note && <div className="small muted">{verify.note}</div>}
            {verify.claims.map((c, i) => (
              <div key={i} className={`claim ${c.status === "terverifikasi" ? "ok" : c.status === "perlu_verifikasi" ? "warn" : "bad"}`}>
                <div>{c.text}</div>
                <div className="small muted">{CLAIM_LABEL[c.status]}{c.source ? ` · sumber: ${c.source}` : ""}</div>
              </div>
            ))}
            {!!verify.fact_items.length && (
              <details><summary className="small">Daftar fakta bersumber ({verify.fact_items.length})</summary>
                <ol className="small" style={{ paddingLeft: 18 }}>{verify.fact_items.map((f, i) => <li key={i}>[F{i + 1}] {f.text} — <i>{f.source || "tanpa sumber"}</i>{f.reliability ? ` · keandalan ${f.reliability}` : ""}</li>)}</ol>
              </details>
            )}
            <div className="small muted">Publikasi tetap memerlukan persetujuan manusia; sistem menolak penayangan naskah yang belum disetujui (S33).</div>
          </div>
        ) : <div className="small muted">Memuat verifikasi…</div>
      )}

      {tab === "pratinjau" && <PlatformPreview title={draft.title} body={draft.body} hashtags={tags} aiLabel={draft.ai_generated ? draft.ai_label ?? "Konten ini disusun dengan bantuan SISTRA AGENT (sistem kecerdasan buatan) dan telah ditinjau oleh redaksi." : null} />}

      {tab === "editorial" && (
        <div className="stack" style={{ gap: 8 }}>
          <div className="row between">
            <span className="small muted">Ejaan PUEBI, ujaran kebencian/SARA, nada, akurasi fakta, risiko hukum, kejelasan. Terima/tolak temuan agar agen belajar dari koreksi redaktur.</span>
            <button className="sm" disabled={!!busy} onClick={() => run("ed", async () => {
              try { setReport(await api<EditorialResult>(`/m4/drafts/${draft.id}/editorial`, { method: "POST" })); onChanged(); } catch (e) { fail(e); }
            })}>{busy === "ed" ? "Memeriksa…" : report ? "Periksa ulang" : "Periksa sekarang"}</button>
          </div>
          {report && <EditorialReport r={report} onDecide={hasRole("redaktur", "pimpinan", "produser", "analis") ? async (f, d) => {
            try { await api(`/m4/drafts/${draft.id}/feedback`, { method: "POST", body: { ...f, decision: d } }); } catch (e) { fail(e); }
          } : undefined} />}
        </div>
      )}

      {tab === "judul" && (
        <div className="stack" style={{ gap: 8 }}>
          <div className="row between"><span className="small muted">5 alternatif judul dengan gaya berbeda, tanpa clickbait.</span>
            <button className="sm" disabled={!!busy} onClick={() => run("tt", async () => { try { setTitles(await api(`/m4/drafts/${draft.id}/titles`, { method: "POST" })); } catch (e) { fail(e); } })}>{busy === "tt" ? "Menyusun…" : "✦ Buat judul"}</button>
          </div>
          {titles && <ModeChip mode={titles.mode} />}
          {titles?.titles.map((t, i) => (
            <div key={i} className="row between" style={{ borderBottom: "1px solid var(--grid)", paddingBottom: 6 }}>
              <span><b>{t.title}</b> <span className="small muted">· {t.style}</span></span>
              {canApply && <button className="sm" disabled={!!busy} onClick={() => save(t.title, draft.body, "Judul diperbarui (revisi baru).")}>Pakai</button>}
            </div>
          ))}
        </div>
      )}

      {tab === "tulis" && (
        <div className="stack" style={{ gap: 8 }}>
          <div className="row">
            <select value={ins} onChange={(e) => setIns(e.target.value)} style={{ flex: 1 }} aria-label="Instruksi tulis ulang">{REWRITES.map((r) => <option key={r}>{r}</option>)}</select>
            <button className="sm" disabled={!!busy} onClick={() => run("rw", async () => { try { setRewrite(await api(`/ai/rewrite`, { method: "POST", body: { text: draft.body, instruction: ins } })); } catch (e) { fail(e); } })}>{busy === "rw" ? "Menulis…" : "Tulis ulang"}</button>
          </div>
          {rewrite && (
            <div className="stack" style={{ gap: 6 }}>
              <ModeChip mode={rewrite.mode} />
              <div className="body-text" style={{ maxHeight: 220 }}>{rewrite.text}</div>
              <div className="row">
                {canApply && <button className="sm primary" disabled={!!busy} onClick={() => save(draft.title, rewrite.text, "Hasil tulis ulang disimpan sebagai revisi baru.")}>Simpan sebagai revisi</button>}
                <button className="sm" onClick={() => navigator.clipboard?.writeText(rewrite.text)}>Salin</button>
                <button className="sm ghost" onClick={() => setRewrite(null)}>Buang</button>
              </div>
            </div>
          )}
        </div>
      )}

      {tab === "lokal" && (
        <div className="stack" style={{ gap: 8 }}>
          <div className="small muted">Maks. 5 provinsi per permintaan; hasil menjadi draf baru. Fakta lokal hanya diambil dari <Link to="/produksi?tab=lokal">basis data konteks lokal terverifikasi</Link> (S28).</div>
          <div className="row">
            <select multiple size={5} value={provs} style={{ flex: 1, minWidth: 200 }} aria-label="Provinsi tujuan pelokalan"
                    onChange={(e) => setProvs(Array.from(e.target.selectedOptions).map((o) => o.value).slice(0, 5))}>
              {provinces.data?.map((p) => <option key={p.code} value={p.code}>{p.name}</option>)}
            </select>
            <button className="sm" disabled={!provs.length || !!busy} onClick={() => run("lc", async () => {
              try { const r = await api<{ created: typeof localized }>(`/m4/drafts/${draft.id}/localize`, { method: "POST", body: { province_codes: provs } }); setLocalized(r.created); setProvs([]); onChanged(); } catch (e) { fail(e); }
            })}>{busy === "lc" ? "Melokalkan…" : `Lokalkan (${provs.length})`}</button>
          </div>
          {localized.map((l) => (
            <div key={l.draft_id} className="small"><ModeChip mode={l.mode} /> <b>{l.province_name}</b> — <Link to={`/editorial?id=${l.draft_id}`}>{l.title}</Link><div className="muted">{l.notes}</div></div>
          ))}
        </div>
      )}

      {tab === "aset" && (
        <div className="stack" style={{ gap: 6 }}>
          {!assets.data?.length && <div className="small muted">Belum ada aset tertaut.</div>}
          {assets.data?.map((a) => (
            <div key={a.id} className="row between small"><span>{a.title}</span><span className={a.license_state === "kedaluwarsa" ? "status-bad" : a.license_state === "segera_kedaluwarsa" ? "status-warn" : "muted"}>{a.license_label} · {a.license_state.replace("_", " ")}</span></div>
          ))}
          <Link className="btn sm" to={`/aset?draft=${draft.id}`}>Kelola & tautkan aset →</Link>
        </div>
      )}
    </div>
  );
}
