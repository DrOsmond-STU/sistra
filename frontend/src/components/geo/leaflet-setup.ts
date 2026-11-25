// Plugin leaflet.markercluster mencari objek global `L`; modul ini harus diimpor lebih dulu.
import L from "leaflet";
import "leaflet/dist/leaflet.css";

(window as unknown as { L: typeof L }).L = L;

export default L;
