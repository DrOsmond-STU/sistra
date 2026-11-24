import { useEffect, useMemo, useState } from "react";
import { api } from "../api";
import { fmtDateTime } from "../format";

interface Rev { version: number; title: string; body: string; editor_name: string; at: string }

/** Diff kata (LCS) antara dua teks. */
function diffWords(a: string, b: string): { t: "same" | "add" | "del"; s: string }[] {
  const A = a.split(/(\s+)/), B = b.split(/(\s+)/);
  const n = A.length, m = B.length;
  if (n * m > 2_000_000) return [{ t: "del", s: a }, { t: "add", s: b }];
  const dp = Array.from({ length: n + 1 }, () => new Uint32Array(m + 1));
  for (let i = n - 1; i >= 0; i--) for (let j = m - 1; j >= 0; j--) dp[i][j] = A[i] === B[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
  const out: { t: "same" | "add" | "del"; s: string }[] = [];
  let i = 0, j = 0;
  const push = (t: "same" | "add" | "del", s: string) => { const l = out[out.length - 1]; if (l && l.t === t) l.s += s; else out.push({ t, s }); };
  while (i < n && j < m) {
    if (A[i] === B[j]) { push("same", A[i]); i++; j++; } else if (dp[i + 1][j] >= dp[i][j + 1]) { push("del", A[i]); i++; } else { push("add", B[j]); j++; }
  }
  while (i < n) push("del", A[i++]);
  while (j < m) push("add", B[j++]);
  return out;
}

/** S35: riwayat revisi naskah dengan perbandingan antarversi. */
export default function RevisionDiff({ draftId, version }: { draftId: number; version: number }) {
  const [revs, setRevs] = useState<Rev[]>([]);
  const [a, setA] = useState(0);
  const [b, setB] = useState(0);
  useEffect(() => {
    api<Rev[]>(`/m5/drafts/${draftId}/revisions`).then((r) => { setRevs(r); setB(r.length - 1); setA(Math.max(0, r.length - 2)); }).catch(() => setRevs([]));
  }, [draftId, version]);
  const parts = useMemo(() => (revs[a] && revs[b] ? diffWords(`${revs[a].title}\n\n${revs[a].body}`, `${revs[b].title}\n\n${revs[b].body}`) : []), [revs, a, b]);
  if (revs.length < 2) return <div className="small muted">Baru ada {revs.length} versi; perbandingan tersedia setelah ada revisi.</div>;
  const added = parts.filter((p) => p.t === "add").reduce((x, p) => x + p.s.trim().split(/\s+/).filter(Boolean).length, 0);
  const removed = parts.filter((p) => p.t === "del").reduce((x, p) => x + p.s.trim().split(/\s+/).filter(Boolean).length, 0);
  return (
    <div className="stack" style={{ gap: 8 }}>
      <div className="row">
        <label className="row small">Dari
          <select value={a} onChange={(e) => setA(Number(e.target.value))}>{revs.map((r, i) => <option key={r.version} value={i}>v{r.version} · {r.editor_name}</option>)}</select></label>
        <label className="row small">ke
          <select value={b} onChange={(e) => setB(Number(e.target.value))}>{revs.map((r, i) => <option key={r.version} value={i}>v{r.version} · {fmtDateTime(r.at)}</option>)}</select></label>
        <span className="small"><span className="status-ok">+{added}</span> / <span className="status-bad">−{removed}</span> kata</span>
      </div>
      <div className="diff" aria-label="Perbandingan versi">
        {parts.map((p, i) => (p.t === "same" ? <span key={i}>{p.s}</span> : p.t === "add" ? <ins key={i}>{p.s}</ins> : <del key={i}>{p.s}</del>))}
      </div>
    </div>
  );
}
