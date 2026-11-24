<?php
/**
 * Data contoh (SINTETIS) untuk M4–M7: isu-isu publik Indonesia akhir 2026.
 * Semua angka, akun, nama, dan tautan adalah contoh untuk demonstrasi, bukan data resmi.
 */
declare(strict_types=1);

const M47_ISSUES = [
    ['EKO-PANGAN', 'Harga Pangan & Inflasi', 'tinggi', 'Ekonomi/Pangan', ['harga beras', 'beras', 'cabai', 'sembako', 'inflasi', 'operasi pasar', 'bulog', 'nataru']],
    ['GIZI-MBG', 'Program Makan Bergizi Gratis', 'tinggi', 'Sosial/Gizi', ['makan bergizi', 'mbg', 'dapur sppg', 'keracunan', 'menu sekolah']],
    ['BEN-ALAM', 'Banjir & Cuaca Ekstrem', 'sedang', 'Keselamatan/Bencana', ['banjir', 'longsor', 'cuaca ekstrem', 'bmkg', 'bpbd', 'pengungsi']],
    ['DIG-JUDOL', 'Judi Online & Pinjol Ilegal', 'tinggi', 'Digital/Kejahatan Siber', ['judi online', 'judol', 'slot', 'pinjol ilegal', 'rekening']],
    ['EKO-KERJA', 'Ketenagakerjaan & UMP 2027', 'sedang', 'Ekonomi/Ketenagakerjaan', ['phk', 'ump', 'upah minimum', 'buruh', 'lowongan kerja']],
    ['KES-PUBLIK', 'DBD & Kesehatan Musim Hujan', 'sedang', 'Sosial/Kesehatan', ['dbd', 'demam berdarah', 'puskesmas', 'fogging', 'jentik']],
    ['DIS-HOAKS', 'Hoaks Bansos & Penipuan Digital', 'tinggi', 'Informasi/Disinformasi', ['hoaks', 'bansos', 'penipuan', 'tautan palsu', 'cek fakta']],
    ['TRA-NATARU', 'Angkutan Natal & Tahun Baru', 'sedang', 'Transportasi', ['mudik', 'nataru', 'tiket', 'tol', 'pelabuhan', 'bandara']],
    ['DIG-PDP', 'Perlindungan Data Pribadi', 'sedang', 'Digital/Privasi', ['kebocoran data', 'data pribadi', 'nik', 'phishing']],
];

/** Fakta contoh per isu (dipakai di naskah dan brief). */
const M47_FACTS = [
    'EKO-PANGAN' => ['Operasi pasar beras SPHP digelar di 1.200 titik hingga akhir Desember (data contoh)', 'Stok beras pemerintah dinyatakan cukup hingga Maret 2027 (data contoh)', 'Harga eceran tertinggi beras medium tidak berubah (data contoh)'],
    'GIZI-MBG' => ['Setiap dapur penyedia wajib lulus uji kelayakan higiene (data contoh)', 'Menu disusun ahli gizi dan diumumkan mingguan di kanal resmi (data contoh)', 'Pengaduan menu dapat disampaikan lewat layanan aduan sekolah (data contoh)'],
    'BEN-ALAM' => ['Puncak musim hujan diperkirakan Desember–Februari (data contoh)', 'Warga di zona rawan diminta memantau peringatan dini cuaca resmi (data contoh)', 'Posko pengungsian disiapkan di setiap kecamatan rawan banjir (data contoh)'],
    'DIG-JUDOL' => ['Ribuan situs judi online diblokir setiap pekan (data contoh)', 'Rekening penampung transaksi judi online dibekukan bersama perbankan (data contoh)', 'Warga dapat melaporkan konten judi online melalui kanal aduan konten resmi (data contoh)'],
    'EKO-KERJA' => ['UMP 2027 ditetapkan setiap provinsi paling lambat akhir tahun (data contoh)', 'Pekerja terdampak PHK dapat mengakses program jaminan kehilangan pekerjaan (data contoh)', 'Bursa kerja daring menampilkan ribuan lowongan aktif (data contoh)'],
    'KES-PUBLIK' => ['Kasus DBD cenderung naik pada musim hujan (data contoh)', 'Gerakan 3M Plus dianjurkan sepekan sekali (data contoh)', 'Pemeriksaan demam gratis tersedia di puskesmas (data contoh)'],
    'DIS-HOAKS' => ['Bantuan sosial tetap disalurkan sesuai jadwal (data contoh)', 'Pemerintah tidak pernah meminta kode OTP atau PIN melalui pesan berantai (data contoh)', 'Status penerima bantuan dapat dicek di laman resmi dengan NIK (data contoh)'],
    'TRA-NATARU' => ['Diskon tarif tol dan tiket diberikan pada periode tertentu (data contoh)', 'Puncak arus mudik Natal diperkirakan H-2 (data contoh)', 'Posko terpadu disiagakan di pelabuhan, bandara, dan rest area (data contoh)'],
    'DIG-PDP' => ['Warga diimbau tidak membagikan foto KTP sembarangan (data contoh)', 'Tautan undangan pernikahan berformat APK adalah modus pencurian data (data contoh)', 'Pengaduan kebocoran data dapat disampaikan ke lembaga pengawas PDP (data contoh)'],
];

