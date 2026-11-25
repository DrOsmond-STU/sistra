import { useEffect, useState, type FormEvent } from "react";
import { api } from "../api";
import { useAuth } from "../auth";
import { ModeChip, useBusy } from "../components/kit";
import { Msg, useLoad } from "../components/ui";
import { fmtDate, fmtDateTime, nf } from "../format";
import type { Card } from "../types47";

type Note = { kind: "ok" | "error" | "warn"; text: string } | null;

// ------------------------------------------------------------ S41 paket respons cepat
interface Pack { id: number; issue_code: string | null; issue_name: string | null; alert_id: number | null; level: string; title: string; trigger: string; holding_statement: string;
  key_messages: string[]; qa: { q: string; a: string }[]; data_points: { claim: string; source: string }[]; spokesperson: string; donts: string[]; status: string; ai_mode: string | null;
  deadline_at: string; published_at: string | null; published_by_name: string | null; sla: string; minutes_used: number; author_name: string; created_at: string }
interface PendingAlert { alert_id: number; issue_code: string; issue_name: string; level: string; reason: string; triggered_at: string; deadline_at: string }
const SLA: Record<string, [string, string]> = { tepat_waktu: ["Terbit tepat waktu", "status-ok"], terlambat: ["Terbit terlambat", "status-bad"], berjalan: ["Dalam tenggat", "status-warn"], lewat_tenggat: ["Lewat tenggat 2 jam", "status-bad"] };

function Countdown({ to }: { to: string }) {
  const [now, setNow] = useState(Date.now());
  useEffect(() => { const t = setInterval(() => setNow(Date.now()), 1000); return () => clearInterval(t); }, []);
  const ms = new Date(to).getTime() - now; const a = Math.abs(ms);
  const s = `${Math.floor(a / 3600000)}j ${String(Math.floor((a % 3600000) / 60000)).padStart(2, "0")}m ${String(Math.floor((a % 60000) / 1000)).padStart(2, "0")}d`;
  return <span className={ms < 0 ? "status-bad" : ms < 1800000 ? "status-warn" : "status-ok"}>{ms < 0 ? `lewat ${s}` : `sisa ${s}`}</span>;
}

export function PacksTab({ setMsg }: { setMsg: (n: Note) => void }) {
  const { hasRole } = useAuth();
  const list = useLoad(() => api<{ items: Pack[]; can_see_drafts: boolean }>("/m6/packs"), []);
  const alerts = useLoad(() => api<PendingAlert[]>("/m6/packs/alerts"), []);
  const meta = useLoad(() => api<{ issues: { code: string; name: string }[] }>("/m4/meta").then((m) => m.issues), []);
  const [sel, setSel] = useState<number | null>(null);
  const [issue, setIssue] = useState("");
  const [busy, run] = useBusy();
  const canEdit = hasRole("redaktur", "pimpinan", "analis");
  const p = list.data?.items.find((x) => x.id === sel) ?? list.data?.items[0];
  const act = (key: string, fn: () => Promise<unknown>, ok: string) => run(key, async () => { try { await fn(); setMsg({ kind: "ok", text: ok }); list.reload(); alerts.reload(); } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); } });
  const create = (body: Record<string, unknown>) => act("new", async () => { const r = await api<Pack>("/m6/packs", { method: "POST", body }); setSel(r.id); }, "Paket dibuka. Tenggat terbit 2 jam sejak peringatan.");
  const onTime = list.data?.items.filter((x) => x.sla === "tepat_waktu").length ?? 0; const pub = list.data?.items.filter((x) => x.status === "terbit").length ?? 0;
  return (
    <div className="stack">
      <Msg kind="info">KAK S41: paket respons terbit ≤ 2 jam setelah peringatan Oranye/Merah. Draf hanya terlihat oleh redaksi/analis; operator hanya melihat paket yang sudah terbit. Kepatuhan SLA: <b>{onTime}/{pub}</b> paket terbit tepat waktu.</Msg>
      {canEdit && !!alerts.data?.length && (
        <div className="card">
          <h3>Peringatan Oranye/Merah tanpa paket respons</h3>
          {alerts.data.map((a) => (
            <div key={a.alert_id} className="row between" style={{ borderBottom: "1px solid var(--grid)", padding: "6px 0" }}>
              <span><span className={`chip prio-${a.level === "merah" ? "tinggi" : "normal"}`}>{a.level}</span> <b>{a.issue_name}</b> <span className="small muted">{a.reason}</span></span>
              <span className="row"><Countdown to={a.deadline_at} /><button className="sm primary" disabled={!!busy} onClick={() => create({ alert_id: a.alert_id })}>Buka paket</button></span>
            </div>
          ))}
        </div>
      )}
      {canEdit && (
        <div className="row"><select value={issue} onChange={(e) => setIssue(e.target.value)} aria-label="Isu"><option value="">Pilih isu…</option>{meta.data?.map((i) => <option key={i.code} value={i.code}>{i.name}</option>)}</select>
          <button disabled={!issue || !!busy} onClick={() => create({ issue_code: issue, level: "oranye" })}>+ Paket respons manual</button></div>
      )}
      <div className="split" style={{ gridTemplateColumns: "minmax(0,.8fr) minmax(0,1.2fr)" }}>
        <div className="card table-wrap">
          <table><thead><tr><th>Paket</th><th>SLA 2 jam</th></tr></thead>
            <tbody>{list.data?.items.map((x) => (
              <tr key={x.id} className={`clickable${p?.id === x.id ? " selected" : ""}`} onClick={() => setSel(x.id)}>
                <td><b>{x.title}</b><div className="small muted">{x.level} · {x.status} · {fmtDateTime(x.created_at)}</div></td>
                <td className="small"><span className={SLA[x.sla][1]}>{SLA[x.sla][0]}</span><div>{x.status === "terbit" ? `${x.minutes_used} menit` : <Countdown to={x.deadline_at} />}</div></td>
              </tr>
            ))}</tbody></table>
          {list.data?.items.length === 0 && <div className="empty">Belum ada paket.</div>}
        </div>
        {p && <PackDetail key={p.id + p.status + (p.ai_mode ?? "")} p={p} canEdit={canEdit} busy={busy} act={act} canPublish={hasRole("redaktur", "pimpinan")} />}
      </div>
    </div>
  );
}

