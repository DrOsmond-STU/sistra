import L from "./leaflet-setup";
import "leaflet.markercluster";
import "leaflet.markercluster/dist/MarkerCluster.css";
import "leaflet.markercluster/dist/MarkerCluster.Default.css";
import { useEffect, useMemo, useRef, useState } from "react";
import { useNavigate } from "react-router-dom";
import { api } from "../../api";
import { fmtDateTime, nf } from "../../format";
import { useLoad } from "../ui";

export type GeoLayer = "artikel" | "naskah" | "liputan" | "kanal" | "akun" | "mitra";
interface Pt { lat: number; lng: number; prov: string; title: string; sub: string; tone: string; link: string; at: string | null }
interface GeoData { layer: GeoLayer; label: string; rank_label?: string; points: Pt[]; national: number; total: number; provinces_covered: number; by_province: { code: string; name: string; count: number }[] }

const LAYER_LABEL: Record<GeoLayer, string> = {
  artikel: "Artikel tayang", naskah: "Naskah lokal", liputan: "Liputan", kanal: "Kanal web", akun: "Akun medsos", mitra: "Mitra media & KOL",
};
const LEGEND: Record<GeoLayer, [string, string][]> = {
  artikel: [["good", "terkirim"], ["info", "terjadwal"], ["bad", "gagal"]],
  naskah: [["good", "tayang"], ["info", "disetujui"], ["warn", "ditinjau"], ["muted", "draf"]],
  liputan: [["good", "selesai"], ["warn", "berlangsung"], ["info", "direncanakan"], ["muted", "batal"]],
  kanal: [["good", "normal"], ["warn", "lambat"], ["bad", "gangguan"]],
  akun: [["good", "token aman"], ["warn", "token perlu diperbarui"]],
  mitra: [["info", "media mitra"], ["good", "KOL berlabel"]],
};
const NATIONAL: Record<GeoLayer, string> = {
  artikel: "di kanal nasional/medsos", naskah: "naskah nasional (tanpa wilayah)", liputan: "tanpa wilayah", kanal: "kanal nasional & medsos resmi",
  akun: "akun nasional", mitra: "berskala nasional",
};
const PERIODS: [number, string][] = [[7, "7 hari"], [30, "30 hari"], [90, "90 hari"], [0, "Semua"]];
const DATED: GeoLayer[] = ["artikel", "naskah", "liputan"];
const BY_ISSUE: GeoLayer[] = ["artikel", "naskah"];
const INDONESIA: L.LatLngBoundsExpression = [[-11.2, 94.8], [6.3, 141.1]];

function el<K extends keyof HTMLElementTagNameMap>(tag: K, cls: string, text?: string) {
  const e = document.createElement(tag);
  e.className = cls;
  if (text !== undefined) e.textContent = text;
  return e;
}

/**
 * Peta sebaran interaktif Indonesia (OpenStreetMap + klaster penanda).
 * Klaster berwarna hijau/kuning/oranye sesuai jumlah titik; klik klaster untuk memperbesar,
 * klik titik untuk melihat ringkasan dan membuka datanya.
 */
