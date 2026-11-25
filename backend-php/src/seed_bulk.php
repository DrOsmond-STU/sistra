<?php
/**
 * Data demo massal (sintetis): setiap fitur transaksional diisi hingga ≥ 500 baris.
 * Bertahap & dapat dilanjutkan: tiap langkah = satu entitas dalam satu transaksi; tiap permintaan memakai anggaran waktu
 * sehingga aman di hosting bersama. Penanda .bulk-v1 dipasang setelah semua langkah selesai.
 * Data master (isu, 39 kanal sesuai KAK, templat visual, pengguna) tidak digandakan.
 */
declare(strict_types=1);

const BULK_TARGET = 520;

function migrate_bulk(): void
{
    db()->exec(<<<SQL
CREATE INDEX IF NOT EXISTS ix_drafts_status ON drafts(status, updated_at);
CREATE INDEX IF NOT EXISTS ix_drafts_issue ON drafts(issue_id, created_at);
CREATE INDEX IF NOT EXISTS ix_drafts_brief ON drafts(brief_id);
CREATE INDEX IF NOT EXISTS ix_steps_draft ON approval_steps(draft_id);
CREATE INDEX IF NOT EXISTS ix_rev_draft ON draft_revisions(draft_id);
CREATE INDEX IF NOT EXISTS ix_dcom_draft ON draft_comments(draft_id);
CREATE INDEX IF NOT EXISTS ix_var_brief ON brief_variants(brief_id);
CREATE INDEX IF NOT EXISTS ix_dist_channel ON distributions(channel_id, created_at);
CREATE INDEX IF NOT EXISTS ix_dist_sent ON distributions(status, sent_at);
CREATE INDEX IF NOT EXISTS ix_tasks_acc ON post_tasks(account_id, planned_at);
CREATE INDEX IF NOT EXISTS ix_tasks_draft ON post_tasks(draft_id);
CREATE INDEX IF NOT EXISTS ix_tasks_camp ON post_tasks(campaign_id);
CREATE INDEX IF NOT EXISTS ix_cm_issue ON social_comments(issue_id, at);
CREATE INDEX IF NOT EXISTS ix_cm_acc ON social_comments(account_id, status);
CREATE INDEX IF NOT EXISTS ix_cm_at ON social_comments(at);
CREATE INDEX IF NOT EXISTS ix_cm_parent ON social_comments(parent_id);
CREATE INDEX IF NOT EXISTS ix_slots_camp ON campaign_slots(campaign_id);
CREATE INDEX IF NOT EXISTS ix_collab_partner ON partner_collabs(partner_id);
CREATE INDEX IF NOT EXISTS ix_cal_planned ON calendar_entries(planned_at);
CREATE INDEX IF NOT EXISTS ix_links_pack ON press_links(pack_id);
CREATE INDEX IF NOT EXISTS ix_dl_pack ON press_downloads(pack_id);
CREATE INDEX IF NOT EXISTS ix_feedback_draft ON editorial_feedback(draft_id);
CREATE INDEX IF NOT EXISTS ix_assets_draft ON draft_assets(asset_id);
CREATE INDEX IF NOT EXISTS ix_reports_period ON reports(period, period_end);
SQL);
}

/** Konteks bersama seluruh langkah. */
function bulk_ctx(): array
{
    static $c = null;
    if ($c) return $c;
    $uid = fn(string $un) => (int)val('SELECT id FROM users WHERE username = ?', [$un]);
    $c = ['now' => microtime(true), 'U' => ['redaktur' => $uid('redaktur'), 'produser' => $uid('produser'), 'analis' => $uid('analis'), 'analis2' => $uid('analis2'),
        'pimpinan' => $uid('pimpinan'), 'pimpinan2' => $uid('pimpinan.anl'), 'operator' => $uid('operator'), 'admin' => $uid('admin')],
        'issues' => array_values(array_filter(array_map(fn($r) => ['id' => (int)$r['id'], 'code' => $r['code'], 'name' => $r['name']], all("SELECT id, code, name FROM issues WHERE active = 1 ORDER BY id")),
            fn($x) => isset(M47_FACTS[$x['code']]))),
        'channels' => all("SELECT * FROM channels WHERE type IN ('nasional','provinsi') ORDER BY id"), 'provs' => array_keys(PROVINCES)];
    return $c;
}
function bulk_at(float $hoursAgo): string { return iso_from_ts(bulk_ctx()['now'] - $hoursAgo * 3600); }
function bulk_pick(array $a, int $i) { return $a[$i % count($a)]; }
function bulk_count(string $t, string $w = '1=1'): int { return (int)val("SELECT COUNT(*) FROM $t WHERE $w"); }
function bulk_issue(int $i): array { return bulk_pick(bulk_ctx()['issues'], $i); }
function bulk_titles(string $code): array { return M47_TITLES[$code] ?? ['Informasi resmi terbaru']; }
function bulk_facts(string $code): array { return M47_FACTS[$code] ?? ['Informasi resmi tersedia di kanal pemerintah (data contoh)']; }
const BULK_PREFIX = ['', 'Pembaruan: ', 'Klarifikasi: ', 'Panduan Warga: ', 'Tanya-Jawab: ', 'Fakta Singkat: ', 'Info Layanan: ', 'Imbauan: '];
function bulk_title(int $i, ?string $prov = null): string
{
    $is = bulk_issue($i);
    $t = BULK_PREFIX[intdiv($i, 7) % count(BULK_PREFIX)] . bulk_pick(bulk_titles($is['code']), intdiv($i, 3));
    return $prov ? "$t — " . PROVINCES[$prov][0] : $t;
}

// ---------------------------------------------------------------- langkah
function bulk_steps(): array
{
    return ['local_context', 'briefs', 'drafts', 'draft_comments', 'editorial_feedback', 'media_outputs', 'calendar_entries', 'assets', 'coverage', 'notifications',
        'social_accounts', 'post_tasks', 'social_comments', 'kb_responses', 'response_packs', 'partners', 'partner_collabs', 'campaigns', 'campaign_slots',
        'distributions', 'media_partners', 'press_packs', 'api_clients', 'reports'];
}

