<?php
/** Data awal (unit, pengguna, isu, kanal) dan data demo SINTETIS. */
declare(strict_types=1);

const SEED_UNITS = [
    ['DEP7', 'Deputi Bidang Komunikasi dan Informasi', null],
    ['DIT-ANL', 'Direktorat Analisis Media', 'DEP7'],
    ['DIT-PRD', 'Direktorat Produksi & Komunikasi Strategis', 'DEP7'],
    ['DIT-KNL', 'Direktorat Kanal & Distribusi', 'DEP7'],
    ['BAG-ARS', 'Bagian Arsip & Data', 'DEP7'],
    ['BAG-TI', 'Bagian Teknologi Informasi', 'DEP7'],
];
const SEED_USERS = [
    ['admin', 'Administrator Sistem', 'BAG-TI', ['admin'], 'TERBATAS'],
    ['auditor', 'Auditor Internal', 'DEP7', ['auditor'], 'RAHASIA'],
    ['pimpinan', 'Pimpinan Deputi (contoh)', 'DEP7', ['pimpinan'], 'SANGAT_RAHASIA'],
    ['pimpinan.anl', 'Direktur Analisis (contoh)', 'DIT-ANL', ['pimpinan'], 'RAHASIA'],
    ['analis', 'Analis Media (contoh)', 'DIT-ANL', ['analis'], 'RAHASIA'],
    ['analis2', 'Analis Produksi (contoh)', 'DIT-PRD', ['analis'], 'RAHASIA'],
    ['redaktur', 'Redaktur (contoh)', 'DIT-PRD', ['redaktur'], 'TERBATAS'],
    ['produser', 'Produser Multimedia (contoh)', 'DIT-PRD', ['produser'], 'TERBATAS'],
    ['operator', 'Operator Kanal (contoh)', 'DIT-KNL', ['operator'], 'TERBATAS'],
    ['arsiparis', 'Arsiparis (contoh)', 'BAG-ARS', ['arsiparis'], 'RAHASIA'],
    ['pelapor', 'Petugas Pelapor Unit (contoh)', 'DIT-KNL', ['pelapor'], 'TERBUKA'],
];

function seed_base(array $passwords): array
{
    $units = [];
    foreach (SEED_UNITS as [$code, $name, $parent]) {
        $id = val('SELECT id FROM units WHERE code = ?', [$code]);
        if (!$id) $id = insert('units', ['code' => $code, 'name' => $name, 'parent_id' => $parent ? $units[$parent] : null]);
        $units[$code] = (int)$id;
    }
    $users = [];
    foreach (SEED_USERS as $i => [$un, $fn, $unit, $roles, $clr]) {
        $row = one('SELECT * FROM users WHERE username = ?', [$un]);
        if (!$row) {
            $id = insert('users', ['username' => $un, 'full_name' => $fn, 'nip' => sprintf('19900101202701%04d', $i + 1), 'unit_id' => $units[$unit],
                'roles' => jenc($roles), 'clearance' => $clr, 'password_hash' => password_hash($passwords[$un], PASSWORD_DEFAULT), 'active' => 1, 'created_at' => now_iso()]);
            $row = one('SELECT * FROM users WHERE id = ?', [$id]);
        }
        $users[$un] = $row;
    }
    foreach (DEFAULT_ISSUES as $s) {
        if (!val('SELECT id FROM issues WHERE code = ?', [$s['code']])) {
            insert('issues', ['code' => $s['code'], 'name' => $s['name'], 'risk_level' => $s['risk_level'], 'taxonomy_path' => $s['taxonomy_path'],
                'description' => '', 'keywords' => jenc($s['keywords']), 'active' => 1, 'created_at' => now_iso()]);
        }
    }
    if (!val('SELECT COUNT(*) FROM channels')) {
        insert('channels', ['code' => 'NAS', 'name' => 'Kanal Nasional', 'type' => 'nasional']);
        foreach (PROVINCES as $code => [$name]) insert('channels', ['code' => "PROV-$code", 'name' => "Kanal $name", 'type' => 'provinsi', 'province_code' => (string)$code]);
        insert('channels', ['code' => 'RESMI-IG', 'name' => 'Akun Resmi Instagram (contoh)', 'type' => 'medsos_resmi']);
        insert('channels', ['code' => 'RESMI-X', 'name' => 'Akun Resmi X (contoh)', 'type' => 'medsos_resmi']);
    }
    return $users;
}

