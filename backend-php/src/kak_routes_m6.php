<?php
/** Rute KAK Modul 6 — Orkestrasi Kanal Media Sosial Resmi (S40–S45, S48). */
declare(strict_types=1);

function quota_usage(array $a): array
{
    $today = gmdate('Y-m-d'); $mon = gmdate('Y-m-d', strtotime('monday this week'));
    $d = (int)val("SELECT COUNT(*) FROM post_tasks WHERE account_id = ? AND status != 'batal' AND substr(planned_at,1,10) = ?", [$a['id'], $today]);
    $w = (int)val("SELECT COUNT(*) FROM post_tasks WHERE account_id = ? AND status != 'batal' AND substr(planned_at,1,10) >= ? AND substr(planned_at,1,10) < ?",
        [$a['id'], $mon, gmdate('Y-m-d', strtotime($mon . ' +7 days'))]);
    return ['daily_used' => $d, 'daily_quota' => (int)$a['quota_daily'], 'weekly_used' => $w, 'weekly_quota' => (int)$a['quota_weekly']];
}
/** S45: tolak tugas unggah yang melampaui kuota kanal (kecuali redaktur/pimpinan memaksa dengan alasan). */
function check_quota(int $accountId, string $plannedAt, array $u, bool $override): ?string
{
    $a = one('SELECT * FROM social_accounts WHERE id = ?', [$accountId]); if (!$a) return null;
    $day = substr($plannedAt, 0, 10);
    $d = (int)val("SELECT COUNT(*) FROM post_tasks WHERE account_id = ? AND status != 'batal' AND substr(planned_at,1,10) = ?", [$accountId, $day]);
    if ($d < (int)$a['quota_daily']) return null;
    $msg = "Kuota harian {$a['handle']} ({$a['quota_daily']} unggahan) pada {$day} sudah penuh";
    if ($override && has_role($u, 'redaktur', 'pimpinan')) return $msg . ' — dilampaui atas persetujuan ' . $u['full_name'];
    fail(409, $msg . '. Pilih tanggal lain atau minta redaktur/pimpinan melampaui kuota.');
    return null;
}

