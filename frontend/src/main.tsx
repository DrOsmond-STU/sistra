import React from "react";
import ReactDOM from "react-dom/client";
import { BrowserRouter, Navigate, Route, Routes } from "react-router-dom";
import { AuthProvider, useAuth } from "./auth";
import Layout from "./components/Layout";
import AlertsPage from "./pages/Alerts";
import AuditPage from "./pages/Audit";
import CalendarPage from "./pages/Calendar";
import ContentPage from "./pages/Content";
import Dashboard from "./pages/Dashboard";
import DraftsPage from "./pages/Drafts";
import LoginPage from "./pages/Login";
import RepositoryPage from "./pages/Repository";
import SourcesPage from "./pages/Sources";
import UsersPage from "./pages/Users";
import "./styles.css";

function Guarded() {
  const { user, loading } = useAuth();
  if (loading) return <div className="empty">Memuat…</div>;
  if (!user) return <LoginPage />;
  return (
    <Routes>
      <Route element={<Layout />}>
        <Route index element={<Dashboard />} />
        <Route path="peringatan" element={<AlertsPage />} />
        <Route path="konten" element={<ContentPage />} />
        <Route path="sumber" element={<SourcesPage />} />
        <Route path="naskah" element={<DraftsPage />} />
        <Route path="kalender" element={<CalendarPage />} />
        <Route path="repositori" element={<RepositoryPage />} />
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
