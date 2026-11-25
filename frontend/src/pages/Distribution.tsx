import { useEffect, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { ApiTab, ChannelCms, PackageTab, PressTab, SmartSchedule, UptimeTab } from "./DistributionKak";
import { api } from "../api";
import { useAuth } from "../auth";
import { Tile, useBusy } from "../components/kit";
import { Msg, useLoad } from "../components/ui";
import { STATUS_DRAFT, fmtDateTime, nf, relTime } from "../format";
import { FORMAT_LABEL, HEALTH_LABEL, type Card, type ChannelInfo, type Distribution } from "../types47";
import SebaranMap from "../components/geo";

type Scope = "semua" | "nasional" | "pilih";
const HCOLOR: Record<string, string> = { normal: "var(--st-good)", lambat: "var(--st-warning)", gangguan: "var(--st-critical)" };

export default function DistributionPage() {
  const { hasRole } = useAuth();
  const channels = useLoad(() => api<ChannelInfo[]>("/m7/channels"), []);
  const ready = useLoad(() => api<Card[]>("/m7/ready"), []);
  const [sel, setSel] = useState<Card | null>(null);
  const [dist, setDist] = useState<Distribution[]>([]);
  const [scope, setScope] = useState<Scope>("semua");
  const [picked, setPicked] = useState<string[]>([]);
  const [filter, setFilter] = useState("");
  const [msg, setMsg] = useState<{ kind: "ok" | "error" | "warn"; text: string } | null>(null);
  const [params, setParams] = useSearchParams();
  const tab = params.get("tab") ?? "salur";
  const [busy, run] = useBusy();
  const canSend = hasRole("operator");
  const canCheck = hasRole("operator", "admin");

  useEffect(() => {
    if (!sel) { setDist([]); return; }
    api<Distribution[]>("/m7/distributions", { query: { draft_id: sel.id } }).then(setDist).catch(() => setDist([]));
  }, [sel]);

  const ch = channels.data ?? [];
  const count = { normal: ch.filter((c) => c.health === "normal").length, lambat: ch.filter((c) => c.health === "lambat").length, gangguan: ch.filter((c) => c.health === "gangguan").length };
  const views = ch.reduce((a, c) => a + c.views_7d, 0);
  const sent = ch.reduce((a, c) => a + c.sent_7d, 0);
  const failed = ch.reduce((a, c) => a + c.failed_7d, 0);
  const shown = ch.filter((c) => !filter || c.health === filter);
  const target = scope === "semua" ? ch.length : scope === "nasional" ? ch.filter((c) => c.type === "nasional").length : picked.length;
  const doneByCode = new Map(dist.map((d) => [d.channel_code, d]));

  const distribute = () => run("go", async () => {
    try {
      const r = await api<{ draft_status: string; sent: number; failed: number; items: Distribution[] }>("/m7/distribute", {
        method: "POST", body: { draft_id: sel!.id, scope, channel_codes: scope === "pilih" ? picked : undefined },
      });
      setDist(r.items);
      setSel({ ...sel!, status: r.draft_status });
      setMsg(r.failed ? { kind: "warn", text: `${r.sent} kanal terkirim, ${r.failed} gagal (kanal gangguan). Coba ulang setelah kanal pulih.` }
        : { kind: "ok", text: `${r.sent} kanal terkirim. Status naskah: ${STATUS_DRAFT[r.draft_status]}.` });
      ready.reload(); channels.reload();
    } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); }
  });
  const retry = (id: number) => run(`r${id}`, async () => {
    try {
      const d = await api<Distribution>(`/m7/distributions/${id}/retry`, { method: "POST" });
      setDist(dist.map((x) => (x.id === id ? d : x))); setMsg({ kind: "ok", text: `${d.channel_name} terkirim ulang.` }); ready.reload();
    } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); }
  });
  const check = () => run("hc", async () => {
    try {
      const r = await api<Record<string, number>>("/m7/health-check", { method: "POST" });
      setMsg({ kind: r.gangguan ? "warn" : "ok", text: `Uji ketersediaan selesai: ${r.normal} normal, ${r.lambat} lambat, ${r.gangguan} gangguan.` });
      channels.reload();
    } catch (e) { setMsg({ kind: "error", text: (e as Error).message }); }
  });

  return (
    <div className="stack">
      <div className="row between">
        <div>
          <h1>Distribusi ke 39 Wilayah <span className="muted" style={{ fontWeight: 400 }}>· M7</span></h1>
          <p className="sub" style={{ marginBottom: 0 }}>Menyalurkan naskah yang sudah disetujui ke 1 kanal nasional dan 38 kanal provinsi, dengan pemantauan kesehatan kanal</p>
        </div>
        {canCheck && <button disabled={!!busy} onClick={check}>{busy === "hc" ? "Menguji…" : "Uji ketersediaan kanal"}</button>}
      </div>
      <Msg kind="warn">Purwarupa: pengiriman ke CMS kanal dan uji ketersediaan masih <b>disimulasikan</b>. Tautan hasil memakai domain contoh.</Msg>
      {msg && <Msg kind={msg.kind}>{msg.text}</Msg>}
      <SebaranMap title="Sebaran artikel di 39 wilayah" layers={["artikel", "kanal", "mitra"]} initial="artikel" height={380} />
      <div className="tabs" role="tablist">
        {([["salur", "Salurkan (S50)"], ["cms", "Kanal & CMS (S49)"], ["jadwal", "Jadwal cerdas (S51)"], ["paket", "Metadata & format (S52)"], ["pers", "Portal media (S53)"],
          ...(hasRole("admin") ? [["api", "API mitra (S54)"]] : []), ["uptime", "Uptime (S55)"]] as [string, string][]).map(([k, v]) => (
          <button key={k} role="tab" aria-selected={tab === k} className={tab === k ? "on" : ""} onClick={() => { setParams({ tab: k }, { replace: true }); setMsg(null); }}>{v}</button>))}
      </div>
      {tab === "cms" && <ChannelCms setMsg={setMsg} />}
      {tab === "jadwal" && <SmartSchedule setMsg={setMsg} />}
      {tab === "paket" && <PackageTab setMsg={setMsg} />}
      {tab === "pers" && <PressTab setMsg={setMsg} />}
      {tab === "api" && <ApiTab setMsg={setMsg} />}
      {tab === "uptime" && <UptimeTab setMsg={setMsg} />}
      {tab === "salur" && <>
      <div className="grid g4">
        <Tile label="Kanal normal" value={`${count.normal} / ${ch.length}`} tone={count.gangguan ? "bad" : count.lambat ? "warn" : "good"}
              foot={`${count.lambat} lambat · ${count.gangguan} gangguan`} />
        <Tile label="Terkirim 7 hari" value={nf.format(sent)} foot={`${failed} gagal`} />
        <Tile label="Tayangan 7 hari" value={nf.format(views)} />
        <Tile label="Naskah siap salur" value={ready.data?.filter((d) => d.status === "disetujui").length ?? "-"} foot="status disetujui, belum tayang" />
      </div>

      <div className="split" style={{ gridTemplateColumns: "minmax(0, 0.9fr) minmax(0, 1.1fr)" }}>
        <div className="card table-wrap" style={{ maxHeight: 640, overflowY: "auto" }}>
          <h2>Naskah disetujui & tayang</h2>
          {ready.data?.length === 0 && <div className="empty">Belum ada naskah yang disetujui. <Link to="/editorial">Ke papan editorial →</Link></div>}
          {!!ready.data?.length && (
            <table>
              <thead><tr><th>Naskah</th><th>Status</th><th className="num">Kanal</th></tr></thead>
              <tbody>{ready.data.map((d) => (
                <tr key={d.id} className={`clickable${sel?.id === d.id ? " selected" : ""}`} onClick={() => { setSel(d); setMsg(null); }}>
                  <td><b>{d.title}</b><div className="small muted">{FORMAT_LABEL[d.format] ?? d.format}{d.province_name ? ` · ${d.province_name}` : ""}</div></td>
                  <td><span className="chip">{STATUS_DRAFT[d.status]}</span></td>
                  <td className="num small">{d.distributed ?? 0}{d.failed ? <div style={{ color: "var(--danger)" }}>{d.failed} gagal</div> : null}</td>
                </tr>
              ))}</tbody>
            </table>
          )}
        </div>

        <div className="stack">
          {!sel && <div className="card empty">Pilih naskah untuk menyalurkan ke kanal wilayah.</div>}
          {sel && (
            <div className="card stack" style={{ gap: 10 }}>
              <div className="row between"><h2 style={{ margin: 0 }}>{sel.title}</h2><span className="chip">{STATUS_DRAFT[sel.status]}</span></div>
              <div className="row">
                {([["semua", `Semua (${ch.length})`], ["nasional", "Kanal nasional"], ["pilih", "Pilih kanal"]] as [Scope, string][]).map(([k, v]) => (
                  <label key={k} className="row small" style={{ gap: 4 }}><input type="radio" name="scope" checked={scope === k} onChange={() => setScope(k)} />{v}</label>
                ))}
              </div>
              {scope === "pilih" && <div className="small muted">Klik kotak kanal di bawah untuk memilih ({picked.length} dipilih).</div>}
              {canSend ? (
                <div className="row">
                  <button className="primary" disabled={!!busy || !target} onClick={distribute}>{busy === "go" ? "Menyalurkan…" : `Salurkan ke ${target} kanal`}</button>
                  {sel.status === "disetujui" && <span className="small muted">Naskah akan berstatus Tayang setelah disalurkan; tanda tangan persetujuan diverifikasi dulu.</span>}
                </div>
              ) : <div className="small muted">Penyaluran dilakukan oleh Operator Kanal.</div>}
              {!!dist.length && (
                <div className="table-wrap" style={{ maxHeight: 300, overflowY: "auto" }}>
                  <table>
                    <thead><tr><th>Kanal</th><th>Status</th><th className="num">Tayangan</th><th></th></tr></thead>
                    <tbody>{dist.map((x) => (
                      <tr key={x.id}>
                        <td className="small">{x.channel_name}{x.url && <div><a href={x.url} target="_blank" rel="noreferrer noopener">tautan</a></div>}</td>
                        <td className="small"><span className={`chip ${x.status === "terkirim" ? "st-ok" : "st-bad"}`}>{x.status}</span>{x.error && <div className="muted">{x.error}</div>}{x.sent_at && <div className="muted">{fmtDateTime(x.sent_at)}</div>}</td>
                        <td className="num small">{nf.format(x.views)}</td>
                        <td>{x.status === "gagal" && canSend && <button className="sm" disabled={!!busy} onClick={() => retry(x.id)}>Coba ulang</button>}</td>
                      </tr>
                    ))}</tbody>
                  </table>
                </div>
              )}
            </div>
          )}
        </div>
      </div>

      <div className="card stack" style={{ gap: 10 }}>
        <div className="row between">
          <h2 style={{ margin: 0 }}>Peta kesehatan kanal</h2>
          <div className="row small">
            {["", "normal", "lambat", "gangguan"].map((h) => (
              <button key={h} className={`sm${filter === h ? " primary" : ""}`} onClick={() => setFilter(h)}>
                {h ? <><span className="hdot" style={{ background: HCOLOR[h] }} />{HEALTH_LABEL[h]}</> : "Semua"}
              </button>
            ))}
          </div>
        </div>
        <div className="chgrid">
          {shown.map((c) => {
            const d = doneByCode.get(c.code);
            const pickable = scope === "pilih" && !!sel;
            const on = pickable && picked.includes(c.code);
            const body = (
              <>
                <span className="n" title={c.name}><span className="hdot" style={{ background: HCOLOR[c.health] }} aria-hidden />{c.name.replace("Kanal ", "")}</span>
                <span className="muted">{HEALTH_LABEL[c.health]}{c.latency_ms ? ` · ${c.latency_ms} ms` : ""}</span>
                <span className="muted">{nf.format(c.views_7d)} tayangan · {c.sent_7d} kirim</span>
                {d ? <span style={{ color: d.status === "terkirim" ? "var(--ok-text)" : "var(--danger)", fontWeight: 600 }}>{d.status === "terkirim" ? "✓ naskah ini terkirim" : "✕ naskah ini gagal"}</span>
                  : <span className="muted">terakhir {relTime(c.last_sent_at)}</span>}
              </>
            );
            return pickable ? (
              <button key={c.code} className={`chtile pick h-${c.health}${on ? " on" : ""}`} aria-pressed={on}
                      onClick={() => setPicked(on ? picked.filter((x) => x !== c.code) : [...picked, c.code])}>{body}</button>
            ) : <div key={c.code} className={`chtile h-${c.health}`}>{body}</div>;
          })}
        </div>
      </div>
      </>}
    </div>
  );
}
