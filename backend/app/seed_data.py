"""Generator berita contoh SINTETIS untuk demo/uji. Bukan data nyata.

Menghasilkan pola: isu harga pangan melonjak dan negatif dalam 24 jam terakhir (memicu MERAH),
disinformasi meningkat (ORANYE), bencana meningkat ringan (KUNING), isu lain stabil.
"""

from __future__ import annotations

import random
from datetime import datetime, timedelta

from app.modules.ingest.parsers import RawItem

CITIES = [
    ("Banda Aceh", "Aceh"), ("Medan", "Sumatera Utara"), ("Padang", "Sumatera Barat"), ("Pekanbaru", "Riau"),
    ("Palembang", "Sumatera Selatan"), ("Bandar Lampung", "Lampung"), ("Batam", "Kepulauan Riau"),
    ("Jakarta", "DKI Jakarta"), ("Bandung", "Jawa Barat"), ("Semarang", "Jawa Tengah"), ("Yogyakarta", "DI Yogyakarta"),
    ("Surabaya", "Jawa Timur"), ("Serang", "Banten"), ("Denpasar", "Bali"), ("Mataram", "Nusa Tenggara Barat"),
    ("Kupang", "Nusa Tenggara Timur"), ("Pontianak", "Kalimantan Barat"), ("Banjarmasin", "Kalimantan Selatan"),
    ("Samarinda", "Kalimantan Timur"), ("Manado", "Sulawesi Utara"), ("Palu", "Sulawesi Tengah"),
    ("Makassar", "Sulawesi Selatan"), ("Kendari", "Sulawesi Tenggara"), ("Ambon", "Maluku"), ("Ternate", "Maluku Utara"),
    ("Jayapura", "Papua"), ("Manokwari", "Papua Barat"), ("Sorong", "Papua Barat Daya"), ("Merauke", "Papua Selatan"),
]

SENT = {
    "negatif": [
        "Warga mengeluhkan kondisi ini yang dinilai sangat membebani dan meresahkan.",
        "Sejumlah tokoh masyarakat kecewa dan mengkritik lambatnya penanganan.",
        "Situasi dinilai memburuk dan memicu keresahan di tengah masyarakat.",
        "Pengamat menilai dampaknya merugikan dan berpotensi memicu polemik.",
    ],
    "positif": [
        "Pemerintah daerah menyebut situasi terkendali dan warga merasa terbantu.",
        "Langkah tersebut diapresiasi dan dinilai berhasil menjaga kondisi tetap kondusif.",
        "Koordinasi lintas instansi berjalan lancar dan efektif.",
    ],
    "netral": [
        "Dinas terkait akan melakukan pemantauan lanjutan pekan depan.",
        "Data tersebut dihimpun dari laporan harian petugas di lapangan.",
        "Rapat koordinasi dijadwalkan kembali dalam waktu dekat.",
    ],
}

