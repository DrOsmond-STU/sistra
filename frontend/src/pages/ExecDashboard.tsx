import { useState } from "react";
import { Link } from "react-router-dom";
import { api } from "../api";
import { ModeChip, Prio, StackBar, Tile, TrendColumns, TrendLegend, dueText } from "../components/kit";
import { Msg, useLoad } from "../components/ui";
import { fmtDateTime, fmtPct, nf } from "../format";
import { FORMAT_LABEL, type ExecSummary } from "../types47";
import SebaranMap from "../components/geo";

function ImpactCard() {
  const d = useLoad(() => api<{ national: number; issues: { name: string; score: number }[] }>("/m8/impact"), []);
  const r = useLoad(() => api<{ id: number; title: string; auto: boolean }[]>("/reports"), []);
  return (
    <div className="card stack" style={{ gap: 10 }}>
      <div className="row between"><h2 style={{ margin: 0 }}>Efektivitas komunikasi</h2><Link to="/analitik" className="btn sm">Rinci →</Link></div>
      <div className="tile"><div className="label">IEKP nasional</div><div className="value num">{d.data?.national ?? "-"}<span className="small muted"> / 100</span></div></div>
      {d.data?.issues.slice(0, 5).map((i) => <div key={i.name}><div className="row between small"><span>{i.name}</span><b>{i.score}</b></div><div className="meter"><span style={{ width: `${i.score}%` }} /></div></div>)}
      <div className="row between"><b className="small">Laporan terbaru</b><Link to="/laporan" className="small">Semua laporan →</Link></div>
      {r.data?.slice(0, 2).map((x) => <Link key={x.id} to="/laporan" className="small">{x.auto ? "⚙ " : ""}{x.title}</Link>)}
    </div>
  );
}

