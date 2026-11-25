import { useEffect, useState } from "react";
import { api } from "../api";
import { useAuth } from "../auth";
import { useBusy } from "../components/kit";
import { Msg, useLoad, CopyButton } from "../components/ui";
import { fmtDateTime, fmtSize, nf, relTime } from "../format";
import type { Card } from "../types47";

type Note = { kind: "ok" | "error" | "warn"; text: string } | null;
const HC: Record<string, string> = { normal: "var(--st-good)", lambat: "var(--st-warning)", gangguan: "var(--st-critical)" };

function Curve({ data, mark }: { data: number[]; mark?: number }) {
  return (
    <div style={{ display: "flex", alignItems: "flex-end", gap: 1, height: 34 }} aria-label="Kurva audiens per jam">
      {data.map((v, h) => <div key={h} title={`${String(h).padStart(2, "0")}.00 · indeks ${v}`} style={{ flex: 1, height: `${Math.max(4, v * 100)}%`, background: h === mark ? "var(--st-warning)" : "var(--seq)", opacity: h === mark ? 1 : 0.75, borderRadius: "2px 2px 0 0" }} />)}
    </div>
  );
}

// ------------------------------------------------------------ S49 CMS per kanal
interface ChannelDetail { code: string; name: string; health: string; latency_ms: number; tz: string; geo_target: string; audience: number; views_7d: number; sent_7d: number;
  articles: { id: number; draft_id: number; title: string; status: string; sent_at: string | null; scheduled_at: string | null; url: string | null; views: number; uniques: number; clicks: number; avg_time_sec: number; sync_ms: number | null; error: string | null; takedown_reason: string | null; ai_label: boolean }[];
  uptime_7d: { uptime: number; checks: number; points: { point: string; latency_ms: number; ok_ratio: number }[] }; incidents: { started_at: string; ended_at: string | null; cause: string }[]; curve: number[] }

