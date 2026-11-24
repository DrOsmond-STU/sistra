import { Fragment, useEffect, useState } from "react";
import { NavLink, Outlet, useLocation } from "react-router-dom";
import { useAuth } from "../auth";
import { ROLE_LABEL } from "../format";
import AiAssistant from "./AiAssistant";
import NotificationBell from "./NotificationBell";
import { ClassChip } from "./ui";

interface NavItem { to: string; label: string; code?: string; roles?: string[]; group?: string }

// Modul lain (Dashboard Situasi, Peringatan Dini, Konten & Isu, Sumber, Repositori) disembunyikan:
// sudah dikelola aplikasi lain. Fokus SISTRA: M4–M7 + dashboard + asisten AI.
const NAV: NavItem[] = [
  { to: "/", label: "Dashboard Eksekutif", group: "Ringkasan" },
  { to: "/analitik", label: "Dashboard Analitik", code: "M8" },
  { to: "/laporan", label: "Laporan Berkala", code: "M8" },
  { to: "/kak", label: "Matriks Kepatuhan KAK" },
  { to: "/produksi", label: "Produksi Narasi", code: "M4", group: "Alur kerja" },
  { to: "/studio", label: "Studio Multimedia", code: "M4" },
  { to: "/editorial", label: "Editorial & Persetujuan", code: "M5" },
  { to: "/kalender", label: "Kalender Terpadu", code: "M5" },
  { to: "/aset", label: "Aset, Templat & Liputan", code: "M5" },
  { to: "/kanal-resmi", label: "Kanal Medsos Resmi", code: "M6" },
  { to: "/distribusi", label: "Distribusi 39 Wilayah", code: "M7" },
  { to: "/naskah", label: "Daftar Naskah", group: "Lainnya" },
  { to: "/pengaturan-ai", label: "Pengaturan AI", roles: ["admin"] },
  { to: "/audit", label: "Jejak Audit", roles: ["auditor"] },
  { to: "/pengguna", label: "Pengguna", roles: ["admin"] },
];

function useTheme(): [string, () => void] {
  const [theme, setTheme] = useState<string>(() => {
    try {
      return localStorage.getItem("sistra.theme") ?? "";
    } catch {
      return "";
    }
  });
  useEffect(() => {
    if (theme) document.documentElement.setAttribute("data-theme", theme);
    else document.documentElement.removeAttribute("data-theme");
    try {
      localStorage.setItem("sistra.theme", theme);
    } catch {
      /* abaikan */
    }
  }, [theme]);
  const toggle = () => {
    const dark = theme ? theme === "dark" : window.matchMedia("(prefers-color-scheme: dark)").matches;
    setTheme(dark ? "light" : "dark");
  };
  return [theme, toggle];
}

export default function Layout() {
  const { user, logout, hasRole } = useAuth();
  const [open, setOpen] = useState(false);
  const [, toggleTheme] = useTheme();
  const loc = useLocation();

  useEffect(() => setOpen(false), [loc.pathname]);

  if (!user) return null;
  return (
    <div className="shell">
      <nav className={`nav${open ? " open" : ""}`} aria-label="Navigasi utama">
        <div className="brand">SISTRA</div>
        <div className="brand-sub">Produksi · Persetujuan · Kanal · Distribusi</div>
        {NAV.filter((n) => !n.roles || hasRole(...n.roles)).map((n) => (
          <Fragment key={n.to}>
            {n.group && <div className="nav-group">{n.group}</div>}
            <NavLink to={n.to} end={n.to === "/"}>
              <span>{n.code && <span className="mcode">{n.code}</span>}{n.label}</span>
            </NavLink>
          </Fragment>
        ))}
        <div className="spacer" />
        <div className="small" style={{ padding: "0 10px", opacity: 0.7 }}>
          Data contoh bersifat sintetis.
        </div>
      </nav>
      <div className="main">
        <header className="topbar">
          <button className="menu-btn ghost" onClick={() => setOpen((o) => !o)} aria-label="Buka menu">☰ Menu</button>
          <div style={{ textAlign: "right", lineHeight: 1.25 }}>
            <div style={{ fontWeight: 600 }}>{user.full_name}</div>
            <div className="small muted">
              {user.roles.map((r) => ROLE_LABEL[r] ?? r).join(", ")} · {user.unit.code}
            </div>
          </div>
          <NotificationBell />
          <ClassChip code={user.clearance} label={`Clearance: ${user.clearance_label}`} />
          <button className="ghost" onClick={toggleTheme} aria-label="Ganti tema terang/gelap">Tema</button>
          <button onClick={logout}>Keluar</button>
        </header>
        <main className="content">
          <Outlet />
        </main>
        <AiAssistant />
      </div>
    </div>
  );
}
