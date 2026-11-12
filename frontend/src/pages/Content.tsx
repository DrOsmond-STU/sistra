import { useState } from "react";
import { useSearchParams } from "react-router-dom";
import { api } from "../api";
import { useAuth } from "../auth";
import { Msg, SentimentChip, useLoad } from "../components/ui";
import { fmtDateTime, nf } from "../format";
import type { ContentItem, Issue } from "../types";

const PAGE = 25;

export default function ContentPage() {
  const { hasRole } = useAuth();
  const [params, setParams] = useSearchParams();
  const [q, setQ] = useState(params.get("q") ?? "");
  const [offset, setOffset] = useState(0);
  const [selected, setSelected] = useState<ContentItem | null>(null);
  const [correction, setCorrection] = useState<string[]>([]);
  const [msg, setMsg] = useState<{ kind: "ok" | "error"; text: string } | null>(null);

  const filters = {
    q: params.get("q") ?? undefined,
    issue: params.get("issue") ?? undefined,
    province: params.get("province") ?? undefined,
    sentiment: params.get("sentiment") ?? undefined,
  };
  const issues = useLoad(() => api<Issue[]>("/issues"), []);
  const provinces = useLoad(() => api<{ code: string; name: string }[]>("/provinces"), []);
  const list = useLoad(
    () => api<{ total: number; items: ContentItem[] }>("/content", { query: { ...filters, limit: PAGE, offset } }),
    [params.toString(), offset],
  );

  const setFilter = (key: string, value: string) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    setParams(next);
    setOffset(0);
  };

  const openItem = async (id: number) => {
    const item = await api<ContentItem>(`/content/${id}`);
    setSelected(item);
    setCorrection(item.issues.map((i) => i.code));
    setMsg(null);
  };

  const saveCorrection = async () => {
    if (!selected) return;
    try {
      const item = await api<ContentItem>(`/content/${selected.id}/issues`, { method: "PUT", body: { issue_codes: correction } });
      setSelected(item);
      setMsg({ kind: "ok", text: "Koreksi isu tersimpan dan akan dipakai sebagai data latih." });
      list.reload();
    } catch (e) {
      setMsg({ kind: "error", text: (e as Error).message });
    }
  };

  return (
    <div className="stack">
      <div>
        <h1>Konten & Isu</h1>
        <p className="sub" style={{ marginBottom: 0 }}>Hasil penyerapan yang telah diperkaya: sentimen, provinsi, dan klasifikasi isu</p>
      </div>
      <form className="card row" onSubmit={(e) => { e.preventDefault(); setFilter("q", q.trim()); }}>
        <input placeholder="Cari kata kunci…" value={q} onChange={(e) => setQ(e.target.value)} style={{ flex: "1 1 220px" }} aria-label="Kata kunci" />
        <select value={filters.issue ?? ""} onChange={(e) => setFilter("issue", e.target.value)} aria-label="Isu">
          <option value="">Semua isu</option>
          {issues.data?.map((i) => <option key={i.code} value={i.code}>{i.name}</option>)}
        </select>
        <select value={filters.province ?? ""} onChange={(e) => setFilter("province", e.target.value)} aria-label="Provinsi">
          <option value="">Semua provinsi</option>
          {provinces.data?.map((p) => <option key={p.code} value={p.code}>{p.name}</option>)}
        </select>
        <select value={filters.sentiment ?? ""} onChange={(e) => setFilter("sentiment", e.target.value)} aria-label="Sentimen">
          <option value="">Semua sentimen</option>
          <option value="negatif">Negatif</option>
          <option value="netral">Netral</option>
          <option value="positif">Positif</option>
        </select>
        <button className="primary" type="submit">Cari</button>
      </form>

      <div className="split">
        <div className="card">
          {list.error && <Msg kind="error">{list.error}</Msg>}
          <div className="row between small muted" style={{ marginBottom: 8 }}>
            <span>{list.data ? `${nf.format(list.data.total)} konten (duplikat disembunyikan)` : "Memuat…"}</span>
            <span className="row">
              <button className="sm" disabled={offset === 0} onClick={() => setOffset(Math.max(0, offset - PAGE))}>‹ Sebelumnya</button>
              <button className="sm" disabled={!list.data || offset + PAGE >= list.data.total} onClick={() => setOffset(offset + PAGE)}>Berikutnya ›</button>
            </span>
          </div>
          {list.data?.items.length === 0 && <div className="empty">Tidak ada konten yang cocok.</div>}
          <div className="stack" style={{ gap: 0 }}>
            {list.data?.items.map((c) => (
              <div key={c.id} onClick={() => openItem(c.id)} className="clickable"
                   style={{ padding: "10px 6px", borderBottom: "1px solid var(--grid)", cursor: "pointer",
                            background: selected?.id === c.id ? "var(--accent-soft)" : undefined }}>
                <div className="row between">
                  <b>{c.title}</b>
                  <SentimentChip value={c.sentiment_label} />
                </div>
                <div className="small muted">{c.source_name} · {fmtDateTime(c.published_at)}{c.province_name ? ` · ${c.province_name}` : ""}</div>
                <div className="row small" style={{ marginTop: 4, gap: 6 }}>
                  {c.issues.map((i) => <span key={i.code} className="chip">{i.name}</span>)}
                </div>
              </div>
            ))}
          </div>
        </div>
        <div className="card">
          {!selected ? (
            <div className="empty">Pilih konten untuk melihat isi lengkap.</div>
          ) : (
            <div className="stack">
              <h2 style={{ margin: 0 }}>{selected.title}</h2>
              <dl className="kv">
                <dt>Sumber</dt><dd>{selected.source_name} (keandalan {selected.source_reliability})</dd>
                <dt>Terbit</dt><dd>{fmtDateTime(selected.published_at)}</dd>
                <dt>Sentimen</dt><dd><SentimentChip value={selected.sentiment_label} /> <span className="small muted">skor {selected.sentiment_score}</span></dd>
                <dt>Provinsi</dt><dd>{selected.province_name ?? "-"}</dd>
                {selected.url && <><dt>URL</dt><dd className="mono">{selected.url}</dd></>}
              </dl>
              <div className="body-text">{selected.body}</div>
              {msg && <Msg kind={msg.kind}>{msg.text}</Msg>}
              <div>
                <h3>Klasifikasi isu</h3>
                {hasRole("analis") ? (
                  <div className="stack" style={{ gap: 8 }}>
                    <div className="row">
                      {issues.data?.map((i) => (
                        <label key={i.code} className="chip" style={{ cursor: "pointer" }}>
                          <input type="checkbox" checked={correction.includes(i.code)} style={{ margin: 0 }}
                                 onChange={(e) => setCorrection((c) => e.target.checked ? [...c, i.code] : c.filter((x) => x !== i.code))} />
                          {i.name}
                        </label>
                      ))}
                    </div>
                    <div><button onClick={saveCorrection}>Simpan koreksi analis</button></div>
                  </div>
                ) : (
                  <div className="row">{selected.issues.map((i) => <span className="chip" key={i.code}>{i.name}</span>)}</div>
                )}
                <div className="small muted" style={{ marginTop: 6 }}>
                  {selected.issues.map((i) => `${i.code}: ${i.assigned_by === "analyst" ? "koreksi analis" : `model ${Math.round(i.confidence * 100)}%`}`).join(" · ")}
                </div>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