export function ChannelCms({ setMsg }: { setMsg: (n: Note) => void }) {
  const { hasRole } = useAuth();
  const chans = useLoad(() => api<{ code: string; name: string; health: string }[]>("/m7/channels"), []);
  const [code, setCode] = useState("NAS");
  const d = useLoad(() => api<ChannelDetail>(`/m7/channels/${code}`), [code]);
  const takedown = async (id: number) => {
    const reason = prompt("Alasan penarikan konten dari kanal ini (wajib, tercatat di audit):");
    if (!reason || reason.trim().length < 5) return;
    try { await api(`/m7/distributions/${id}/takedown`, { method: "POST", body: { reason } }); setMsg({ kind: "ok", text: "Artikel ditarik dari kanal." }); d.reload(); } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); }
  };
  const c = d.data;
  return (
    <div className="split" style={{ gridTemplateColumns: "minmax(0,.55fr) minmax(0,1.45fr)" }}>
      <div className="card" style={{ maxHeight: 640, overflowY: "auto" }}>
        <h3>39 situs kanal (CMS multi-site)</h3>
        {chans.data?.map((x) => (
          <button key={x.code} className={`notif${code === x.code ? " unread" : ""}`} onClick={() => setCode(x.code)} style={{ flexDirection: "row", justifyContent: "space-between" }}>
            <span><span className="hdot" style={{ background: HC[x.health] }} />{x.name.replace("Kanal ", "")}</span><span className="small muted">{x.code}</span>
          </button>
        ))}
      </div>
      {c && (
        <div className="stack">
          <div className="card stack" style={{ gap: 8 }}>
            <div className="row between"><h2 style={{ margin: 0 }}>{c.name}</h2><span className="chip"><span className="hdot" style={{ background: HC[c.health] }} />{c.health} · {c.latency_ms} ms</span></div>
            <dl className="kv">
              <dt>Penargetan geolokasi</dt><dd>{c.geo_target}</dd>
              <dt>Zona waktu</dt><dd>{c.tz}</dd>
              <dt>Uptime 7 hari</dt><dd className={c.uptime_7d.uptime >= 99.9 ? "status-ok" : "status-bad"}>{c.uptime_7d.uptime}% (SLA 99,9%) · {c.uptime_7d.checks} uji</dd>
              <dt>Tayangan 7 hari</dt><dd>{nf.format(c.views_7d)} · {c.sent_7d} artikel terkirim</dd>
              <dt>CDN</dt><dd>Sindikasi satu sumber ke banyak kanal; aset statis disajikan via CDN lokal (fase produksi)</dd>
            </dl>
            <div className="small muted">Kurva audiens per jam ({c.tz})</div><Curve data={c.curve} />
          </div>
          <div className="card table-wrap">
            <h3>Artikel di kanal ini</h3>
            <table>
              <thead><tr><th>Artikel</th><th>Status</th><th className="num">Sinkron</th><th className="num">Tayangan</th><th className="num">Pembaca unik</th><th className="num">Waktu baca</th><th></th></tr></thead>
              <tbody>{c.articles.map((a) => (
                <tr key={a.id}>
                  <td><b>{a.title}</b>{a.ai_label && <div className="small muted">berlabel konten dibantu SISTRA AGENT</div>}{a.takedown_reason && <div className="small status-bad">Ditarik: {a.takedown_reason}</div>}{a.error && <div className="small status-bad">{a.error}</div>}</td>
                  <td className="small"><span className={`chip ${a.status === "terkirim" ? "st-ok" : a.status === "gagal" || a.status === "ditarik" ? "st-bad" : ""}`}>{a.status}</span><div className="muted">{fmtDateTime(a.sent_at ?? a.scheduled_at)}</div></td>
                  <td className="num small">{a.sync_ms != null ? <span className={a.sync_ms <= 5000 ? "status-ok" : "status-bad"}>{(a.sync_ms / 1000).toFixed(1)} dtk</span> : "-"}</td>
                  <td className="num">{nf.format(a.views)}</td><td className="num">{nf.format(a.uniques)}</td><td className="num">{a.avg_time_sec ? `${a.avg_time_sec} dtk` : "-"}</td>
                  <td>{a.status === "terkirim" && hasRole("operator", "redaktur", "pimpinan") && <button className="sm danger" onClick={() => takedown(a.id)}>Tarik</button>}</td>
                </tr>
              ))}</tbody>
            </table>
            <div className="small muted" style={{ marginTop: 6 }}>Kriteria KAK S49: sinkronisasi ke 39 situs ≤ 5 detik.</div>
          </div>
        </div>
      )}
    </div>
  );
}