TEMPLATES: dict[str, dict[str, list[str]]] = {
    "EKO-PANGAN": {
        "title": ["Harga beras di {city} kembali naik", "Warga {prov} soroti harga cabai dan sembako",
                  "Operasi pasar digelar di {city}", "Pasokan minyak goreng di {prov} dipantau ketat"],
        "body": ["Harga beras medium di pasar tradisional {city}, {prov}, tercatat Rp{n}.{m}00 per kilogram pada pantauan hari ini.",
                 "Harga cabai rawit dan sejumlah bahan pokok di {city} bergerak naik dalam {k} hari terakhir.",
                 "Bulog bersama pemerintah {prov} menyalurkan {k}0 ton beras melalui operasi pasar di {city}."],
    },
    "DIS-HOAKS": {
        "title": ["Beredar hoaks soal bantuan sosial di {prov}", "Cek fakta: video viral di {city} ternyata disinformasi",
                  "Akun palsu sebarkan narasi menyesatkan terkait {prov}"],
        "body": ["Sebuah unggahan yang menyebut pembagian bantuan di {city} dihentikan dipastikan sebagai berita bohong; unggahan dibagikan {n}{m} kali.",
                 "Tim cek fakta menelusuri video yang diklaim terjadi di {city}, {prov}, dan menemukan rekaman tersebut berasal dari {k} tahun lalu.",
                 "Narasi menyesatkan yang menyasar warga {prov} menyebar melalui {k} grup percakapan dan sejumlah akun anonim."],
    },
    "BEN-ALAM": {
        "title": ["Banjir rendam permukiman di {city}", "BPBD {prov} siaga cuaca ekstrem", "Tanah longsor tutup akses jalan di {prov}"],
        "body": ["Banjir setinggi {n}0 sentimeter merendam {k}00 rumah warga di {city}, {prov}.",
                 "BPBD {prov} menetapkan status siaga menyusul prakiraan cuaca ekstrem selama {k} hari ke depan.",
                 "Longsor menutup ruas jalan penghubung di wilayah {prov}; {k} unit alat berat dikerahkan."],
    },
    "POL-PEMILU": {
        "title": ["KPU {prov} gelar simulasi pemungutan suara", "Bawaslu {city} awasi tahapan pilkada"],
        "body": ["KPU {prov} melaksanakan simulasi pemungutan suara di {k} TPS percontohan di {city}.",
                 "Bawaslu {city} membuka posko pengaduan terkait dugaan politik uang menjelang pilkada."],
    },
    "KES-PUBLIK": {
        "title": ["Kasus demam berdarah di {city} dipantau", "Vaksinasi anak digelar serentak di {prov}"],
        "body": ["Dinas kesehatan mencatat {n}{m} kasus DBD di {city} sepanjang bulan ini.",
                 "Puskesmas di {prov} menargetkan vaksinasi bagi {k} ribu anak pekan ini."],
    },
    "EKO-KERJA": {
        "title": ["Serikat pekerja {prov} bahas upah minimum", "Bursa lowongan kerja dibuka di {city}"],
        "body": ["Serikat pekerja dan asosiasi pengusaha di {prov} membahas usulan upah minimum tahun depan.",
                 "Pemerintah {city} membuka bursa lowongan kerja yang diikuti {k}0 perusahaan."],
    },
    "SOS-SARA": {
        "title": ["Forum kerukunan antarumat beragama {prov} bertemu", "Dialog kerukunan digelar di {city}"],
        "body": ["Forum kerukunan umat beragama {prov} menggelar pertemuan rutin di {city} bersama {k} tokoh lintas agama.",
                 "Dialog kerukunan di {city} membahas pencegahan ujaran kebencian di media sosial."],
    },
    "LIN-AGRARIA": {
        "title": ["Sengketa lahan di {prov} dimediasi", "Warga {city} soroti pencemaran sungai"],
        "body": ["Pemerintah {prov} memediasi sengketa lahan antara masyarakat adat dan perusahaan seluas {k}00 hektare.",
                 "Warga di sekitar {city} melaporkan dugaan pencemaran limbah ke sungai setempat."],
    },
}

# (isu, jumlah 24 jam terakhir, rerata harian sebelumnya, bobot sentimen (neg, net, pos))
PROFILE = [
    ("EKO-PANGAN", 34, 4, (0.8, 0.15, 0.05)),
    ("DIS-HOAKS", 14, 3, (0.65, 0.3, 0.05)),
    ("BEN-ALAM", 7, 3, (0.4, 0.5, 0.1)),
    ("POL-PEMILU", 4, 4, (0.2, 0.6, 0.2)),
    ("KES-PUBLIK", 3, 3, (0.2, 0.5, 0.3)),
    ("EKO-KERJA", 3, 3, (0.3, 0.5, 0.2)),
    ("SOS-SARA", 2, 2, (0.1, 0.5, 0.4)),
    ("LIN-AGRARIA", 2, 2, (0.4, 0.5, 0.1)),
]


def _article(rng: random.Random, issue: str, sentiment: str, published: datetime, idx: int) -> RawItem:
    city, prov = rng.choice(CITIES)
    fmt = {"city": city, "prov": prov, "n": rng.randint(1, 9), "m": rng.randint(0, 9), "k": rng.randint(2, 9)}
    t = TEMPLATES[issue]
    title = rng.choice(t["title"]).format(**fmt)
    sentences = [rng.choice(t["body"]).format(**fmt)]
    sentences += rng.sample(SENT[sentiment], k=min(2, len(SENT[sentiment])))
    sentences.append(rng.choice(SENT["netral"]))
    sentences.append(f"Laporan contoh nomor {idx} untuk keperluan demonstrasi sistem.")
    return RawItem(
        title=title,
        body=" ".join(sentences),
        url=f"https://contoh.invalid/berita/{issue.lower()}/{idx}",
        external_id=f"sintetis-{issue}-{idx}",
        author="Redaksi Contoh",
        published_at=published,
    )