const M47_TITLES = [
    'EKO-PANGAN' => ['Operasi Pasar Beras Diperluas Jelang Natal dan Tahun Baru', 'Stok Beras Aman, Warga Diimbau Belanja Secukupnya', 'Cek Titik Operasi Pasar Terdekat di Wilayah Anda', 'Harga Cabai Naik? Ini Langkah Pemerintah Menjaga Pasokan'],
    'GIZI-MBG' => ['Standar Keamanan Pangan Dapur Makan Bergizi Gratis Diperketat', 'Cara Melaporkan Menu Makan Bergizi Gratis yang Bermasalah', 'Menu Sepekan Makan Bergizi Gratis Disusun Ahli Gizi'],
    'BEN-ALAM' => ['Siaga Banjir Puncak Musim Hujan: Kenali Tanda Bahaya', 'Daftar Posko Pengungsian dan Nomor Darurat', 'Peringatan Dini Cuaca Ekstrem Sepekan ke Depan'],
    'DIG-JUDOL' => ['Judi Online Merusak Keluarga: Kenali dan Laporkan', 'Ribuan Situs Judi Online Diblokir Pekan Ini', 'Waspada Pinjol Ilegal yang Menyamar Aplikasi Resmi'],
    'EKO-KERJA' => ['UMP 2027 Diumumkan, Ini Jadwal Penetapan di Daerah', 'Terkena PHK? Manfaat Jaminan Kehilangan Pekerjaan Bisa Diklaim', 'Bursa Kerja Daring Akhir Tahun Dibuka'],
    'KES-PUBLIK' => ['Kasus DBD Naik, Lakukan 3M Plus Sepekan Sekali', 'Kenali Gejala DBD pada Anak dan Kapan ke Puskesmas'],
    'DIS-HOAKS' => ['Hoaks: Bansos Dihentikan Bulan Depan', 'Jangan Bagikan Kode OTP, Pemerintah Tak Pernah Memintanya', 'Cara Cek Status Penerima Bansos yang Benar'],
    'TRA-NATARU' => ['Tips Aman Mudik Natal dan Tahun Baru', 'Jadwal Diskon Tarif Tol Libur Akhir Tahun', 'Posko Terpadu Angkutan Nataru Siaga 24 Jam'],
    'DIG-PDP' => ['Waspada Undangan Digital Berformat APK', 'Lindungi Data Pribadi: Jangan Unggah Foto KTP Sembarangan'],
];