// ------------------------------------------------------------ S51 penjadwalan cerdas
interface Rec { code: string; name: string; tz: string; curve: number[]; at: string; local_hour: string; audience_index: number; health: string }
export function SmartSchedule({ setMsg }: { setMsg: (n: Note) => void }) {
  const { hasRole } = useAuth();
  const rec = useLoad(() => api<Rec[]>("/m7/schedule/recommend"), []);
  const queue = useLoad(() => api<{ processed: number; items: { id: number; title: string; channel: string; scheduled_at: string; local: string }[] }>("/m7/queue"), []);
  const ready = useLoad(() => api<Card[]>("/m7/ready"), []);
  const [draft, setDraft] = useState(0);
  const [pick, setPick] = useState<string[]>([]);
  const [over, setOver] = useState<Record<string, string>>({});
  const [busy, run] = useBusy();
  useEffect(() => { if (queue.data?.processed) setMsg({ kind: "ok", text: `${queue.data.processed} distribusi terjadwal yang jatuh tempo telah dijalankan.` }); }, [queue.data?.processed]); // eslint-disable-line react-hooks/exhaustive-deps
  const schedule = () => run("s", async () => {
    try {
      const times: Record<string, string> = {}; Object.entries(over).forEach(([k, v]) => { if (v && pick.includes(k)) times[k] = new Date(v).toISOString(); });
      const r = await api<{ scheduled: number }>("/m7/schedule", { method: "POST", body: { draft_id: draft, channel_codes: pick, times } });
      setMsg({ kind: "ok", text: `${r.scheduled} kanal dijadwalkan pada jam puncak${Object.keys(times).length ? ` (${Object.keys(times).length} ditimpa operator)` : ""}.` }); queue.reload();
    } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); }
  });
  const toLocalInput = (iso: string) => { const d = new Date(iso); const p = (n: number) => String(n).padStart(2, "0"); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`; };
  return (
    <div className="stack">
      <Msg kind="info">Rekomendasi jam tayang per kanal dihitung dari kurva jam puncak audiens provinsi (waktu setempat WIB/WITA/WIT). Operator dapat menimpa jam per kanal (S51).</Msg>
      {hasRole("operator") && (
        <div className="card row">
          <select value={draft} onChange={(e) => setDraft(Number(e.target.value))} aria-label="Naskah"><option value={0}>Pilih naskah disetujui…</option>{ready.data?.map((d) => <option key={d.id} value={d.id}>#{d.id} {d.title}</option>)}</select>
          <button className="sm" onClick={() => setPick(pick.length ? [] : (rec.data ?? []).map((r) => r.code))}>{pick.length ? "Kosongkan pilihan" : "Pilih semua 39"}</button>
          <button className="primary" disabled={!draft || !pick.length || !!busy} onClick={schedule}>Jadwalkan {pick.length} kanal</button>
        </div>
      )}
      <div className="split">
        <div className="card table-wrap" style={{ maxHeight: 620, overflowY: "auto" }}>
          <table>
            <thead><tr><th></th><th>Kanal</th><th>Kurva audiens 24 jam</th><th>Rekomendasi</th>{hasRole("operator") && <th>Timpa</th>}</tr></thead>
            <tbody>{rec.data?.map((r) => {
              const lh = Number(r.local_hour.slice(0, 2));
              return (
                <tr key={r.code}>
                  <td>{hasRole("operator") && <input type="checkbox" checked={pick.includes(r.code)} onChange={(e) => setPick(e.target.checked ? [...pick, r.code] : pick.filter((x) => x !== r.code))} aria-label={r.name} />}</td>
                  <td className="small"><span className="hdot" style={{ background: HC[r.health] }} />{r.name.replace("Kanal ", "")}</td>
                  <td style={{ minWidth: 140 }}><Curve data={r.curve} mark={lh} /></td>
                  <td className="small"><b>{r.local_hour}</b><div className="muted">indeks {r.audience_index}</div></td>
                  {hasRole("operator") && <td><input type="datetime-local" value={over[r.code] ?? ""} onChange={(e) => setOver({ ...over, [r.code]: e.target.value })} aria-label={`Timpa jam ${r.name}`} placeholder={toLocalInput(r.at)} style={{ width: 170 }} /></td>}
                </tr>
              );
            })}</tbody>
          </table>
        </div>
        <div className="card table-wrap">
          <h3>Antrean terjadwal ({queue.data?.items.length ?? 0})</h3>
          {!queue.data?.items.length && <div className="empty">Tidak ada distribusi terjadwal.</div>}
          <table><tbody>{queue.data?.items.map((q) => (
            <tr key={q.id}><td className="small"><b>{q.title}</b><div className="muted">{q.channel}</div></td><td className="small">{q.local}<div className="muted">{relTime(q.scheduled_at).replace("baru saja", "segera")}</div></td>
              <td>{hasRole("operator") && <button className="sm ghost" onClick={async () => { await api(`/m7/distributions/${q.id}/cancel`, { method: "POST" }); queue.reload(); }}>Batal</button>}</td></tr>
          ))}</tbody></table>
        </div>
      </div>
    </div>
  );
}

// ------------------------------------------------------------ S52 konversi & metadata
interface Pkg { opengraph: Record<string, string>; jsonld: Record<string, unknown>; checks: { rule: string; ok: boolean }[]; valid: boolean; renditions: { profile: string; label: string; mime: string; dimension: string; status: string; size: number }[] }
export function PackageTab({ setMsg }: { setMsg: (n: Note) => void }) {
  const { hasRole } = useAuth();
  const ready = useLoad(() => api<Card[]>("/m7/ready"), []);
  const [id, setId] = useState(0);
  useEffect(() => { if (!id && ready.data?.length) setId(ready.data[0].id); }, [ready.data]); // eslint-disable-line react-hooks/exhaustive-deps
  const pkg = useLoad(() => (id ? api<Pkg>(`/m7/drafts/${id}/package`) : Promise.resolve(null)), [id]);
  const [busy, run] = useBusy();
  const p = pkg.data;
  const meta = p ? Object.entries(p.opengraph).map(([k, v]) => `<meta ${k.startsWith("og:") || k.startsWith("article:") ? "property" : "name"}="${k}" content="${String(v).replace(/"/g, "&quot;")}" />`).join("\n") : "";
  return (
    <div className="stack">
      <div className="row"><select value={id} onChange={(e) => setId(Number(e.target.value))} aria-label="Naskah">{ready.data?.map((d) => <option key={d.id} value={d.id}>#{d.id} {d.title}</option>)}</select>
        {hasRole("operator", "produser", "redaktur") && <button className="primary" disabled={!id || !!busy} onClick={() => run("r", async () => { try { await api(`/m7/drafts/${id}/renditions`, { method: "POST" }); setMsg({ kind: "ok", text: "12 format turunan dibuat." }); pkg.reload(); } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); } })}>Konversi ke 12 format</button>}</div>
      {p && (
        <div className="split">
          <div className="card stack" style={{ gap: 8 }}>
            <div className="row between"><h3 style={{ margin: 0 }}>Validator OpenGraph & Schema.org</h3><span className={p.valid ? "status-ok" : "status-bad"}>{p.valid ? "✓ Lulus" : "✕ Belum lulus"}</span></div>
            {p.checks.map((c) => <div key={c.rule} className="small">{c.ok ? <span className="status-ok">✓</span> : <span className="status-bad">✕</span>} {c.rule}</div>)}
            <details><summary className="small">Tag &lt;meta&gt; OpenGraph</summary><pre className="body-text mono" style={{ fontSize: 11 }}>{meta}</pre></details>
            <details><summary className="small">JSON-LD Schema.org (NewsArticle)</summary><pre className="body-text mono" style={{ fontSize: 11 }}>{JSON.stringify(p.jsonld, null, 2)}</pre></details>
          </div>
          <div className="card table-wrap">
            <h3>12 profil format turunan</h3>
            <table><thead><tr><th>Profil</th><th>Ukuran</th><th>Status</th></tr></thead>
              <tbody>{p.renditions.map((r) => <tr key={r.profile}><td className="small"><b>{r.label}</b><div className="muted">{r.mime}</div></td><td className="small">{r.dimension}</td>
                <td className="small">{r.status === "siap" ? <span className="status-ok">✓ siap · {fmtSize(r.size)}</span> : <span className="muted">belum</span>}</td></tr>)}</tbody></table>
            <div className="small muted">Purwarupa: konversi disimulasikan; produksi memakai pipeline transcoding on-premise.</div>
          </div>
        </div>
      )}
    </div>
  );
}