// ---------- generator berita sintetis ----------
const CITIES = [['Banda Aceh', 'Aceh'], ['Medan', 'Sumatera Utara'], ['Padang', 'Sumatera Barat'], ['Pekanbaru', 'Riau'], ['Palembang', 'Sumatera Selatan'],
    ['Bandar Lampung', 'Lampung'], ['Batam', 'Kepulauan Riau'], ['Jakarta', 'DKI Jakarta'], ['Bandung', 'Jawa Barat'], ['Semarang', 'Jawa Tengah'],
    ['Yogyakarta', 'DI Yogyakarta'], ['Surabaya', 'Jawa Timur'], ['Serang', 'Banten'], ['Denpasar', 'Bali'], ['Mataram', 'Nusa Tenggara Barat'],
    ['Kupang', 'Nusa Tenggara Timur'], ['Pontianak', 'Kalimantan Barat'], ['Banjarmasin', 'Kalimantan Selatan'], ['Samarinda', 'Kalimantan Timur'],
    ['Manado', 'Sulawesi Utara'], ['Palu', 'Sulawesi Tengah'], ['Makassar', 'Sulawesi Selatan'], ['Kendari', 'Sulawesi Tenggara'], ['Ambon', 'Maluku'],
    ['Ternate', 'Maluku Utara'], ['Jayapura', 'Papua'], ['Manokwari', 'Papua Barat'], ['Sorong', 'Papua Barat Daya'], ['Merauke', 'Papua Selatan']];
