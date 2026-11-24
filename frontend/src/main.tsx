import React from "react";
import ReactDOM from "react-dom/client";
import { BrowserRouter, Navigate, Route, Routes } from "react-router-dom";
import { AuthProvider, useAuth } from "./auth";
import Layout from "./components/Layout";
import AuditPage from "./pages/Audit";
import CalendarPage from "./pages/Calendar";
import AiSettingsPage from "./pages/AiSettings";
import AssetsPage from "./pages/Assets";
import KakMatrix from "./pages/KakMatrix";
import PressPortal from "./pages/PressPortal";
import ReportsPage from "./pages/Reports";
import StudioPage from "./pages/Studio";
import AnalyticsPage from "./pages/Analytics";
import DistributionPage from "./pages/Distribution";
import DraftsPage from "./pages/Drafts";
import EditorialPage from "./pages/Editorial";
import ExecDashboard from "./pages/ExecDashboard";
import ProductionPage from "./pages/Production";
import SocialPage from "./pages/Social";
import LoginPage from "./pages/Login";
import UsersPage from "./pages/Users";
import "./styles.css";

function Guarded() {
  const { user, loading } = useAuth();
  // S53: portal media publik (tanpa akun) memakai tautan berwaktu
  const press = window.location.pathname.match(/^\/pers\/([A-Za-z0-9_-]+)$/);
  if (press) return <PressPortal token={press[1]} />;
  if (loading) return <div className="empty">Memuat…</div>;
  if (!user) return <LoginPage />;
  return (
    <Routes>
      <Route element={<Layout />}>
        <Route index element={<ExecDashboard />} />
        <Route path="analitik" element={<AnalyticsPage />} />
        <Route path="produksi" element={<ProductionPage />} />
        <Route path="editorial" element={<EditorialPage />} />
        <Route path="kalender" element={<CalendarPage />} />
        <Route path="studio" element={<StudioPage />} />
        <Route path="aset" element={<AssetsPage />} />
        <Route path="laporan" element={<ReportsPage />} />
        <Route path="kak" element={<KakMatrix />} />
        <Route path="kanal-resmi" element={<SocialPage />} />
        <Route path="distribusi" element={<DistributionPage />} />
        <Route path="naskah" element={<DraftsPage />} />
        <Route path="pengaturan-ai" element={<AiSettingsPage />} />
        <Route path="audit" element={<AuditPage />} />
        <Route path="pengguna" element={<UsersPage />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Route>
    </Routes>
  );
}

ReactDOM.createRoot(document.getElementById("root")!).render(
  <React.StrictMode>
    <BrowserRouter>
      <AuthProvider>
        <Guarded />
      </AuthProvider>
    </BrowserRouter>
  </React.StrictMode>,
);
