// Klien API SISTRA. Token disimpan di sessionStorage (hilang saat tab ditutup).
// F1: diganti sesi OIDC (Keycloak) dengan cookie httpOnly.

const TOKEN_KEY = "sistra.token";
const BASE = (import.meta.env.VITE_API_BASE as string | undefined) ?? "";

export function getToken(): string | null {
  try {
    return sessionStorage.getItem(TOKEN_KEY);
  } catch {
    return null;
  }
}

export function setToken(token: string | null): void {
  try {
    if (token) sessionStorage.setItem(TOKEN_KEY, token);
    else sessionStorage.removeItem(TOKEN_KEY);
  } catch {
    /* penyimpanan tidak tersedia */
  }
}

export class ApiError extends Error {
  status: number;
  detail: unknown;
  constructor(status: number, detail: unknown) {
    super(formatDetail(detail));
    this.status = status;
    this.detail = detail;
  }
}

function formatDetail(detail: unknown): string {
  if (typeof detail === "string") return detail;
  if (Array.isArray(detail)) {
    return detail.map((d) => (d && typeof d === "object" && "msg" in d ? String((d as { msg: string }).msg) : String(d))).join("; ");
  }
  if (detail && typeof detail === "object") {
    const d = detail as { message?: string; missing?: string[] };
    if (d.message) return d.missing ? `${d.message}: ${d.missing.join(", ")}` : d.message;
  }
  return "Terjadi kesalahan";
}

type Query = Record<string, string | number | boolean | undefined | null>;

function buildUrl(path: string, query?: Query): string {
  const qs = new URLSearchParams();
  Object.entries(query ?? {}).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== "") qs.set(k, String(v));
  });
  const s = qs.toString();
  return `${BASE}/api${path}${s ? `?${s}` : ""}`;
}

async function request(path: string, init: RequestInit & { query?: Query } = {}): Promise<Response> {
  const headers = new Headers(init.headers);
  const token = getToken();
  if (token) headers.set("Authorization", `Bearer ${token}`);
  const res = await fetch(buildUrl(path, init.query), { ...init, headers });
  if (res.status === 401 && path !== "/auth/login") {
    setToken(null);
    window.dispatchEvent(new Event("sistra:logout"));
  }
  if (!res.ok) {
    let detail: unknown = res.statusText;
    try {
      detail = (await res.json()).detail;
    } catch {
      /* bukan JSON */
    }
    throw new ApiError(res.status, detail);
  }
  return res;
}

export async function api<T>(
  path: string,
  opts: { method?: string; body?: unknown; form?: FormData; query?: Query } = {},
): Promise<T> {
  const init: RequestInit & { query?: Query } = { method: opts.method ?? "GET", query: opts.query };
  if (opts.form) init.body = opts.form;
  else if (opts.body !== undefined) {
    init.body = JSON.stringify(opts.body);
    init.headers = { "Content-Type": "application/json" };
  }
  const res = await request(path, init);
  return (res.status === 204 ? undefined : await res.json()) as T;
}

/** Unduh berkas (dengan watermark server) dan simpan melalui browser. */
export async function downloadFile(path: string, fallbackName: string): Promise<string | null> {
  const res = await request(path);
  const blob = await res.blob();
  const cd = res.headers.get("Content-Disposition") ?? "";
  const match = /filename\*=UTF-8''([^;]+)/.exec(cd);
  const name = match ? decodeURIComponent(match[1]) : fallbackName;
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
  return res.headers.get("X-SISTRA-Watermark");
}