export default function GeoMap({ layers = ["artikel", "naskah", "liputan", "kanal", "akun", "mitra"], initial, title = "Peta sebaran", height = 420, days: daysIn = 30, note }:
  { layers?: GeoLayer[]; initial?: GeoLayer; title?: string; height?: number; days?: number; note?: string }) {
  const nav = useNavigate();
  const [layer, setLayer] = useState<GeoLayer>(initial ?? layers[0]);
  const [days, setDays] = useState(daysIn);
  const [issue, setIssue] = useState("");
  const [baseFail, setBaseFail] = useState(false);
  const issues = useLoad(() => api<{ id: number; name: string }[]>("/issues"), []);
  const q = { layer, days: DATED.includes(layer) ? String(days) : "0", ...(issue && BY_ISSUE.includes(layer) ? { issue_id: issue } : {}) };
  const d = useLoad(() => api<GeoData>("/geo/points", { query: q }), [layer, days, issue]);

  const box = useRef<HTMLDivElement>(null);
  const map = useRef<L.Map | null>(null);
  const group = useRef<L.LayerGroup | null>(null);
  const navRef = useRef(nav);
  navRef.current = nav;

  // Inisialisasi peta sekali.
  useEffect(() => {
    if (!box.current || map.current) return;
    const m = L.map(box.current, { scrollWheelZoom: false, minZoom: 4, maxZoom: 14, zoomSnap: 0.25, worldCopyJump: false, maxBounds: [[-25, 80], [20, 160]] });
    m.fitBounds(INDONESIA);
    let failed = 0;
    const tiles = L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {
      // OSM mewajibkan header Referer; halaman ini bawaannya no-referrer, jadi ubin peta mengirim asal situs saja (tanpa jalur halaman).
      referrerPolicy: "strict-origin",
      maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noreferrer">OpenStreetMap</a>',
    }).addTo(m);
    // Bila peta dasar diblokir jaringan, tampilkan garis pantai sederhana (Natural Earth) agar peta tetap terbaca.
    tiles.on("tileerror", () => {
      failed += 1;
      if (failed !== 3) return;
      setBaseFail(true);
      import("../../data/nusantara.json").then((mod) => {
        const gj = L.geoJSON((mod as { default: GeoJSON.FeatureCollection }).default, {
          interactive: false,
          style: (f) => ({ color: "#7d93b2", weight: 0.8, fillColor: f?.properties?.k === "id" ? "#eef3e2" : "#f4f4f0", fillOpacity: 1 }),
        });
        gj.addTo(m);
        gj.bringToBack();
      });
    });
    m.on("click focus", () => m.scrollWheelZoom.enable());
    m.on("mouseout blur", () => m.scrollWheelZoom.disable());
    map.current = m;
    const ro = new ResizeObserver(() => m.invalidateSize());
    ro.observe(box.current);
    return () => { ro.disconnect(); m.remove(); map.current = null; };
  }, []);

  // Gambar ulang penanda saat data berubah.
  useEffect(() => {
    const m = map.current;
    if (!m || !d.data) return;
    if (group.current) { m.removeLayer(group.current); group.current = null; }
    const big = d.data.layer === "kanal";
    const g: L.LayerGroup = big ? L.layerGroup() : L.markerClusterGroup({ chunkedLoading: true, showCoverageOnHover: false, maxClusterRadius: 55, spiderfyOnMaxZoom: true });
    for (const p of d.data.points) {
      const icon = L.divIcon({ className: "", html: `<span class="geo-dot tone-${p.tone}${big ? " big" : ""}"></span>`, iconSize: big ? [18, 18] : [12, 12], iconAnchor: big ? [9, 9] : [6, 6] });
      const mk = L.marker([p.lat, p.lng], { icon, title: p.title, keyboard: true });
      mk.bindPopup(() => {
        const w = el("div", "geo-pop");
        w.append(el("b", "", p.title), el("div", "small muted", p.sub));
        if (p.at) w.append(el("div", "small muted", fmtDateTime(p.at)));
        const a = el("button", "btn sm", "Buka →");
        a.type = "button";
        a.onclick = () => navRef.current(p.link);
        w.append(a);
        return w;
      }, { maxWidth: 280 });
      g.addLayer(mk);
    }
    g.addTo(m);
    group.current = g;
  }, [d.data]);

  const zoomTo = (code: string) => {
    const pts = d.data?.points.filter((p) => p.prov === code) ?? [];
    if (map.current && pts.length) map.current.fitBounds(L.latLngBounds(pts.map((p) => [p.lat, p.lng] as [number, number])).pad(0.8), { maxZoom: 8 });
  };
  const top = useMemo(() => (d.data?.by_province ?? []).slice(0, 8), [d.data]);
  const max = Math.max(1, ...top.map((t) => t.count));

  return (
    <div className="card geo-card">
      <div className="geo-head">
        <div>
          <h2 style={{ margin: 0 }}>{title}</h2>
          <div className="small muted">{d.data?.label ?? LAYER_LABEL[layer]} · klik klaster untuk memperbesar, klik titik untuk rincian</div>
        </div>
        <div className="row" style={{ gap: 6 }}>
          {DATED.includes(layer) && (
            <select value={days} onChange={(e) => setDays(Number(e.target.value))} aria-label="Periode">
              {PERIODS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          )}
          {BY_ISSUE.includes(layer) && (
            <select value={issue} onChange={(e) => setIssue(e.target.value)} aria-label="Isu">
              <option value="">Semua isu</option>
              {issues.data?.map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}
            </select>
          )}
        </div>
      </div>
      {layers.length > 1 && (
        <div className="subtabs geo-layers" role="tablist" aria-label="Lapisan peta">
          {layers.map((l) => <button key={l} role="tab" aria-selected={layer === l} className={layer === l ? "on" : ""} onClick={() => setLayer(l)}>{LAYER_LABEL[l]}</button>)}
        </div>
      )}
      <div className="geo-body">
        <div className="geo-map-wrap">
          <div ref={box} className="geo-map" style={{ height }} role="region" aria-label={`Peta ${title}`} tabIndex={0} />
          {d.loading && <div className="geo-loading small">Memuat…</div>}
          <div className="geo-legend small">
            {LEGEND[layer].map(([t, l]) => <span key={t}><i className={`geo-dot tone-${t}`} />{l}</span>)}
          </div>
        </div>
        <div className="geo-side">
          <div className="geo-stats">
            <div><span className="small muted">Titik di peta</span><b className="num">{nf.format(d.data?.points.length ?? 0)}</b></div>
            <div><span className="small muted">Provinsi terjangkau</span><b className="num">{d.data?.provinces_covered ?? 0}<span className="muted small"> / 38</span></b></div>
          </div>
          {!!d.data?.national && <div className="small muted">+ {nf.format(d.data.national)} {NATIONAL[layer]}</div>}
          <div className="small" style={{ fontWeight: 700, marginTop: 4 }}>{d.data?.rank_label ?? "Provinsi teratas"}</div>
          <div className="geo-top">
            {top.map((t) => (
              <button key={t.code} type="button" className="geo-top-row" onClick={() => zoomTo(t.code)} title={`Perbesar ke ${t.name}`}>
                <span className="geo-top-name">{t.name}</span>
                <span className="geo-top-bar"><span style={{ width: `${(t.count / max) * 100}%` }} /></span>
                <b className="num">{nf.format(t.count)}</b>
              </button>
            ))}
            {!top.length && !d.loading && <div className="small muted">Belum ada data pada periode ini.</div>}
          </div>
          <button type="button" className="btn sm" onClick={() => map.current?.fitBounds(INDONESIA)}>Tampilkan seluruh Indonesia</button>
        </div>
      </div>
      {d.error && <div className="small" style={{ color: "var(--st-critical)" }}>{d.error}</div>}
      {(baseFail || note) && <div className="small muted">{baseFail ? "Peta dasar OpenStreetMap tidak dapat dimuat dari jaringan ini; ditampilkan garis pantai sederhana. " : ""}{note}</div>}
    </div>
  );
}