// ------------------------------------------------------------ S53 portal media konvensional
export function PressTab({ setMsg }: { setMsg: (n: Note) => void }) {
  const { hasRole } = useAuth();
  const partners = useLoad(() => api<{ id: number; name: string; type: string; province_name: string; contact: string; downloads: number }[]>("/m7/media-partners"), []);
  const packs = useLoad(() => api<{ id: number; title: string; expires_at: string; assets: number; links: number; downloads: { partner: string; ip: string; at: string }[] }[]>("/m7/press-packs"), []);
  const ready = useLoad(() => api<Card[]>("/m7/ready"), []);
  const [draft, setDraft] = useState(0);
  const [sel, setSel] = useState<number[]>([]);
  const [links, setLinks] = useState<{ partner: string; path: string }[]>([]);
  const canEdit = hasRole("operator", "redaktur");
  const make = async () => {
    try {
      const pk = await api<{ id: number }>("/m7/press-packs", { method: "POST", body: { draft_id: draft, days: 7 } });
      const r = await api<{ links: { partner: string; path: string }[] }>(`/m7/press-packs/${pk.id}/links`, { method: "POST", body: { partner_ids: sel } });
      setLinks(r.links); packs.reload(); partners.reload(); setMsg({ kind: "ok", text: `Paket media dibuat; ${r.links.length} tautan berizin (berlaku 7 hari). Tautan hanya tampil sekali.` });
    } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); }
  };
  return (
    <div className="stack">
      <Msg kind="info">Portal unduhan berizin untuk media cetak, TV, dan radio lokal: setiap penerima mendapat tautan berwaktu milik sendiri, dan setiap unduhan tercatat per penerima (S53).</Msg>
      {canEdit && (
        <div className="card stack" style={{ gap: 8 }}>
          <div className="row"><select value={draft} onChange={(e) => setDraft(Number(e.target.value))} aria-label="Naskah"><option value={0}>Pilih naskah disetujui…</option>{ready.data?.map((d) => <option key={d.id} value={d.id}>#{d.id} {d.title}</option>)}</select>
            <button className="primary" disabled={!draft || !sel.length} onClick={make}>Buat paket & tautan ({sel.length} media)</button></div>
          <div className="row">{partners.data?.map((p) => <label key={p.id} className="row small" style={{ gap: 4 }}><input type="checkbox" checked={sel.includes(p.id)} onChange={(e) => setSel(e.target.checked ? [...sel, p.id] : sel.filter((x) => x !== p.id))} />{p.name} <span className="muted">({p.type})</span></label>)}</div>
          {!!links.length && <div className="stack" style={{ gap: 4 }}>{links.map((l) => <div key={l.path} className="small"><b>{l.partner}</b>: <span className="mono">{window.location.origin}{l.path}</span> <CopyButton className="sm ghost" text={window.location.origin + l.path} /></div>)}</div>}
        </div>
      )}
      <div className="split">
        <div className="card table-wrap"><h3>Media mitra</h3>
          <table><thead><tr><th>Media</th><th>Jenis</th><th>Wilayah</th><th className="num">Unduhan</th></tr></thead>
            <tbody>{partners.data?.map((p) => <tr key={p.id}><td>{p.name}<div className="small muted">{p.contact}</div></td><td>{p.type}</td><td className="small">{p.province_name}</td><td className="num">{p.downloads}</td></tr>)}</tbody></table></div>
        <div className="card table-wrap"><h3>Paket & log unduhan</h3>
          {packs.data?.map((p) => (
            <div key={p.id} style={{ borderBottom: "1px solid var(--grid)", padding: "6px 0" }}>
              <b>{p.title}</b><div className="small muted">{p.links} tautan · {p.assets} aset · berlaku s.d. {fmtDateTime(p.expires_at)}</div>
              {p.downloads.map((d, i) => <div key={i} className="small">⬇ {d.partner} · {d.ip} · {fmtDateTime(d.at)}</div>)}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

// ------------------------------------------------------------ S54 gerbang API
interface Client { id: number; name: string; org: string; token_prefix: string; scopes: string[]; rate_per_min: number; expires_at: string; active: boolean; revoked: boolean; last_used_at: string | null; calls_24h: number; denied_24h: number }
export function ApiTab({ setMsg }: { setMsg: (n: Note) => void }) {
  const list = useLoad(() => api<Client[]>("/m7/api-clients"), []);
  const [f, setF] = useState({ name: "", org: "", rate_per_min: 60, days: 90 });
  const [token, setToken] = useState("");
  const [calls, setCalls] = useState<{ id: number; rows: { path: string; status: number; ip: string; at: string }[] } | null>(null);
  const create = async () => { try { const r = await api<Client & { token: string }>("/m7/api-clients", { method: "POST", body: f }); setToken(r.token); list.reload(); setMsg({ kind: "warn", text: "Token hanya ditampilkan sekali. Serahkan ke mitra melalui kanal aman." }); } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); } };
  const origin = window.location.origin;
  return (
    <div className="stack">
      <Msg kind="info">Gerbang API REST untuk sistem mitra K/L: token berwaktu (disimpan sebagai hash), cakupan <span className="mono">publikasi:baca</span>, pembatasan laju per menit, pencabutan instan, dan log setiap panggilan (S54). Di produksi diletakkan di belakang WAF.</Msg>
      <div className="card form-grid">
        <label className="field req"><span>Nama aplikasi</span><input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></label>
        <label className="field req"><span>Instansi</span><input value={f.org} onChange={(e) => setF({ ...f, org: e.target.value })} /></label>
        <label className="field"><span>Batas laju / menit</span><input type="number" value={f.rate_per_min} onChange={(e) => setF({ ...f, rate_per_min: Number(e.target.value) })} /></label>
        <label className="field"><span>Masa berlaku (hari)</span><input type="number" value={f.days} onChange={(e) => setF({ ...f, days: Number(e.target.value) })} /></label>
        <div className="full"><button className="primary" disabled={f.name.length < 3 || f.org.length < 2} onClick={create}>Terbitkan token</button></div>
        {token && <div className="full alert-msg warn"><b>Token (sekali tampil):</b> <span className="mono" style={{ wordBreak: "break-all" }}>{token}</span> <CopyButton text={token} />
          <div className="small mono" style={{ marginTop: 6 }}>curl -H "Authorization: Bearer {token.slice(0, 10)}…" {origin}/api/public/v1/publications?limit=5</div></div>}
      </div>
      <div className="card table-wrap">
        <table><thead><tr><th>Klien</th><th>Token</th><th>Laju</th><th>Berlaku</th><th className="num">Panggilan 24j</th><th className="num">Ditolak</th><th></th></tr></thead>
          <tbody>{list.data?.map((c) => (
            <tr key={c.id}>
              <td><b>{c.name}</b><div className="small muted">{c.org}</div></td><td className="mono small">{c.token_prefix}<div className="muted">{c.scopes.join(", ")}</div></td>
              <td className="small">{c.rate_per_min}/menit</td><td className="small">{c.revoked ? <span className="status-bad">dicabut</span> : c.active ? <>s.d. {fmtDateTime(c.expires_at)}</> : <span className="status-bad">kedaluwarsa</span>}<div className="muted">dipakai {relTime(c.last_used_at)}</div></td>
              <td className="num">{c.calls_24h}</td><td className="num">{c.denied_24h}</td>
              <td className="row"><button className="sm" onClick={async () => setCalls({ id: c.id, rows: await api(`/m7/api-clients/${c.id}/calls`) })}>Log</button>
                {c.active && <button className="sm danger" onClick={async () => { await api(`/m7/api-clients/${c.id}/revoke`, { method: "POST" }); list.reload(); }}>Cabut</button>}</td>
            </tr>
          ))}</tbody></table>
      </div>
      {calls && <div className="card table-wrap"><div className="row between"><h3 style={{ margin: 0 }}>Log panggilan</h3><button className="sm ghost" onClick={() => setCalls(null)}>Tutup</button></div>
        <table><tbody>{calls.rows.map((r, i) => <tr key={i}><td className="small mono">{r.path}</td><td className={r.status < 400 ? "status-ok" : "status-bad"}>{r.status}</td><td className="small">{r.ip}</td><td className="small">{fmtDateTime(r.at)}</td></tr>)}</tbody></table></div>}
      <details className="card"><summary><b>Dokumentasi singkat (OpenAPI)</b></summary>
        <pre className="body-text mono" style={{ fontSize: 11 }}>{`GET /api/public/v1/publications?limit=1..100
GET /api/public/v1/publications/{id}
Header: Authorization: Bearer sst_…
200: { data: [{ id, title, body, format, province, issue, published_at, ai_label, url, opengraph, content_hash }] }
401 token tidak valid/dicabut/kedaluwarsa · 403 cakupan kurang · 429 batas laju (Retry-After: 60)`}</pre></details>
    </div>
  );
}

// ------------------------------------------------------------ S55 uptime
interface Up { sla_target: number; avg_uptime: number; below_sla: number; points: string[]; channels: { code: string; name: string; health: string; latency_ms: number; uptime: number; checks: number; checked_at: string | null; points: { point: string; latency_ms: number; ok_ratio: number }[] }[];
  incidents: { channel: string; started_at: string; ended_at: string | null; cause: string; points: string[]; minutes: number }[] }
export function UptimeTab({ setMsg }: { setMsg: (n: Note) => void }) {
  const { hasRole } = useAuth();
  const up = useLoad(() => api<Up>("/m7/uptime"), []);
  const [busy, run] = useBusy();
  const u = up.data;
  return (
    <div className="stack">
      <div className="row between"><Msg kind="info">Uji ketersediaan dari 5 titik pantau di Indonesia; gangguan tercatat sebagai insiden bila ≥ 3 titik gagal. Target SLA kanal publik 99,9% (S55). Purwarupa: uji disimulasikan.</Msg>
        {hasRole("operator", "admin") && <button disabled={!!busy} onClick={() => run("hc", async () => { const r = await api<Record<string, number>>("/m7/health-check", { method: "POST" }); setMsg({ kind: r.gangguan ? "warn" : "ok", text: `Uji 5 titik selesai: ${r.normal} normal, ${r.lambat} lambat, ${r.gangguan} gangguan.` }); up.reload(); })}>{busy ? "Menguji…" : "Uji sekarang (5 titik)"}</button>}</div>
      {u && (
        <>
          <div className="grid g4">
            <div className="card tile"><div className="label">Rerata uptime 7 hari</div><div className="value num">{u.avg_uptime}%</div><div className="foot">target {u.sla_target}%</div></div>
            <div className="card tile tone-bad"><div className="label">Kanal di bawah SLA</div><div className="value num">{u.below_sla}</div></div>
            <div className="card tile"><div className="label">Insiden (7 hari)</div><div className="value num">{u.incidents.length}</div><div className="foot">{u.incidents.filter((i) => !i.ended_at).length} masih berlangsung</div></div>
            <div className="card tile"><div className="label">Titik pantau</div><div className="value" style={{ fontSize: 16 }}>{u.points.join(" · ")}</div></div>
          </div>
          <div className="split">
            <div className="card table-wrap" style={{ maxHeight: 560, overflowY: "auto" }}>
              <table><thead><tr><th>Kanal</th><th className="num">Uptime</th>{u.points.map((p) => <th key={p} className="num">{p}</th>)}</tr></thead>
                <tbody>{u.channels.map((c) => (
                  <tr key={c.code}><td className="small"><span className="hdot" style={{ background: HC[c.health] }} />{c.name.replace("Kanal ", "")}</td>
                    <td className={`num small ${c.uptime >= u.sla_target ? "status-ok" : "status-bad"}`}>{c.uptime}%</td>
                    {u.points.map((p) => { const x = c.points.find((y) => y.point === p); return <td key={p} className="num small">{x ? (x.ok_ratio < 1 ? <span className="status-bad">{Math.round(x.ok_ratio * 100)}%</span> : `${x.latency_ms} ms`) : "-"}</td>; })}</tr>
                ))}</tbody></table>
            </div>
            <div className="card"><h3>Insiden</h3>
              {u.incidents.map((i, k) => <div key={k} className={`claim ${i.ended_at ? "ok" : "bad"}`}><b>{i.channel}</b> · {i.cause}<div className="small muted">{fmtDateTime(i.started_at)} → {i.ended_at ? fmtDateTime(i.ended_at) : "berlangsung"} · {i.minutes} menit · titik: {i.points.join(", ")}</div></div>)}
            </div>
          </div>
        </>
      )}
    </div>
  );
}
