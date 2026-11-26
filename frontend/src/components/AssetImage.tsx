import { useEffect, useRef, useState } from "react";
import { getToken } from "../api";

/**
 * Pemuat gambar aset ber-token yang ramah hosting bersama.
 * - <img> tidak dapat mengirim header Authorization, jadi berkas diambil dengan fetch lalu ditampilkan sebagai blob.
 * - Paling banyak MAX_PARALLEL unduhan sekaligus (batas proses server terbatas), dan hanya kartu yang terlihat di layar.
 * - Server sibuk (429/502/503/504/508) atau jaringan putus → dicoba ulang otomatis dengan jeda bertambah.
 * - Gambar yang sudah dimuat disimpan selama sesi sehingga tidak diunduh ulang saat kembali ke halaman.
 */
const BASE = (import.meta.env.VITE_API_BASE as string | undefined) ?? "";
const MAX_PARALLEL = 3;
const RETRY_STATUS = new Set([0, 429, 500, 502, 503, 504, 508]);
const DELAYS = [800, 1600, 3200, 6400];

const cache = new Map<string, string>();
const queue: (() => void)[] = [];
let active = 0;

function pump() {
  while (active < MAX_PARALLEL && queue.length) {
    active += 1;
    queue.shift()!();
  }
}

/** Jalankan tugas dalam antrean dengan batas paralel. */
function enqueue<T>(job: () => Promise<T>): Promise<T> {
  return new Promise<T>((resolve, reject) => {
    queue.push(() => { job().then(resolve, reject).finally(() => { active -= 1; pump(); }); });
    pump();
  });
}

const wait = (ms: number) => new Promise((r) => setTimeout(r, ms));

async function fetchImage(url: string): Promise<string> {
  for (let i = 0; ; i++) {
    let status = 0;
    try {
      const r = await fetch(url, { headers: { Authorization: `Bearer ${getToken()}` } });
      status = r.status;
      if (r.ok) {
        const type = r.headers.get("Content-Type") ?? "";
        if (!type.startsWith("image/")) throw new Error("bukan gambar");
        return URL.createObjectURL(await r.blob());
      }
    } catch (e) {
      if ((e as Error).message === "bukan gambar") throw e;
    }
    if (!RETRY_STATUS.has(status) || i >= DELAYS.length) throw new Error(`HTTP ${status || "jaringan"}`);
    await wait(DELAYS[i] + Math.random() * 400);
  }
}

export function loadAssetImage(id: number, key: string, width?: number): Promise<string> {
  const ck = `${id}:${key}:${width ?? 0}`;
  const hit = cache.get(ck);
  if (hit) return Promise.resolve(hit);
  const url = `${BASE}/api/m5/assets/${id}/file${width ? `?w=${width}` : ""}`;
  return enqueue(() => fetchImage(url)).then((u) => { cache.set(ck, u); return u; });
}

const box = { width: "100%", aspectRatio: "1.9", borderRadius: 6, background: "var(--surface-2)" } as const;

/** Gambar mini aset: dimuat saat masuk layar, antre, dan dapat dicoba ulang bila gagal. */
export default function AssetImage({ id, sha, mime, kind, title, width = 480 }:
  { id: number; sha: string; mime: string; kind: string; title: string; width?: number }) {
  const isImg = mime.startsWith("image/");
  const ck = `${id}:${sha}:${width}`;
  const [src, setSrc] = useState(() => cache.get(ck) ?? "");
  const [state, setState] = useState<"idle" | "loading" | "error">("idle");
  const [visible, setVisible] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!isImg || src || visible || !ref.current) return;
    if (typeof IntersectionObserver === "undefined") { setVisible(true); return; }
    const io = new IntersectionObserver((es) => { if (es.some((e) => e.isIntersecting)) { setVisible(true); io.disconnect(); } }, { rootMargin: "300px" });
    io.observe(ref.current);
    return () => io.disconnect();
  }, [isImg, src, visible]);

  useEffect(() => {
    if (!isImg || src || !visible) return;
    let live = true;
    setState("loading");
    loadAssetImage(id, sha, width).then((u) => { if (live) { setSrc(u); setState("idle"); } }, () => { if (live) setState("error"); });
    return () => { live = false; };
  }, [id, sha, width, isImg, src, visible, attempt]);

  if (src) return <img src={src} alt={title} style={{ ...box, objectFit: "cover" }} onError={() => { cache.delete(ck); setSrc(""); setState("error"); }} />;
  return (
    <div ref={ref} style={{ ...box, display: "grid", placeItems: "center", textAlign: "center" }} className="muted small">
      {!isImg ? kind
        : state === "error" ? <span>Gambar gagal dimuat<br /><button type="button" className="sm" style={{ marginTop: 6 }} onClick={() => { setState("idle"); setAttempt((n) => n + 1); }}>Coba lagi</button></span>
        : "Memuat gambar…"}
    </div>
  );
}