export default function ExecDashboard() {
  const { data, error, loading, reload } = useLoad(() => api<ExecSummary>("/exec/summary"), []);
  const [brief, setBrief] = useState<{ text: string; mode: string } | null>(null);
  const [briefing, setBriefing] = useState(false);
  const [briefErr, setBriefErr] = useState("");

  const askBrief = async () => {
    setBriefing(true); setBriefErr("");
    try {
      const r = await api<{ reply: string; mode: string }>("/ai/chat", {
        method: "POST",
        body: { page: "dashboard-eksekutif", messages: [{ role: "user", content: "Susun ringkasan eksekutif 5 poin untuk pimpinan hari ini: kondisi produksi, keputusan yang menunggu, risiko media sosial, kesehatan 39 kanal, dan rekomendasi tindakan." }] },
      });
      setBrief({ text: r.reply, mode: r.mode });
    } catch (e) {
      setBriefErr((e as Error).message);
    } finally {
      setBriefing(false);
    }
  };

  if (error) return <Msg kind="error">{error}</Msg>;
  if (loading && !data) return <div className="empty">Memuat dashboard eksekutif…</div>;
  if (!data) return null;
  const k = data.kpi;
  const h = data.channel_health;

  return (
    <div className="stack">
      <div className="row between">
        <div>
          <h1>Dashboard Eksekutif</h1>
          <p className="sub" style={{ marginBottom: 0 }}>Produksi narasi, persetujuan, kanal resmi, dan distribusi 39 wilayah · diperbarui {fmtDateTime(data.generated_at)}</p>
        </div>
        <div className="row">
          <button onClick={reload}>Muat ulang</button>
          <button className="primary" onClick={askBrief} disabled={briefing}>{briefing ? "Menyusun…" : "✦ Ringkasan SISTRA AGENT untuk pimpinan"}</button>
        </div>
      </div>

      {briefErr && <Msg kind="error">{briefErr}</Msg>}
      {brief && (
        <div className="card stack" style={{ gap: 8 }}>
          <div className="row between"><h2 style={{ margin: 0 }}>Ringkasan eksekutif</h2><ModeChip mode={brief.mode} /></div>
          <div style={{ whiteSpace: "pre-wrap" }}>{brief.text}</div>
          <div className="small muted">Disusun SISTRA AGENT dari data SISTRA. Verifikasi sebelum dijadikan dasar keputusan.</div>
        </div>
      )}

      {data.alerts.length > 0 && (
        <div className="card">
          <h2>Perlu perhatian</h2>
          <div className="stack" style={{ gap: 6 }}>
            {data.alerts.map((a, i) => (
              <div key={i} className="row" style={{ gap: 8 }}>
                <span className={`chip prio-${a.level === "tinggi" ? "tinggi" : "normal"}`}>{a.level === "tinggi" ? "▲ Tinggi" : "■ Sedang"}</span>
                <span>{a.text}</span>
              </div>
            ))}
          </div>
        </div>
      )}

      <div className="grid g4">
        <Tile label="Naskah disusun (30 hari)" value={nf.format(k.produced_30d)} foot={`${k.ai_assisted_pct}% dibantu SISTRA AGENT`} />
        <Tile label="Naskah tayang (30 hari)" value={nf.format(k.published_30d)} foot={`${nf.format(k.views_7d)} pembaca/jangkauan 7 hari`} tone="good" />
        <Tile label="Menunggu persetujuan" value={nf.format(k.awaiting_approval)} tone={k.awaiting_approval > 5 ? "warn" : undefined}
              foot={`rerata ${k.avg_approval_hours} jam ke persetujuan · ${k.approved_not_published} siap tayang`} />
        <Tile label="Kanal web sehat" value={`${k.channels_ok} / ${k.channels_total}`} tone={h.gangguan > 0 ? "bad" : h.lambat > 0 ? "warn" : "good"}
              foot={`${k.dist_sent_7d} terkirim · ${k.dist_failed_7d} gagal (7 hari)`} />
        <Tile label="Tugas unggah medsos" value={nf.format(k.post_tasks_pending)} tone={k.post_tasks_overdue ? "warn" : undefined}
              foot={`${k.post_tasks_overdue} melewati jadwal`} />
        <Tile label="Komentar belum ditangani" value={nf.format(k.comments_open)} tone={k.comments_high ? "bad" : undefined}
              foot={`${k.comments_high} prioritas tinggi · ${k.comments_bot} indikasi bot`} />
        <Tile label="Komentar dibalas (30 hari)" value={nf.format(k.comments_replied_30d)} foot={`rerata respons ${k.avg_reply_hours} jam`} />
        <Tile label="Total pengikut akun resmi" value={nf.format(k.followers)} foot="akun contoh, data sintetis" />
      </div>

      <div className="grid g-main">
        <div className="card">
          <div className="viz-head">
            <div><h2 style={{ marginBottom: 2 }}>Produksi 14 hari</h2><div className="small muted">Naskah disusun vs tayang per hari</div></div>
            <TrendLegend />
          </div>
          <TrendColumns data={data.trend} />
        </div>
        <div className="stack">
        <div className="card stack" style={{ gap: 12 }}>
          <div className="row between"><h2 style={{ margin: 0 }}>Kesehatan 39 kanal</h2><Link to="/distribusi" className="btn sm">Distribusi →</Link></div>
          <StackBar parts={[
            { label: "Normal", value: h.normal, color: "var(--st-good)" },
            { label: "Lambat", value: h.lambat, color: "var(--st-warning)" },
            { label: "Gangguan", value: h.gangguan, color: "var(--st-critical)" },
          ]} />
          <div className="small muted">Uji ketersediaan disimulasikan; belum tersambung ke CMS kanal sungguhan.</div>
        </div>
        <ImpactCard />
        </div>
      </div>

      <SebaranMap title="Peta sebaran 38 provinsi" initial="artikel" days={30} />

      <div className="grid g2">
        <div className="card">
          <div className="row between" style={{ marginBottom: 10 }}>
            <h2 style={{ margin: 0 }}>Menunggu keputusan Anda</h2>
            <Link to="/editorial" className="btn sm">Papan editorial →</Link>
          </div>
          {!data.decisions.length && <div className="empty">Tidak ada naskah yang menunggu.</div>}
          <div className="stack" style={{ gap: 8 }}>
            {data.decisions.map((d) => (
              <Link key={d.id} to={`/editorial?id=${d.id}`} className="kcard" style={{ textDecoration: "none", color: "inherit" }}>
                <span className="t">{d.title}</span>
                <span className="meta">
                  <span>{FORMAT_LABEL[d.format] ?? d.format}</span><span>{d.author_name}</span>
                  {d.due_at && <span className={d.overdue ? "late" : ""}>tenggat {dueText(d.due_at)}</span>}
                  <Prio p={d.priority} />
                  {d.editorial_score !== null && <span>skor {d.editorial_score}</span>}
                </span>
              </Link>
            ))}
          </div>
        </div>
        <div className="card table-wrap">
          <h2>Isu prioritas komunikasi</h2>
          <table>
            <thead><tr><th>Isu</th><th className="num">Naskah</th><th className="num">Tayang</th><th className="num">Komentar 7h</th><th className="num">Negatif</th></tr></thead>
            <tbody>
              {data.issues.map((i) => (
                <tr key={i.code}>
                  <td><b>{i.name}</b><div className="small muted">{i.code} · risiko {i.risk_level}</div></td>
                  <td className="num">{i.drafts}</td>
                  <td className="num">{i.published}</td>
                  <td className="num">{i.comments}</td>
                  <td className="num">{fmtPct(i.negative_ratio)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <div className="small muted" style={{ marginTop: 8 }}>Isu dan angka bersifat contoh (sintetis) yang meniru tema aktual.</div>
        </div>
      </div>
    </div>
  );
}
