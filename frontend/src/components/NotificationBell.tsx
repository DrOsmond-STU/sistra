import { useEffect, useRef, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { api } from "../api";
import { relTime } from "../format";

interface Notif { id: number; kind: string; text: string; link: string; read: boolean; at: string }
const KIND: Record<string, string> = {
  persetujuan: "Persetujuan", penugasan: "Penugasan", diskusi: "Diskusi", tugas_unggah: "Tugas unggah", paket_respons: "Paket respons",
  kampanye: "Kampanye", distribusi: "Distribusi", liputan: "Liputan",
};

/** S35: notifikasi tugas & persetujuan. */
export default function NotificationBell() {
  const [data, setData] = useState<{ unread: number; items: Notif[] }>({ unread: 0, items: [] });
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const nav = useNavigate();
  const loc = useLocation();
  const load = () => api<{ unread: number; items: Notif[] }>("/notifications").then(setData).catch(() => undefined);
  useEffect(() => { load(); const t = setInterval(load, 45_000); return () => clearInterval(t); }, [loc.pathname]);
  useEffect(() => {
    const close = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false); };
    document.addEventListener("mousedown", close);
    return () => document.removeEventListener("mousedown", close);
  }, []);
  const markAll = async () => { await api("/notifications/read", { method: "POST", body: {} }); load(); };
  const go = async (n: Notif) => {
    if (!n.read) await api("/notifications/read", { method: "POST", body: { ids: [n.id] } }).catch(() => undefined);
    setOpen(false); load();
    if (n.link) nav(n.link);
  };
  return (
    <div className="bell" ref={ref}>
      <button className="ghost" onClick={() => setOpen((o) => !o)} aria-label={`Notifikasi, ${data.unread} belum dibaca`} aria-expanded={open}>
        🔔{data.unread > 0 && <span className="badge-dot">{data.unread > 9 ? "9+" : data.unread}</span>}
      </button>
      {open && (
        <div className="bell-pop" role="dialog" aria-label="Notifikasi">
          <div className="row between" style={{ padding: "8px 12px", borderBottom: "1px solid var(--border)" }}>
            <b>Notifikasi</b>
            {data.unread > 0 && <button className="sm ghost" onClick={markAll}>Tandai semua dibaca</button>}
          </div>
          {!data.items.length && <div className="empty">Belum ada notifikasi.</div>}
          <div style={{ maxHeight: 380, overflowY: "auto" }}>
            {data.items.map((n) => (
              <button key={n.id} className={`notif${n.read ? "" : " unread"}`} onClick={() => go(n)}>
                <span className="small muted">{KIND[n.kind] ?? n.kind} · {relTime(n.at)}</span>
                <span>{n.text}</span>
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