const SENT_LINES = [
    'negatif' => ['Warga mengeluhkan kondisi ini yang dinilai sangat membebani dan meresahkan.', 'Sejumlah tokoh masyarakat kecewa dan mengkritik lambatnya penanganan.',
        'Situasi dinilai memburuk dan memicu keresahan di tengah masyarakat.', 'Pengamat menilai dampaknya merugikan dan berpotensi memicu polemik.'],
    'positif' => ['Pemerintah daerah menyebut situasi terkendali dan warga merasa terbantu.', 'Langkah tersebut diapresiasi dan dinilai berhasil menjaga kondisi tetap kondusif.',
        'Koordinasi lintas instansi berjalan lancar dan efektif.'],
    'netral' => ['Dinas terkait akan melakukan pemantauan lanjutan pekan depan.', 'Data tersebut dihimpun dari laporan harian petugas di lapangan.',
        'Rapat koordinasi dijadwalkan kembali dalam waktu dekat.'],
];
const TEMPLATES = [
    'EKO-PANGAN' => ['title' => ['Harga beras di {city} kembali naik', 'Warga {prov} soroti harga cabai dan sembako', 'Operasi pasar digelar di {city}', 'Pasokan minyak goreng di {prov} dipantau ketat'],
        'body' => ['Harga beras medium di pasar tradisional {city}, {prov}, tercatat Rp{n}.{m}00 per kilogram pada pantauan hari ini.',
            'Harga cabai rawit dan sejumlah bahan pokok di {city} bergerak naik dalam {k} hari terakhir.',
            'Bulog bersama pemerintah {prov} menyalurkan {k}0 ton beras melalui operasi pasar di {city}.']],
    'DIS-HOAKS' => ['title' => ['Beredar hoaks soal bantuan sosial di {prov}', 'Cek fakta: video viral di {city} ternyata disinformasi', 'Akun palsu sebarkan narasi menyesatkan terkait {prov}'],
        'body' => ['Sebuah unggahan yang menyebut pembagian bantuan di {city} dihentikan dipastikan sebagai berita bohong; unggahan dibagikan {n}{m} kali.',
            'Tim cek fakta menelusuri video yang diklaim terjadi di {city}, {prov}, dan menemukan rekaman tersebut berasal dari {k} tahun lalu.',
            'Narasi menyesatkan yang menyasar warga {prov} menyebar melalui {k} grup percakapan dan sejumlah akun anonim.']],
    'BEN-ALAM' => ['title' => ['Banjir rendam permukiman di {city}', 'BPBD {prov} siaga cuaca ekstrem', 'Tanah longsor tutup akses jalan di {prov}'],
        'body' => ['Banjir setinggi {n}0 sentimeter merendam {k}00 rumah warga di {city}, {prov}.', 'BPBD {prov} menetapkan status siaga menyusul prakiraan cuaca ekstrem selama {k} hari ke depan.',
            'Longsor menutup ruas jalan penghubung di wilayah {prov}; {k} unit alat berat dikerahkan.']],
    'POL-PEMILU' => ['title' => ['KPU {prov} gelar simulasi pemungutan suara', 'Bawaslu {city} awasi tahapan pilkada'],
        'body' => ['KPU {prov} melaksanakan simulasi pemungutan suara di {k} TPS percontohan di {city}.', 'Bawaslu {city} membuka posko pengaduan terkait dugaan politik uang menjelang pilkada.']],
    'KES-PUBLIK' => ['title' => ['Kasus demam berdarah di {city} dipantau', 'Vaksinasi anak digelar serentak di {prov}'],
        'body' => ['Dinas kesehatan mencatat {n}{m} kasus DBD di {city} sepanjang bulan ini.', 'Puskesmas di {prov} menargetkan vaksinasi bagi {k} ribu anak pekan ini.']],
    'EKO-KERJA' => ['title' => ['Serikat pekerja {prov} bahas upah minimum', 'Bursa lowongan kerja dibuka di {city}'],
        'body' => ['Serikat pekerja dan asosiasi pengusaha di {prov} membahas usulan upah minimum tahun depan.', 'Pemerintah {city} membuka bursa lowongan kerja yang diikuti {k}0 perusahaan.']],
    'SOS-SARA' => ['title' => ['Forum kerukunan antarumat beragama {prov} bertemu', 'Dialog kerukunan digelar di {city}'],
        'body' => ['Forum kerukunan umat beragama {prov} menggelar pertemuan rutin di {city} bersama {k} tokoh lintas agama.', 'Dialog kerukunan di {city} membahas pencegahan ujaran kebencian di media sosial.']],
    'LIN-AGRARIA' => ['title' => ['Sengketa lahan di {prov} dimediasi', 'Warga {city} soroti pencemaran sungai'],
        'body' => ['Pemerintah {prov} memediasi sengketa lahan antara masyarakat adat dan perusahaan seluas {k}00 hektare.', 'Warga di sekitar {city} melaporkan dugaan pencemaran limbah ke sungai setempat.']],
];
const PROFILE = [['EKO-PANGAN', 34, 4, [0.8, 0.15, 0.05]], ['DIS-HOAKS', 14, 3, [0.65, 0.3, 0.05]], ['BEN-ALAM', 7, 3, [0.4, 0.5, 0.1]], ['POL-PEMILU', 4, 4, [0.2, 0.6, 0.2]],
    ['KES-PUBLIK', 3, 3, [0.2, 0.5, 0.3]], ['EKO-KERJA', 3, 3, [0.3, 0.5, 0.2]], ['SOS-SARA', 2, 2, [0.1, 0.5, 0.4]], ['LIN-AGRARIA', 2, 2, [0.4, 0.5, 0.1]]];

