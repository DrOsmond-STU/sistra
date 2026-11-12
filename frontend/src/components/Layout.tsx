import { useEffect, useState } from "react";
import { NavLink, Outlet, useLocation } from "react-router-dom";
import { api } from "../api";
import { useAuth } from "../auth";
import { ROLE_LABEL } from "../format";
import { ClassChip } from "./ui";

interface NavItem { to: string; label: string; roles?: string[] }

const NAV: NavItem[] = [
  { to: "/", label: "Dashboard Situasi" },
  { to: "/peringatan", label: "Peringatan Dini" },
  { to: "/konten", label: "Konten & Isu" },
  { to: "/sumber", label: "Sumber & Impor", roles: ["analis", "admin"] },
  { to: "/naskah", label: "Naskah & Persetujuan" },
  { to: "/kalender", label: "Kalender Distribusi" },
  { to: "/repositori", label: "Repositori Dokumen" },
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
  const [redAlerts, setRedAlerts] = useState(0);
  const [, toggleTheme] = useTheme();
  const loc = useLocation();

  useEffect(() => setOpen(false), [loc.pathname]);
  useEffect(() => {
    const load = () =>
      api<{ level: string }[]>("/alerts")
        .then((a) => setRedAlerts(a.filter((x) => x.level === "merah" || x.level === "oranye").length))
        .catch(() => undefined);
    load();
    const t = setInterval(load, 60_000);
    return () => clearInterval(t);
  }, [loc.pathname]);

  if (!user) return null;
  return (
    <div className="shell">
      <nav className={`nav${open ? " open" : ""}`} aria-label="Navigasi utama">
        <div className="brand">SISTRA</div>
        <div className="brand-sub">Sistem Informasi dan Aksi Strategis · MVP</div>
        {NAV.filter((n) => !n.roles || hasRole(...n.roles)).map((n) => (
          <NavLink key={n.to} to={n.to} end={n.to === "/"}>
            {n.label}
            {n.to === "/peringatan" && redAlerts > 0 && <span className="badge" aria-label={`${redAlerts} peringatan tinggi`}>{redAlerts}</span>}
          </NavLink>
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
          <ClassChip code={user.clearance} label={`Clearance: ${user.clearance_label}`} />
          <button className="ghost" onClick={toggleTheme} aria-label="Ganti tema terang/gelap">Tema</button>
          <button onClick={logout}>Keluar</button>
        </header>
        <main className="content">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