function register_routes_kak_m6(Router $r): void
{
    // ---------- S40 kendali akun resmi ----------
    $r->add('POST', '/api/m6/accounts/(\d+)/rotate', function ($id) {
        $u = require_roles('admin', 'operator');
        $a = one('SELECT * FROM social_accounts WHERE id = ?', [(int)$id]); if (!$a) fail(404, 'Akun tidak ditemukan');
        q("UPDATE social_accounts SET token_status = 'aman', token_rotated_at = ? WHERE id = ?", [now_iso(), $a['id']]);
        audit_record($u, 'akun_resmi.rotasi_token', 'social_account', $a['id'], ['handle' => $a['handle']]);
        send_json(['ok' => true, 'token_rotated_at' => now_iso(), 'message' => 'Token OAuth diperbarui di brankas (vault). Token tidak pernah ditampilkan.']);
    });
    $r->add('PATCH', '/api/m6/accounts/(\d+)/quota', function ($id) {
        $u = require_roles('redaktur', 'pimpinan', 'admin');
        $a = one('SELECT * FROM social_accounts WHERE id = ?', [(int)$id]); if (!$a) fail(404, 'Akun tidak ditemukan');
        $b = json_body();
        $d = max(1, min(50, (int)($b['quota_daily'] ?? $a['quota_daily']))); $w = max($d, min(300, (int)($b['quota_weekly'] ?? $a['quota_weekly'])));
        q('UPDATE social_accounts SET quota_daily = ?, quota_weekly = ? WHERE id = ?', [$d, $w, $a['id']]);
        audit_record($u, 'akun_resmi.kuota', 'social_account', $a['id'], ['harian' => $d, 'mingguan' => $w]);
        send_json(quota_usage(one('SELECT * FROM social_accounts WHERE id = ?', [$a['id']])));
    });
    $r->add('GET', '/api/m6/quota', function () {
        current_user();
        send_json(array_map(fn($a) => ['account_id' => (int)$a['id'], 'handle' => $a['handle'], 'platform' => PLATFORMS[$a['platform']] ?? $a['platform'],
            'token_rotated_at' => $a['token_rotated_at'], 'token_status' => $a['token_status'], 'mfa' => (bool)$a['mfa']] + quota_usage($a), all('SELECT * FROM social_accounts WHERE active = 1 ORDER BY id')));
    });

    // ---------- S41 paket respons cepat ----------
    $r->add('GET', '/api/m6/packs', function () {
        $u = current_user();
        $draftOk = has_role($u, 'redaktur', 'pimpinan', 'analis', 'produser');
        $rows = $draftOk ? all('SELECT * FROM response_packs ORDER BY id DESC LIMIT 100') : all("SELECT * FROM response_packs WHERE status = 'terbit' ORDER BY id DESC LIMIT 100");
        send_json(['items' => array_map('pack_out', $rows), 'can_see_drafts' => $draftOk]);
    });
    $r->add('GET', '/api/m6/packs/alerts', function () {
        current_user();
        $rows = all("SELECT a.*, i.code, i.name FROM alerts a JOIN issues i ON i.id = a.issue_id WHERE a.level IN ('oranye','merah') AND a.status IN ('open','ack','in_progress')
            AND NOT EXISTS (SELECT 1 FROM response_packs p WHERE p.alert_id = a.id) ORDER BY a.triggered_at DESC");
        send_json(array_map(fn($a) => ['alert_id' => (int)$a['id'], 'issue_code' => $a['code'], 'issue_name' => $a['name'], 'level' => $a['level'], 'reason' => $a['reason'],
            'triggered_at' => $a['triggered_at'], 'deadline_at' => iso_from_ts((float)ts_from_iso($a['triggered_at']) + 7200)], $rows));
    });
    $r->add('POST', '/api/m6/packs', function () {
        $u = require_roles('redaktur', 'pimpinan', 'analis');
        $b = json_body();
        $alert = ($b['alert_id'] ?? null) ? one('SELECT * FROM alerts WHERE id = ?', [(int)$b['alert_id']]) : null;
        $iss = $alert ? one('SELECT * FROM issues WHERE id = ?', [$alert['issue_id']]) : issue_by_code($b['issue_code'] ?? null);
        if (!$iss) fail(422, 'Pilih isu atau peringatan');
        $level = $alert['level'] ?? (in_array($b['level'] ?? '', ['kuning', 'oranye', 'merah'], true) ? $b['level'] : 'oranye');
        $now = now_iso();
        // SLA KAK: paket terbit ≤ 2 jam sejak peringatan Oranye/Merah
        $deadline = iso_from_ts((float)ts_from_iso($alert['triggered_at'] ?? $now) + 7200);
        $id = insert('response_packs', ['issue_id' => $iss['id'], 'alert_id' => $alert['id'] ?? null, 'level' => $level, 'title' => trim((string)($b['title'] ?? '')) ?: "Paket respons: {$iss['name']}",
            'trigger_text' => trim((string)($b['trigger'] ?? ($alert['reason'] ?? ''))), 'status' => 'draf', 'deadline_at' => $deadline, 'created_by' => $u['id'], 'created_at' => $now, 'updated_at' => $now]);
        notify(users_with_roles('redaktur', 'pimpinan'), 'paket_respons', "Paket respons cepat dibuka: {$iss['name']} (tenggat 2 jam)", '/kanal-resmi?tab=paket', (int)$u['id']);
        audit_record($u, 'paket_respons.buat', 'response_pack', $id, ['issue' => $iss['code'], 'alert_id' => $alert['id'] ?? null]);
        send_json(pack_out(one('SELECT * FROM response_packs WHERE id = ?', [$id])), 201);
    });
    $r->add('POST', '/api/m6/packs/(\d+)/generate', function ($id) {
        $u = require_roles('redaktur', 'pimpinan', 'analis');
        $p = one('SELECT * FROM response_packs WHERE id = ?', [(int)$id]); if (!$p) fail(404, 'Paket tidak ditemukan');
        if ($p['status'] === 'terbit') fail(409, 'Paket sudah terbit');
        $iss = one('SELECT * FROM issues WHERE id = ?', [$p['issue_id']]);
        $facts = [];
        foreach (all('SELECT facts FROM briefs WHERE issue_id = ? ORDER BY id DESC LIMIT 5', [$p['issue_id']]) as $b) $facts = array_merge($facts, fact_items($b['facts']));
        $res = ai_response_pack($u, $iss['name'] ?? '-', $p['trigger_text'] ?: 'pemantauan rutin', array_slice($facts, 0, 12), kb_text($p['issue_id'] ? (int)$p['issue_id'] : null));
        $k = $res['pack'];
        q('UPDATE response_packs SET holding_statement = ?, key_messages = ?, qa = ?, data_points = ?, spokesperson = ?, donts = ?, ai_mode = ?, updated_at = ? WHERE id = ?',
            [(string)($k['holding_statement'] ?? ''), jenc($k['key_messages'] ?? []), jenc($k['qa'] ?? []), jenc($k['data_points'] ?? []), (string)($k['spokesperson'] ?? ''), jenc($k['donts'] ?? []),
                $res['mode'], now_iso(), $p['id']]);
        audit_record($u, 'paket_respons.ai', 'response_pack', $p['id'], ['mode' => $res['mode']]);
        send_json(pack_out(one('SELECT * FROM response_packs WHERE id = ?', [$p['id']])));
    });
    $r->add('PATCH', '/api/m6/packs/(\d+)', function ($id) {
        $u = require_roles('redaktur', 'pimpinan', 'analis');
        $p = one('SELECT * FROM response_packs WHERE id = ?', [(int)$id]); if (!$p) fail(404, 'Paket tidak ditemukan');
        if ($p['status'] === 'terbit') fail(409, 'Paket yang sudah terbit tidak dapat diubah; buat paket pembaruan');
        $b = json_body(); $set = [];
        foreach (['title', 'holding_statement', 'spokesperson'] as $k) if (isset($b[$k])) $set[$k] = mb_substr(trim((string)$b[$k]), 0, 4000);
        foreach (['key_messages', 'qa', 'data_points', 'donts'] as $k) if (isset($b[$k]) && is_array($b[$k])) $set[$k] = jenc(array_slice($b[$k], 0, 20));
        $set['updated_at'] = now_iso();
        foreach ($set as $k => $v) q("UPDATE response_packs SET $k = ? WHERE id = ?", [$v, $p['id']]);
        audit_record($u, 'paket_respons.sunting', 'response_pack', $p['id'], array_keys($set));
        send_json(pack_out(one('SELECT * FROM response_packs WHERE id = ?', [$p['id']])));
    });
    $r->add('POST', '/api/m6/packs/(\d+)/publish', function ($id) {
        $u = require_roles('redaktur', 'pimpinan');
        $p = one('SELECT * FROM response_packs WHERE id = ?', [(int)$id]); if (!$p) fail(404, 'Paket tidak ditemukan');
        if ($p['status'] === 'terbit') fail(409, 'Paket sudah terbit');
        if (trim($p['holding_statement']) === '' || count(jdec($p['key_messages'])) === 0) fail(422, 'Lengkapi pernyataan sementara dan pesan kunci sebelum menerbitkan');
        $now = now_iso();
        q("UPDATE response_packs SET status = 'terbit', published_at = ?, published_by = ?, updated_at = ? WHERE id = ?", [$now, $u['id'], $now, $p['id']]);
        // bahan respons terbit menjadi rujukan triase/balasan AI
        foreach (jdec($p['qa']) as $qa) if (!empty($qa['q']) && !empty($qa['a']))
            insert('kb_responses', ['issue_id' => $p['issue_id'], 'title' => mb_substr($p['title'], 0, 200), 'question' => mb_substr((string)$qa['q'], 0, 1000), 'answer' => mb_substr((string)$qa['a'], 0, 3000),
                'sources' => 'Paket respons #' . $p['id'], 'status' => 'disetujui', 'created_by' => $u['id'], 'updated_at' => $now]);
        notify(users_with_roles('operator'), 'paket_respons', "Paket respons terbit: {$p['title']}. Gunakan untuk membalas komentar.", '/kanal-resmi?tab=paket', (int)$u['id']);
        audit_record($u, 'paket_respons.terbit', 'response_pack', $p['id'], ['tepat_waktu' => $now <= $p['deadline_at']]);
        send_json(pack_out(one('SELECT * FROM response_packs WHERE id = ?', [$p['id']])));
    });

    // ---------- S42 kemitraan KOL terbuka ----------
    $r->add('GET', '/api/m6/partners', function () { current_user(); send_json(array_map('partner_out', all('SELECT * FROM partners ORDER BY status, followers DESC'))); });
    $r->add('GET', '/api/m6/partners/(\d+)', function ($id) {
        current_user();
        $p = one('SELECT * FROM partners WHERE id = ?', [(int)$id]); if (!$p) fail(404, 'Mitra tidak ditemukan');
        send_json(partner_out($p) + ['history' => array_map(fn($c) => ['id' => (int)$c['id'], 'campaign' => $c['campaign_id'] ? val('SELECT name FROM campaigns WHERE id = ?', [$c['campaign_id']]) : null,
            'draft_title' => $c['draft_id'] ? val('SELECT title FROM drafts WHERE id = ?', [$c['draft_id']]) : null, 'posted_at' => $c['posted_at'], 'url' => $c['url'],
            'disclosure_ok' => (bool)$c['disclosure_ok'], 'reach' => (int)$c['reach'], 'engagement' => (int)$c['engagement'], 'notes' => $c['notes']],
            all('SELECT * FROM partner_collabs WHERE partner_id = ? ORDER BY posted_at DESC', [$p['id']]))]);
    });
    $r->add('POST', '/api/m6/partners', function () {
        $u = require_roles('redaktur', 'pimpinan');
        $b = json_body();
        $p = (string)($b['platform'] ?? ''); if (!isset(PLATFORMS[$p])) fail(422, 'Platform tidak dikenal');
        $no = trim(str_field($b, 'contract_no', 3, 80));
        $dis = trim(str_field($b, 'disclosure', 5, 120));
        $start = (string)($b['contract_start'] ?? ''); $end = (string)($b['contract_end'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $end < $start) fail(422, 'Periode kontrak tidak valid');
        $id = insert('partners', ['name' => trim(str_field($b, 'name', 2, 120)), 'handle' => trim(str_field($b, 'handle', 2, 80)), 'platform' => $p, 'category' => trim((string)($b['category'] ?? 'umum')),
            'followers' => max(0, (int)($b['followers'] ?? 0)), 'province_code' => isset(PROVINCES[$b['province_code'] ?? '']) ? $b['province_code'] : null, 'contract_no' => $no,
            'contract_start' => $start, 'contract_end' => $end, 'disclosure' => $dis, 'status' => 'aktif', 'notes' => trim((string)($b['notes'] ?? '')), 'created_by' => $u['id'], 'created_at' => now_iso()]);
        audit_record($u, 'mitra.tambah', 'partner', $id, ['kontrak' => $no]);
        send_json(partner_out(one('SELECT * FROM partners WHERE id = ?', [$id])), 201);
    });
    $r->add('POST', '/api/m6/partners/(\d+)/collabs', function ($id) {
        $u = require_roles('redaktur', 'pimpinan', 'operator');
        $p = one('SELECT * FROM partners WHERE id = ?', [(int)$id]); if (!$p) fail(404, 'Mitra tidak ditemukan');
        if ($p['contract_end'] < gmdate('Y-m-d')) fail(409, 'Kontrak mitra sudah berakhir; perpanjang kontrak dulu');
        $b = json_body();
        $url = trim((string)($b['url'] ?? '')); if ($url !== '' && !preg_match('#^https?://#i', $url)) fail(422, 'Tautan harus diawali http(s)://');
        $cid = insert('partner_collabs', ['partner_id' => $p['id'], 'campaign_id' => ($b['campaign_id'] ?? null) ? (int)$b['campaign_id'] : null, 'draft_id' => ($b['draft_id'] ?? null) ? (int)$b['draft_id'] : null,
            'posted_at' => opt_dt($b['posted_at'] ?? null) ?? now_iso(), 'url' => $url, 'disclosure_ok' => !empty($b['disclosure_ok']) ? 1 : 0, 'reach' => max(0, (int)($b['reach'] ?? 0)),
            'engagement' => max(0, (int)($b['engagement'] ?? 0)), 'notes' => trim((string)($b['notes'] ?? '')), 'created_by' => $u['id'], 'created_at' => now_iso()]);
        audit_record($u, 'mitra.kolaborasi', 'partner', $p['id'], ['collab' => $cid, 'label_kemitraan' => !empty($b['disclosure_ok'])]);
        send_json(partner_out(one('SELECT * FROM partners WHERE id = ?', [$p['id']])), 201);
    });

    // ---------- S44/S48 kampanye resmi ----------
    $r->add('GET', '/api/m6/campaigns', function () { current_user(); send_json(array_map(fn($c) => campaign_out($c) + ['monitoring' => campaign_metrics($c)], all('SELECT * FROM campaigns ORDER BY start_date DESC'))); });
    $r->add('GET', '/api/m6/campaigns/(\d+)', function ($id) {
        current_user();
        $c = one('SELECT * FROM campaigns WHERE id = ?', [(int)$id]); if (!$c) fail(404, 'Kampanye tidak ditemukan');
        send_json(campaign_out($c, true));
    });
    $r->add('POST', '/api/m6/campaigns', function () {
        $u = require_roles('redaktur', 'pimpinan', 'analis');
        $b = json_body();
        $iss = issue_by_code($b['issue_code'] ?? null) ?? fail(422, 'Pilih isu');
        $s = (string)($b['start_date'] ?? ''); $e = (string)($b['end_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $e) || $e < $s) fail(422, 'Periode kampanye tidak valid');
        $regions = array_values(array_filter(list_field($b, 'regions') ?? [], fn($x) => $x === 'NAS' || isset(PROVINCES[$x])));
        $kpi = ['reach' => max(0, (int)($b['kpi']['reach'] ?? 0)), 'engagement_rate' => (float)($b['kpi']['engagement_rate'] ?? 0), 'sentiment_neg_max' => (float)($b['kpi']['sentiment_neg_max'] ?? 0)];
        $now = now_iso();
        $id = insert('campaigns', ['name' => trim(str_field($b, 'name', 5, 160)), 'issue_id' => $iss['id'], 'objective' => trim(str_field($b, 'objective', 5, 600)), 'audience' => trim((string)($b['audience'] ?? '')),
            'regions' => jenc($regions ?: ['NAS']), 'key_messages' => trim(str_field($b, 'key_messages', 5, 2000)), 'start_date' => $s, 'end_date' => $e, 'status' => 'draf', 'kpi' => jenc($kpi),
            'created_by' => $u['id'], 'created_at' => $now, 'updated_at' => $now]);
        audit_record($u, 'kampanye.buat', 'campaign', $id);
        send_json(campaign_out(one('SELECT * FROM campaigns WHERE id = ?', [$id]), true), 201);
    });
    $r->add('POST', '/api/m6/campaigns/(\d+)/transition', function ($id) {
        $u = current_user();
        $c = one('SELECT * FROM campaigns WHERE id = ?', [(int)$id]); if (!$c) fail(404, 'Kampanye tidak ditemukan');
        $to = (string)(json_body()['to'] ?? '');
        $rules = ['diajukan' => [['draf'], ['redaktur', 'analis', 'pimpinan']], 'draf' => [['diajukan'], ['pimpinan']], 'disetujui' => [['diajukan'], ['pimpinan']],
            'berjalan' => [['disetujui'], ['redaktur', 'operator', 'pimpinan']], 'selesai' => [['berjalan'], ['redaktur', 'pimpinan']], 'dibatalkan' => [['draf', 'diajukan', 'disetujui', 'berjalan'], ['pimpinan']]];
        if (!isset($rules[$to])) fail(422, 'Status tujuan tidak dikenal');
        [$from, $roles] = $rules[$to];
        if (!in_array($c['status'], $from, true)) fail(409, "Transisi dari '{$c['status']}' ke '{$to}' tidak diizinkan");
        if (!has_role($u, ...$roles)) fail(403, 'Peran Anda tidak berwenang untuk transisi ini');
        if ($to === 'disetujui' && (int)$c['created_by'] === (int)$u['id']) fail(403, 'Pemisahan tugas: penyusun rencana tidak boleh menyetujui sendiri');
        $now = now_iso();
        if ($to === 'disetujui') {
            $sig = sign("sistra-campaign|{$c['id']}|" . hash('sha256', $c['name'] . $c['key_messages'] . $c['start_date'] . $c['end_date'] . $c['regions']) . "|{$u['id']}");
            q('UPDATE campaigns SET approved_by = ?, approved_at = ?, signature = ? WHERE id = ?', [$u['id'], $now, $sig, $c['id']]);
        }
        q('UPDATE campaigns SET status = ?, updated_at = ? WHERE id = ?', [$to, $now, $c['id']]);
        if ($to === 'diajukan') notify(users_with_roles('pimpinan'), 'kampanye', "Rencana kampanye menunggu persetujuan: {$c['name']}", '/kanal-resmi?tab=kampanye', (int)$u['id']);
        if (in_array($to, ['disetujui', 'draf'], true)) notify([(int)$c['created_by']], 'kampanye', "Kampanye \"{$c['name']}\" " . ($to === 'disetujui' ? 'disetujui' : 'dikembalikan'), '/kanal-resmi?tab=kampanye', (int)$u['id']);
        audit_record($u, 'kampanye.transisi', 'campaign', $c['id'], ['from' => $c['status'], 'to' => $to]);
        send_json(campaign_out(one('SELECT * FROM campaigns WHERE id = ?', [$c['id']]), true));
    });
    $r->add('POST', '/api/m6/campaigns/(\d+)/slots', function ($id) {
        $u = require_roles('redaktur', 'pimpinan', 'operator');
        $c = one('SELECT * FROM campaigns WHERE id = ?', [(int)$id]); if (!$c) fail(404, 'Kampanye tidak ditemukan');
        if (!in_array($c['status'], ['disetujui', 'berjalan'], true)) fail(409, 'Matriks jadwal hanya dapat diisi setelah rencana kampanye disetujui');
        $b = json_body();
        $date = (string)($b['date'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $c['start_date'] || $date > $c['end_date']) fail(422, 'Tanggal harus dalam periode kampanye');
        $acc = ($b['account_id'] ?? null) ? (int)$b['account_id'] : null; $par = ($b['partner_id'] ?? null) ? (int)$b['partner_id'] : null;
        if (!$acc && !$par) fail(422, 'Pilih akun resmi atau mitra');
        $draft = ($b['draft_id'] ?? null) ? get_draft_row((int)$b['draft_id']) : null;
        $taskId = null; $note = trim((string)($b['note'] ?? ''));
        if ($acc && $draft) {
            if (!in_array($draft['status'], ['disetujui', 'tayang'], true)) fail(409, 'Naskah harus sudah disetujui');
            $planned = $date . 'T' . (preg_match('/^\d{2}:\d{2}$/', (string)($b['time'] ?? '')) ? $b['time'] : '12:00') . ':00Z';
            $ov = check_quota($acc, $planned, $u, !empty($b['override_quota']));
            if ($ov) $note = trim($note . ' · ' . $ov, ' ·');
            $taskId = insert('post_tasks', ['draft_id' => $draft['id'], 'account_id' => $acc, 'planned_at' => $planned, 'caption' => $draft['title'], 'status' => 'menunggu',
                'assignee_id' => val('SELECT pic_user_id FROM social_accounts WHERE id = ?', [$acc]) ?: null, 'created_by' => $u['id'], 'created_at' => now_iso(), 'campaign_id' => $c['id']]);
        }
        if ($par) { $pp = one('SELECT * FROM partners WHERE id = ?', [$par]); if (!$pp || $pp['contract_end'] < $date) fail(409, 'Mitra tidak aktif pada tanggal tersebut (kontrak)'); }
        $sid = insert('campaign_slots', ['campaign_id' => $c['id'], 'date' => $date, 'account_id' => $acc, 'partner_id' => $par, 'draft_id' => $draft['id'] ?? null, 'post_task_id' => $taskId, 'note' => $note, 'created_at' => now_iso()]);
        audit_record($u, 'kampanye.slot', 'campaign', $c['id'], ['slot' => $sid, 'task' => $taskId]);
        send_json(campaign_out(one('SELECT * FROM campaigns WHERE id = ?', [$c['id']]), true), 201);
    });
    $r->add('POST', '/api/m6/campaigns/(\d+)/evaluate', function ($id) {
        $u = require_roles('redaktur', 'pimpinan', 'analis');
        $c = one('SELECT * FROM campaigns WHERE id = ?', [(int)$id]); if (!$c) fail(404, 'Kampanye tidak ditemukan');
        $m = campaign_metrics($c); $s = $m['sentiment']; $kpi = jdec($c['kpi']);
        $data = "Kampanye: {$c['name']} ({$c['start_date']} s.d. {$c['end_date']})\nTujuan: {$c['objective']}\nTarget jangkauan: " . ($kpi['reach'] ?? 0)
            . "\nJangkauan: {$m['reach']}; impresi: {$m['impressions']}; interaksi: {$m['engagement']}; ER: " . round($m['engagement_rate'] * 100, 2) . "%\nUnggahan: {$m['posts_done']}/{$m['posts_planned']}; unggahan mitra: {$m['partner_posts']}"
            . "\nKomentar negatif sebelum: " . round($s['before']['neg_ratio'] * 100) . "% (n={$s['before']['n']}); selama: " . round($s['after']['neg_ratio'] * 100) . "% (n={$s['after']['n']}); p-value: " . ($s['p_value'] ?? '-');
        $res = ai_narrative($u, 'evaluasi_kampanye', 'menulis laporan evaluasi kampanye komunikasi resmi pemerintah.', $data,
            "Kampanye menjangkau {$m['reach']} akun dengan ER " . round($m['engagement_rate'] * 100, 2) . "%. {$s['interpretation']}");
        q('UPDATE campaigns SET evaluation = ?, evaluation_mode = ?, updated_at = ? WHERE id = ?', [$res['text'], $res['mode'], now_iso(), $c['id']]);
        audit_record($u, 'kampanye.evaluasi', 'campaign', $c['id'], ['mode' => $res['mode']]);
        send_json(campaign_out(one('SELECT * FROM campaigns WHERE id = ?', [$c['id']]), true));
    });
    // ---------- S43 utas komentar ----------
    $r->add('GET', '/api/m6/comments/(\d+)/thread', function ($id) {
        current_user();
        $c = one('SELECT * FROM social_comments WHERE id = ?', [(int)$id]); if (!$c) fail(404, 'Komentar tidak ditemukan');
        $root = $c['parent_id'] ? (one('SELECT * FROM social_comments WHERE id = ?', [$c['parent_id']]) ?? $c) : $c;
        send_json(['root' => comment_out($root), 'replies' => array_map('comment_out', all('SELECT * FROM social_comments WHERE parent_id = ? ORDER BY at', [$root['id']]))]);
    });
}
