import { useState } from "react";
import { api } from "../api";
import { BarList, Tile } from "../components/kit";
import { EngagementTab, ImpactTab, QualityTab } from "./AnalyticsKak";
import { Msg, useLoad } from "../components/ui";
import { STATUS_DRAFT, fmtPct, nf } from "../format";
import { AI_FEATURE_LABEL, CATEGORY_LABEL, COMMENT_STATUS, FORMAT_LABEL, HEALTH_LABEL, type Analytics } from "../types47";
import SebaranMap from "../components/geo";

const TABS = [
  ["m4", "M4 · Produksi"], ["m5", "M5 · Editorial"], ["m6", "M6 · Kanal resmi"], ["m7", "M7 · Distribusi"], ["eng", "Keterlibatan per jam (S56)"],
  ["dampak", "Dampak & IEKP (S57, S59)"], ["mutu", "Mutu SISTRA AGENT (S64)"], ["ai", "Penggunaan SISTRA AGENT"],
] as const;

export default function AnalyticsPage() {
  const [days, setDays] = useState(30);
  const [tab, setTab] = useState<(typeof TABS)[number][0]>("m4");
  const { data, error, loading } = useLoad(() => api<Analytics>("/analytics/overview", { query: { days } }), [days]);

  return (
    <div className="stack">
      <div className="row between">
        <div>
          <h1>Dashboard Analitik</h1>
          <p className="sub" style={{ marginBottom: 0 }}>Kinerja produksi, persetujuan, kanal resmi, distribusi 39 wilayah, dan pemakaian SISTRA AGENT</p>
        </div>
        <label className="row small">Periode
          <select value={days} onChange={(e) => setDays(Number(e.target.value))}>
            <option value={7}>7 hari</option><option value={14}>14 hari</option><option value={30}>30 hari</option><option value={90}>90 hari</option>
          </select>
        </label>
      </div>
      {error && <Msg kind="error">{error}</Msg>}
      {loading && !data && <div className="empty">Memuat analitik…</div>}
      {data && (
        <>
          <div className="grid g4">
            <Tile label="Naskah disusun" value={nf.format(data.production.total)} foot={`${data.production.ai_assisted} dibantu SISTRA AGENT (${fmtPct(data.production.total ? data.production.ai_assisted / data.production.total : 0)})`} />
            <Tile label="Rerata skor editorial" value={data.editorial.avg_score ?? "-"} foot={`${data.editorial.checked} naskah diperiksa · ${data.editorial.returned} dikembalikan`} />
            <Tile label="Distribusi kanal web" value={nf.format(data.distribution.sent)} foot={`${nf.format(data.distribution.views)} tayangan · ${data.distribution.failed} gagal`} />
            <Tile label="Respons komentar" value={`${data.social.avg_reply_hours} jam`} foot={`${data.social.replied} dibalas · ${data.social.within_sla} dalam SLA 2 jam`} />
          </div>

          <SebaranMap title="Sebaran artikel & kanal" layers={["artikel", "naskah", "kanal", "akun"]} initial="artikel" height={380} />

          <div className="tabs" role="tablist">
            {TABS.map(([id, label]) => <button key={id} role="tab" aria-selected={tab === id} className={tab === id ? "on" : ""} onClick={() => setTab(id)}>{label}</button>)}
          </div>

          {tab === "m4" && (
            <div className="grid g2">
              <div className="card"><h2>Per format konten</h2><BarList data={data.production.by_format} labels={FORMAT_LABEL} /></div>
              <div className="card"><h2>Per isu</h2><BarList data={data.production.by_issue} /></div>
              <div className="card"><h2>Per penyusun</h2><BarList data={data.production.by_author} /></div>
              <div className="card"><h2>Status naskah</h2><BarList data={data.production.by_status} labels={STATUS_DRAFT} /></div>
            </div>
          )}

          {tab === "m5" && (
            <div className="grid g2">
              <div className="card">
                <h2>Rerata waktu per tahap (jam)</h2>
                <BarList data={data.editorial.stage_hours.map((s) => ({ key: s.stage, value: s.hours }))} unit=" j" />
                <div className="small muted" style={{ marginTop: 10 }}>Tahap terlama adalah titik hambat yang perlu diurai pimpinan/redaktur.</div>
              </div>
              <div className="card stack" style={{ gap: 10 }}>
                <h2 style={{ margin: 0 }}>Mutu editorial</h2>
                <dl className="kv">
                  <dt>Rerata skor SISTRA AGENT</dt><dd>{data.editorial.avg_score ?? "-"} / 100</dd>
                  <dt>Naskah diperiksa</dt><dd>{data.editorial.checked}</dd>
                  <dt>Dikembalikan ke penulis</dt><dd>{data.editorial.returned}</dd>
                </dl>
                <div className="small muted">Skor dihitung oleh pemeriksa editorial SISTRA AGENT (ejaan, SARA, nada, fakta, hukum, kejelasan). Keputusan tetap di tangan redaktur.</div>
              </div>
            </div>
          )}

          {tab === "m6" && (
            <div className="stack">
              <div className="grid g3">
                <div className="card"><h2>Kategori komentar</h2><BarList data={data.social.categories} labels={CATEGORY_LABEL} /></div>
                <div className="card"><h2>Sentimen</h2><BarList data={data.social.sentiment} /></div>
                <div className="card"><h2>Status penanganan</h2><BarList data={data.social.status} labels={COMMENT_STATUS} /></div>
              </div>
              <div className="card table-wrap">
                <h2>Kinerja akun resmi</h2>
                <table>
                  <thead><tr><th>Akun</th><th className="num">Pengikut</th><th className="num">Unggahan</th><th className="num">Jangkauan</th><th className="num">Interaksi</th><th className="num">ER</th><th className="num">Komentar</th><th className="num">Dibalas</th></tr></thead>
                  <tbody>{data.social.accounts.map((a) => (
                    <tr key={a.handle + a.platform}>
                      <td><b>{a.handle}</b><div className="small muted">{a.platform}</div></td>
                      <td className="num">{nf.format(a.followers)}</td><td className="num">{a.posts}</td><td className="num">{nf.format(a.reach)}</td>
                      <td className="num">{nf.format(a.engagement)}</td><td className="num">{(a.engagement_rate * 100).toFixed(1)}%</td>
                      <td className="num">{a.comments}</td><td className="num">{a.replied}</td>
                    </tr>
                  ))}</tbody>
                </table>
              </div>
            </div>
          )}

          {tab === "m7" && (
            <div className="grid g-main">
              <div className="card">
                <h2>Tayangan per kanal (10 teratas)</h2>
                <BarList data={data.distribution.channels.map((c) => ({ key: c.name, value: c.views }))} />
              </div>
              <div className="card table-wrap" style={{ maxHeight: 520, overflowY: "auto" }}>
                <h2>Semua kanal</h2>
                <table>
                  <thead><tr><th>Kanal</th><th className="num">Kirim</th><th className="num">Gagal</th><th className="num">CTR</th></tr></thead>
                  <tbody>{data.distribution.channels.map((c) => (
                    <tr key={c.code}>
                      <td>{c.name}<div className="small muted">{HEALTH_LABEL[c.health] ?? c.health}</div></td>
                      <td className="num">{c.sent}</td><td className="num">{c.failed}</td><td className="num">{(c.ctr * 100).toFixed(1)}%</td>
                    </tr>
                  ))}</tbody>
                </table>
              </div>
            </div>
          )}

          {tab === "eng" && <EngagementTab />}
          {tab === "dampak" && <ImpactTab />}
          {tab === "mutu" && <QualityTab />}
          {tab === "ai" && (
            <div className="card table-wrap">
              <h2>Pemakaian SISTRA AGENT per fitur</h2>
              {!data.ai.length && <div className="empty">Belum ada pemakaian SISTRA AGENT pada periode ini.</div>}
              {!!data.ai.length && (
                <table>
                  <thead><tr><th>Fitur</th><th className="num">Panggilan</th><th className="num">Oleh SISTRA AGENT</th><th className="num">Token masuk</th><th className="num">Token keluar</th><th className="num">Rerata waktu</th><th className="num">Galat</th></tr></thead>
                  <tbody>{data.ai.map((a) => (
                    <tr key={a.feature}>
                      <td>{AI_FEATURE_LABEL[a.feature] ?? a.feature}</td><td className="num">{a.calls}</td><td className="num">{a.live}</td>
                      <td className="num">{nf.format(a.input_tokens)}</td><td className="num">{nf.format(a.output_tokens)}</td>
                      <td className="num">{a.avg_ms ? `${(a.avg_ms / 1000).toFixed(1)} dtk` : "-"}</td><td className="num">{a.errors}</td>
                    </tr>
                  ))}</tbody>
                </table>
              )}
              <div className="small muted" style={{ marginTop: 8 }}>Panggilan "simulasi" tidak memakai token. Isi kunci API di Pengaturan SISTRA AGENT untuk mengaktifkan SISTRA AGENT.</div>
            </div>
          )}
        </>
      )}
    </div>
  );
}