function bulk_step(string $s): void
{
    $C = bulk_ctx(); $U = $C['U']; $N = BULK_TARGET;
    mt_srand(crc32($s) & 0x7fffffff);
    $need = fn(string $t, string $w = '1=1') => max(0, $N - bulk_count($t, $w));
    switch ($s) {
        case 'local_context':
            $cats = [['istilah', 'Gunakan sapaan santun dan istilah setempat yang lazim dipakai warga %s (catatan tim daerah, contoh)'],
                ['budaya', 'Hindari penjadwalan unggahan bertepatan dengan kegiatan adat/keagamaan besar di %s; konfirmasi kalender daerah (contoh)'],
                ['layanan', 'Rujuk warga %s ke kanal informasi resmi pemerintah daerah dan SP4N-LAPOR! (contoh)'],
                ['tokoh', 'Rujukan lembaga: dinas komunikasi dan informatika provinsi %s (contoh, nama pejabat tidak dicantumkan)'],
                ['istilah', 'Gunakan contoh lokasi publik umum (pasar, terminal, puskesmas) di %s tanpa menyebut nama pribadi (contoh)'],
                ['budaya', 'Gunakan visual yang menghormati keberagaman masyarakat %s (pedoman redaksi, contoh)'],
                ['layanan', 'Nomor darurat 112 dan posko pemerintah daerah %s dicantumkan pada konten kebencanaan (contoh)'],
                ['istilah', 'Sertakan versi singkat dalam bahasa sehari-hari untuk khalayak muda di %s (contoh)'],
                ['tokoh', 'Kanal pengaduan sektoral (kesehatan, pangan, transportasi) di %s mengikuti daftar resmi dinas terkait (contoh)'],
                ['budaya', 'Perhatikan zona waktu %s saat menjadwalkan unggahan dan siaran (contoh)']];
            $n = $need('local_context'); $i = 0;
            while ($n > 0) {
                foreach ($C['provs'] as $k => $p) {
                    if ($n <= 0) break;
                    [$cat, $tpl] = $cats[$i % count($cats)];
                    $ver = (($i + $k) % 3) !== 0 && !str_starts_with((string)$p, '9');
                    insert('local_context', ['province_code' => (string)$p, 'category' => $cat, 'content' => sprintf($tpl, PROVINCES[$p][0]), 'source' => 'Catatan tim daerah (contoh)',
                        'verified' => $ver ? 1 : 0, 'verified_by' => $ver ? $U['redaktur'] : null, 'verified_at' => $ver ? bulk_at(mt_rand(24, 2000)) : null, 'created_by' => $U['analis'], 'created_at' => bulk_at(mt_rand(100, 3000))]);
                    $n--;
                }
                $i++;
            }
            break;

        case 'briefs':
            for ($i = 0, $n = $need('briefs'); $i < $n; $i++) {
                $is = bulk_issue($i); $pv = $i % 4 === 0 ? (string)bulk_pick($C['provs'], $i) : null;
                $h = mt_rand(2, 180 * 24);
                $facts = array_map(fn($t, $k) => ['text' => $t, 'source' => ['Siaran pers kementerian teknis (contoh)', 'Data BPS/instansi statistik (contoh)', 'Keterangan resmi pemda (contoh)'][$k % 3],
                    'url' => 'https://contoh.invalid/fakta/' . strtolower($is['code']) . '-' . ($k + 1), 'reliability' => ['A', 'A', 'B'][$k % 3]], bulk_facts($is['code']), array_keys(bulk_facts($is['code'])));
                $bid = insert('briefs', ['title' => bulk_title($i, $pv), 'issue_id' => $is['id'], 'format' => bulk_pick(FORMATS, $i), 'objective' => 'Memberi informasi akurat dan langkah yang dapat dilakukan warga',
                    'audience' => bulk_pick(['Warga umum', 'Orang tua murid', 'Pekerja dan pencari kerja', 'Pedagang pasar', 'Pemudik', 'Pengguna media sosial muda'], $i), 'region_code' => $pv,
                    'tone' => bulk_pick(TONES, $i), 'key_messages' => 'Informasi resmi hanya dari kanal pemerintah. ' . bulk_facts($is['code'])[0] . '.', 'facts' => jenc($facts),
                    'status' => bulk_pick(['baru', 'dihasilkan', 'dihasilkan', 'dipakai'], $i), 'ai_mode' => 'simulasi', 'gen_ms' => mt_rand(1800, 13500),
                    'created_by' => bulk_pick([$U['produser'], $U['redaktur'], $U['analis2']], $i), 'created_at' => bulk_at($h), 'updated_at' => bulk_at(max(1, $h - 2))]);
                foreach ([0, 1] as $k) insert('brief_variants', ['brief_id' => $bid, 'angle' => ANGLES[($i + $k) % 5], 'title' => bulk_title($i + $k, $pv),
                    'body' => m47_body($is['code'], bulk_title($i, $pv), 'artikel', $pv ? PROVINCES[$pv][0] : null), 'hashtags' => jenc(['#InfoResmi']), 'ai_mode' => 'simulasi', 'created_at' => bulk_at(max(1, $h - 1))]);
            }
            break;

        case 'drafts':
            $briefs = array_column(all('SELECT id, issue_id FROM briefs ORDER BY id'), 'id', 'issue_id');
            for ($i = 0, $n = $need('drafts'); $i < $n; $i++) {
                $is = bulk_issue($i); $pv = $i % 3 === 0 ? (string)bulk_pick($C['provs'], $i * 7) : null; $fmt = bulk_pick(FORMATS, $i * 5 + 1);
                $roll = $i % 20; $status = $roll < 4 ? 'draf' : ($roll < 7 ? 'ditinjau' : ($roll < 10 ? 'disetujui' : ($roll < 19 ? 'tayang' : 'ditarik')));
                $created = mt_rand(6, 180 * 24); $title = bulk_title($i, $pv);
                $body = m47_body($is['code'], $title, $fmt, $pv ? PROVINCES[$pv][0] : null);
                $hash = draft_hash($title, $body); $author = bulk_pick([$U['produser'], $U['redaktur'], $U['analis2']], $i); $ai = $i % 3 !== 0;
                $did = insert('drafts', ['kind' => $fmt, 'format' => $fmt, 'title' => $title, 'body' => $body, 'province_code' => $pv, 'issue_id' => $is['id'], 'ai_generated' => $ai ? 1 : 0,
                    'status' => $status, 'version' => 1, 'content_hash' => $hash, 'created_by' => $author, 'created_at' => bulk_at($created), 'updated_at' => bulk_at($created),
                    'brief_id' => $briefs[$is['id']] ?? null, 'assignee_id' => bulk_pick([$U['redaktur'], $U['produser'], $U['analis2']], $i + 1),
                    'due_at' => $status === 'draf' || $status === 'ditinjau' ? bulk_at(-mt_rand(4, 200)) : bulk_at($created - 48), 'priority' => bulk_pick(['normal', 'normal', 'tinggi', 'rendah'], $i),
                    'editorial_score' => $ai ? mt_rand(62, 97) : null, 'hashtags' => jenc(in_array($fmt, ['caption', 'carousel', 'video', 'thread'], true) ? ['#InfoResmi'] : [])]);
                insert('draft_revisions', ['draft_id' => $did, 'version' => 1, 'title' => $title, 'body' => $body, 'edited_by' => $author, 'at' => bulk_at($created)]);
                $t = $created; $role = $author === $U['analis2'] ? 'analis' : ($author === $U['redaktur'] ? 'redaktur' : 'produser');
                $step = fn(string $a, string $b, int $who, string $r, float $h, string $c = '', ?string $sig = null) => insert('approval_steps', ['draft_id' => $did, 'from_status' => $a, 'to_status' => $b,
                    'actor_id' => $who, 'actor_role' => $r, 'comment' => $c, 'version' => 1, 'content_hash' => $hash, 'signature' => $sig, 'at' => bulk_at($h)]);
                if ($status !== 'draf') { $t = max(3, $t - mt_rand(1, 20)); $step('draf', 'ditinjau', $author, $role, $t); }
                if (in_array($status, ['disetujui', 'tayang', 'ditarik'], true)) {
                    $t = max(2, $t - mt_rand(1, 30)); $ap = bulk_pick([$U['pimpinan'], $U['pimpinan2']], $i);
                    $sig = sign(signing_message(['id' => $did, 'version' => 1, 'content_hash' => $hash], $ap));
                    $step('ditinjau', 'disetujui', $ap, 'pimpinan', $t, 'Setuju.', $sig);
                    q('UPDATE drafts SET approved_by = ?, approved_at = ?, approval_signature = ? WHERE id = ?', [$ap, bulk_at($t), $sig, $did]);
                }
                if (in_array($status, ['tayang', 'ditarik'], true)) { $t = max(1, $t - mt_rand(1, 12)); $step('disetujui', 'tayang', $U['operator'], 'operator', $t); q('UPDATE drafts SET published_at = ? WHERE id = ?', [bulk_at($t), $did]); }
                if ($status === 'ditarik') { $t = max(0.5, $t - mt_rand(1, 48)); $step('tayang', 'ditarik', $U['pimpinan'], 'pimpinan', $t, 'Ditarik untuk koreksi data (contoh).'); }
                q('UPDATE drafts SET updated_at = ? WHERE id = ?', [bulk_at($t), $did]);
            }
            break;

        case 'draft_comments':
            $ids = array_column(all('SELECT id FROM drafts ORDER BY id DESC LIMIT 600'), 'id');
            $notes = ['Mohon cantumkan sumber data di akhir naskah.', 'Judul bisa dipersingkat agar muat di pratinjau media sosial.', 'Tambahkan kanal pengaduan resmi.', 'Cek ulang angka dengan rilis terbaru.',
                'Sudah sesuai pedoman, siap diajukan.', 'Perlu versi bahasa sederhana untuk warga lansia.', 'Hindari istilah teknis tanpa penjelasan.', 'Tambahkan ajakan cek fakta.'];
            for ($i = 0, $n = $need('draft_comments'); $i < $n; $i++)
                insert('draft_comments', ['draft_id' => (int)bulk_pick($ids, $i * 3), 'user_id' => bulk_pick([$U['pimpinan'], $U['redaktur'], $U['produser'], $U['analis2']], $i), 'body' => bulk_pick($notes, $i), 'at' => bulk_at(mt_rand(1, 2000))]);
            break;

        case 'editorial_feedback':
            $ids = array_column(all('SELECT id FROM drafts WHERE editorial_score IS NOT NULL ORDER BY id DESC LIMIT 600'), 'id');
            $cats = [['ejaan', 'info', 'di karenakan'], ['fakta', 'peringatan', 'naik 40%'], ['nada', 'peringatan', 'segera!!!'], ['kejelasan', 'info', 'Pemerintah menyampaikan…'], ['sara', 'kritis', 'warga pendatang'], ['hukum', 'peringatan', 'pasti dipidana']];
            for ($i = 0, $n = $need('editorial_feedback'); $i < $n; $i++) {
                [$c, $sv, $ex] = bulk_pick($cats, $i); $dec = $i % 4 === 3 ? 'ditolak' : 'diterima';
                insert('editorial_feedback', ['draft_id' => (int)bulk_pick($ids, $i), 'category' => $c, 'severity' => $sv, 'excerpt' => $ex, 'suggestion' => 'Saran agen editorial (contoh)',
                    'decision' => $dec, 'note' => $dec === 'ditolak' ? 'Sudah sesuai gaya redaksi' : '', 'user_id' => $U['redaktur'], 'at' => bulk_at(mt_rand(1, 3000))]);
            }
            break;

        case 'media_outputs':
            $before = (int)val('SELECT COALESCE(MAX(id), 0) FROM ai_logs');
            $drafts = all("SELECT * FROM drafts WHERE status IN ('disetujui','tayang') ORDER BY id DESC LIMIT 400");
            for ($i = 0, $n = $need('media_outputs'); $i < $n; $i++) {
                $d = bulk_pick($drafts, $i); $kind = bulk_pick(['infografis', 'video', 'podcast'], $i);
                $spec = $kind === 'infografis' ? ai_infographic(null, $d, draft_facts($d))['spec'] : ($kind === 'video' ? ai_video(null, $d, $i % 2 ? '16:9' : '9:16', bulk_pick([30, 45, 60], $i))['spec'] : ai_podcast(null, $d, 2)['spec']);
                $h = mt_rand(1, 2500);
                insert('media_outputs', ['draft_id' => $d['id'], 'kind' => $kind, 'title' => $d['title'], 'spec' => jenc($spec), 'template_code' => $kind === 'podcast' ? null : ($kind === 'video' ? 'VID-STD' : bulk_pick(['INF-STD', 'INF-DARURAT', 'INF-CERAH'], $i)),
                    'ai_mode' => 'simulasi', 'ms' => mt_rand(400, 9000), 'created_by' => $U['produser'], 'created_at' => bulk_at($h), 'updated_at' => bulk_at($h)]);
            }
            q('DELETE FROM ai_logs WHERE id > ?', [$before]); // penanaman data bukan pemakaian AI sungguhan
            break;

        case 'calendar_entries':
            $drafts = array_column(all("SELECT id FROM drafts WHERE status IN ('disetujui','tayang') ORDER BY id DESC LIMIT 500"), 'id');
            for ($i = 0, $n = $need('calendar_entries'); $i < $n; $i++) {
                $future = $i % 5 === 0; $h = $future ? -mt_rand(2, 30 * 24) : mt_rand(1, 120 * 24);
                insert('calendar_entries', ['draft_id' => (int)bulk_pick($drafts, $i), 'channel_id' => (int)bulk_pick($C['channels'], $i * 7)['id'], 'planned_at' => bulk_at($h),
                    'status' => $future ? 'rencana' : ($i % 17 === 0 ? 'batal' : 'tayang'), 'created_by' => $U['redaktur'], 'created_at' => bulk_at(max($h, 0) + 24)]);
            }
            break;

        case 'assets':
            $lic = ['milik_sendiri', 'milik_sendiri', 'cc_by', 'berlisensi', 'editorial', 'mitra'];
            $kinds = ['Foto', 'Ilustrasi', 'Grafik', 'Ikon', 'Latar video', 'Peta'];
            for ($i = 0, $n = $need('assets'); $i < $n; $i++) {
                $is = bulk_issue($i); $l = bulk_pick($lic, $i); $k = bulk_pick($kinds, intdiv($i, 6));
                $title = "$k " . mb_strtolower(bulk_pick(bulk_titles($is['code']), $i)) . ' (contoh)';
                $svg = seed_svg($title, 'Isu ' . $is['code'], bulk_pick(['#10243F', '#1C3D5A', '#3E4A1C', '#5A1C1C', '#2F5D50', '#2A2F5A', '#4A1C5A'], $i), '#F2B705');
                $exp = in_array($l, ['berlisensi', 'editorial', 'mitra'], true) ? iso_from_ts($C['now'] + mt_rand(-60, 400) * 86400) : null;
                insert('assets', ['kind' => 'gambar', 'title' => mb_substr($title, 0, 200), 'filename' => "aset-$i.svg", 'mime' => 'image/svg+xml', 'size' => strlen($svg), 'sha256' => blob_put($svg),
                    'tags' => jenc([mb_strtolower($is['code']), mb_strtolower($k), 'contoh']), 'ai_tags' => jenc(array_slice(array_values(array_filter(explode(' ', mb_strtolower(bulk_pick(bulk_titles($is['code']), $i))), fn($w) => mb_strlen($w) > 4)), 0, 4)),
                    'description' => 'Aset contoh sintetis untuk demonstrasi bank aset.', 'credit' => $l === 'mitra' ? 'Mitra data (contoh)' : ($l === 'berlisensi' ? 'Agensi foto (contoh)' : 'Tim produksi'),
                    'license' => $l, 'license_expires_at' => $exp, 'issue_id' => $is['id'], 'province_code' => $i % 4 ? null : (string)bulk_pick($C['provs'], $i), 'created_by' => $U['produser'], 'created_at' => bulk_at(mt_rand(1, 4000))]);
            }
            break;

        case 'coverage':
            $acts = ['Peninjauan operasi pasar', 'Simulasi evakuasi bencana', 'Pemantauan dapur makan bergizi', 'Sosialisasi bahaya judi online', 'Peluncuran posko angkutan', 'Bursa kerja daring',
                'Gerakan 3M Plus', 'Edukasi pelindungan data pribadi', 'Penyaluran bantuan sosial', 'Rapat koordinasi lintas instansi'];
            $places = ['Pasar induk', 'Balai kota', 'Sekolah dasar negeri', 'Terminal antarkota', 'Puskesmas', 'Aula kampus', 'Pelabuhan', 'Kantor kecamatan'];
            for ($i = 0, $n = $need('coverage'); $i < $n; $i++) {
                $h = mt_rand(-30 * 24, 150 * 24); $pv = (string)bulk_pick($C['provs'], $i * 3);
                $st = $h < 0 ? 'direncanakan' : ($h < 6 ? 'berlangsung' : ($i % 13 === 0 ? 'batal' : 'selesai'));
                insert('coverage', ['title' => bulk_pick($acts, $i) . ' di ' . PROVINCES[$pv][0] . ' (contoh)', 'event_at' => bulk_at($h), 'location' => bulk_pick($places, $i) . ' (contoh)', 'province_code' => $pv,
                    'reporter_id' => bulk_pick([$U['produser'], $U['analis2'], $U['redaktur']], $i), 'status' => $st, 'brief' => 'Ambil foto suasana tanpa wajah anak; wawancara petugas resmi.',
                    'created_by' => $U['redaktur'], 'created_at' => bulk_at(max($h, 0) + 72), 'updated_at' => bulk_at(max($h, 0))]);
            }
            break;

        case 'notifications':
            $kinds = [['persetujuan', 'Naskah menunggu tinjauan Anda', '/editorial'], ['penugasan', 'Anda ditugaskan pada naskah baru', '/editorial'], ['diskusi', 'Ada catatan baru pada naskah Anda', '/editorial'],
                ['tugas_unggah', 'Tugas unggah baru menunggu', '/kanal-resmi?tab=tugas'], ['paket_respons', 'Paket respons cepat terbit', '/kanal-resmi?tab=paket'], ['distribusi', 'Naskah siap disalurkan', '/distribusi'],
                ['kampanye', 'Rencana kampanye diperbarui', '/kanal-resmi?tab=kampanye'], ['liputan', 'Jadwal liputan diperbarui', '/aset?tab=liputan']];
            $users = array_values($U);
            for ($i = 0, $n = $need('notifications'); $i < $n; $i++) {
                [$k, $t, $l] = bulk_pick($kinds, $i); $h = mt_rand(1, 60 * 24);
                insert('notifications', ['user_id' => bulk_pick($users, $i), 'kind' => $k, 'text' => "$t (contoh #" . ($i + 1) . ')', 'link' => $l, 'read_at' => $i % 5 ? bulk_at($h - 1) : null, 'at' => bulk_at($h)]);
            }
            break;

        case 'social_accounts':
            $plat = array_keys(PLATFORMS);
            for ($i = 0, $n = $need('social_accounts'); $i < $n; $i++) {
                $pv = (string)bulk_pick($C['provs'], $i); $p = bulk_pick($plat, intdiv($i, 38)); $kab = $i >= 228;
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '', PROVINCES[$pv][0]));
                insert('social_accounts', ['platform' => $p, 'handle' => '@' . ($kab ? "kabkota{$i}." : 'info') . "$slug.demo", 'name' => ($kab ? "Kanal Kab/Kota #$i di " : 'Kanal Informasi ') . PROVINCES[$pv][0] . ' (contoh)',
                    'scope' => $kab ? 'kabkota' : 'provinsi', 'province_code' => $pv, 'pic_user_id' => $U['operator'], 'followers' => $kab ? mt_rand(2, 60) * 1000 : mt_rand(20, 400) * 1000,
                    'token_status' => $i % 29 === 0 ? 'kedaluwarsa' : 'aman', 'mfa' => $i % 41 === 0 ? 0 : 1, 'active' => $i % 23 === 0 ? 0 : 1, 'url' => "https://contoh.invalid/akun/$slug$i",
                    'created_at' => bulk_at(mt_rand(500, 5000)), 'quota_daily' => $kab ? 2 : 4, 'quota_weekly' => $kab ? 10 : 20, 'token_rotated_at' => bulk_at(mt_rand(24, 2000))]);
            }
            break;

        case 'post_tasks':
            $drafts = all("SELECT id, title FROM drafts WHERE status IN ('disetujui','tayang') ORDER BY id DESC LIMIT 400");
            $accs = array_column(all('SELECT id FROM social_accounts WHERE active = 1 ORDER BY id'), 'id');
            for ($i = 0, $n = $need('post_tasks'); $i < $n; $i++) {
                $d = bulk_pick($drafts, $i); $h = mt_rand(-5 * 24, 150 * 24); $done = $h > 3 && $i % 9 !== 0;
                $st = $done ? 'diunggah' : ($h > 3 ? 'batal' : 'menunggu'); $r = $done ? mt_rand(3, 260) * 1000 : 0;
                insert('post_tasks', ['draft_id' => $d['id'], 'account_id' => (int)bulk_pick($accs, $i * 11), 'planned_at' => bulk_at($h), 'caption' => $d['title'], 'status' => $st, 'assignee_id' => $U['operator'],
                    'posted_url' => $done ? "https://contoh.invalid/post/b$i" : null, 'posted_at' => $done ? bulk_at($h - 0.3) : null, 'posted_by' => $done ? $U['operator'] : null,
                    'reach' => $r, 'engagement' => (int)($r * mt_rand(20, 90) / 1000), 'impressions' => (int)($r * 1.6), 'created_by' => $U['redaktur'], 'created_at' => bulk_at(max($h, 0) + 12)]);
            }
            break;

        case 'social_comments':
            // teks dibuat beragam (templat × konteks) agar tidak memicu deteksi bot palsu
            $tpl = ['pertanyaan' => ['Min, info %s di %s kapan ya?', 'Mau tanya soal %s, untuk warga %s daftarnya di mana?', 'Apakah %s berlaku juga di %s?'],
                'keluhan' => ['Sudah seminggu %s di %s belum jelas, mohon ditindaklanjuti.', 'Di %s antre lama sekali soal %s.', 'Petugas %s di %s sulit dihubungi.'],
                'apresiasi' => ['Terima kasih infonya soal %s, warga %s terbantu.', 'Mantap, %s di %s berjalan lancar.', 'Penjelasan %s jelas, salam dari %s.'],
                'hoaks' => ['Katanya %s dihentikan, benar tidak? Ramai di grup %s.', 'Ada pesan berantai soal %s di %s, mohon klarifikasi.'],
                'spam' => ['Promo spesial %s, cek profil kami! (%s)', 'Info peluang usaha %s klik bio (%s)']];
            $sent = ['pertanyaan' => 'netral', 'keluhan' => 'negatif', 'apresiasi' => 'positif', 'hoaks' => 'negatif', 'spam' => 'netral'];
            $accs = array_column(all('SELECT id FROM social_accounts WHERE active = 1 ORDER BY id'), 'id');
            $cats = array_keys($tpl);
            for ($i = 0, $n = $need('social_comments', 'parent_id IS NULL') + 300; $i < $n; $i++) {
                $is = bulk_issue($i); $cat = bulk_pick($cats, $i * 3 + intdiv($i, 5)); $pv = PROVINCES[bulk_pick($C['provs'], $i * 13)][0];
                $text = sprintf(bulk_pick($tpl[$cat], $i), mb_strtolower(explode(' ', $is['name'])[0] . ' ' . (explode(' ', $is['name'])[1] ?? '')), $pv) . ' #' . ($i + 1);
                $h = mt_rand(1, 90 * 24) + $i / 100; $st = $h < 24 ? 'baru' : bulk_pick(['dibalas', 'dibalas', 'ditriase', 'diabaikan', 'dieskalasi'], $i);
                $tri = $st !== 'baru';
                insert('social_comments', ['account_id' => (int)bulk_pick($accs, $i * 7), 'issue_id' => $is['id'], 'author' => 'warga_' . (20000 + $i), 'text' => $text, 'at' => bulk_at($h),
                    'category' => $tri ? $cat : null, 'sentiment' => $tri ? $sent[$cat] : null, 'priority' => $tri ? ($cat === 'hoaks' ? 'tinggi' : ($cat === 'keluhan' ? 'sedang' : 'rendah')) : null,
                    'reason' => $tri ? 'Triase contoh' : null, 'status' => $st, 'reply_text' => $st === 'dibalas' ? 'Terima kasih. Informasi resmi tersedia di kanal pemerintah; silakan sampaikan detail melalui kanal pengaduan.' : null,
                    'replied_by' => $st === 'dibalas' ? $U['operator'] : null, 'replied_at' => $st === 'dibalas' ? bulk_at(max(0.2, $h - mt_rand(1, 10) / 2)) : null,
                    'ai_mode' => $tri ? 'simulasi' : null, 'triaged_at' => $tri ? bulk_at(max(0.1, $h - 0.2)) : null]);
            }
            break;

        case 'kb_responses':
            $qs = [['Apa yang perlu dilakukan warga terkait %s?', 'Ikuti informasi resmi pemerintah terkait %s dan hindari menyebarkan pesan berantai tanpa sumber.'],
                ['Di mana informasi resmi tentang %s?', 'Informasi resmi %s tersedia di kanal pemerintah pusat dan daerah serta akun resmi yang terverifikasi.'],
                ['Bagaimana melaporkan masalah terkait %s?', 'Sampaikan laporan terkait %s melalui SP4N-LAPOR! atau kanal pengaduan resmi instansi terkait, sertakan lokasi dan waktu kejadian.'],
                ['Apakah kabar terbaru soal %s benar?', 'Periksa kebenaran kabar soal %s di kanal resmi; informasi tanpa sumber jelas sebaiknya tidak disebarkan.']];
            for ($i = 0, $n = $need('kb_responses'); $i < $n; $i++) {
                $is = bulk_issue($i); [$qq, $a] = bulk_pick($qs, intdiv($i, 9)); $topic = mb_strtolower($is['name']);
                insert('kb_responses', ['issue_id' => $is['id'], 'title' => $is['name'] . ' · butir ' . ($i + 1), 'question' => sprintf($qq, $topic), 'answer' => sprintf($a, $topic) . ' (contoh)',
                    'sources' => 'Kanal resmi (contoh)', 'status' => $i % 6 === 0 ? 'draf' : 'disetujui', 'created_by' => $U['redaktur'], 'updated_at' => bulk_at(mt_rand(1, 4000))]);
            }
            break;

        case 'response_packs':
            for ($i = 0, $n = $need('response_packs'); $i < $n; $i++) {
                $is = bulk_issue($i); $h = mt_rand(3, 180 * 24); $mins = $i % 7 === 0 ? mt_rand(130, 400) : mt_rand(25, 115); $pub = $i % 11 !== 0;
                $f = bulk_facts($is['code']);
                insert('response_packs', ['issue_id' => $is['id'], 'level' => bulk_pick(['oranye', 'oranye', 'merah', 'kuning'], $i), 'title' => "Paket respons: {$is['name']} #" . ($i + 1),
                    'trigger_text' => 'Lonjakan percakapan negatif (contoh)', 'holding_statement' => "Pemerintah memantau perkembangan isu {$is['name']} dan akan menyampaikan informasi resmi secara berkala. (contoh)",
                    'key_messages' => jenc(array_slice($f, 0, 3)), 'qa' => jenc([['q' => "Apa yang terjadi terkait {$is['name']}?", 'a' => $f[0]], ['q' => 'Ke mana warga melapor?', 'a' => 'Melalui kanal pengaduan resmi.']]),
                    'data_points' => jenc(array_map(fn($x) => ['claim' => $x, 'source' => 'Siaran pers (contoh)'], array_slice($f, 0, 2))), 'spokesperson' => 'Tenang, empatik, fokus pada fakta.',
                    'donts' => jenc(['Jangan menyebut angka yang belum diverifikasi']), 'status' => $pub ? 'terbit' : 'draf', 'ai_mode' => 'simulasi', 'deadline_at' => bulk_at($h - 2),
                    'published_at' => $pub ? bulk_at($h - $mins / 60) : null, 'published_by' => $pub ? $U['pimpinan'] : null, 'created_by' => $U['analis'], 'created_at' => bulk_at($h), 'updated_at' => bulk_at($h)]);
            }
            break;

        case 'partners':
            $fields = ['kesehatan', 'pendidikan', 'literasi digital', 'kebencanaan', 'ekonomi keluarga', 'kuliner', 'olahraga', 'teknologi', 'pertanian', 'cek fakta'];
            for ($i = 0, $n = $need('partners'); $i < $n; $i++) {
                $f = bulk_pick($fields, $i); $pv = $i % 3 ? (string)bulk_pick($C['provs'], $i) : null; $end = mt_rand(-60, 300);
                insert('partners', ['name' => 'Kreator ' . ucfirst($f) . ' ' . ($i + 1) . ' (contoh)', 'handle' => '@kreator' . ($i + 1) . '.demo', 'platform' => bulk_pick(array_keys(PLATFORMS), $i),
                    'category' => $f, 'followers' => mt_rand(5, 1500) * 1000, 'province_code' => $pv, 'contract_no' => sprintf('PKS/%04d/KOM/2026 (contoh)', $i + 100),
                    'contract_start' => gmdate('Y-m-d', (int)($C['now'] - mt_rand(90, 400) * 86400)), 'contract_end' => gmdate('Y-m-d', (int)($C['now'] + $end * 86400)),
                    'disclosure' => $i % 4 ? 'Kemitraan berbayar dengan pemerintah' : 'Kolaborasi tanpa bayaran (disebutkan)', 'status' => $i % 17 === 0 ? 'nonaktif' : 'aktif',
                    'notes' => 'Mitra contoh; setiap unggahan wajib berlabel kemitraan.', 'created_by' => $U['redaktur'], 'created_at' => bulk_at(mt_rand(2000, 9000))]);
            }
            break;

        case 'campaigns':
            for ($i = 0, $n = $need('campaigns'); $i < $n; $i++) {
                $is = bulk_issue($i); $startDays = mt_rand(-30, 360); $len = mt_rand(7, 30);
                $sd = gmdate('Y-m-d', (int)($C['now'] - $startDays * 86400)); $ed = gmdate('Y-m-d', (int)($C['now'] - ($startDays - $len) * 86400));
                $st = $startDays < 0 ? bulk_pick(['draf', 'diajukan', 'disetujui'], $i) : ($startDays - $len > 0 ? ($i % 19 === 0 ? 'dibatalkan' : 'selesai') : 'berjalan');
                $name = bulk_pick(['Lawan Hoaks', 'Siaga', 'Gerakan Sadar', 'Pekan Edukasi', 'Bijak Digital', 'Aman Bersama'], $i) . ' ' . $is['name'] . ' #' . ($i + 1) . ' (contoh)';
                $id = insert('campaigns', ['name' => $name, 'issue_id' => $is['id'], 'objective' => 'Meningkatkan pemahaman warga dan menurunkan sebaran informasi keliru.', 'audience' => 'Warga usia 18–45',
                    'regions' => jenc($i % 3 ? ['NAS'] : [(string)bulk_pick($C['provs'], $i)]), 'key_messages' => 'Informasi resmi hanya dari kanal pemerintah. ' . bulk_facts($is['code'])[0] . '.',
                    'start_date' => $sd, 'end_date' => $ed, 'status' => $st, 'kpi' => jenc(['reach' => mt_rand(2, 30) * 100000]), 'created_by' => $U['redaktur'], 'created_at' => bulk_at(($startDays + 10) * 24), 'updated_at' => bulk_at(max(0, $startDays) * 24)]);
                if (in_array($st, ['disetujui', 'berjalan', 'selesai'], true)) {
                    $c = one('SELECT * FROM campaigns WHERE id = ?', [$id]);
                    $sig = sign("sistra-campaign|{$id}|" . hash('sha256', $c['name'] . $c['key_messages'] . $c['start_date'] . $c['end_date'] . $c['regions']) . "|{$U['pimpinan']}");
                    q('UPDATE campaigns SET approved_by = ?, approved_at = ?, signature = ? WHERE id = ?', [$U['pimpinan'], bulk_at(($startDays + 3) * 24), $sig, $id]);
                }
            }
            break;

        case 'campaign_slots':
            $camps = all("SELECT * FROM campaigns WHERE status IN ('disetujui','berjalan','selesai') ORDER BY id DESC LIMIT 400");
            $accs = array_column(all('SELECT id FROM social_accounts WHERE active = 1 ORDER BY id LIMIT 200'), 'id');
            $drafts = array_column(all("SELECT id FROM drafts WHERE status IN ('disetujui','tayang') ORDER BY id DESC LIMIT 300"), 'id');
            for ($i = 0, $n = $need('campaign_slots'); $i < $n; $i++) {
                $c = bulk_pick($camps, $i); $span = max(0, (int)((strtotime($c['end_date']) - strtotime($c['start_date'])) / 86400));
                insert('campaign_slots', ['campaign_id' => $c['id'], 'date' => gmdate('Y-m-d', strtotime($c['start_date']) + ($i % ($span + 1)) * 86400), 'account_id' => (int)bulk_pick($accs, $i),
                    'draft_id' => (int)bulk_pick($drafts, $i), 'note' => '', 'created_at' => bulk_at(mt_rand(1, 3000))]);
            }
            break;

        case 'partner_collabs':
            $parts = all("SELECT * FROM partners WHERE status = 'aktif' ORDER BY id");
            $camps = array_column(all("SELECT id FROM campaigns WHERE status IN ('berjalan','selesai') ORDER BY id DESC LIMIT 300"), 'id');
            for ($i = 0, $n = $need('partner_collabs'); $i < $n; $i++) {
                $p = bulk_pick($parts, $i * 3); $endTs = strtotime($p['contract_end']); $startTs = strtotime($p['contract_start']);
                $ts = mt_rand($startTs, min((int)$C['now'], $endTs)); $r = mt_rand(10, 600) * 1000;
                insert('partner_collabs', ['partner_id' => $p['id'], 'campaign_id' => $camps ? (int)bulk_pick($camps, $i) : null, 'posted_at' => iso_from_ts($ts), 'url' => "https://contoh.invalid/mitra/b$i",
                    'disclosure_ok' => $i % 13 === 0 ? 0 : 1, 'reach' => $r, 'engagement' => (int)($r * mt_rand(25, 95) / 1000), 'notes' => '', 'created_by' => $U['operator'], 'created_at' => iso_from_ts($ts)]);
            }
            break;

        case 'distributions':
            $drafts = all("SELECT id, published_at, province_code FROM drafts WHERE status IN ('tayang','ditarik') AND id NOT IN (SELECT DISTINCT draft_id FROM distributions) ORDER BY id DESC LIMIT 200");
            $made = 0;
            foreach ($drafts as $k => $d) {
                if ($made >= $N + 200) break;
                $sentTs = (float)ts_from_iso($d['published_at'] ?? bulk_at(100));
                // Naskah bermuatan lokal hanya tayang di kanal nasional + kanal provinsinya sendiri.
                $pick = !empty($d['province_code'])
                    ? array_values(array_filter($C['channels'], fn($c) => $c['type'] === 'nasional' || $c['province_code'] === $d['province_code']))
                    : ($k % 3 === 0 ? $C['channels'] : array_slice($C['channels'], $k % 30, 6 + $k % 9));
                foreach ($pick as $c) {
                    $fail = $c['health'] === 'gangguan' && $k % 2 === 0; $views = $fail ? 0 : (int)(($c['type'] === 'nasional' ? 8 : 1) * mt_rand(400, 7000));
                    insert('distributions', ['draft_id' => $d['id'], 'channel_id' => $c['id'], 'status' => $fail ? 'gagal' : 'terkirim', 'sent_at' => $fail ? null : iso_from_ts($sentTs + mt_rand(5, 600)),
                        'error' => $fail ? 'Kanal tidak merespons (gangguan)' : null, 'url' => $fail ? null : 'https://contoh.invalid/' . strtolower($c['code']) . "/naskah-{$d['id']}", 'views' => $views,
                        'clicks' => (int)($views * mt_rand(15, 60) / 1000), 'shares' => (int)($views * mt_rand(2, 10) / 1000), 'created_by' => $U['operator'], 'created_at' => iso_from_ts($sentTs),
                        'sync_ms' => $fail ? null : mt_rand(600, 4600), 'uniques' => (int)($views * 0.7), 'avg_time_sec' => mt_rand(40, 170), 'comments' => (int)($views * 0.002)]);
                    $made++;
                }
            }
            break;

        case 'media_partners':
            $types = ['cetak' => 'Harian', 'tv' => 'TV Lokal', 'radio' => 'Radio', 'daring' => 'Portal Berita'];
            for ($i = 0, $n = $need('media_partners'); $i < $n; $i++) {
                $t = bulk_pick(array_keys($types), $i); $pv = (string)bulk_pick($C['provs'], intdiv($i, 4));
                insert('media_partners', ['name' => $types[$t] . ' ' . PROVINCES[$pv][0] . ' ' . ($i + 1) . ' (contoh)', 'type' => $t, 'province_code' => $pv, 'contact' => "redaksi$i@contoh.invalid",
                    'active' => $i % 15 === 0 ? 0 : 1, 'created_at' => bulk_at(mt_rand(500, 9000))]);
            }
            break;

        case 'press_packs':
            $drafts = all("SELECT id, title FROM drafts WHERE status = 'tayang' ORDER BY id DESC LIMIT 300");
            $mps = array_column(all('SELECT id FROM media_partners WHERE active = 1 ORDER BY id'), 'id');
            for ($i = 0, $n = $need('press_packs'); $i < $n; $i++) {
                $d = bulk_pick($drafts, $i); $h = mt_rand(1, 150 * 24);
                $pk = insert('press_packs', ['draft_id' => $d['id'], 'title' => 'Paket media: ' . $d['title'], 'note' => 'Boleh dikutip utuh dengan menyebut sumber resmi.', 'asset_ids' => '[]',
                    'expires_at' => bulk_at($h - 7 * 24), 'created_by' => $U['operator'], 'created_at' => bulk_at($h)]);
                $lid = insert('press_links', ['pack_id' => $pk, 'partner_id' => (int)bulk_pick($mps, $i), 'token_hash' => hash('sha256', 'bulk-' . $pk . '-' . bin2hex(random_bytes(6))), 'expires_at' => bulk_at($h - 7 * 24), 'created_at' => bulk_at($h)]);
                if ($i % 3) insert('press_downloads', ['link_id' => $lid, 'pack_id' => $pk, 'partner_id' => (int)bulk_pick($mps, $i), 'ip' => '203.0.113.' . (1 + $i % 250), 'at' => bulk_at(max(0.5, $h - 3))]);
            }
            break;

        case 'api_clients':
            for ($i = 0, $n = $need('api_clients'); $i < $n; $i++) {
                $days = mt_rand(-200, 300);
                insert('api_clients', ['name' => 'Aplikasi mitra ' . ($i + 1) . ' (contoh)', 'org' => bulk_pick(['Kementerian mitra', 'Pemerintah provinsi', 'Pemerintah kab/kota', 'Lembaga nonkementerian'], $i) . ' (contoh)',
                    'token_prefix' => 'sst_' . substr(bin2hex(random_bytes(3)), 0, 6), 'token_hash' => hash('sha256', 'bulk-api-' . bin2hex(random_bytes(16))), 'scopes' => jenc(['publikasi:baca']),
                    'rate_per_min' => bulk_pick([30, 60, 120], $i), 'expires_at' => iso_from_ts($C['now'] + $days * 86400), 'active' => $i % 9 === 0 ? 0 : 1, 'created_by' => $U['admin'],
                    'created_at' => bulk_at(mt_rand(400, 9000)), 'last_used_at' => $days > 0 && $i % 3 ? bulk_at(mt_rand(1, 500)) : null]);
            }
            break;

        case 'reports':
            // arsip laporan historis ringan (angka sintetis) — laporan terbaru tetap disusun dari data sungguhan
            for ($i = 0, $n = $need('reports'); $i < $n; $i++) {
                $per = $i < 400 ? 'harian' : ($i < 480 ? 'mingguan' : 'bulanan'); $days = ['harian' => 1, 'mingguan' => 7, 'bulanan' => 30][$per];
                $k = $per === 'harian' ? $i + 2 : ($per === 'mingguan' ? ($i - 399) * 7 + 7 : ($i - 479) * 30 + 30);
                $endTs = (float)strtotime(gmdate('Y-m-d', (int)($C['now'] - $k * 86400)) . 'T00:00:00Z');
                $prod = mt_rand(2, 9) * $days; $pub = (int)($prod * 0.6);
                $content = ['kpi' => [['label' => 'Naskah disusun', 'value' => $prod], ['label' => 'Naskah tayang', 'value' => $pub], ['label' => 'Pengiriman kanal web', 'value' => $pub * 14],
                    ['label' => 'Tayangan kanal web', 'value' => mt_rand(40, 400) * 1000 * $days], ['label' => 'Komentar dibalas', 'value' => mt_rand(10, 60) * $days], ['label' => 'Kanal sehat', 'value' => mt_rand(36, 39) . '/39']],
                    'issues' => array_map(fn($x) => [$x['name'], mt_rand(1, 6), mt_rand(0, 4), mt_rand(5, 80), mt_rand(10, 70) . '%'], array_slice($C['issues'], 0, 6)),
                    'iekp' => array_map(fn($x) => ['issue' => $x['name'], 'score' => mt_rand(45, 92)], array_slice($C['issues'], 0, 5)), 'channels_top' => [], 'alerts' => [], 'decisions' => []];
                $label = ['harian' => 'Harian', 'mingguan' => 'Mingguan', 'bulanan' => 'Bulanan'][$per];
                insert('reports', ['period' => $per, 'period_start' => iso_from_ts($endTs - $days * 86400), 'period_end' => iso_from_ts($endTs),
                    'title' => "Laporan $label Komunikasi Publik · " . gmdate('Y-m-d', (int)($endTs - $days * 86400)) . ' (arsip contoh)',
                    'content' => jenc($content), 'summary' => "[Arsip contoh] Pada periode ini disusun $prod naskah dan $pub tayang. Angka bersifat sintetis untuk demonstrasi.", 'ai_mode' => 'simulasi',
                    'auto' => 1, 'created_by' => null, 'created_at' => iso_from_ts($endTs + 3600)]);
            }
            break;
    }
}

/** Jalankan langkah yang belum selesai dalam batas waktu; kembalikan true bila semua selesai. */
function seed_bulk(float $budget = 8.0): bool
{
    migrate_bulk();
    $mark = data_dir() . '/.bulk-progress';
    $done = is_file($mark) ? array_filter(explode("\n", (string)file_get_contents($mark))) : [];
    $t0 = microtime(true);
    foreach (bulk_steps() as $s) {
        if (in_array($s, $done, true)) continue;
        if (microtime(true) - $t0 > $budget) return false;
        tx(fn() => bulk_step($s));
        $done[] = $s;
        file_put_contents($mark, implode("\n", $done));
    }
    detect_bot_swarm();
    return true;
}
