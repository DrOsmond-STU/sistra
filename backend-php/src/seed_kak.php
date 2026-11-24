<?php
/** Data contoh (sintetis) untuk katalog KAK S26–S65. Hanya ditanam pada basis data demo. */
declare(strict_types=1);

// Ibu kota provinsi dan bahasa daerah utama (rujukan umum; tetap ditandai perlu verifikasi tim daerah bila belum diverifikasi).
const KAK_PROV_INFO = [
    '11' => ['Banda Aceh', 'Bahasa Aceh'], '12' => ['Medan', 'Bahasa Batak dan Melayu'], '13' => ['Padang', 'Bahasa Minangkabau'], '14' => ['Pekanbaru', 'Bahasa Melayu Riau'],
    '15' => ['Jambi', 'Bahasa Melayu Jambi'], '16' => ['Palembang', 'Bahasa Palembang'], '17' => ['Bengkulu', 'Bahasa Melayu Bengkulu dan Rejang'], '18' => ['Bandar Lampung', 'Bahasa Lampung'],
    '19' => ['Pangkalpinang', 'Bahasa Melayu Bangka'], '21' => ['Tanjungpinang', 'Bahasa Melayu Kepulauan Riau'], '31' => ['Jakarta', 'Bahasa Betawi'], '32' => ['Bandung', 'Bahasa Sunda'],
    '33' => ['Semarang', 'Bahasa Jawa'], '34' => ['Yogyakarta', 'Bahasa Jawa'], '35' => ['Surabaya', 'Bahasa Jawa dan Madura'], '36' => ['Serang', 'Bahasa Sunda Banten dan Jawa Serang'],
    '51' => ['Denpasar', 'Bahasa Bali'], '52' => ['Mataram', 'Bahasa Sasak, Samawa, dan Mbojo'], '53' => ['Kupang', 'Beragam bahasa daerah (antara lain Dawan, Manggarai, Sumba)'],
    '61' => ['Pontianak', 'Bahasa Melayu Pontianak dan Dayak'], '62' => ['Palangka Raya', 'Bahasa Dayak Ngaju'], '63' => ['Banjarbaru', 'Bahasa Banjar'], '64' => ['Samarinda', 'Bahasa Kutai dan Banjar'],
    '65' => ['Tanjung Selor', 'Bahasa Tidung dan Bulungan'], '71' => ['Manado', 'Bahasa Melayu Manado'], '72' => ['Palu', 'Bahasa Kaili'], '73' => ['Makassar', 'Bahasa Bugis dan Makassar'],
    '74' => ['Kendari', 'Bahasa Tolaki'], '75' => ['Gorontalo', 'Bahasa Gorontalo'], '76' => ['Mamuju', 'Bahasa Mandar'], '81' => ['Ambon', 'Bahasa Melayu Ambon'],
    '82' => ['Sofifi', 'Bahasa Ternate dan Tidore'], '91' => ['Jayapura', 'Beragam bahasa daerah; Melayu Papua'], '92' => ['Manokwari', 'Beragam bahasa daerah; Melayu Papua'],
    '93' => ['Merauke', 'Beragam bahasa daerah; Melayu Papua'], '94' => ['Nabire', 'Beragam bahasa daerah; Melayu Papua'], '95' => ['Jayawijaya (Wamena)', 'Beragam bahasa daerah; Melayu Papua'],
    '96' => ['Sorong', 'Beragam bahasa daerah; Melayu Papua'],
];

function seed_svg(string $title, string $sub, string $bg, string $accent, int $w = 1200, int $h = 630): string
{
    $t = htmlspecialchars(mb_substr($title, 0, 46), ENT_XML1); $s = htmlspecialchars(mb_substr($sub, 0, 70), ENT_XML1);
    return "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"$w\" height=\"$h\" viewBox=\"0 0 $w $h\"><rect width=\"100%\" height=\"100%\" fill=\"$bg\"/>"
        . "<rect x=\"0\" y=\"" . ($h - 18) . "\" width=\"$w\" height=\"18\" fill=\"$accent\"/><circle cx=\"" . ($w - 140) . "\" cy=\"140\" r=\"90\" fill=\"$accent\" opacity=\".35\"/>"
        . "<text x=\"70\" y=\"" . (int)($h * 0.48) . "\" font-family=\"Arial, sans-serif\" font-size=\"" . (int)($w / 22) . "\" font-weight=\"700\" fill=\"#ffffff\">$t</text>"
        . "<text x=\"70\" y=\"" . (int)($h * 0.62) . "\" font-family=\"Arial, sans-serif\" font-size=\"" . (int)($w / 42) . "\" fill=\"#dfe7f2\">$s</text>"
        . "<text x=\"70\" y=\"" . ($h - 44) . "\" font-family=\"Arial, sans-serif\" font-size=\"22\" fill=\"#ffffff\" opacity=\".8\">Aset contoh (sintetis) · SISTRA</text></svg>";
}