function rnd_pick(array $a) { return $a[mt_rand(0, count($a) - 1)]; }
function rnd_float(float $a, float $b): float { return $a + ($b - $a) * mt_rand() / mt_getrandmax(); }
function rnd_weighted(array $labels, array $w): string
{
    $x = rnd_float(0, array_sum($w));
    foreach ($labels as $i => $l) { $x -= $w[$i]; if ($x <= 0) return $l; }
    return end($labels);
}
function synth_article(string $issue, string $sent, float $ts, int $idx): array
{
    [$city, $prov] = rnd_pick(CITIES);
    $f = ['{city}' => $city, '{prov}' => $prov, '{n}' => (string)mt_rand(1, 9), '{m}' => (string)mt_rand(0, 9), '{k}' => (string)mt_rand(2, 9)];
    $t = TEMPLATES[$issue];
    $lines = SENT_LINES[$sent];
    $keys = (array)array_rand($lines, 2);
    shuffle($keys);
    $s = [strtr(rnd_pick($t['body']), $f), $lines[$keys[0]], $lines[$keys[1]], rnd_pick(SENT_LINES['netral']), "Laporan contoh nomor $idx untuk keperluan demonstrasi sistem."];
    return ['title' => strtr(rnd_pick($t['title']), $f), 'body' => implode(' ', $s), 'url' => 'https://contoh.invalid/berita/' . strtolower($issue) . "/$idx",
        'external_id' => "sintetis-$issue-$idx", 'author' => 'Redaksi Contoh', 'published_at' => iso_from_ts($ts)];
}
function generate_synthetic(float $now, int $seed = 2027): array
{
    mt_srand($seed);
    $items = []; $idx = 0;
    foreach (PROFILE as [$issue, $recent, $base, $w]) {
        for ($day = 1; $day <= 7; $day++) {
            $n = max(0, $base + mt_rand(-1, 1));
            for ($j = 0; $j < $n; $j++) $items[] = synth_article($issue, rnd_weighted(['negatif', 'netral', 'positif'], [0.3, 0.5, 0.2]), $now - $day * 86400 - rnd_float(0.5, 23.5) * 3600, ++$idx);
        }
        for ($j = 0; $j < $recent; $j++) $items[] = synth_article($issue, rnd_weighted(['negatif', 'netral', 'positif'], $w), $now - rnd_float(0.1, 23.5) * 3600, ++$idx);
    }
    shuffle($items);
    mt_srand();
    return $items;
}

const SAMPLE_DOCUMENTS = [
    ['title' => 'Laporan Situasi Harian Media (contoh)', 'classification' => 'TERBUKA', 'unit' => 'DIT-ANL', 'owner' => 'analis', 'origin' => 'Pemantauan media daring',
        'tags' => 'laporan-harian,media', 'filename' => 'lapsit-harian-contoh.md',
        'text' => "# Laporan Situasi Harian (DATA CONTOH SINTETIS)\n\nIsu dominan: harga pangan, disinformasi bantuan sosial, dan cuaca ekstrem. Sentimen publik terhadap harga beras cenderung negatif di beberapa provinsi.\n"],
    ['title' => 'Kajian Isu Harga Pangan Pekan Ini (contoh)', 'classification' => 'TERBATAS', 'unit' => 'DIT-ANL', 'owner' => 'analis', 'origin' => 'Analisis Direktorat Analisis Media',
        'tags' => 'kajian,pangan', 'filename' => 'kajian-pangan-contoh.md',
        'text' => "# Kajian Isu Harga Pangan (DATA CONTOH SINTETIS)\n\nVolume pemberitaan harga beras meningkat sekitar delapan kali dibanding rerata pekan lalu. Rekomendasi: percepat penyampaian informasi operasi pasar melalui kanal resmi provinsi.\n"],
    ['title' => 'Telaahan Staf Disinformasi Bantuan Sosial (contoh)', 'classification' => 'RAHASIA', 'unit' => 'DIT-ANL', 'owner' => 'analis', 'origin' => 'Telaahan internal',
        'tags' => 'telaahan,disinformasi', 'filename' => 'telaahan-disinformasi-contoh.txt',
        'text' => "TELAAHAN STAF (DATA CONTOH SINTETIS)\n\nPola penyebaran narasi menyesatkan terkait bantuan sosial terdeteksi pada beberapa grup percakapan publik. Disarankan penerbitan klarifikasi resmi.\n"],
    ['title' => 'Nota Dinas Arahan Strategi Komunikasi (contoh)', 'classification' => 'SANGAT_RAHASIA', 'unit' => 'DEP7', 'owner' => 'pimpinan', 'origin' => 'Pimpinan Deputi',
        'tags' => 'nota-dinas,arahan', 'filename' => 'nota-dinas-contoh.txt',
        'text' => "NOTA DINAS (DATA CONTOH SINTETIS)\n\nArahan: prioritaskan komunikasi berbasis data melalui kanal resmi dan pastikan setiap konten melalui persetujuan berjenjang.\n"],
    ['title' => 'Laporan Berkala Kanal Distribusi Triwulan (contoh)', 'classification' => 'TERBATAS', 'unit' => 'DIT-KNL', 'owner' => 'operator', 'origin' => 'Direktorat Kanal & Distribusi',
        'tags' => 'laporan-berkala,kanal', 'filename' => 'laporan-kanal-contoh.csv',
        'text' => "kanal,tayang,pembaca_unik\nNAS,120,54000\nPROV-31,45,12000\nPROV-35,38,9800\n"],
];

