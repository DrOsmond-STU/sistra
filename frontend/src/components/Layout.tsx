import { Fragment, useEffect, useState } from "react";
import { Link, NavLink, Outlet, useLocation } from "react-router-dom";
import { useAuth } from "../auth";
import { ROLE_LABEL } from "../format";
import AiAssistant from "./AiAssistant";
import ChangePassword from "./ChangePassword";
import NotificationBell from "./NotificationBell";
import { ClassChip } from "./ui";

interface NavItem { to: string; label: string; icon: string; code?: string; roles?: string[]; group?: string }

// Modul lain (Dashboard Situasi, Peringatan Dini, Konten & Isu, Sumber, Repositori) disembunyikan:
// sudah dikelola aplikasi lain. Fokus SISTRA: M4–M8 + data & CRUD + asisten AI.
const NAV: NavItem[] = [
  { to: "/", label: "Dashboard Eksekutif", icon: "ni-tv-2", group: "Ringkasan" },
  { to: "/peta", label: "Peta Sebaran", icon: "ni-map-big" },
  { to: "/analitik", label: "Dashboard Analitik", icon: "ni-chart-bar-32", code: "M8" },
  { to: "/laporan", label: "Laporan Berkala", icon: "ni-single-copy-04", code: "M8" },
  { to: "/kak", label: "Matriks Kepatuhan KAK", icon: "ni-check-bold" },
  { to: "/produksi", label: "Produksi Narasi", icon: "ni-ruler-pencil", code: "M4", group: "Alur kerja" },
  { to: "/studio", label: "Studio Multimedia", icon: "ni-palette", code: "M4" },
  { to: "/editorial", label: "Editorial & Persetujuan", icon: "ni-paper-diploma", code: "M5" },
  { to: "/kalender", label: "Kalender Terpadu", icon: "ni-calendar-grid-58", code: "M5" },
  { to: "/aset", label: "Aset, Templat & Liputan", icon: "ni-image", code: "M5" },
  { to: "/kanal-resmi", label: "Kanal Medsos Resmi", icon: "ni-chat-round", code: "M6" },
  { to: "/distribusi", label: "Distribusi 39 Wilayah", icon: "ni-world-2", code: "M7" },
  { to: "/data", label: "Data & CRUD", icon: "ni-collection", group: "Data" },
  { to: "/naskah", label: "Daftar Naskah", icon: "ni-bullet-list-67" },
  { to: "/pengaturan-ai", label: "Pengaturan AI", icon: "ni-atom", roles: ["admin"], group: "Sistem" },
  { to: "/audit", label: "Jejak Audit", icon: "ni-lock-circle-open", roles: ["auditor"] },
  { to: "/pengguna", label: "Pengguna", icon: "ni-single-02", roles: ["admin"] },
];

function useTheme(): [string, () => void] {
  const [theme, setTheme] = useState<string>(() => {
    try { return localStorage.getItem("sistra.theme") ?? ""; } catch { return ""; }
  });
  useEffect(() => {
    if (theme) document.documentElement.setAttribute("data-theme", theme);
    else document.documentElement.removeAttribute("data-theme");
    try { localStorage.setItem("sistra.theme", theme); } catch { /* abaikan */ }
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
  const [scrolled, setScrolled] = useState(false);
  const [pwOpen, setPwOpen] = useState(false);
  const [, toggleTheme] = useTheme();
  const loc = useLocation();

  useEffect(() => setOpen(false), [loc.pathname]);
  useEffect(() => {
    const on = () => setScrolled(window.scrollY > 8);
    window.addEventListener("scroll", on, { passive: true });
    return () => window.removeEventListener("scroll", on);
  }, []);

  if (!user) return null;
  const items = NAV.filter((n) => !n.roles || hasRole(...n.roles));
  const current = [...items].sort((a, b) => b.to.length - a.to.length).find((n) => (n.to === "/" ? loc.pathname === "/" : loc.pathname.startsWith(n.to)));
  return (
    <div className="shell">
      {open && <div className="nav-backdrop" onClick={() => setOpen(false)} />}
      <nav className={`nav${open ? " open" : ""}`} aria-label="Navigasi utama">
        <Link to="/" className="brand-row">
          <span className="brand-mark" aria-hidden>S</span>
          <span><span className="brand">SISTRA</span><span className="brand-sub">Produksi · Kanal · Distribusi</span></span>
        </Link>
        <hr className="nav-hr" />
        <div className="nav-items">
          {items.map((n) => (
            <Fragment key={n.to}>
              {n.group && <div className="nav-group">{n.group}</div>}
              <NavLink to={n.to} end={n.to === "/"}>
                <span className="nav-icon" aria-hidden><i className={`ni ${n.icon}`} /></span>
                <span className="nav-label">{n.label}</span>
                {n.code && <span className="mcode">{n.code}</span>}
              </NavLink>
            </Fragment>
          ))}
        </div>
        <div className="nav-help">
          <span className="nav-help-icon" aria-hidden><i className="ni ni-diamond" /></span>
          <b>Kepatuhan KAK</b>
          <span>Pemetaan fitur S26–S65 ke layar aplikasi</span>
          <Link to="/kak" className="btn sm">Buka matriks</Link>
          <span className="small" style={{ opacity: 0.8 }}>Data contoh bersifat sintetis.</span>
        </div>
      </nav>
      <div className="main">
        <header className={`topbar${scrolled ? " scrolled" : ""}`}>
          <button className="menu-btn ghost" onClick={() => setOpen((o) => !o)} aria-label="Buka menu"><i className="ni ni-bullet-list-67" /></button>
          <div className="crumbs">
            <div className="crumb-path"><span>Halaman</span><span aria-hidden> / </span><span className="crumb-cur">{current?.label ?? "SISTRA"}</span></div>
            <div className="crumb-title">{current?.label ?? "SISTRA"}</div>
          </div>
          <div className="topbar-right">
            <div className="who">
              <span className="who-name">{user.full_name}</span>
              <span className="small muted">{user.roles.map((r) => ROLE_LABEL[r] ?? r).join(", ")} · {user.unit.code}</span>
            </div>
            <ClassChip code={user.clearance} label={user.clearance_label} />
            <NotificationBell />
            <button className="ghost icon-btn" onClick={toggleTheme} aria-label="Ganti tema terang/gelap" title="Tema terang/gelap"><i className="ni ni-settings-gear-65" /></button>
            <button className="ghost icon-btn" onClick={() => setPwOpen(true)} title="Ganti kata sandi" aria-label="Ganti kata sandi"><i className="ni ni-key-25" /></button>
            <button className="ghost icon-btn" onClick={logout} title="Keluar" aria-label="Keluar"><i className="ni ni-button-power" /><span className="hide-sm"> Keluar</span></button>
          </div>
        </header>
        <main className="content">
          <Outlet />
        </main>
        <footer className="foot-note">© 2026 SISTRA · Tema berbasis <a href="/theme/LICENSE-soft-ui-dashboard.txt" target="_blank" rel="noreferrer">Soft UI Dashboard Tailwind</a> (Creative Tim, MIT) · Data contoh sintetis</footer>
        <AiAssistant />
        {pwOpen && <ChangePassword onClose={() => setPwOpen(false)} />}
      </div>
    </div>
  );
}