const M47_COMMENTS = [
    'EKO-PANGAN' => [['Operasi pasar di kecamatan kami kapan ya min? Harga beras sudah berat sekali.', 'pertanyaan'], ['Kemarin antre 2 jam beras SPHP sudah habis. Kecewa.', 'keluhan'],
        ['Terima kasih infonya, sangat membantu ibu-ibu di kampung.', 'apresiasi'], ['Harga cabai di pasar sini 120 ribu, kenapa tidak ada tindakan?', 'keluhan']],
    'GIZI-MBG' => [['Anak saya bilang menunya kemarin basi, lapor ke mana?', 'keluhan'], ['Menu MBG di sekolah anak saya enak dan bergizi, terima kasih.', 'apresiasi'],
        ['Katanya MBG dihentikan tahun depan, benar tidak?', 'hoaks']],
    'BEN-ALAM' => [['Air sudah masuk rumah di RT 05, posko terdekat di mana?', 'pertanyaan'], ['Bantuan logistik belum sampai ke desa kami sejak kemarin.', 'keluhan'],
        ['Tim BPBD sigap sekali, salut.', 'apresiasi']],
    'DIG-JUDOL' => [['Slot gacor hari ini klik link di bio 🔥🔥', 'spam'], ['Tetangga saya habis jual motor gara-gara judol. Tolong diberantas tuntas.', 'keluhan'],
        ['Bagaimana cara melaporkan situs judi online?', 'pertanyaan']],
    'EKO-KERJA' => [['UMP Jawa Tengah 2027 sudah keluar belum?', 'pertanyaan'], ['Pabrik kami PHK massal tapi pesangon belum jelas.', 'keluhan']],
    'KES-PUBLIK' => [['Fogging di perumahan kami kapan dijadwalkan?', 'pertanyaan'], ['Informasinya jelas, terima kasih.', 'apresiasi']],
    'DIS-HOAKS' => [['Benarkah ada link pendaftaran bansos baru lewat WA?', 'pertanyaan'], ['Pemerintah bohong, bansos pasti dipotong!', 'keluhan']],
    'TRA-NATARU' => [['Diskon tol berlaku tanggal berapa saja?', 'pertanyaan'], ['Tiket kapal ke Bali sudah habis semua, tolong tambah armada.', 'keluhan']],
    'DIG-PDP' => [['Saya dapat undangan nikah file APK, ternyata penipuan. Terima kasih sudah diingatkan.', 'apresiasi'], ['Data saya bocor dan dipakai pinjol, harus lapor ke mana?', 'pertanyaan']],
];

function m47_body(string $code, string $title, string $format, ?string $prov): string
{
    $f = M47_FACTS[$code];
    $where = $prov ? "Warga {$prov}" : 'Masyarakat';
    return match ($format) {
        'caption' => "{$title}\n\n{$f[0]}. {$f[1]}.\n\n{$where} dapat memperoleh informasi resmi melalui kanal pemerintah dan layanan pengaduan yang tersedia.\n\n#InfoResmi #KanalResmi",
        'thread' => "1/ {$title}\n\n2/ {$f[0]}.\n\n3/ {$f[1]}.\n\n4/ {$f[2]}.\n\n5/ Simpan dan bagikan utas ini agar keluarga Anda mendapat informasi yang benar.",
        'carousel' => "Slide 1: {$title}\nSlide 2: {$f[0]}\nSlide 3: {$f[1]}\nSlide 4: {$f[2]}\nSlide 5: Informasi resmi hanya dari kanal pemerintah. Simpan & bagikan.",
        'video' => "Adegan 1 (0–5 dtk): Judul besar \"{$title}\".\nAdegan 2 (5–20 dtk): Narator menjelaskan: {$f[0]}.\nAdegan 3 (20–40 dtk): {$f[1]}.\nAdegan 4 (40–55 dtk): {$f[2]}.\nPenutup (55–60 dtk): Logo kanal resmi dan ajakan cek informasi resmi.",
        'faq' => "T: Apa yang perlu diketahui warga?\nJ: {$f[0]}.\n\nT: Apa yang harus dilakukan?\nJ: {$f[1]}.\n\nT: Ke mana mencari informasi lebih lanjut?\nJ: {$f[2]}.",
        default => "{$where} perlu mengetahui perkembangan terbaru terkait isu ini. {$f[0]}.\n\n{$f[1]}. Pemerintah terus berkoordinasi lintas instansi untuk memastikan layanan berjalan baik.\n\n{$f[2]}. Informasi resmi selalu diperbarui melalui kanal pemerintah. (Naskah contoh sintetis.)",
    };
}