function seed_kak(): void
{
    if ((int)val('SELECT COUNT(*) FROM partners') > 0) return;
    mt_srand(3127);
    $nowTs = microtime(true);
    $at = fn(float $h) => iso_from_ts($nowTs - $h * 3600);
    $uid = fn(string $un) => (int)val('SELECT id FROM users WHERE username = ?', [$un]);
    $U = ['redaktur' => $uid('redaktur'), 'produser' => $uid('produser'), 'analis' => $uid('analis'), 'pimpinan' => $uid('pimpinan'), 'operator' => $uid('operator'), 'admin' => $uid('admin')];
    $iss = []; foreach (all('SELECT id, code FROM issues') as $i) $iss[$i['code']] = (int)$i['id'];
    $chs = all("SELECT * FROM channels WHERE type IN ('nasional','provinsi') ORDER BY id");

    // ---- S26: fakta brief bersumber ----
    $srcs = [['Siaran pers kementerian teknis (contoh)', 'A'], ['Data BPS/instansi statistik (contoh)', 'A'], ['Keterangan resmi pemerintah daerah (contoh)', 'B'], ['Rilis lembaga terkait (contoh)', 'B']];
    foreach (all('SELECT * FROM briefs') as $i => $b) {
        $items = array_map(fn($f, $k) => ['text' => $f, 'source' => $srcs[($i + $k) % 4][0], 'url' => 'https://contoh.invalid/sumber/' . ($i * 10 + $k), 'reliability' => $srcs[($i + $k) % 4][1]],
            jdec($b['facts']), array_keys(jdec($b['facts'])));
        q('UPDATE briefs SET facts = ?, gen_ms = ? WHERE id = ?', [jenc(fact_items($items)), mt_rand(2100, 11800), $b['id']]);
    }

    // basis fakta per isu: naskah contoh tanpa brief ditautkan ke brief basis fakta bersumber
    foreach (M47_FACTS as $code => $fl) {
        if (!isset($iss[$code])) continue;
        $name = (string)val('SELECT name FROM issues WHERE id = ?', [$iss[$code]]);
        $bid = insert('briefs', ['title' => "Basis fakta isu: {$name}", 'issue_id' => $iss[$code], 'format' => 'rilis', 'objective' => 'Basis fakta terverifikasi untuk seluruh naskah isu ini',
            'audience' => 'Warga', 'region_code' => null, 'tone' => 'informatif', 'key_messages' => 'Informasi resmi hanya dari kanal pemerintah.',
            'facts' => jenc(array_map(fn($t, $k) => ['text' => $t, 'source' => $srcs[$k % 4][0], 'url' => 'https://contoh.invalid/fakta/' . strtolower($code) . '-' . ($k + 1), 'reliability' => $srcs[$k % 4][1]], $fl, array_keys($fl))),
            'status' => 'dipakai', 'ai_mode' => null, 'created_by' => $U['analis'], 'created_at' => $at(24 * 30), 'updated_at' => $at(24 * 30), 'gen_ms' => null]);
        q('UPDATE drafts SET brief_id = ? WHERE issue_id = ? AND brief_id IS NULL', [$bid, $iss[$code]]);
    }
    // satu draf memuat klaim angka tanpa sumber (untuk demonstrasi skor verifikasi S33)
    if ($dx = one("SELECT * FROM drafts WHERE status = 'draf' AND issue_id = ? ORDER BY id LIMIT 1", [$iss['EKO-PANGAN'] ?? 0])) {
        $body = $dx['body'] . "\n\nSebanyak 87% pedagang pasar menyatakan harga sudah turun pekan ini. [DATA PERLU DIVERIFIKASI]\nPenyaluran ditargetkan mencapai 2,5 juta keluarga penerima.";
        $h = draft_hash($dx['title'], $body);
        q('UPDATE drafts SET body = ?, content_hash = ?, version = version + 1, updated_at = ? WHERE id = ?', [$body, $h, $at(3), $dx['id']]);
        insert('draft_revisions', ['draft_id' => $dx['id'], 'version' => (int)$dx['version'] + 1, 'title' => $dx['title'], 'body' => $body, 'edited_by' => $U['produser'], 'at' => $at(3)]);
    }

    // ---- S28: basis data konteks lokal 38 provinsi ----
    foreach (KAK_PROV_INFO as $code => [$cap, $lang]) {
        $papua = str_starts_with((string)$code, '9');
        $rows = [['ibu_kota', "Ibu kota provinsi: {$cap}", 'Rujukan administrasi wilayah', 1],
            ['bahasa', "Bahasa daerah utama: {$lang}", 'Rujukan kebahasaan umum', $papua ? 0 : 1],
            ['layanan', 'Nomor darurat terpadu 112 dapat dihubungi untuk keadaan darurat', 'Layanan darurat nasional', $papua ? 0 : 1],
            ['layanan', 'Pengaduan layanan publik melalui SP4N-LAPOR! (lapor.go.id)', 'Kanal pengaduan nasional', $papua ? 0 : 1]];
        if (in_array($code, ['32', '33', '35', '73', '51', '31'], true)) $rows[] = ['istilah', 'Gunakan sapaan dan istilah setempat secara santun pada konten lokal (contoh, tinjau tim daerah)', 'Catatan tim daerah (contoh)', 0];
        foreach ($rows as [$cat, $txt, $src, $ver]) insert('local_context', ['province_code' => $code, 'category' => $cat, 'content' => $txt, 'source' => $src, 'verified' => $ver,
            'verified_by' => $ver ? $U['redaktur'] : null, 'verified_at' => $ver ? $at(mt_rand(48, 400)) : null, 'created_by' => $U['analis'], 'created_at' => $at(500)]);
    }

    // ---- S38: templat visual ----
    $tpls = [['INF-STD', 'Infografis standar', 'infografis', ['bg' => '#10243F', 'fg' => '#FFFFFF', 'accent' => '#F2B705', 'font' => 'Inter, Arial, sans-serif', 'logo_text' => 'KANAL INFORMASI RESMI (contoh)', 'footer' => 'Sumber resmi · Data dapat diperbarui']],
        ['INF-DARURAT', 'Infografis siaga/darurat', 'infografis', ['bg' => '#7A1414', 'fg' => '#FFFFFF', 'accent' => '#FFD166', 'font' => 'Inter, Arial, sans-serif', 'logo_text' => 'INFO SIAGA (contoh)', 'footer' => 'Darurat? Hubungi 112']],
        ['INF-CERAH', 'Infografis layanan publik', 'infografis', ['bg' => '#F6F6F3', 'fg' => '#10243F', 'accent' => '#1C5CAB', 'font' => 'Inter, Arial, sans-serif', 'logo_text' => 'LAYANAN PUBLIK (contoh)', 'footer' => 'Informasi resmi pemerintah']],
        ['VID-STD', 'Video pendek standar', 'video', ['bg' => '#0B1626', 'fg' => '#FFFFFF', 'accent' => '#3987E5', 'font' => 'Inter, Arial, sans-serif', 'logo_text' => 'KANAL INFORMASI RESMI (contoh)', 'footer' => 'Cek fakta di kanal resmi']]];
    foreach ($tpls as [$c, $n, $k, $spec]) {
        insert('visual_templates', ['code' => $c, 'name' => $n, 'kind' => $k, 'version' => 1, 'spec' => jenc($spec), 'locked' => 0, 'is_current' => 0, 'note' => 'Versi awal', 'created_by' => $U['produser'], 'created_at' => $at(900)]);
        insert('visual_templates', ['code' => $c, 'name' => $n, 'kind' => $k, 'version' => 2, 'spec' => jenc($spec), 'locked' => $c === 'INF-STD' ? 1 : 0, 'is_current' => 1, 'note' => 'Penyesuaian kontras warna (WCAG AA)', 'created_by' => $U['produser'], 'created_at' => $at(300)]);
    }

    // ---- S37: bank aset ----
    $assetDefs = [['Foto posko pengungsian banjir (contoh)', 'BEN-ALAM', 'milik_sendiri', null, '#1C3D5A'], ['Ilustrasi operasi pasar beras', 'EKO-PANGAN', 'milik_sendiri', null, '#3E4A1C'],
        ['Ikon menu makan bergizi', 'GIZI-MBG', 'cc_by', null, '#2F5D50'], ['Kartu cek fakta bansos', 'DIS-HOAKS', 'milik_sendiri', null, '#5A1C1C'],
        ['Foto antrean SPBU mudik (mitra)', 'TRA-NATARU', 'berlisensi', 20, '#2A2F5A'], ['Ilustrasi bahaya judi online', 'DIG-JUDOL', 'berlisensi', -5, '#4A1C5A'],
        ['Peta rawan banjir (data mitra)', 'BEN-ALAM', 'mitra', 120, '#1C4A5A'], ['Ikon perlindungan data pribadi', 'DIG-PDP', 'cc_by', null, '#1C2F5A'],
        ['Foto kegiatan posyandu (editorial)', 'KES-PUBLIK', 'editorial', 60, '#3B5A1C'], ['Grafik inflasi pangan (contoh)', 'EKO-PANGAN', 'milik_sendiri', null, '#5A4A1C'],
        ['Foto bursa kerja daring', 'EKO-KERJA', 'berlisensi', 12, '#1C5A3B'], ['Latar video siaga cuaca', 'BEN-ALAM', 'milik_sendiri', null, '#0B1626']];
    $assetIds = [];
    foreach ($assetDefs as $i => [$t, $code, $lic, $expDays, $bg]) {
        $svg = seed_svg($t, 'Isu ' . $code, $bg, '#F2B705');
        $sha = blob_put($svg);
        $words = array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($t)), fn($w) => mb_strlen($w) > 3)));
        $assetIds[$code][] = $assetIds['all'][] = insert('assets', ['kind' => 'gambar', 'title' => $t, 'filename' => 'aset-' . ($i + 1) . '.svg', 'mime' => 'image/svg+xml', 'size' => strlen($svg), 'sha256' => $sha,
            'tags' => jenc([mb_strtolower($code), 'contoh']), 'ai_tags' => jenc(array_slice($words, 0, 5)), 'description' => 'Aset contoh sintetis untuk demonstrasi bank aset.',
            'credit' => $lic === 'mitra' ? 'Mitra data (contoh)' : ($lic === 'berlisensi' ? 'Agensi foto (contoh)' : 'Tim produksi'), 'license' => $lic,
            'license_expires_at' => $expDays === null ? null : iso_from_ts($nowTs + $expDays * 86400), 'issue_id' => $iss[$code] ?? null, 'created_by' => $U['produser'], 'created_at' => $at(24 * (20 - $i))]);
    }
    $approved = all("SELECT * FROM drafts WHERE status IN ('disetujui','tayang') ORDER BY id");
    foreach ($approved as $i => $d) if ($i % 2 === 0) {
        $code = val('SELECT code FROM issues WHERE id = ?', [$d['issue_id']]);
        if (!empty($assetIds[$code])) q('INSERT OR IGNORE INTO draft_assets (draft_id, asset_id) VALUES (?, ?)', [$d['id'], $assetIds[$code][0]]);
    }

    // ---- S29/S30: keluaran multimedia contoh ----
    foreach (array_slice($approved, 0, 3) as $i => $d) {
        $facts = draft_facts($d) ?: [['text' => 'Penyaluran berjalan sesuai jadwal (contoh)', 'source' => 'Siaran pers (contoh)', 'url' => '', 'reliability' => 'A']];
        $inf = ai_infographic(null, $d, $facts);
        insert('media_outputs', ['draft_id' => $d['id'], 'kind' => 'infografis', 'title' => $d['title'], 'spec' => jenc($inf['spec']), 'template_code' => $i === 1 ? 'INF-DARURAT' : 'INF-STD', 'ai_mode' => 'simulasi',
            'ms' => mt_rand(900, 4000), 'created_by' => $U['produser'], 'created_at' => $at(30 - $i), 'updated_at' => $at(30 - $i)]);
        if ($i < 2) {
            $vid = ai_video(null, $d, '9:16', 45);
            insert('media_outputs', ['draft_id' => $d['id'], 'kind' => 'video', 'title' => $d['title'], 'spec' => jenc($vid['spec']), 'template_code' => 'VID-STD', 'ai_mode' => 'simulasi',
                'ms' => mt_rand(1500, 6000), 'created_by' => $U['produser'], 'created_at' => $at(28 - $i), 'updated_at' => $at(28 - $i)]);
        }
    }

    // ---- S32: umpan balik koreksi redaktur ----
    $cats = [['ejaan', 'info', 'di karenakan', 'diterima'], ['fakta', 'peringatan', '45%', 'diterima'], ['nada', 'peringatan', 'segera', 'ditolak'], ['kejelasan', 'info', 'Pemerintah menyampaikan…', 'diterima'],
        ['sara', 'kritis', 'warga pendatang', 'diterima'], ['ejaan', 'info', 'Nataru', 'ditolak'], ['hukum', 'peringatan', 'pasti dipidana', 'diterima'], ['fakta', 'peringatan', 'Rp 12.500', 'ditolak']];
    foreach (array_slice(all('SELECT id FROM drafts WHERE editorial_score IS NOT NULL ORDER BY id'), 0, 16) as $i => $d) {
        [$c, $s, $e, $dec] = $cats[$i % count($cats)];
        insert('editorial_feedback', ['draft_id' => $d['id'], 'category' => $c, 'severity' => $s, 'excerpt' => $e, 'suggestion' => 'Saran contoh', 'decision' => $dec,
            'note' => $dec === 'ditolak' ? ($c === 'ejaan' ? 'Singkatan baku yang sudah lazim di redaksi' : 'Sudah sesuai rilis resmi') : '', 'user_id' => $U['redaktur'], 'at' => $at(mt_rand(5, 300))]);
    }

    // ---- S39: liputan lapangan ----
    foreach ([['Peninjauan operasi pasar beras', 3, 'Pasar induk kota (contoh)', '35', 'selesai'], ['Simulasi evakuasi banjir sekolah', -20, 'SD Negeri (contoh)', '32', 'direncanakan'],
        ['Peluncuran posko angkutan Nataru', -44, 'Terminal bus antarkota (contoh)', '33', 'direncanakan'], ['Sosialisasi bahaya judi online di kampus', 1, 'Aula kampus (contoh)', '73', 'berlangsung'],
        ['Pemantauan dapur makan bergizi', 50, 'Dapur umum sekolah (contoh)', '31', 'selesai']] as $i => [$t, $h, $loc, $pv, $st]) {
        $cid = insert('coverage', ['title' => $t . ' (contoh)', 'event_at' => $at($h), 'location' => $loc, 'province_code' => $pv, 'reporter_id' => $i % 2 ? $U['produser'] : $U['analis'], 'status' => $st,
            'brief' => 'Ambil foto suasana tanpa wajah anak di bawah umur; wawancara petugas resmi.', 'created_by' => $U['redaktur'], 'created_at' => $at($h + 72), 'updated_at' => $at(max(1, $h))]);
        if ($st === 'selesai') q('UPDATE assets SET coverage_id = ? WHERE id = ?', [$cid, $assetIds['all'][$i]]);
    }

    // ---- S43: utas & data pribadi ----
    $parents = all("SELECT * FROM social_comments WHERE parent_id IS NULL AND bot_flag = 0 AND category IN ('keluhan','pertanyaan') ORDER BY id LIMIT 6");
    $piiTexts = ['Saya sudah lapor berkali-kali, NIK saya 3201123456789012 tapi belum dapat bantuan', 'Tolong hubungi saya di 081234567890, rumah terendam sejak kemarin',
        'Sudah kirim email ke warga.contoh@contoh.invalid belum dibalas', 'Ikut tanya juga, di kecamatan kami belum ada informasi', 'Sama, tolong dijawab min', 'Rek. no 1234567890 sudah benar tapi dana belum masuk'];
    foreach ($parents as $i => $p) {
        foreach ([0, 1] as $k) {
            $t = $piiTexts[($i + $k) % count($piiTexts)];
            insert('social_comments', ['account_id' => $p['account_id'], 'issue_id' => $p['issue_id'], 'author' => 'warga_' . (7000 + $i * 3 + $k), 'text' => $t, 'at' => iso_from_ts((float)ts_from_iso($p['at']) + 1800 * ($k + 1)),
                'category' => 'keluhan', 'sentiment' => 'negatif', 'priority' => 'sedang', 'reason' => 'Balasan pada utas', 'status' => 'ditriase', 'parent_id' => $p['id'], 'ai_mode' => 'simulasi', 'triaged_at' => $at(1)]);
        }
    }

    // ---- S45: kuota & S40 token ----
    foreach (all('SELECT id, platform FROM social_accounts') as $i => $a) {
        $qd = ['instagram' => 3, 'x' => 8, 'tiktok' => 2, 'facebook' => 3, 'youtube' => 1, 'threads' => 5][$a['platform']] ?? 3;
        q('UPDATE social_accounts SET quota_daily = ?, quota_weekly = ?, token_rotated_at = ? WHERE id = ?', [$qd, $qd * 6, $at(24 * (10 + $i * 9)), $a['id']]);
    }
    q('UPDATE post_tasks SET impressions = CAST(reach * 1.6 AS INTEGER) WHERE reach > 0');

    // ---- S42: mitra KOL terbuka ----
    $pids = [];
    foreach ([['Dokter Edukasi (contoh)', '@dokteredukasi.demo', 'instagram', 'kesehatan', 820000, '31', 'Kemitraan berbayar dengan pemerintah', 40],
        ['Guru Literasi Digital (contoh)', '@gurudigital.demo', 'tiktok', 'literasi digital', 1250000, '33', 'Kemitraan berbayar dengan pemerintah', 25],
        ['Relawan Siaga Bencana (contoh)', '@relawansiaga.demo', 'x', 'kebencanaan', 310000, '32', 'Kolaborasi tanpa bayaran (disebutkan)', 60],
        ['Kreator Kuliner Lokal (contoh)', '@kulinerlokal.demo', 'youtube', 'gaya hidup', 540000, '73', 'Kemitraan berbayar dengan pemerintah', -10],
        ['Komunitas Cek Fakta (contoh)', '@cekfakta.demo', 'instagram', 'cek fakta', 190000, null, 'Kolaborasi tanpa bayaran (disebutkan)', 90]] as $i => [$n, $h, $pl, $cat, $fol, $pv, $dis, $endDays]) {
        $pids[] = insert('partners', ['name' => $n, 'handle' => $h, 'platform' => $pl, 'category' => $cat, 'followers' => $fol, 'province_code' => $pv, 'contract_no' => sprintf('PKS/%03d/KOM/XI/2026 (contoh)', 11 + $i),
            'contract_start' => gmdate('Y-m-d', (int)($nowTs - 60 * 86400)), 'contract_end' => gmdate('Y-m-d', (int)($nowTs + $endDays * 86400)), 'disclosure' => $dis, 'status' => 'aktif',
            'notes' => 'Mitra contoh. Setiap unggahan wajib mencantumkan label kemitraan.', 'created_by' => $U['redaktur'], 'created_at' => $at(24 * 60)]);
    }

    // ---- S44/S48: kampanye resmi ----
    $camps = [['Lawan Hoaks Bansos 2026 (contoh)', 'DIS-HOAKS', -6, 8, 'berjalan', ['NAS', '32', '35'], 2500000],
        ['Siaga Banjir Musim Hujan (contoh)', 'BEN-ALAM', -3, 25, 'berjalan', ['32', '33', '35', '31'], 1800000],
        ['Mudik Aman Nataru (contoh)', 'TRA-NATARU', 12, 40, 'disetujui', ['NAS'], 3000000],
        ['Stop Judi Online di Kampus (contoh)', 'DIG-JUDOL', 20, 50, 'diajukan', ['73', '33'], 900000]];
    $accs = all('SELECT * FROM social_accounts ORDER BY id');
    foreach ($camps as $ci => [$name, $code, $s, $e, $st, $regions, $reach]) {
        $sd = gmdate('Y-m-d', (int)($nowTs + $s * 86400)); $ed = gmdate('Y-m-d', (int)($nowTs + $e * 86400));
        $cid = insert('campaigns', ['name' => $name, 'issue_id' => $iss[$code], 'objective' => 'Meningkatkan pemahaman warga dan menurunkan sebaran informasi keliru terkait isu ini.',
            'audience' => 'Warga usia 18–45, pengguna media sosial', 'regions' => jenc($regions), 'key_messages' => 'Informasi resmi hanya dari kanal pemerintah. Laporkan informasi menyesatkan. Ikuti langkah layanan yang tersedia.',
            'start_date' => $sd, 'end_date' => $ed, 'status' => $st, 'kpi' => jenc(['reach' => $reach, 'engagement_rate' => 0.04, 'sentiment_neg_max' => 0.3]),
            'created_by' => $U['redaktur'], 'created_at' => $at(24 * (12 - $s)), 'updated_at' => $at(24)]);
        if (in_array($st, ['berjalan', 'disetujui'], true)) {
            $c = one('SELECT * FROM campaigns WHERE id = ?', [$cid]);
            $sig = sign("sistra-campaign|{$cid}|" . hash('sha256', $c['name'] . $c['key_messages'] . $c['start_date'] . $c['end_date'] . $c['regions']) . "|{$U['pimpinan']}");
            q('UPDATE campaigns SET approved_by = ?, approved_at = ?, signature = ? WHERE id = ?', [$U['pimpinan'], $at(24 * (10 - $s)), $sig, $cid]);
        }
        if ($st !== 'berjalan') continue;
        $drafts = all("SELECT * FROM drafts WHERE issue_id = ? AND status IN ('disetujui','tayang') ORDER BY id", [$iss[$code]]);
        for ($dday = $s; $dday <= min(0, $e); $dday++) {
            foreach ([0, 1] as $k) {
                $acc = $accs[($dday + $k + 10 + $ci) % count($accs)]; $d = $drafts[($dday + $k + 10) % max(1, count($drafts))] ?? null;
                if (!$d) continue;
                $date = gmdate('Y-m-d', (int)($nowTs + $dday * 86400)); $done = $dday < 0;
                $r = $done ? mt_rand(40, 320) * 1000 : 0;
                $tid = insert('post_tasks', ['draft_id' => $d['id'], 'account_id' => $acc['id'], 'planned_at' => $date . 'T0' . (3 + $k * 6) . ':00:00.000000Z', 'caption' => $d['title'], 'status' => $done ? 'diunggah' : 'menunggu',
                    'assignee_id' => $U['operator'], 'posted_url' => $done ? "https://contoh.invalid/post/k{$cid}-{$dday}{$k}" : null, 'posted_at' => $done ? $date . 'T0' . (3 + $k * 6) . ':20:00.000000Z' : null,
                    'posted_by' => $done ? $U['operator'] : null, 'reach' => $r, 'engagement' => (int)($r * mt_rand(25, 70) / 1000), 'impressions' => (int)($r * 1.7), 'created_by' => $U['redaktur'], 'created_at' => $at(24 * (1 - $s)), 'campaign_id' => $cid]);
                insert('campaign_slots', ['campaign_id' => $cid, 'date' => $date, 'account_id' => $acc['id'], 'draft_id' => $d['id'], 'post_task_id' => $tid, 'note' => '', 'created_at' => $at(24 * (1 - $s))]);
            }
            if ($dday % 2 === 0 && $dday < 0) {
                $pid = $pids[($dday + 10 + $ci) % 3];
                insert('campaign_slots', ['campaign_id' => $cid, 'date' => gmdate('Y-m-d', (int)($nowTs + $dday * 86400)), 'partner_id' => $pid, 'note' => 'Unggahan mitra berlabel kemitraan', 'created_at' => $at(24 * (1 - $s))]);
                $r = mt_rand(60, 400) * 1000;
                insert('partner_collabs', ['partner_id' => $pid, 'campaign_id' => $cid, 'draft_id' => $drafts[0]['id'] ?? null, 'posted_at' => $at(-$dday * 24 - 5), 'url' => "https://contoh.invalid/mitra/{$pid}-{$dday}",
                    'disclosure_ok' => ($dday + $ci) % 7 === 0 ? 0 : 1, 'reach' => $r, 'engagement' => (int)($r * mt_rand(30, 90) / 1000), 'notes' => '', 'created_by' => $U['operator'], 'created_at' => $at(-$dday * 24)]);
            }
        }
        // komentar sebelum vs selama kampanye (S57)
        $accId = $accs[0]['id'];
        $beforeH = (-$s) * 24; $len = max(1, -$s) * 24;
        foreach (range(0, 27) as $j) {
            $neg = $j % 10 < ($code === 'DIS-HOAKS' ? 6 : 5);
            insert('social_comments', ['account_id' => $accId, 'issue_id' => $iss[$code], 'author' => 'warga_' . (8000 + $ci * 100 + $j), 'text' => $neg ? 'Masih bingung, katanya program dihentikan?' : 'Terima kasih infonya, jadi jelas.',
                'at' => $at($beforeH + 2 + ($j / 28) * ($len - 4)), 'category' => $neg ? 'hoaks' : 'apresiasi', 'sentiment' => $neg ? 'negatif' : 'positif', 'priority' => $neg ? 'sedang' : 'rendah', 'reason' => 'Data contoh periode sebelum kampanye',
                'status' => 'dibalas', 'reply_text' => 'Terima kasih. Informasi resmi tersedia di kanal pemerintah.', 'replied_by' => $U['operator'], 'replied_at' => $at($beforeH + 1 + ($j / 28) * ($len - 4)), 'ai_mode' => 'simulasi']);
        }
        foreach (range(0, 35) as $j) {
            $neg = $j % 10 < ($code === 'DIS-HOAKS' ? 2 : 3);
            insert('social_comments', ['account_id' => $accId, 'issue_id' => $iss[$code], 'author' => 'warga_' . (8500 + $ci * 100 + $j), 'text' => $neg ? 'Kenapa di daerah kami belum ada info?' : 'Sudah cek di kanal resmi, ternyata hoaks. Terima kasih.',
                'at' => $at(max(1, $beforeH - 2 - ($j / 36) * max(4, $beforeH - 4))), 'category' => $neg ? 'keluhan' : 'apresiasi', 'sentiment' => $neg ? 'negatif' : 'positif', 'priority' => $neg ? 'sedang' : 'rendah',
                'reason' => 'Data contoh periode kampanye', 'status' => 'dibalas', 'reply_text' => 'Terima kasih atas masukannya.', 'replied_by' => $U['operator'], 'replied_at' => $at(max(0.5, $beforeH - 3 - ($j / 36) * max(4, $beforeH - 4))), 'ai_mode' => 'simulasi']);
        }
    }

    // ---- S41: paket respons cepat ----
    $alerts = all("SELECT * FROM alerts WHERE level IN ('oranye','merah') ORDER BY triggered_at DESC");
    $packDefs = [['DIS-HOAKS', 'terbit', 26, 95], ['BEN-ALAM', 'terbit', 50, 150], ['EKO-PANGAN', 'draf', 1.2, null]];
    foreach ($packDefs as $i => [$code, $st, $hAgo, $minutes]) {
        $facts = [];
        foreach (all('SELECT facts FROM briefs WHERE issue_id = ?', [$iss[$code]]) as $b) $facts = array_merge($facts, fact_items($b['facts']));
        if (!$facts) $facts = array_map(fn($t) => ['text' => $t, 'source' => 'Siaran pers (contoh)', 'url' => '', 'reliability' => 'A'], M47_FACTS[$code] ?? []);
        $p = ai_response_pack(null, (string)val('SELECT name FROM issues WHERE id = ?', [$iss[$code]]), 'Lonjakan percakapan negatif (contoh)', $facts, kb_text($iss[$code]))['pack'];
        $created = $at($hAgo);
        insert('response_packs', ['issue_id' => $iss[$code], 'alert_id' => $alerts[$i]['id'] ?? null, 'level' => $i === 1 ? 'merah' : 'oranye', 'title' => 'Paket respons: ' . val('SELECT name FROM issues WHERE id = ?', [$iss[$code]]),
            'trigger_text' => 'Lonjakan percakapan negatif dan pesan berantai (contoh)', 'holding_statement' => $p['holding_statement'], 'key_messages' => jenc($p['key_messages']), 'qa' => jenc($p['qa']),
            'data_points' => jenc($p['data_points']), 'spokesperson' => $p['spokesperson'], 'donts' => jenc($p['donts']), 'status' => $st, 'ai_mode' => 'simulasi',
            'deadline_at' => iso_from_ts((float)ts_from_iso($created) + 7200), 'published_at' => $minutes ? iso_from_ts((float)ts_from_iso($created) + $minutes * 60) : null,
            'published_by' => $minutes ? $U['pimpinan'] : null, 'created_by' => $U['analis'], 'created_at' => $created, 'updated_at' => $created]);
    }

    // ---- S51: kurva jam puncak per provinsi (indeks audiens 0–1, waktu setempat) ----
    $base = [0.08, 0.05, 0.04, 0.04, 0.08, 0.25, 0.55, 0.72, 0.6, 0.48, 0.42, 0.5, 0.78, 0.7, 0.5, 0.45, 0.52, 0.62, 0.74, 0.92, 1.0, 0.85, 0.55, 0.25];
    foreach (array_merge(['NAS'], array_keys(PROVINCES)) as $i => $code) {
        $shift = ($i % 3) - 1;
        $curve = array_map(fn($h) => round(max(0.02, min(1.0, $base[($h - $shift + 24) % 24] * (0.88 + mt_rand(0, 24) / 100))), 2), range(0, 23));
        q('INSERT OR REPLACE INTO audience_curves (province_code, hours) VALUES (?, ?)', [(string)$code, jenc($curve)]);
    }

    // ---- S49/S56: metrik distribusi & per jam ----
    foreach (all("SELECT id FROM distributions WHERE status = 'terkirim'") as $x) {
        q('UPDATE distributions SET sync_ms = ?, uniques = CAST(views * ? AS INTEGER), avg_time_sec = ?, comments = CAST(views * ? AS INTEGER) WHERE id = ?',
            [mt_rand(650, 4300), mt_rand(62, 81) / 100, mt_rand(55, 160), mt_rand(1, 4) / 1000, $x['id']]);
    }
    $tk = one("SELECT x.id FROM distributions x JOIN channels c ON c.id = x.channel_id WHERE x.status = 'terkirim' AND c.type = 'provinsi' ORDER BY x.id DESC LIMIT 1");
    if ($tk) q("UPDATE distributions SET status = 'ditarik', taken_down_at = ?, takedown_reason = 'Koreksi data oleh pemerintah daerah (contoh)' WHERE id = ?", [$at(6), $tk['id']]);
    $nextApproved = one("SELECT * FROM drafts WHERE status = 'disetujui' ORDER BY id DESC LIMIT 1");
    if ($nextApproved) foreach (array_slice($chs, 0, 8) as $c) {
        $rec = recommend_time($c['province_code'], $nowTs);
        if (!one('SELECT id FROM distributions WHERE draft_id = ? AND channel_id = ?', [$nextApproved['id'], $c['id']]))
            insert('distributions', ['draft_id' => $nextApproved['id'], 'channel_id' => $c['id'], 'status' => 'terjadwal', 'scheduled_at' => $rec['at'], 'created_by' => $U['operator'], 'created_at' => $at(2)]);
    }
    $startH = floor($nowTs / 3600) - 71;
    foreach ($chs as $c) {
        $curve = curve_for($c['province_code'] ?? 'NAS'); $off = tz_offset($c['province_code']);
        $aud = max(20000, (int)$c['audience']);
        for ($h = 0; $h < 72; $h++) {
            $ts = ($startH + $h) * 3600; $local = ((int)gmdate('G', (int)$ts) + $off) % 24;
            $v = (int)($aud * 0.0045 * $curve[$local] * (0.7 + mt_rand(0, 60) / 100) * ($c['health'] === 'gangguan' && $h > 54 ? 0.05 : 1));
            q('INSERT OR REPLACE INTO metric_hourly (channel_id, hour, views, uniques, clicks, avg_time_sec, comments, video_retention) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$c['id'], iso_from_ts($ts), $v, (int)($v * mt_rand(60, 80) / 100), (int)($v * mt_rand(18, 45) / 1000), mt_rand(55, 150), (int)($v * mt_rand(1, 4) / 1000), mt_rand(320, 640) / 10]);
        }
    }

    // ---- S55: uptime 7 hari dari 5 titik + insiden ----
    $t0 = floor($nowTs / 10800) * 10800 - 7 * 86400;
    foreach ($chs as $c) {
        for ($t = $t0; $t <= $nowTs; $t += 10800) {
            $down = ($c['code'] === 'PROV-95' && $t > $nowTs - 18 * 3600) || ($c['code'] === 'PROV-81' && abs($t - ($nowTs - 3 * 86400)) < 5000);
            foreach (MONITOR_POINTS as $pi => $pt) {
                $ok = !$down || $pi === 0;
                $lat = $ok ? (in_array($c['code'], ['PROV-94', 'PROV-53'], true) ? mt_rand(1000, 2400) : mt_rand(90, 380) + $pi * 40) : 0;
                insert('channel_checks', ['channel_id' => $c['id'], 'point' => $pt, 'ok' => $ok ? 1 : 0, 'latency_ms' => $lat, 'at' => iso_from_ts($t)]);
            }
        }
    }
    foreach ([['PROV-95', 18, null, 'Tidak merespons dari 4 titik pantau (contoh)'], ['PROV-81', 74, 70, 'Sertifikat TLS kedaluwarsa (contoh), dipulihkan'], ['PROV-53', 140, 138.5, 'Latensi tinggi > 2 detik (contoh)']] as [$code, $sh, $eh, $cause]) {
        $cid = (int)val('SELECT id FROM channels WHERE code = ?', [$code]);
        if ($cid) insert('channel_incidents', ['channel_id' => $cid, 'started_at' => $at($sh), 'ended_at' => $eh === null ? null : $at($eh), 'cause' => $cause, 'points' => jenc(array_slice(MONITOR_POINTS, 1))]);
    }

    // ---- S52: turunan format ----
    foreach (array_slice(all("SELECT id FROM drafts WHERE status = 'tayang' ORDER BY id"), 0, 4) as $d)
        foreach (array_keys(RENDITION_PROFILES) as $k) q('INSERT OR IGNORE INTO renditions (draft_id, profile, status, size, created_at) VALUES (?, ?, ?, ?, ?)', [$d['id'], $k, 'siap', mt_rand(80, 900) * 1024, $at(20)]);

    // ---- S53: portal media konvensional ----
    $mp = [];
    foreach ([['Harian Nusantara (contoh)', 'cetak', null], ['Radio Suara Kota (contoh)', 'radio', '35'], ['TV Lokal Parahyangan (contoh)', 'tv', '32'], ['Koran Makassar Pagi (contoh)', 'cetak', '73'],
        ['Radio Komunitas Pesisir (contoh)', 'radio', '53'], ['Portal Berita Daerah (contoh)', 'daring', '12']] as [$n, $t, $pv])
        $mp[] = insert('media_partners', ['name' => $n, 'type' => $t, 'province_code' => $pv, 'contact' => 'redaksi@contoh.invalid', 'active' => 1, 'created_at' => $at(24 * 40)]);
    $pub = all("SELECT * FROM drafts WHERE status = 'tayang' ORDER BY id DESC LIMIT 2");
    foreach ($pub as $i => $d) {
        $pk = insert('press_packs', ['draft_id' => $d['id'], 'title' => 'Paket media: ' . $d['title'], 'note' => 'Boleh dikutip utuh dengan menyebut sumber resmi.', 'asset_ids' => jenc(array_slice($assetIds['all'], $i * 2, 2)),
            'expires_at' => iso_from_ts($nowTs + 5 * 86400), 'created_by' => $U['operator'], 'created_at' => $at(30)]);
        foreach (array_slice($mp, $i * 2, 3) as $j => $m) {
            $lid = insert('press_links', ['pack_id' => $pk, 'partner_id' => $m, 'token_hash' => hash('sha256', 'pers_seed_' . $pk . '_' . $m . '_' . bin2hex(random_bytes(8))), 'expires_at' => iso_from_ts($nowTs + 5 * 86400), 'created_at' => $at(30)]);
            if ($j < 2) insert('press_downloads', ['link_id' => $lid, 'pack_id' => $pk, 'partner_id' => $m, 'ip' => '203.0.113.' . (10 + $j), 'at' => $at(28 - $j * 3)]);
        }
    }

    // ---- S54: klien API mitra K/L ----
    foreach ([['Portal Informasi K/L Mitra (contoh)', 'Kementerian mitra (contoh)', 120, 60], ['Aplikasi Pemda Terpadu (contoh)', 'Pemerintah provinsi (contoh)', 60, 15]] as $i => [$n, $o, $rate, $days]) {
        $cid = insert('api_clients', ['name' => $n, 'org' => $o, 'token_prefix' => 'sst_' . substr(bin2hex(random_bytes(3)), 0, 6), 'token_hash' => hash('sha256', 'seed-' . bin2hex(random_bytes(16))),
            'scopes' => jenc(['publikasi:baca']), 'rate_per_min' => $rate, 'expires_at' => iso_from_ts($nowTs + $days * 86400), 'active' => 1, 'created_by' => $U['admin'], 'created_at' => $at(24 * 30), 'last_used_at' => $at(0.3 + $i)]);
        for ($k = 0; $k < 40; $k++) insert('api_calls', ['client_id' => $cid, 'path' => $k % 4 ? '/api/public/v1/publications' : '/api/public/v1/publications/' . mt_rand(1, 30),
            'status' => $k % 17 === 5 ? 429 : 200, 'ip' => '198.51.100.' . (20 + $i), 'at' => $at(mt_rand(1, 230) / 10)]);
    }

    // ---- S34: contoh bentrok jadwal (dua unggahan pada akun yang sama berselisih 20 menit) ----
    if ($ap = one("SELECT * FROM drafts WHERE status IN ('disetujui','tayang') ORDER BY id DESC LIMIT 1")) {
        $slot = floor(($nowTs + 5 * 3600) / 3600) * 3600;
        foreach ([0, 1200] as $off) insert('post_tasks', ['draft_id' => $ap['id'], 'account_id' => $accs[0]['id'], 'planned_at' => iso_from_ts($slot + $off), 'caption' => $ap['title'], 'status' => 'menunggu',
            'assignee_id' => $U['operator'], 'created_by' => $U['redaktur'], 'created_at' => $at(1)]);
    }

    // ---- S35: notifikasi awal ----
    foreach ([[$U['pimpinan'], 'persetujuan', '5 naskah menunggu tinjauan Anda', '/editorial'], [$U['pimpinan'], 'kampanye', 'Rencana kampanye menunggu persetujuan: Stop Judi Online di Kampus (contoh)', '/kanal-resmi?tab=kampanye'],
        [$U['redaktur'], 'paket_respons', 'Paket respons cepat dibuka: Harga Pangan & Inflasi (tenggat 2 jam)', '/kanal-resmi?tab=paket'], [$U['operator'], 'tugas_unggah', 'Tugas unggah baru menunggu (2 melewati jadwal)', '/kanal-resmi?tab=tugas'],
        [$U['operator'], 'distribusi', 'Kanal Papua Pegunungan gangguan: 4 dari 5 titik pantau gagal', '/distribusi'], [$U['produser'], 'penugasan', 'Anda ditugaskan meliput: Peluncuran posko angkutan Nataru', '/aset?tab=liputan']] as [$u, $k, $t, $l])
        insert('notifications', ['user_id' => $u, 'kind' => $k, 'text' => $t, 'link' => $l, 'at' => $at(mt_rand(1, 20) / 4)]);

    // ---- S61: laporan otomatis awal ----
    $today = (float)strtotime(gmdate('Y-m-d') . 'T00:00:00Z');
    build_report(null, 'mingguan', true, $today - ((int)gmdate('N', (int)$today) - 1) * 86400);
    build_report(null, 'harian', true, $today);
}