function PackDetail({ p, canEdit, canPublish, busy, act }: { p: Pack; canEdit: boolean; canPublish: boolean; busy: string; act: (k: string, fn: () => Promise<unknown>, ok: string) => void }) {
  const [hs, setHs] = useState(p.holding_statement);
  const [km, setKm] = useState(p.key_messages.join("\n"));
  const [sp, setSp] = useState(p.spokesperson);
  const draft = p.status !== "terbit";
  return (
    <div className="card stack" style={{ gap: 10 }}>
      <div className="row between"><h2 style={{ margin: 0 }}>{p.title}</h2><span className="row"><ModeChip mode={p.ai_mode} /><span className="chip">{p.status}</span></span></div>
      <div className="small muted">Pemicu: {p.trigger || "-"} · disusun {p.author_name}{p.published_at ? ` · terbit ${fmtDateTime(p.published_at)} oleh ${p.published_by_name}` : ""}</div>
      {draft && canEdit && <div className="row">
        <button className="sm primary" disabled={!!busy} onClick={() => act("gen", () => api(`/m6/packs/${p.id}/generate`, { method: "POST" }), "Paket disusun SISTRA AGENT — tinjau sebelum terbit.")}>{busy === "gen" ? "Menyusun…" : "✦ Susun dengan SISTRA AGENT"}</button>
        <button className="sm" disabled={!!busy} onClick={() => act("sv", () => api(`/m6/packs/${p.id}`, { method: "PATCH", body: { holding_statement: hs, key_messages: km.split("\n").filter(Boolean), spokesperson: sp } }), "Perubahan tersimpan.")}>Simpan suntingan</button>
        {canPublish && <button className="sm" disabled={!!busy} onClick={() => act("pub", () => api(`/m6/packs/${p.id}/publish`, { method: "POST" }), "Paket terbit; Q&A masuk ke bahan respons dan operator diberi tahu.")}>Terbitkan</button>}
      </div>}
      <label className="field"><span>Pernyataan sementara (holding statement)</span>{draft && canEdit ? <textarea style={{ minHeight: 80 }} value={hs} onChange={(e) => setHs(e.target.value)} /> : <div className="body-text">{p.holding_statement || "-"}</div>}</label>
      <label className="field"><span>Pesan kunci</span>{draft && canEdit ? <textarea style={{ minHeight: 70 }} value={km} onChange={(e) => setKm(e.target.value)} /> : <ul style={{ margin: 0 }}>{p.key_messages.map((k) => <li key={k}>{k}</li>)}</ul>}</label>
      <div><b>Tanya-jawab</b>{p.qa.map((x, i) => <div key={i} className="claim ok"><b>T:</b> {x.q}<div><b>J:</b> {x.a}</div></div>)}</div>
      <div><b>Argumen berbasis data</b>{p.data_points.map((d, i) => <div key={i} className="small">• {d.claim} <span className="muted">— {d.source}</span></div>)}</div>
      <label className="field"><span>Petunjuk juru bicara</span>{draft && canEdit ? <textarea style={{ minHeight: 60 }} value={sp} onChange={(e) => setSp(e.target.value)} /> : <div>{p.spokesperson}</div>}</label>
      {!!p.donts.length && <div><b>Hindari</b>{p.donts.map((d) => <div key={d} className="small status-bad">✕ {d}</div>)}</div>}
    </div>
  );
}

