import { useState } from "react";
import { Link } from "react-router-dom";
import { api } from "../api";
import { ProvinceBars, SentimentLegend, SentimentTimeline } from "../components/charts";
import { LevelChip, Msg, useLoad } from "../components/ui";
import { fmtDateTime, fmtPct, fmtShortDate, nf, relTime } from "../format";
import type { DashboardSummary } from "../types";

function Tile({ label, value, foot }: { label: string; value: string; foot?: React.ReactNode }) {
  return (
    <div className="card tile">
      <div className="label">{label}</div>
      <div className="value">{value}</div>
      {foot && <div className="foot">{foot}</div>}
    </div>
  );
}

export default function Dashboard() {
  const { data, error, loading } = useLoad(() => api<DashboardSummary>("/dashboard/summary"), []);
  const [tableTimeline, setTableTimeline] = useState(false);
  const [tableProv, setTableProv] = useState(false);

  if (error) return <Msg kind="error">{error}</Msg>;
  if (loading && !data) return <div className="empty">Memuat dashboard…</div>;
  if (!data) return null;

  const s = data.content.sentiment;
  const totalAlerts = data.alerts.merah + data.alerts.oranye + data.alerts.kuning;
  const pending = (data.drafts.ditinjau ?? 0) + (data.drafts.disetujui ?? 0);

  return (
    <div className="stack">
      <div>
        <h1>Dashboard Situasi</h1>
        <p className="sub" style={{ marginBottom: 0 }}>
          Jendela {data.window_hours} jam · diperbarui {fmtDateTime(data.generated_at)} · penyerapan terakhir {relTime(data.last_ingest_at)}
        </p>
      </div>

      <div className="grid g4">
        <Tile label={`Konten ${data.window_hours} jam terakhir`} value={nf.format(data.content.total_window)}
              foot={`${fmtPct(data.content.total_window ? s.negatif / data.content.total_window : 0)} bersentimen negatif`} />
        <Tile label="Peringatan aktif" value={nf.format(totalAlerts)}
              foot={<span className="row" style={{ gap: 6 }}>
                {data.alerts.merah > 0 && <LevelChip level="merah" short />}
                {data.alerts.oranye > 0 && <LevelChip level="oranye" short />}
                {data.alerts.kuning > 0 && <LevelChip level="kuning" short />}
                {totalAlerts === 0 && "Tidak ada"}
              </span>} />
        <Tile label="Cakupan provinsi" value={`${data.content.provinces_covered} / 38`} foot="provinsi dengan pemberitaan" />
        <Tile label="Naskah menunggu proses" value={nf.format(pending)}
              foot={`${data.drafts.ditinjau ?? 0} ditinjau · ${data.drafts.disetujui ?? 0} siap tayang`} />
      </div>

      <div className="card">
        <div className="viz-head">
          <div>
            <h2 style={{ marginBottom: 2 }}>Prioritas isu</h2>
            <div className="small muted">Penilaian otomatis 24 jam vs rerata 7 hari sebelumnya</div>
          </div>
          <Link to="/peringatan" className="btn">Kelola peringatan →</Link>
        </div>
        <div className="table-wrap">
          <table>
            <thead>
              <tr><th>Isu</th><th>Tingkat</th><th className="num">Volume 24j</th><th className="num">Pertumbuhan</th>
                <th className="num">Negatif</th><th className="num">Provinsi</th><th>Alasan</th></tr>
            </thead>
            <tbody>
              {data.issues.map((i) => (
                <tr key={i.issue_id}>
                  <td><Link to={`/konten?issue=${i.code}`}>{i.name}</Link><div className="small muted">{i.code}</div></td>
                  <td><LevelChip level={i.level} short /></td>
                  <td className="num">{nf.format(i.volume_24h)}</td>
                  <td className="num">{i.growth.toFixed(1)}×</td>
                  <td className="num">{fmtPct(i.negative_ratio)}</td>
                  <td className="num">{i.province_spread}</td>
                  <td className="small">{i.reason}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="grid g-main">
        <div className="card">
          <div className="viz-head">
            <div>
              <h2 style={{ marginBottom: 2 }}>Volume konten harian menurut sentimen</h2>
              <div className="small muted">14 hari terakhir</div>
            </div>
            <div className="row">
              <SentimentLegend />
              <button className="sm" onClick={() => setTableTimeline((v) => !v)}>{tableTimeline ? "Grafik" : "Tabel"}</button>
            </div>
          </div>
          {tableTimeline ? (
            <div className="table-wrap">
              <table>
                <thead><tr><th>Tanggal</th><th className="num">Negatif</th><th className="num">Netral</th><th className="num">Positif</th><th className="num">Total</th></tr></thead>
                <tbody>{data.timeline.map((d) => (
                  <tr key={d.date}><td>{fmtShortDate(d.date)}</td><td className="num">{d.negatif}</td><td className="num">{d.netral}</td>
                    <td className="num">{d.positif}</td><td className="num">{d.total}</td></tr>
                ))}</tbody>
              </table>
            </div>
          ) : (
            <SentimentTimeline data={data.timeline} />
          )}
        </div>
        <div className="card">
          <div className="viz-head">
            <div>
              <h2 style={{ marginBottom: 2 }}>Provinsi teratas</h2>
              <div className="small muted">Volume konten {data.window_hours} jam terakhir</div>
            </div>
            <button className="sm" onClick={() => setTableProv((v) => !v)}>{tableProv ? "Grafik" : "Tabel 38"}</button>
          </div>
          {tableProv ? (
            <div className="table-wrap" style={{ maxHeight: 300, overflow: "auto" }}>
              <table>
                <thead><tr><th>Provinsi</th><th className="num">Volume</th><th className="num">Negatif</th></tr></thead>
                <tbody>{data.provinces.map((p) => (
                  <tr key={p.code}><td>{p.name}</td><td className="num">{p.volume}</td><td className="num">{p.negative}</td></tr>
                ))}</tbody>
              </table>
            </div>
          ) : (
            <ProvinceBars data={data.provinces} />
          )}
        </div>
      </div>

      <div className="small muted">
        {nf.format(data.content.total_all)} konten tersimpan · {data.sources} sumber aktif · {data.documents_accessible} dokumen dapat Anda akses
      </div>
    </div>
  );
}
