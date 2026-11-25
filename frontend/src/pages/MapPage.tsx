import SebaranMap from "../components/geo";

/** Peta sebaran nasional: semua lapisan dalam satu peta besar. */
export default function MapPage() {
  return (
    <div className="stack">
      <div>
        <h1>Peta Sebaran</h1>
        <p className="sub" style={{ marginBottom: 0 }}>Sebaran artikel, naskah, liputan, kanal, akun resmi, dan mitra di 38 provinsi. Pilih lapisan di atas peta; angka pada lingkaran menunjukkan jumlah titik di area tersebut.</p>
      </div>
      <SebaranMap title="Sebaran di 38 provinsi" height={560} />
    </div>
  );
}
