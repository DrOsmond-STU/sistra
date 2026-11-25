import { lazy, Suspense, type ComponentProps } from "react";
import type GeoMapT from "./GeoMap";

// Leaflet dimuat terpisah agar bundel utama tetap ringan.
const GeoMap = lazy(() => import("./GeoMap"));

export type { GeoLayer } from "./GeoMap";

export default function SebaranMap(props: ComponentProps<typeof GeoMapT>) {
  return (
    <Suspense fallback={<div className="card geo-card"><div className="geo-map geo-placeholder" style={{ height: props.height ?? 420 }}>Memuat peta…</div></div>}>
      <GeoMap {...props} />
    </Suspense>
  );
}