function seed_demo(array $users): void
{
    $nowTs = microtime(true); $now = iso_from_ts($nowTs);
    $sid = insert('sources', ['type' => 'news', 'name' => 'Portal Berita Contoh (sintetis)', 'url' => '', 'platform' => '', 'reliability' => 'B',
        'access_basis' => 'Data sintetis untuk demonstrasi', 'active' => 1, 'created_at' => $now]);
    insert('sources', ['type' => 'news', 'name' => 'Feed RSS (isi URL feed publik)', 'url' => '', 'platform' => '', 'reliability' => 'C',
        'access_basis' => 'Sumber terbuka/publik', 'active' => 1, 'created_at' => $now]);
    ingest_items(one('SELECT * FROM sources WHERE id = ?', [$sid]), generate_synthetic($nowTs), $users['analis'], true, $nowTs);

    $today = gmdate('Y-m-d'); $weekAgo = gmdate('Y-m-d', time() - 7 * 86400);
    foreach (SAMPLE_DOCUMENTS as $s) {
        $owner = $users[$s['owner']];
        $sha = blob_put($s['text']);
        $mime = str_ends_with($s['filename'], '.csv') ? 'text/csv' : (str_ends_with($s['filename'], '.md') ? 'text/markdown' : 'text/plain');
        $tags = explode(',', $s['tags']);
        $id = insert('documents', ['title' => $s['title'], 'description' => '', 'owner_unit_id' => val('SELECT id FROM units WHERE code = ?', [$s['unit']]),
            'classification' => $s['classification'], 'period_start' => $weekAgo, 'period_end' => $today, 'origin' => $s['origin'], 'retention_class' => 'UMUM',
            'tags' => jenc($tags), 'status' => 'active', 'current_version_no' => 1, 'created_by' => $owner['id'], 'created_at' => $now, 'updated_at' => $now]);
        insert('document_versions', ['document_id' => $id, 'version_no' => 1, 'filename' => $s['filename'], 'mime' => $mime, 'size' => strlen($s['text']), 'sha256' => $sha,
            'text_content' => $s['text'], 'note' => 'Versi awal', 'created_by' => $owner['id'], 'created_at' => $now]);
        audit_record($owner, 'dokumen.unggah', 'document', $id, ['title' => $s['title'], 'classification' => $s['classification'], 'sha256' => $sha, 'seed' => true]);
    }

    $issueId = (int)val("SELECT id FROM issues WHERE code = 'EKO-PANGAN'");
    $make = function (array $author, string $kind, string $title, string $body, bool $ai = false, ?string $prov = null) use ($issueId, $now): int {
        $id = insert('drafts', ['kind' => $kind, 'title' => $title, 'body' => $body, 'province_code' => $prov, 'issue_id' => $issueId, 'ai_generated' => (int)$ai,
            'status' => 'draf', 'version' => 1, 'content_hash' => draft_hash($title, $body), 'created_by' => $author['id'], 'created_at' => $now, 'updated_at' => $now]);
        insert('draft_revisions', ['draft_id' => $id, 'version' => 1, 'title' => $title, 'body' => $body, 'edited_by' => $author['id'], 'at' => $now]);
        audit_record($author, 'naskah.buat', 'draft', $id, ['kind' => $kind, 'ai_generated' => $ai, 'seed' => true]);
        return $id;
    };
    $step = function (int $id, array $actor, string $to, string $role, string $comment = '') use ($now): void {
        $d = one('SELECT * FROM drafts WHERE id = ?', [$id]);
        $sig = null;
        if ($to === 'disetujui') {
            $sig = sign(signing_message($d, (int)$actor['id']));
            q('UPDATE drafts SET approved_by = ?, approved_at = ?, approval_signature = ? WHERE id = ?', [$actor['id'], $now, $sig, $id]);
        }
        insert('approval_steps', ['draft_id' => $id, 'from_status' => $d['status'], 'to_status' => $to, 'actor_id' => $actor['id'], 'actor_role' => $role,
            'comment' => $comment, 'version' => $d['version'], 'content_hash' => $d['content_hash'], 'signature' => $sig, 'at' => $now]);
        audit_record($actor, 'naskah.transisi', 'draft', $id, ['from' => $d['status'], 'to' => $to, 'seed' => true]);
        q('UPDATE drafts SET status = ? WHERE id = ?', [$to, $id]);
    };
    $make($users['redaktur'], 'rilis', 'Pemerintah Pastikan Stok Beras Nasional Aman (contoh)',
        'Stok beras nasional dalam kondisi aman dan operasi pasar diperluas ke sejumlah provinsi. Masyarakat diimbau membeli sesuai kebutuhan. (Naskah contoh sintetis.)');
    $d2 = $make($users['produser'], 'briefing', 'Klarifikasi Hoaks Bantuan Sosial (contoh)',
        'Informasi yang menyebut penghentian bantuan sosial tidak benar. Penyaluran berjalan sesuai jadwal dan masyarakat dapat memeriksa melalui kanal resmi. (Naskah contoh sintetis.)', true);
    $step($d2, $users['produser'], 'ditinjau', 'produser');
    $d3 = $make($users['redaktur'], 'rilis', 'Operasi Pasar Beras Diperluas di Jawa Timur (contoh)',
        'Operasi pasar beras diperluas ke sepuluh kabupaten/kota di Jawa Timur mulai pekan ini untuk menjaga keterjangkauan harga. (Naskah contoh sintetis.)', false, '35');
    $step($d3, $users['redaktur'], 'ditinjau', 'redaktur');
    $step($d3, $users['pimpinan'], 'disetujui', 'pimpinan', 'Setuju untuk ditayangkan.');
    foreach (['NAS', 'PROV-35'] as $code) {
        insert('calendar_entries', ['draft_id' => $d3, 'channel_id' => val('SELECT id FROM channels WHERE code = ?', [$code]), 'planned_at' => iso_from_ts($nowTs + 3 * 3600),
            'status' => 'rencana', 'created_by' => $users['redaktur']['id'], 'created_at' => $now]);
    }
}

function random_password(): string { return b64url(random_bytes(12)); }

/** Isi otomatis saat pertama kali (aman untuk banyak proses). Kredensial acak ditulis ke data_dir/kredensial.txt (0600). */
function auto_seed(bool $demo = true): void
{
    $lock = fopen(data_dir() . '/.seed.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        init_schema();
        if ((int)val('SELECT COUNT(*) FROM users') > 0) return;
        $pw = [];
        $fixed = getenv('SISTRA_DEV_PASSWORD'); // hanya untuk uji lokal; tidak diset di hosting
        foreach (SEED_USERS as $u) $pw[$u[0]] = $fixed ?: random_password();
        tx(function () use ($pw, $demo) {
            $users = seed_base($pw);
            if ($demo) seed_demo($users);
        });
        $path = data_dir() . '/kredensial.txt';
        $fh = fopen($path, 'w');
        chmod($path, 0600);
        fwrite($fh, "# Kredensial akun SISTRA — simpan di tempat aman lalu hapus berkas ini.\n");
        foreach ($pw as $un => $p) fwrite($fh, "$un\t$p\n");
        fclose($fh);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
