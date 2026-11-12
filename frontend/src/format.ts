export const nf = new Intl.NumberFormat("id-ID");

export function fmtDateTime(iso: string | null | undefined): string {
  if (!iso) return "-";
  return new Date(iso).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" });
}

export function fmtDate(iso: string | null | undefined): string {
  if (!iso) return "-";
  const d = iso.length === 10 ? new Date(`${iso}T00:00:00`) : new Date(iso);
  return d.toLocaleDateString("id-ID", { day: "numeric", month: "short", year: "numeric" });
}

export function fmtShortDate(iso: string): string {
  return new Date(`${iso}T00:00:00`).toLocaleDateString("id-ID", { day: "numeric", month: "short" });
}

export function fmtPct(x: number): string {
  return `${Math.round(x * 100)}%`;
}

export function fmtSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

export function relTime(iso: string | null): string {
  if (!iso) return "-";
  const mins = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
  if (mins < 1) return "baru saja";
  if (mins < 60) return `${mins} menit lalu`;
  const h = Math.round(mins / 60);
  if (h < 48) return `${h} jam lalu`;
  return `${Math.round(h / 24)} hari lalu`;
}

export function toLocalInput(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export const STATUS_DRAFT: Record<string, string> = {
  draf: "Draf", ditinjau: "Ditinjau", disetujui: "Disetujui", tayang: "Tayang", ditarik: "Ditarik",
};

export const ACTION_LABEL: Record<string, string> = {
  ditinjau: "Ajukan untuk ditinjau", draf: "Kembalikan ke penulis", disetujui: "Setujui & tandatangani",
  tayang: "Tayangkan", ditarik: "Tarik / batalkan",
};

export const ALERT_STATUS: Record<string, string> = {
  open: "Baru", ack: "Diterima", in_progress: "Ditangani", resolved: "Selesai",
};

export const ROLE_LABEL: Record<string, string> = {
  pimpinan: "Pimpinan", analis: "Analis", redaktur: "Redaktur", produser: "Produser", operator: "Operator Kanal",
  arsiparis: "Arsiparis", pelapor: "Pelapor", admin: "Administrator", auditor: "Auditor",
};