function seed_m47(): void
{
    if ((int)val('SELECT COUNT(*) FROM social_accounts') > 0) return;
    mt_srand(2711);
    $nowTs = microtime(true);
    $at = fn(float $hoursAgo) => iso_from_ts($nowTs - $hoursAgo * 3600);
    $uid = fn(string $un) => (int)val('SELECT id FROM users WHERE username = ?', [$un]);
    $U = ['redaktur' => $uid('redaktur'), 'produser' => $uid('produser'), 'analis' => $uid('analis'), 'analis2' => $uid('analis2'), 'pimpinan' => $uid('pimpinan'), 'operator' => $uid('operator')];
    if (!$U['redaktur'] || !$U['pimpinan'] || !$U['operator']) return;

    // --- isu ---
    $iss = [];
    foreach (M47_ISSUES as [$code, $name, $risk, $path, $kw]) {
        $id = val('SELECT id FROM issues WHERE code = ?', [$code]);
        if (!$id) $id = insert('issues', ['code' => $code, 'name' => $name, 'risk_level' => $risk, 'taxonomy_path' => $path, 'description' => 'Isu contoh (sintetis) untuk demonstrasi M4–M7',
            'keywords' => jenc($kw), 'active' => 1, 'created_at' => now_iso()]);
        else q('UPDATE issues SET name = ?, risk_level = ? WHERE id = ?', [$name, $risk, $id]);
        $iss[$code] = (int)$id;
    }

    // --- kanal: kesehatan & khalayak ---
    foreach (all("SELECT * FROM channels WHERE type IN ('nasional','provinsi')") as $c) {
        $health = in_array($c['code'], ['PROV-94', 'PROV-53'], true) ? 'lambat' : ($c['code'] === 'PROV-95' ? 'gangguan' : 'normal');
        q('UPDATE channels SET health = ?, latency_ms = ?, uptime = ?, checked_at = ?, audience = ? WHERE id = ?', [$health,
            $health === 'normal' ? mt_rand(140, 420) : ($health === 'lambat' ? mt_rand(1100, 2200) : 0), $health === 'gangguan' ? 97.8 : ($health === 'lambat' ? 99.2 : round(99.6 + mt_rand(0, 39) / 100, 2)),
            $at(0.3), $c['type'] === 'nasional' ? 1450000 : mt_rand(25, 260) * 1000, $c['id']]);
    }

    // --- akun resmi (contoh; bukan akun sungguhan) ---
    $accounts = [];
    foreach ([['instagram', '@kanalresmi.demo', 'Kanal Resmi (contoh) · Instagram', null, 1250000], ['x', '@kanalresmi_demo', 'Kanal Resmi (contoh) · X', null, 830000],
        ['tiktok', '@kanalresmi.demo', 'Kanal Resmi (contoh) · TikTok', null, 970000], ['facebook', 'Kanal Resmi Demo', 'Kanal Resmi (contoh) · Facebook', null, 610000],
        ['youtube', '@KanalResmiDemo', 'Kanal Resmi (contoh) · YouTube', null, 420000], ['threads', '@kanalresmi.demo', 'Kanal Resmi (contoh) · Threads', null, 150000],
        ['instagram', '@kanaljatim.demo', 'Kanal Jawa Timur (contoh)', '35', 210000], ['instagram', '@kanalsulsel.demo', 'Kanal Sulawesi Selatan (contoh)', '73', 98000]] as $i => [$p, $h, $n, $pv, $fol]) {
        $accounts[] = insert('social_accounts', ['platform' => $p, 'handle' => $h, 'name' => $n, 'scope' => $pv ? 'provinsi' : 'nasional', 'province_code' => $pv,
            'pic_user_id' => $U['operator'], 'followers' => $fol, 'token_status' => $i === 4 ? 'kedaluwarsa' : 'aman', 'mfa' => 1, 'active' => 1,
            'url' => 'https://contoh.invalid/akun/' . trim($h, '@ '), 'created_at' => $at(24 * 60)]);
    }

    // --- naskah lintas isu, format, status ---
    $formats = ['rilis', 'caption', 'carousel', 'video', 'thread', 'artikel', 'faq', 'infografis', 'opini'];
    $authors = ['redaktur', 'produser', 'analis2', 'redaktur', 'produser'];
    $provs = [null, null, '35', null, '73', null, '31', null, '32', null];
    $plan = [];
    $k = 0;
    foreach (M47_TITLES as $code => $titles) foreach ($titles as $t) $plan[] = [$code, $t, $formats[$k % 9], $authors[$k % 5], $provs[$k % 10], $k++];
    $statusOf = function (int $k): string { $m = $k % 10; return $m <= 3 ? 'tayang' : ($m <= 5 ? 'disetujui' : ($m <= 7 ? 'ditinjau' : 'draf')); };
    $published = [];
    foreach ($plan as [$code, $title, $fmt, $author, $pv, $k]) {
        $status = $statusOf($k);
        $created = max(3.0, 24 * (21 - ($k * 0.75)) + mt_rand(0, 10));
        // tenggat: naskah yang masih berjalan mayoritas akan datang, sebagian terlambat; naskah tayang memakai tenggat lampau
        $due = $status === 'tayang' ? $created - 48 : ($k % 4 === 0 ? mt_rand(2, 30) : -mt_rand(4, 96));
        $body = m47_body($code, $title, $fmt, $pv ? PROVINCES[$pv][0] : null);
        $aid = $U[$author]; $h = draft_hash($title, $body);
        $ai = $k % 3 !== 2;
        $score = $ai ? mt_rand(72, 96) : null;
        $edit = $score === null ? null : jenc(['mode' => 'simulasi', 'score' => $score, 'summary' => 'Pemeriksaan contoh.', 'findings' => $score < 85 ? [['category' => 'ejaan', 'severity' => 'info', 'excerpt' => 'di karenakan', 'suggestion' => 'Tulis "dikarenakan".']] : []]);
        $did = insert('drafts', ['kind' => $fmt, 'format' => $fmt, 'title' => $title, 'body' => $body, 'province_code' => $pv, 'issue_id' => $iss[$code], 'ai_generated' => $ai ? 1 : 0,
            'status' => 'draf', 'version' => 1, 'content_hash' => $h, 'created_by' => $aid, 'created_at' => $at($created), 'updated_at' => $at($created),
            'assignee_id' => $U[$k % 2 ? 'redaktur' : 'produser'], 'due_at' => $at($due), 'priority' => in_array($code, ['DIS-HOAKS', 'EKO-PANGAN', 'BEN-ALAM'], true) ? 'tinggi' : 'normal',
            'editorial_score' => $score, 'editorial_json' => $edit, 'hashtags' => jenc(in_array($fmt, ['caption', 'carousel', 'video', 'thread'], true) ? ['#InfoResmi'] : [])]);
        insert('draft_revisions', ['draft_id' => $did, 'version' => 1, 'title' => $title, 'body' => $body, 'edited_by' => $aid, 'at' => $at($created)]);
        $t = $created;
        $step = function (string $from, string $to, int $actor, string $role, float $hoursAgo, string $comment = '', ?string $sig = null) use ($did, $h, $at) {
            insert('approval_steps', ['draft_id' => $did, 'from_status' => $from, 'to_status' => $to, 'actor_id' => $actor, 'actor_role' => $role, 'comment' => $comment, 'version' => 1,
                'content_hash' => $h, 'signature' => $sig, 'at' => $at($hoursAgo)]);
        };
        if ($status !== 'draf') { $t = max(2.0, $t - mt_rand(1, 6)); $step('draf', 'ditinjau', $aid, $author === 'analis2' ? 'analis' : $author, $t); }
        if ($k % 7 === 3 && $status !== 'draf') { $step('ditinjau', 'draf', $U['pimpinan'], 'pimpinan', max(1.8, $t - 0.5), 'Perjelas sumber data dan tambahkan kanal pengaduan.'); $t = max(1.6, $t - 1); $step('draf', 'ditinjau', $aid, $author === 'analis2' ? 'analis' : $author, $t); }
        if (in_array($status, ['disetujui', 'tayang'], true)) {
            $t = max(1.2, $t - mt_rand(1, 12));
            $d = ['id' => $did, 'version' => 1, 'content_hash' => $h];
            $sig = sign(signing_message($d, $U['pimpinan']));
            $step('ditinjau', 'disetujui', $U['pimpinan'], 'pimpinan', $t, 'Setuju.', $sig);
            q('UPDATE drafts SET approved_by = ?, approved_at = ?, approval_signature = ? WHERE id = ?', [$U['pimpinan'], $at($t), $sig, $did]);
        }
        if ($status === 'tayang') { $t = max(0.8, $t - mt_rand(1, 10)); $step('disetujui', 'tayang', $U['operator'], 'operator', $t); $published[] = [$did, $code, $fmt, $t]; q('UPDATE drafts SET published_at = ? WHERE id = ?', [$at($t), $did]); }
        q('UPDATE drafts SET status = ?, updated_at = ? WHERE id = ?', [$status, $at(max(0.5, $t)), $did]);
        if ($k % 4 === 1) insert('draft_comments', ['draft_id' => $did, 'user_id' => $U['pimpinan'], 'body' => 'Mohon cantumkan sumber data di akhir naskah.', 'at' => $at($t + 1)]);
        if ($k % 5 === 2) insert('draft_comments', ['draft_id' => $did, 'user_id' => $U['redaktur'], 'body' => 'Judul bisa dipersingkat agar muat di pratinjau media sosial.', 'at' => $at($t + 2)]);
        // tugas unggah medsos untuk naskah tayang/disetujui berformat medsos
        if (in_array($status, ['tayang', 'disetujui'], true) && in_array($fmt, ['caption', 'carousel', 'video', 'thread', 'infografis'], true)) {
            $targets = $fmt === 'video' ? [$accounts[2], $accounts[4]] : ($fmt === 'thread' ? [$accounts[1], $accounts[5]] : [$accounts[0], $accounts[3]]);
            foreach ($targets as $j => $acc) {
                $done = $status === 'tayang';
                $plannedH = $done ? $t - 1 : ($j ? -6 : 3);
                $reach = $done ? mt_rand(18, 260) * 1000 : 0;
                insert('post_tasks', ['draft_id' => $did, 'account_id' => $acc, 'planned_at' => $at($plannedH), 'caption' => $title, 'status' => $done ? 'diunggah' : 'menunggu', 'assignee_id' => $U['operator'],
                    'posted_url' => $done ? "https://contoh.invalid/post/{$did}-{$acc}" : null, 'posted_at' => $done ? $at($plannedH - 0.3) : null, 'posted_by' => $done ? $U['operator'] : null,
                    'reach' => $reach, 'engagement' => (int)($reach * mt_rand(25, 90) / 1000), 'created_by' => $U['redaktur'], 'created_at' => $at($t + 1)]);
            }
        }
    }

    // --- distribusi ke 39 kanal untuk naskah tayang ---
    $chs = all("SELECT * FROM channels WHERE type IN ('nasional','provinsi') ORDER BY id");
    foreach ($published as $n => [$did, $code, $fmt, $t]) {
        if (in_array($fmt, ['caption', 'thread', 'video'], true)) continue;
        $pick = $n % 2 === 0 ? $chs : array_slice($chs, 0, 12 + ($n * 5) % 27);
        foreach ($pick as $c) {
            $fail = $c['health'] === 'gangguan';
            $views = $fail ? 0 : (int)(($c['type'] === 'nasional' ? 9 : 1) * mt_rand(800, 9000) * (in_array($code, ['EKO-PANGAN', 'DIS-HOAKS', 'BEN-ALAM'], true) ? 1.8 : 1));
            insert('distributions', ['draft_id' => $did, 'channel_id' => $c['id'], 'status' => $fail ? 'gagal' : 'terkirim', 'sent_at' => $fail ? null : $at($t - 0.2), 'error' => $fail ? 'Kanal tidak merespons (gangguan)' : null,
                'url' => $fail ? null : 'https://contoh.invalid/' . strtolower($c['code']) . "/naskah-{$did}", 'views' => $views, 'clicks' => (int)($views * mt_rand(20, 70) / 1000),
                'shares' => (int)($views * mt_rand(2, 12) / 1000), 'created_by' => $U['operator'], 'created_at' => $at($t)]);
        }
    }

    // --- komentar warga (contoh) ---
    $handles = ['warga_', 'ibu.', 'pak', 'mas.', 'kak_', 'user'];
    $n = 0;
    foreach (M47_COMMENTS as $code => $list) {
        foreach ($list as $j => [$text, $cat]) {
            for ($rep = 0; $rep < 2; $rep++) {
                $acc = $accounts[($n + $rep) % count($accounts)];
                $hAgo = ($n * 7 + $rep * 31) % (24 * 12) + 0.5;
                $status = $hAgo < 30 ? 'baru' : ($n % 3 === 0 ? 'ditriase' : ($cat === 'spam' ? 'diabaikan' : 'dibalas'));
                $sent = in_array($cat, ['keluhan', 'hoaks'], true) ? 'negatif' : ($cat === 'apresiasi' ? 'positif' : 'netral');
                $triaged = $status !== 'baru';
                $sim = sim_triage(['id' => 0, 'text' => $text, 'platform' => '']);
                insert('social_comments', ['account_id' => $acc, 'issue_id' => $iss[$code], 'author' => $handles[$n % 6] . (1000 + $n * 37 % 9000), 'text' => $text, 'at' => $at($hAgo),
                    'category' => $triaged ? $cat : null, 'sentiment' => $triaged ? $sent : null, 'priority' => $triaged ? ($cat === 'hoaks' ? 'tinggi' : ($cat === 'keluhan' ? 'sedang' : 'rendah')) : null,
                    'reason' => $triaged ? 'Triase contoh' : null, 'status' => $status, 'suggested_reply' => $triaged ? $sim['suggested_reply'] : null,
                    'reply_text' => $status === 'dibalas' ? $sim['suggested_reply'] : null, 'replied_by' => $status === 'dibalas' ? $U['operator'] : null,
                    'replied_at' => $status === 'dibalas' ? $at($hAgo - mt_rand(1, 9) / 2) : null, 'ai_mode' => $triaged ? 'simulasi' : null, 'triaged_at' => $triaged ? $at($hAgo - 0.2) : null]);
                $n++;
            }
        }
    }
    // klaster bot: teks identik dari banyak akun dalam hitungan menit
    foreach (['bansos.info88', 'rakyat_sadar21', 'infoviral_id', 'kabarterkini.99', 'suara_rakyat7'] as $i => $bot) {
        insert('social_comments', ['account_id' => $accounts[0], 'issue_id' => $iss['DIS-HOAKS'], 'author' => $bot, 'text' => 'BANSOS DIHENTIKAN BULAN DEPAN!! Pemerintah tidak transparan, sebarkan sebelum dihapus!!',
            'at' => $at(5 - $i * 0.03), 'status' => 'baru']);
    }
    detect_bot_swarm();
    q("UPDATE social_comments SET priority = 'tinggi' WHERE bot_flag = 1");

    // --- bahan respons cepat ---
    foreach ([['DIS-HOAKS', 'Bansos tidak dihentikan', 'Apakah bansos dihentikan bulan depan?', 'Tidak benar. Bantuan sosial tetap disalurkan sesuai jadwal. Cek status penerima di laman resmi dengan NIK dan jangan percaya pesan berantai.'],
        ['DIS-HOAKS', 'Kode OTP', 'Ada petugas minta kode OTP untuk pencairan bansos?', 'Pemerintah tidak pernah meminta kode OTP, PIN, atau kata sandi. Abaikan dan laporkan nomor tersebut.'],
        ['EKO-PANGAN', 'Lokasi operasi pasar', 'Di mana operasi pasar beras terdekat?', 'Jadwal dan lokasi operasi pasar diumumkan berkala di kanal resmi pemerintah daerah. Bawa kantong belanja sendiri dan beli secukupnya.'],
        ['BEN-ALAM', 'Nomor darurat banjir', 'Ke mana melapor saat banjir?', 'Hubungi nomor darurat 112 atau posko BPBD setempat. Utamakan keselamatan, matikan listrik bila air masuk rumah.'],
        ['DIG-JUDOL', 'Lapor judi online', 'Bagaimana melaporkan situs judi online?', 'Laporkan tautan atau akun judi online melalui kanal aduan konten resmi. Jangan klik tautan dan jangan transfer dana.'],
        ['GIZI-MBG', 'Aduan menu MBG', 'Ke mana mengadukan menu makan bergizi yang bermasalah?', 'Sampaikan melalui pihak sekolah atau layanan aduan resmi program, sertakan tanggal, sekolah, dan foto bila ada.'],
        ['TRA-NATARU', 'Diskon tol Nataru', 'Kapan diskon tarif tol libur akhir tahun?', 'Jadwal diskon diumumkan operator jalan tol dan kanal resmi. Pantau pembaruan sebelum berangkat.']] as [$code, $t, $qq, $a]) {
        insert('kb_responses', ['issue_id' => $iss[$code], 'title' => $t, 'question' => $qq, 'answer' => $a . ' (contoh)', 'sources' => 'Kanal resmi (contoh)', 'status' => 'disetujui', 'created_by' => $U['redaktur'], 'updated_at' => $at(mt_rand(5, 200))]);
    }

    // --- brief contoh ---
    $now = now_iso();
    $bid = insert('briefs', ['title' => 'Klarifikasi hoaks bansos dihentikan', 'issue_id' => $iss['DIS-HOAKS'], 'format' => 'carousel', 'objective' => 'Meluruskan hoaks dan mengarahkan warga ke laman cek status resmi',
        'audience' => 'Penerima bansos dan keluarganya', 'region_code' => null, 'tone' => 'tegas', 'key_messages' => 'Bansos tetap disalurkan. Cek status hanya di laman resmi. Jangan bagikan OTP.',
        'facts' => jenc(M47_FACTS['DIS-HOAKS']), 'status' => 'baru', 'created_by' => $U['produser'], 'created_at' => $now, 'updated_at' => $now]);
    audit_record(null, 'seed.m47', 'system', '-', ['naskah' => count($plan), 'akun' => count($accounts), 'brief' => $bid], 'system');
    mt_srand();
}