// ------------------------------------------------------------ S42 mitra KOL
interface Partner { id: number; name: string; handle: string; platform: string; category: string; followers: number; province_name: string | null; contract_no: string; contract_start: string;
  contract_end: string; disclosure: string; status: string; notes: string; collabs: number; reach: number; engagement: number; engagement_rate: number; disclosure_issues: number; compliant: boolean }
interface PartnerDetail extends Partner { history: { id: number; campaign: string | null; draft_title: string | null; posted_at: string; url: string; disclosure_ok: boolean; reach: number; engagement: number }[] }
const PLAT: Record<string, string> = { instagram: "Instagram", x: "X", tiktok: "TikTok", facebook: "Facebook", youtube: "YouTube", threads: "Threads" };

export function PartnersTab({ setMsg }: { setMsg: (n: Note) => void }) {
  const { hasRole } = useAuth();
  const list = useLoad(() => api<Partner[]>("/m6/partners"), []);
  const [sel, setSel] = useState<number | null>(null);
  const det = useLoad(() => (sel ? api<PartnerDetail>(`/m6/partners/${sel}`) : Promise.resolve(null)), [sel]);
  const [show, setShow] = useState(false);
  const today = new Date().toISOString().slice(0, 10);
  const [f, setF] = useState({ name: "", handle: "", platform: "instagram", category: "", followers: 0, contract_no: "", contract_start: today, contract_end: today, disclosure: "Kemitraan berbayar dengan pemerintah" });
  const [c, setC] = useState({ url: "", reach: 0, engagement: 0, disclosure_ok: true });
  const submit = async (e: FormEvent) => { e.preventDefault(); try { await api("/m6/partners", { method: "POST", body: f }); setShow(false); list.reload(); setMsg({ kind: "ok", text: "Mitra tercatat dengan kontrak & label kemitraan." }); } catch (ex) { setMsg({ kind: "error", text: (ex as Error).message }); } };
  const addCollab = async () => { try { await api(`/m6/partners/${sel}/collabs`, { method: "POST", body: c }); det.reload(); list.reload(); setMsg({ kind: c.disclosure_ok ? "ok" : "warn", text: c.disclosure_ok ? "Kolaborasi tercatat." : "Kolaborasi tercatat TANPA label kemitraan — ditandai tidak patuh." }); } catch (ex) { setMsg({ kind: "error", text: (ex as Error).message }); } };
  return (
    <div className="stack">
      <div className="row between"><Msg kind="info">Kemitraan <b>terbuka</b> saja: setiap mitra wajib punya kontrak tercatat dan setiap unggahan wajib berlabel kemitraan (S42). Akun samaran tidak didukung.</Msg>
        {hasRole("redaktur", "pimpinan") && <button className="primary" onClick={() => setShow((x) => !x)}>{show ? "Tutup" : "+ Mitra"}</button>}</div>
      {show && (
        <form className="card form-grid" onSubmit={submit}>
          <label className="field req"><span>Nama</span><input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required /></label>
          <label className="field req"><span>Akun</span><input value={f.handle} onChange={(e) => setF({ ...f, handle: e.target.value })} required /></label>
          <label className="field"><span>Platform</span><select value={f.platform} onChange={(e) => setF({ ...f, platform: e.target.value })}>{Object.entries(PLAT).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></label>
          <label className="field"><span>Bidang</span><input value={f.category} onChange={(e) => setF({ ...f, category: e.target.value })} /></label>
          <label className="field req"><span>No. kontrak/PKS</span><input value={f.contract_no} onChange={(e) => setF({ ...f, contract_no: e.target.value })} required /></label>
          <label className="field req"><span>Label kemitraan</span><input value={f.disclosure} onChange={(e) => setF({ ...f, disclosure: e.target.value })} required /></label>
          <label className="field"><span>Kontrak mulai</span><input type="date" value={f.contract_start} onChange={(e) => setF({ ...f, contract_start: e.target.value })} /></label>
          <label className="field"><span>Kontrak berakhir</span><input type="date" value={f.contract_end} onChange={(e) => setF({ ...f, contract_end: e.target.value })} /></label>
          <div className="full"><button className="primary">Simpan</button></div>
        </form>
      )}
      <div className="card table-wrap">
        <table>
          <thead><tr><th>Mitra</th><th>Kontrak</th><th>Label kemitraan</th><th className="num">Kolaborasi</th><th className="num">Jangkauan</th><th className="num">ER</th><th>Kepatuhan</th></tr></thead>
          <tbody>{list.data?.map((p) => (
            <tr key={p.id} className={`clickable${sel === p.id ? " selected" : ""}`} onClick={() => setSel(p.id)}>
              <td><b>{p.name}</b><div className="small muted">{PLAT[p.platform]} {p.handle} · {nf.format(p.followers)} pengikut · {p.category}</div></td>
              <td className="small">{p.contract_no}<div className={p.status === "kontrak_berakhir" ? "status-bad" : "muted"}>{fmtDate(p.contract_start)} – {fmtDate(p.contract_end)}</div></td>
              <td className="small">{p.disclosure}</td>
              <td className="num">{p.collabs}</td><td className="num">{nf.format(p.reach)}</td><td className="num">{(p.engagement_rate * 100).toFixed(1)}%</td>
              <td className="small">{p.compliant ? <span className="status-ok">✓ Patuh</span> : <span className="status-bad">✕ {p.status === "kontrak_berakhir" ? "Kontrak berakhir" : `${p.disclosure_issues} unggahan tanpa label`}</span>}</td>
            </tr>
          ))}</tbody>
        </table>
      </div>
      {det.data && (
        <div className="card stack" style={{ gap: 8 }}>
          <h3 style={{ margin: 0 }}>Rekam jejak kolaborasi · {det.data.name}</h3>
          <table><thead><tr><th>Tanggal</th><th>Kampanye/naskah</th><th>Label</th><th className="num">Jangkauan</th><th className="num">Interaksi</th></tr></thead>
            <tbody>{det.data.history.map((h) => (
              <tr key={h.id}><td className="small">{fmtDateTime(h.posted_at)}</td><td className="small">{h.campaign ?? "-"}<div className="muted">{h.draft_title}</div>{h.url && <a href={h.url} target="_blank" rel="noreferrer noopener">tautan</a>}</td>
                <td>{h.disclosure_ok ? <span className="status-ok">✓ berlabel</span> : <span className="status-bad">✕ tanpa label</span>}</td><td className="num">{nf.format(h.reach)}</td><td className="num">{nf.format(h.engagement)}</td></tr>
            ))}</tbody></table>
          {hasRole("redaktur", "pimpinan", "operator") && (
            <div className="row">
              <input placeholder="https://tautan-unggahan" value={c.url} onChange={(e) => setC({ ...c, url: e.target.value })} aria-label="Tautan" style={{ flex: 1 }} />
              <input type="number" placeholder="Jangkauan" value={c.reach || ""} onChange={(e) => setC({ ...c, reach: Number(e.target.value) })} aria-label="Jangkauan" style={{ width: 110 }} />
              <input type="number" placeholder="Interaksi" value={c.engagement || ""} onChange={(e) => setC({ ...c, engagement: Number(e.target.value) })} aria-label="Interaksi" style={{ width: 110 }} />
              <label className="row small"><input type="checkbox" checked={c.disclosure_ok} onChange={(e) => setC({ ...c, disclosure_ok: e.target.checked })} /> Berlabel kemitraan</label>
              <button className="sm" onClick={addCollab}>Catat kolaborasi</button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// ------------------------------------------------------------ S44/S48 kampanye
interface Shift { before: { n: number; neg_ratio: number; pos_ratio: number }; after: { n: number; neg_ratio: number; pos_ratio: number }; delta_neg: number; z: number | null; p_value: number | null; significant: boolean; interpretation: string }
interface Monitoring { reach: number; impressions: number; engagement: number; engagement_rate: number; posts_done: number; posts_planned: number; partner_posts: number; reach_target: number; reach_progress: number | null; daily: { date: string; reach: number; engagement: number }[]; sentiment: Shift; updated_at: string }
interface Campaign { id: number; name: string; issue_name: string | null; objective: string; audience: string; regions: string[]; region_names: string[]; key_messages: string; start_date: string; end_date: string; status: string;
  kpi: { reach?: number; engagement_rate?: number }; approved_by_name: string | null; approved_at: string | null; signature: string | null; author_name: string; evaluation: string | null; evaluation_mode: string | null; slots: number;
  monitoring: Monitoring; slot_list?: { id: number; date: string; account: string | null; partner: string | null; draft_title: string | null; note: string; task_status: string | null; reach: number; engagement: number }[] }
const NEXT: Record<string, [string, string, string[]][]> = {
  draf: [["diajukan", "Ajukan ke pimpinan", ["redaktur", "analis", "pimpinan"]]], diajukan: [["disetujui", "Setujui & tandatangani", ["pimpinan"]], ["draf", "Kembalikan", ["pimpinan"]]],
  disetujui: [["berjalan", "Mulai kampanye", ["redaktur", "operator", "pimpinan"]]], berjalan: [["selesai", "Tutup kampanye", ["redaktur", "pimpinan"]]],
};

function MiniBars({ data }: { data: { date: string; reach: number }[] }) {
  const max = Math.max(1, ...data.map((d) => d.reach));
  return <div style={{ display: "flex", alignItems: "flex-end", gap: 3, height: 70 }}>{data.map((d) => <div key={d.date} title={`${d.date}: ${nf.format(d.reach)}`} style={{ flex: 1, height: `${(d.reach / max) * 100}%`, background: "var(--seq)", borderRadius: "3px 3px 0 0", minHeight: 2 }} />)}</div>;
}

export function CampaignsTab({ setMsg }: { setMsg: (n: Note) => void }) {
  const { user, hasRole } = useAuth();
  const list = useLoad(() => api<Campaign[]>("/m6/campaigns"), []);
  const [sel, setSel] = useState<number | null>(null);
  const det = useLoad(() => (sel ? api<Campaign>(`/m6/campaigns/${sel}`) : Promise.resolve(null)), [sel]);
  const accounts = useLoad(() => api<{ id: number; platform_label: string; handle: string }[]>("/m6/accounts"), []);
  const partners = useLoad(() => api<{ id: number; name: string; status: string }[]>("/m6/partners"), []);
  const ready = useLoad(() => api<Card[]>("/m7/ready"), []);
  const meta = useLoad(() => api<{ issues: { code: string; name: string }[] }>("/m4/meta").then((m) => m.issues), []);
  const [show, setShow] = useState(false);
  const [busy, run] = useBusy();
  const t = new Date(); const iso = (d: Date) => d.toISOString().slice(0, 10);
  const [f, setF] = useState({ name: "", issue_code: "", objective: "", audience: "", key_messages: "", start_date: iso(new Date(t.getTime() + 2 * 86400000)), end_date: iso(new Date(t.getTime() + 16 * 86400000)), regions: "NAS", reach: 500000 });
  const [slot, setSlot] = useState({ date: "", time: "12:00", target: "", draft_id: 0, override_quota: false });
  useEffect(() => { if (!sel && list.data?.length) setSel(list.data[0].id); }, [list.data]); // eslint-disable-line react-hooks/exhaustive-deps
  const act = (k: string, fn: () => Promise<unknown>, ok: string) => run(k, async () => { try { await fn(); setMsg({ kind: "ok", text: ok }); list.reload(); det.reload(); } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); } });
  const c = det.data;
  return (
    <div className="stack">
      <div className="row between"><Msg kind="info">Rencana kampanye resmi terhubung ke alur persetujuan (S44): disusun → diajukan → disetujui pimpinan (bertanda tangan) → berjalan. Matriks jadwal hanya untuk akun resmi & mitra terbuka; kuota kanal berlaku.</Msg>
        {hasRole("redaktur", "pimpinan", "analis") && <button className="primary" onClick={() => setShow((x) => !x)}>{show ? "Tutup" : "+ Rencana kampanye"}</button>}</div>
      {show && (
        <form className="card form-grid" onSubmit={(e) => { e.preventDefault(); act("new", async () => { const r = await api<Campaign>("/m6/campaigns", { method: "POST", body: { ...f, regions: f.regions.split(",").map((x) => x.trim()).filter(Boolean), kpi: { reach: f.reach } } }); setSel(r.id); setShow(false); }, "Rencana kampanye tersimpan sebagai draf."); }}>
          <label className="field req full"><span>Nama kampanye</span><input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required minLength={5} /></label>
          <label className="field req"><span>Isu</span><select value={f.issue_code} onChange={(e) => setF({ ...f, issue_code: e.target.value })} required><option value="">—</option>{meta.data?.map((i) => <option key={i.code} value={i.code}>{i.name}</option>)}</select></label>
          <label className="field"><span>Sasaran audiens</span><input value={f.audience} onChange={(e) => setF({ ...f, audience: e.target.value })} /></label>
          <label className="field req full"><span>Tujuan</span><input value={f.objective} onChange={(e) => setF({ ...f, objective: e.target.value })} required minLength={5} /></label>
          <label className="field req full"><span>Pesan kunci</span><textarea style={{ minHeight: 60 }} value={f.key_messages} onChange={(e) => setF({ ...f, key_messages: e.target.value })} required minLength={5} /></label>
          <label className="field"><span>Mulai</span><input type="date" value={f.start_date} onChange={(e) => setF({ ...f, start_date: e.target.value })} /></label>
          <label className="field"><span>Selesai</span><input type="date" value={f.end_date} onChange={(e) => setF({ ...f, end_date: e.target.value })} /></label>
          <label className="field"><span>Wilayah (kode, pisah koma; NAS = nasional)</span><input value={f.regions} onChange={(e) => setF({ ...f, regions: e.target.value })} /></label>
          <label className="field"><span>Target jangkauan</span><input type="number" value={f.reach} onChange={(e) => setF({ ...f, reach: Number(e.target.value) })} /></label>
          <div className="full"><button className="primary">Simpan rencana</button></div>
        </form>
      )}
      <div className="grid g4">
        {list.data?.map((x) => (
          <button key={x.id} className={`card kcard${sel === x.id ? " sel" : ""}`} onClick={() => setSel(x.id)}>
            <span className="row between"><b>{x.name}</b></span>
            <span className="meta"><span className="chip">{x.status}</span>{fmtDate(x.start_date)} – {fmtDate(x.end_date)}</span>
            <span className="meta">Jangkauan {nf.format(x.monitoring.reach)}{x.kpi.reach ? ` / ${nf.format(x.kpi.reach)}` : ""}</span>
            {x.monitoring.reach_progress != null && <span className="meter"><span style={{ width: `${Math.min(100, x.monitoring.reach_progress * 100)}%` }} /></span>}
          </button>
        ))}
      </div>
      {c && (
        <div className="card stack" style={{ gap: 12 }}>
          <div className="row between">
            <div><h2 style={{ margin: 0 }}>{c.name}</h2><div className="small muted">{c.issue_name} · {c.region_names.join(", ")} · disusun {c.author_name}</div></div>
            <div className="row">{(NEXT[c.status] ?? []).filter(([, , roles]) => hasRole(...roles)).map(([to, label]) => (
              <button key={to} className={to === "disetujui" ? "primary" : ""} disabled={!!busy || (to === "disetujui" && c.author_name === user?.full_name)} title={to === "disetujui" && c.author_name === user?.full_name ? "Pemisahan tugas: penyusun tidak boleh menyetujui sendiri" : ""}
                      onClick={() => act("tr", () => api(`/m6/campaigns/${c.id}/transition`, { method: "POST", body: { to } }), `Status kampanye: ${to}.`)}>{label}</button>))}</div>
          </div>
          {c.signature && <div className="small">✓ Disetujui {c.approved_by_name} · {fmtDateTime(c.approved_at)} · tanda tangan <span className="mono">{c.signature.slice(0, 16)}…</span></div>}
          <dl className="kv"><dt>Tujuan</dt><dd>{c.objective}</dd><dt>Audiens</dt><dd>{c.audience || "-"}</dd><dt>Pesan kunci</dt><dd>{c.key_messages}</dd></dl>
          <h3 style={{ margin: 0 }}>Pemantauan kampanye (S48) <span className="small muted">diperbarui {fmtDateTime(c.monitoring.updated_at)}</span></h3>
          <div className="grid g4">
            <div className="tile"><div className="label">Jangkauan</div><div className="value num">{nf.format(c.monitoring.reach)}</div><div className="foot">{c.monitoring.reach_target ? `target ${nf.format(c.monitoring.reach_target)}` : ""}</div></div>
            <div className="tile"><div className="label">Impresi</div><div className="value num">{nf.format(c.monitoring.impressions)}</div></div>
            <div className="tile"><div className="label">Tingkat interaksi</div><div className="value num">{(c.monitoring.engagement_rate * 100).toFixed(1)}%</div><div className="foot">{nf.format(c.monitoring.engagement)} interaksi</div></div>
            <div className="tile"><div className="label">Unggahan</div><div className="value num">{c.monitoring.posts_done}/{c.monitoring.posts_planned}</div><div className="foot">+ {c.monitoring.partner_posts} unggahan mitra</div></div>
          </div>
          {!!c.monitoring.daily.length && <div><div className="small muted">Jangkauan harian</div><MiniBars data={c.monitoring.daily} /></div>}
          <div className="claim">
            <b>Pergeseran sentimen (S57):</b> komentar negatif {Math.round(c.monitoring.sentiment.before.neg_ratio * 100)}% (n={c.monitoring.sentiment.before.n}) sebelum → {Math.round(c.monitoring.sentiment.after.neg_ratio * 100)}% (n={c.monitoring.sentiment.after.n}) selama kampanye
            {c.monitoring.sentiment.p_value != null && <> · z = {c.monitoring.sentiment.z}, p = {c.monitoring.sentiment.p_value}</>}
            <div className={c.monitoring.sentiment.significant ? "status-ok" : "muted"}>{c.monitoring.sentiment.interpretation}</div>
          </div>
          <div className="row between"><h3 style={{ margin: 0 }}>Laporan evaluasi</h3>
            {hasRole("redaktur", "pimpinan", "analis") && <button className="sm" disabled={!!busy} onClick={() => act("ev", () => api(`/m6/campaigns/${c.id}/evaluate`, { method: "POST" }), "Laporan evaluasi disusun.")}>{busy === "ev" ? "Menyusun…" : "✦ Susun evaluasi SISTRA AGENT"}</button>}</div>
          {c.evaluation ? <div><ModeChip mode={c.evaluation_mode} /><div style={{ whiteSpace: "pre-wrap", marginTop: 6 }}>{c.evaluation}</div></div> : <div className="small muted">Belum ada evaluasi.</div>}
          <h3 style={{ margin: 0 }}>Matriks jadwal ({c.slot_list?.length ?? 0} slot)</h3>
          {["disetujui", "berjalan"].includes(c.status) && hasRole("redaktur", "pimpinan", "operator") && (
            <div className="row">
              <input type="date" value={slot.date} min={c.start_date} max={c.end_date} onChange={(e) => setSlot({ ...slot, date: e.target.value })} aria-label="Tanggal" />
              <input type="time" value={slot.time} onChange={(e) => setSlot({ ...slot, time: e.target.value })} aria-label="Jam" />
              <select value={slot.target} onChange={(e) => setSlot({ ...slot, target: e.target.value })} aria-label="Akun/mitra"><option value="">Akun/mitra…</option>
                <optgroup label="Akun resmi">{accounts.data?.map((a) => <option key={a.id} value={`a${a.id}`}>{a.platform_label} {a.handle}</option>)}</optgroup>
                <optgroup label="Mitra terbuka">{partners.data?.filter((p) => p.status === "aktif").map((p) => <option key={p.id} value={`p${p.id}`}>{p.name}</option>)}</optgroup></select>
              <select value={slot.draft_id} onChange={(e) => setSlot({ ...slot, draft_id: Number(e.target.value) })} aria-label="Naskah"><option value={0}>Naskah disetujui…</option>{ready.data?.map((d) => <option key={d.id} value={d.id}>#{d.id} {d.title}</option>)}</select>
              {hasRole("redaktur", "pimpinan") && <label className="row small"><input type="checkbox" checked={slot.override_quota} onChange={(e) => setSlot({ ...slot, override_quota: e.target.checked })} /> Lampaui kuota</label>}
              <button className="sm primary" disabled={!slot.date || !slot.target || !!busy} onClick={() => act("slot", () => api(`/m6/campaigns/${c.id}/slots`, { method: "POST", body: {
                date: slot.date, time: slot.time, draft_id: slot.draft_id || null, override_quota: slot.override_quota,
                account_id: slot.target.startsWith("a") ? Number(slot.target.slice(1)) : null, partner_id: slot.target.startsWith("p") ? Number(slot.target.slice(1)) : null } }), "Slot ditambahkan; tugas unggah dibuat untuk operator.")}>+ Slot</button>
            </div>
          )}
          <div className="table-wrap" style={{ maxHeight: 320, overflowY: "auto" }}>
            <table><thead><tr><th>Tanggal</th><th>Akun/mitra</th><th>Naskah</th><th>Status</th><th className="num">Jangkauan</th></tr></thead>
              <tbody>{c.slot_list?.map((s) => (
                <tr key={s.id}><td className="small">{fmtDate(s.date)}</td><td className="small">{s.account ?? s.partner}</td><td className="small">{s.draft_title ?? "-"}<div className="muted">{s.note}</div></td>
                  <td className="small">{s.task_status ?? (s.partner ? "mitra" : "-")}</td><td className="num">{nf.format(s.reach)}</td></tr>
              ))}</tbody></table>
          </div>
        </div>
      )}
    </div>
  );
}

// ------------------------------------------------------------ S40/S45 kuota & token
interface Quota { account_id: number; handle: string; platform: string; token_rotated_at: string | null; token_status: string; mfa: boolean; daily_used: number; daily_quota: number; weekly_used: number; weekly_quota: number }
export function QuotaPanel({ setMsg }: { setMsg: (n: Note) => void }) {
  const { hasRole } = useAuth();
  const q = useLoad(() => api<Quota[]>("/m6/quota"), []);
  const act = async (fn: () => Promise<unknown>, ok: string) => { try { await fn(); setMsg({ kind: "ok", text: ok }); q.reload(); } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); } };
  return (
    <div className="card table-wrap">
      <h2>Kuota unggahan & keamanan token (S40, S45)</h2>
      <table>
        <thead><tr><th>Akun</th><th>Kuota harian</th><th>Kuota mingguan</th><th>Token OAuth (vault)</th><th></th></tr></thead>
        <tbody>{q.data?.map((x) => (
          <tr key={x.account_id}>
            <td><b>{x.handle}</b><div className="small muted">{x.platform}</div></td>
            <td style={{ minWidth: 150 }}><div className="small">{x.daily_used}/{x.daily_quota}</div><div className="meter"><span style={{ width: `${Math.min(100, (x.daily_used / x.daily_quota) * 100)}%`, background: x.daily_used >= x.daily_quota ? "var(--st-critical)" : undefined }} /></div></td>
            <td style={{ minWidth: 150 }}><div className="small">{x.weekly_used}/{x.weekly_quota}</div><div className="meter"><span style={{ width: `${Math.min(100, (x.weekly_used / x.weekly_quota) * 100)}%` }} /></div></td>
            <td className="small">{x.token_status === "aman" ? <span className="status-ok">aman</span> : <span className="status-bad">{x.token_status}</span>} · rotasi {fmtDate(x.token_rotated_at)}<div className="muted">token tidak pernah ditampilkan</div></td>
            <td className="row">
              {hasRole("admin", "operator") && <button className="sm" onClick={() => act(() => api(`/m6/accounts/${x.account_id}/rotate`, { method: "POST" }), `Token ${x.handle} dirotasi di vault.`)}>Rotasi token</button>}
              {hasRole("redaktur", "pimpinan", "admin") && <button className="sm ghost" onClick={() => { const d = Number(prompt(`Kuota harian ${x.handle}`, String(x.daily_quota))); if (d > 0) act(() => api(`/m6/accounts/${x.account_id}/quota`, { method: "PATCH", body: { quota_daily: d, quota_weekly: Math.max(d, x.weekly_quota) } }), "Kuota diperbarui."); }}>Ubah kuota</button>}
            </td>
          </tr>
        ))}</tbody>
      </table>
      <div className="small muted" style={{ marginTop: 6 }}>Tugas unggah yang melampaui kuota harian ditolak sistem, kecuali dilampaui redaktur/pimpinan dengan jejak audit.</div>
    </div>
  );
}

// ------------------------------------------------------------ S43 utas komentar
export function ThreadView({ id, onClose }: { id: number; onClose: () => void }) {
  const t = useLoad(() => api<{ root: { author: string; text: string; at: string; pii_masked: number }; replies: { id: number; author: string; text: string; at: string; pii_masked: number }[] }>(`/m6/comments/${id}/thread`), [id]);
  if (!t.data) return null;
  return (
    <div className="card stack" style={{ gap: 6, background: "var(--surface-2)" }}>
      <div className="row between"><b>Utas komentar</b><button className="sm ghost" onClick={onClose}>Tutup</button></div>
      <div><b>{t.data.root.author}</b> <span className="small muted">{fmtDateTime(t.data.root.at)}</span><div>{t.data.root.text}</div></div>
      {t.data.replies.map((r) => <div key={r.id} style={{ paddingLeft: 18, borderLeft: "2px solid var(--axis)" }}><b>{r.author}</b> <span className="small muted">{fmtDateTime(r.at)}</span><div>{r.text}</div>{r.pii_masked > 0 && <div className="small muted">🔒 {r.pii_masked} data pribadi disamarkan</div>}</div>)}
    </div>
  );
}
