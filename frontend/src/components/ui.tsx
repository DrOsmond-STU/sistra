import { useCallback, useEffect, useRef, useState, type ReactNode } from "react";
import type { Level, Sentiment } from "../types";

// Status: warna tetap + ikon + label (tidak pernah warna saja)
const LEVEL_META: Record<Level, { label: string; color: string; icon: string; ink: string }> = {
  merah: { label: "Merah · Kritis", color: "var(--st-critical)", icon: "■", ink: "#fff" },
  oranye: { label: "Oranye · Siaga", color: "var(--st-serious)", icon: "▲", ink: "#fff" },
  kuning: { label: "Kuning · Waspada", color: "var(--st-warning)", icon: "!", ink: "#0b0b0b" },
  hijau: { label: "Hijau · Normal", color: "var(--st-good)", icon: "✓", ink: "#fff" },
};

export function LevelChip({ level, short = false }: { level: Level; short?: boolean }) {
  const m = LEVEL_META[level];
  return (
    <span className="chip" title={m.label}>
      <span className="dot" style={{ background: m.color, color: m.ink }} aria-hidden>
        {m.icon}
      </span>
      {short ? m.label.split(" · ")[0] : m.label}
    </span>
  );
}

export const SENT_COLOR: Record<Sentiment, string> = {
  negatif: "var(--sent-neg)",
  netral: "var(--sent-neu)",
  positif: "var(--sent-pos)",
};

export function SentimentChip({ value }: { value: Sentiment }) {
  const icon = value === "negatif" ? "−" : value === "positif" ? "+" : "·";
  return (
    <span className="chip">
      <span className="dot" style={{ background: SENT_COLOR[value] }} aria-hidden>
        {icon}
      </span>
      {value[0].toUpperCase() + value.slice(1)}
    </span>
  );
}

export function ClassChip({ code, label }: { code: string; label?: string }) {
  return <span className={`chip cls-${code}`}>{label ?? code.replace("_", " ")}</span>;
}

export function Msg({ kind = "info", children }: { kind?: "info" | "error" | "ok" | "warn"; children: ReactNode }) {
  if (!children) return null;
  return (
    <div className={`alert-msg ${kind}`} role={kind === "error" ? "alert" : "status"}>
      {children}
    </div>
  );
}

/** Hook data sederhana: muat ulang bila deps berubah. */
export function useLoad<T>(fn: () => Promise<T>, deps: unknown[]) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string>("");
  const [loading, setLoading] = useState(true);
  const fnRef = useRef(fn);
  fnRef.current = fn;
  const reload = useCallback(() => {
    setLoading(true);
    fnRef
      .current()
      .then((d) => {
        setData(d);
        setError("");
      })
      .catch((e: Error) => setError(e.message))
      .finally(() => setLoading(false));
  }, []);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(reload, deps);
  return { data, error, loading, reload, setData };
}

export function useWidth<T extends HTMLElement>(): [React.RefObject<T>, number] {
  const ref = useRef<T>(null);
  const [w, setW] = useState(0);
  useEffect(() => {
    if (!ref.current) return;
    const ro = new ResizeObserver((entries) => setW(Math.floor(entries[0].contentRect.width)));
    ro.observe(ref.current);
    return () => ro.disconnect();
  }, []);
  return [ref, w];
}