def generate(now: datetime, seed: int = 2027) -> list[RawItem]:
    rng = random.Random(seed)
    items: list[RawItem] = []
    idx = 0
    for issue, recent_count, daily_base, weights in PROFILE:
        for day in range(1, 8):  # 7 hari baseline
            for _ in range(max(0, daily_base + rng.randint(-1, 1))):
                ts = now - timedelta(days=day, hours=rng.uniform(0.5, 23.5))
                label = rng.choices(["negatif", "netral", "positif"], weights=(0.3, 0.5, 0.2))[0]
                idx += 1
                items.append(_article(rng, issue, label, ts, idx))
        for _ in range(recent_count):
            ts = now - timedelta(hours=rng.uniform(0.1, 23.5))
            label = rng.choices(["negatif", "netral", "positif"], weights=weights)[0]
            idx += 1
            items.append(_article(rng, issue, label, ts, idx))
    rng.shuffle(items)
    return items


SAMPLE_DOCUMENTS = [
    {
        "title": "Laporan Situasi Harian Media (contoh)", "classification": "TERBUKA", "unit": "DIT-ANL",
        "owner": "analis", "origin": "Pemantauan media daring", "tags": "laporan-harian,media",
        "filename": "lapsit-harian-contoh.md",
        "text": "# Laporan Situasi Harian (DATA CONTOH SINTETIS)\n\nIsu dominan: harga pangan, disinformasi bantuan sosial, "
                "dan cuaca ekstrem. Sentimen publik terhadap harga beras cenderung negatif di beberapa provinsi.\n",
    },
    {
        "title": "Kajian Isu Harga Pangan Pekan Ini (contoh)", "classification": "TERBATAS", "unit": "DIT-ANL",
        "owner": "analis", "origin": "Analisis Direktorat Analisis Media", "tags": "kajian,pangan",
        "filename": "kajian-pangan-contoh.md",
        "text": "# Kajian Isu Harga Pangan (DATA CONTOH SINTETIS)\n\nVolume pemberitaan harga beras meningkat sekitar "
                "delapan kali dibanding rerata pekan lalu. Rekomendasi: percepat penyampaian informasi operasi pasar "
                "melalui kanal resmi provinsi.\n",
    },
    {
        "title": "Telaahan Staf Disinformasi Bantuan Sosial (contoh)", "classification": "RAHASIA", "unit": "DIT-ANL",
        "owner": "analis", "origin": "Telaahan internal", "tags": "telaahan,disinformasi",
        "filename": "telaahan-disinformasi-contoh.txt",
        "text": "TELAAHAN STAF (DATA CONTOH SINTETIS)\n\nPola penyebaran narasi menyesatkan terkait bantuan sosial "
                "terdeteksi pada beberapa grup percakapan publik. Disarankan penerbitan klarifikasi resmi.\n",
    },
    {
        "title": "Nota Dinas Arahan Strategi Komunikasi (contoh)", "classification": "SANGAT_RAHASIA", "unit": "DEP7",
        "owner": "pimpinan", "origin": "Pimpinan Deputi", "tags": "nota-dinas,arahan",
        "filename": "nota-dinas-contoh.txt",
        "text": "NOTA DINAS (DATA CONTOH SINTETIS)\n\nArahan: prioritaskan komunikasi berbasis data melalui kanal resmi "
                "dan pastikan setiap konten melalui persetujuan berjenjang.\n",
    },
    {
        "title": "Laporan Berkala Kanal Distribusi Triwulan (contoh)", "classification": "TERBATAS", "unit": "DIT-KNL",
        "owner": "operator", "origin": "Direktorat Kanal & Distribusi", "tags": "laporan-berkala,kanal",
        "filename": "laporan-kanal-contoh.csv",
        "text": "kanal,tayang,pembaca_unik\nNAS,120,54000\nPROV-31,45,12000\nPROV-35,38,9800\n",
    },
]
