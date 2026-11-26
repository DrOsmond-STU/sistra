<?php
/** Rute katalog KAK Modul 4–8 (S26–S65). */
declare(strict_types=1);

// ======================= keluaran =======================
function lc_out(array $x): array
{
    return ['id' => (int)$x['id'], 'province_code' => $x['province_code'], 'province_name' => province_name($x['province_code']) ?? 'Nasional', 'category' => $x['category'],
        'category_label' => LC_CATEGORIES[$x['category']] ?? $x['category'], 'content' => $x['content'], 'source' => $x['source'], 'verified' => (bool)$x['verified'],
        'verified_by_name' => user_name($x['verified_by'] ? (int)$x['verified_by'] : null), 'verified_at' => $x['verified_at'], 'created_at' => $x['created_at']];
}
function media_out(array $m): array
{
    return ['id' => (int)$m['id'], 'draft_id' => $m['draft_id'] ? (int)$m['draft_id'] : null, 'kind' => $m['kind'], 'title' => $m['title'], 'spec' => jdec($m['spec'], new stdClass()),
        'template_code' => $m['template_code'], 'ai_mode' => $m['ai_mode'], 'asset_id' => $m['asset_id'] ? (int)$m['asset_id'] : null, 'ms' => (int)$m['ms'],
        'author_name' => user_name($m['created_by'] ? (int)$m['created_by'] : null), 'created_at' => $m['created_at'], 'updated_at' => $m['updated_at']];
}
function asset_state(?string $exp): string
{
    if (!$exp) return 'aktif';
    $t = (float)ts_from_iso($exp); $now = microtime(true);
    return $t < $now ? 'kedaluwarsa' : ($t < $now + 30 * 86400 ? 'segera_kedaluwarsa' : 'aktif');
}
function asset_out(array $a): array
{
    return ['id' => (int)$a['id'], 'kind' => $a['kind'], 'title' => $a['title'], 'filename' => $a['filename'], 'mime' => $a['mime'], 'size' => (int)$a['size'], 'sha256' => $a['sha256'],
        'tags' => jdec($a['tags']), 'ai_tags' => jdec($a['ai_tags']), 'description' => $a['description'], 'credit' => $a['credit'], 'license' => $a['license'],
        'license_label' => ASSET_LICENSES[$a['license']] ?? $a['license'], 'license_expires_at' => $a['license_expires_at'], 'license_state' => asset_state($a['license_expires_at']),
        'issue_code' => $a['issue_id'] ? val('SELECT code FROM issues WHERE id = ?', [$a['issue_id']]) : null, 'province_name' => province_name($a['province_code']),
        'coverage_id' => $a['coverage_id'] ? (int)$a['coverage_id'] : null, 'used_in' => (int)val('SELECT COUNT(*) FROM draft_assets WHERE asset_id = ?', [$a['id']]),
        'author_name' => user_name($a['created_by'] ? (int)$a['created_by'] : null), 'created_at' => $a['created_at']];
}
function tpl_out(array $t): array
{
    return ['id' => (int)$t['id'], 'code' => $t['code'], 'name' => $t['name'], 'kind' => $t['kind'], 'version' => (int)$t['version'], 'spec' => jdec($t['spec'], new stdClass()),
        'locked' => (bool)$t['locked'], 'is_current' => (bool)$t['is_current'], 'note' => $t['note'], 'author_name' => user_name($t['created_by'] ? (int)$t['created_by'] : null), 'created_at' => $t['created_at']];
}
function coverage_out(array $c): array
{
    return ['id' => (int)$c['id'], 'title' => $c['title'], 'event_at' => $c['event_at'], 'location' => $c['location'], 'province_code' => $c['province_code'],
        'province_name' => province_name($c['province_code']), 'reporter_id' => $c['reporter_id'] ? (int)$c['reporter_id'] : null, 'reporter_name' => user_name($c['reporter_id'] ? (int)$c['reporter_id'] : null),
        'status' => $c['status'], 'brief' => $c['brief'], 'brief_id' => $c['brief_id'] ? (int)$c['brief_id'] : null,
        'assets' => array_map('asset_out', all('SELECT * FROM assets WHERE coverage_id = ? ORDER BY id', [$c['id']])), 'created_at' => $c['created_at']];
}
function pack_out(array $p): array
{
    $late = $p['published_at'] ? $p['published_at'] > $p['deadline_at'] : now_iso() > $p['deadline_at'];
    return ['id' => (int)$p['id'], 'issue_code' => $p['issue_id'] ? val('SELECT code FROM issues WHERE id = ?', [$p['issue_id']]) : null,
        'issue_name' => $p['issue_id'] ? val('SELECT name FROM issues WHERE id = ?', [$p['issue_id']]) : null, 'alert_id' => $p['alert_id'] ? (int)$p['alert_id'] : null,
        'level' => $p['level'], 'title' => $p['title'], 'trigger' => $p['trigger_text'], 'holding_statement' => $p['holding_statement'], 'key_messages' => jdec($p['key_messages']),
        'qa' => jdec($p['qa']), 'data_points' => jdec($p['data_points']), 'spokesperson' => $p['spokesperson'], 'donts' => jdec($p['donts']), 'status' => $p['status'],
        'ai_mode' => $p['ai_mode'], 'deadline_at' => $p['deadline_at'], 'published_at' => $p['published_at'], 'published_by_name' => user_name($p['published_by'] ? (int)$p['published_by'] : null),
        'sla' => $p['status'] === 'terbit' ? ($late ? 'terlambat' : 'tepat_waktu') : ($late ? 'lewat_tenggat' : 'berjalan'),
        'minutes_used' => (int)round(((float)ts_from_iso($p['published_at'] ?? now_iso()) - (float)ts_from_iso($p['created_at'])) / 60),
        'author_name' => user_name($p['created_by'] ? (int)$p['created_by'] : null), 'created_at' => $p['created_at'], 'updated_at' => $p['updated_at']];
}
function partner_out(array $p): array
{
    $c = one('SELECT COUNT(*) n, COALESCE(SUM(reach),0) r, COALESCE(SUM(engagement),0) e, SUM(disclosure_ok = 0) bad FROM partner_collabs WHERE partner_id = ?', [$p['id']]);
    $expired = $p['contract_end'] && $p['contract_end'] < gmdate('Y-m-d');
    return ['id' => (int)$p['id'], 'name' => $p['name'], 'handle' => $p['handle'], 'platform' => $p['platform'], 'category' => $p['category'], 'followers' => (int)$p['followers'],
        'province_name' => province_name($p['province_code']), 'contract_no' => $p['contract_no'], 'contract_start' => $p['contract_start'], 'contract_end' => $p['contract_end'],
        'contract_asset_id' => $p['contract_asset_id'] ? (int)$p['contract_asset_id'] : null, 'disclosure' => $p['disclosure'], 'status' => $expired && $p['status'] === 'aktif' ? 'kontrak_berakhir' : $p['status'],
        'notes' => $p['notes'], 'collabs' => (int)$c['n'], 'reach' => (int)$c['r'], 'engagement' => (int)$c['e'], 'engagement_rate' => $c['r'] ? round($c['e'] / $c['r'], 4) : 0.0,
        'disclosure_issues' => (int)$c['bad'], 'compliant' => !$expired && (int)$c['bad'] === 0 && $p['contract_no'] !== ''];
}
function campaign_out(array $c, bool $detail = false): array
{
    $o = ['id' => (int)$c['id'], 'name' => $c['name'], 'issue_code' => $c['issue_id'] ? val('SELECT code FROM issues WHERE id = ?', [$c['issue_id']]) : null,
        'issue_name' => $c['issue_id'] ? val('SELECT name FROM issues WHERE id = ?', [$c['issue_id']]) : null, 'objective' => $c['objective'], 'audience' => $c['audience'],
        'regions' => jdec($c['regions']), 'region_names' => array_map(fn($x) => province_name($x) ?? $x, jdec($c['regions'])), 'key_messages' => $c['key_messages'],
        'start_date' => $c['start_date'], 'end_date' => $c['end_date'], 'status' => $c['status'], 'kpi' => jdec($c['kpi'], new stdClass()),
        'approved_by_name' => user_name($c['approved_by'] ? (int)$c['approved_by'] : null), 'approved_at' => $c['approved_at'], 'signature' => $c['signature'],
        'slots' => (int)val('SELECT COUNT(*) FROM campaign_slots WHERE campaign_id = ?', [$c['id']]), 'author_name' => user_name($c['created_by'] ? (int)$c['created_by'] : null),
        'evaluation' => $c['evaluation'], 'evaluation_mode' => $c['evaluation_mode'], 'created_at' => $c['created_at']];
    if ($detail) {
        $o['slot_list'] = array_map(function ($s) {
            $acc = $s['account_id'] ? one('SELECT platform, handle FROM social_accounts WHERE id = ?', [$s['account_id']]) : null;
            $par = $s['partner_id'] ? one('SELECT name, handle, disclosure FROM partners WHERE id = ?', [$s['partner_id']]) : null;
            $t = $s['post_task_id'] ? one('SELECT status, reach, engagement, impressions FROM post_tasks WHERE id = ?', [$s['post_task_id']]) : null;
            return ['id' => (int)$s['id'], 'date' => $s['date'], 'account' => $acc ? (PLATFORMS[$acc['platform']] ?? $acc['platform']) . ' ' . $acc['handle'] : null,
                'partner' => $par ? "{$par['name']} ({$par['handle']}) · {$par['disclosure']}" : null, 'draft_id' => $s['draft_id'] ? (int)$s['draft_id'] : null,
                'draft_title' => $s['draft_id'] ? val('SELECT title FROM drafts WHERE id = ?', [$s['draft_id']]) : null, 'note' => $s['note'],
                'task_status' => $t['status'] ?? null, 'reach' => (int)($t['reach'] ?? 0), 'engagement' => (int)($t['engagement'] ?? 0), 'impressions' => (int)($t['impressions'] ?? 0)];
        }, all('SELECT * FROM campaign_slots WHERE campaign_id = ? ORDER BY date, id', [$c['id']]));
        $o['monitoring'] = campaign_metrics($c);
    }
    return $o;
}
function campaign_metrics(array $c): array
{
    $tasks = all('SELECT * FROM post_tasks WHERE campaign_id = ?', [$c['id']]);
    $col = all('SELECT * FROM partner_collabs WHERE campaign_id = ?', [$c['id']]);
    $reach = array_sum(array_column($tasks, 'reach')) + array_sum(array_column($col, 'reach'));
    $eng = array_sum(array_column($tasks, 'engagement')) + array_sum(array_column($col, 'engagement'));
    $imp = array_sum(array_column($tasks, 'impressions'));
    $daily = [];
    foreach ($tasks as $t) if ($t['posted_at']) { $d = substr($t['posted_at'], 0, 10); $daily[$d] ??= ['date' => $d, 'reach' => 0, 'engagement' => 0]; $daily[$d]['reach'] += (int)$t['reach']; $daily[$d]['engagement'] += (int)$t['engagement']; }
    foreach ($col as $t) if ($t['posted_at']) { $d = substr($t['posted_at'], 0, 10); $daily[$d] ??= ['date' => $d, 'reach' => 0, 'engagement' => 0]; $daily[$d]['reach'] += (int)$t['reach']; $daily[$d]['engagement'] += (int)$t['engagement']; }
    ksort($daily);
    $kpi = jdec($c['kpi']);
    $shift = sentiment_shift($c['issue_id'] ? (int)$c['issue_id'] : null, $c['start_date'] . 'T00:00:00Z', $c['end_date'] . 'T23:59:59Z');
    return ['reach' => $reach, 'impressions' => $imp, 'engagement' => $eng, 'engagement_rate' => $reach ? round($eng / $reach, 4) : 0.0,
        'posts_done' => count(array_filter($tasks, fn($t) => $t['status'] === 'diunggah')), 'posts_planned' => count($tasks), 'partner_posts' => count($col),
        'reach_target' => (int)($kpi['reach'] ?? 0), 'reach_progress' => !empty($kpi['reach']) ? round($reach / $kpi['reach'], 3) : null,
        'daily' => array_values($daily), 'sentiment' => $shift, 'updated_at' => now_iso()];
}

function require_draft_reader(int $id): array { current_user(); return get_draft_row($id); }

// ======================= rute =======================
function register_routes_kak(Router $r): void
{
    // ---------------- M4 · S26/S33 verifikasi fakta ----------------
    $r->add('GET', '/api/m4/drafts/(\d+)/verify', function ($id) {
        $d = require_draft_reader((int)$id);
        send_json(verify_claims($d['body'], draft_facts($d)) + ['fact_items' => draft_facts($d), 'ai_label' => $d['ai_generated'] ? AI_LABEL : null,
            'word_count' => count(preg_split('/\s+/u', trim($d['body']), -1, PREG_SPLIT_NO_EMPTY))]);
    });
    // ---------------- M4 · S28 basis data konteks lokal ----------------
    $r->add('GET', '/api/m4/local-context', function () {
        current_user();
        $p = qparam('province');
        $rows = $p ? all('SELECT * FROM local_context WHERE province_code = ? ORDER BY category, id', [$p]) : all('SELECT * FROM local_context ORDER BY province_code, category, id');
        $summary = [];
        foreach (all('SELECT province_code, COUNT(*) n, SUM(verified) v FROM local_context GROUP BY province_code') as $s) $summary[$s['province_code']] = ['total' => (int)$s['n'], 'verified' => (int)$s['v']];
        send_json(['items' => array_map('lc_out', $rows), 'summary' => $summary, 'categories' => LC_CATEGORIES]);
    });
    $r->add('POST', '/api/m4/local-context', function () {
        $u = require_roles(...AUTHOR_ROLES);
        $b = json_body();
        $p = (string)($b['province_code'] ?? ''); if (!isset(PROVINCES[$p])) fail(422, 'Kode provinsi tidak dikenal');
        $cat = (string)($b['category'] ?? ''); if (!isset(LC_CATEGORIES[$cat])) fail(422, 'Kategori tidak dikenal');
        $id = insert('local_context', ['province_code' => $p, 'category' => $cat, 'content' => trim(str_field($b, 'content', 3, 600)), 'source' => trim(str_field($b, 'source', 3, 300)),
            'verified' => 0, 'created_by' => $u['id'], 'created_at' => now_iso()]);
        audit_record($u, 'konteks_lokal.tambah', 'local_context', $id, ['provinsi' => $p, 'kategori' => $cat]);
        send_json(lc_out(one('SELECT * FROM local_context WHERE id = ?', [$id])), 201);
    });
    $r->add('POST', '/api/m4/local-context/(\d+)/verify', function ($id) {
        $u = require_roles('redaktur', 'pimpinan');
        $x = one('SELECT * FROM local_context WHERE id = ?', [(int)$id]); if (!$x) fail(404, 'Data tidak ditemukan');
        $v = !empty(json_body()['verified']);
        q('UPDATE local_context SET verified = ?, verified_by = ?, verified_at = ? WHERE id = ?', [$v ? 1 : 0, $v ? $u['id'] : null, $v ? now_iso() : null, $x['id']]);
        audit_record($u, 'konteks_lokal.verifikasi', 'local_context', $x['id'], ['verified' => $v]);
        send_json(lc_out(one('SELECT * FROM local_context WHERE id = ?', [$x['id']])));
    });
    // ---------------- M4 · S32 judul & umpan balik koreksi ----------------
    $r->add('POST', '/api/m4/drafts/(\d+)/titles', function ($id) {
        $u = require_roles(...AUTHOR_OR_EDITOR);
        $d = get_draft_row((int)$id);
        send_json(ai_titles($u, $d['title'], $d['body'], FORMAT_LABEL[$d['format'] ?: $d['kind']] ?? $d['kind']));
    });
    $r->add('POST', '/api/m4/drafts/(\d+)/feedback', function ($id) {
        $u = require_roles('redaktur', 'pimpinan', 'produser', 'analis');
        $d = get_draft_row((int)$id);
        $b = json_body();
        $dec = (string)($b['decision'] ?? ''); if (!in_array($dec, ['diterima', 'ditolak'], true)) fail(422, 'Keputusan harus diterima/ditolak');
        $fid = insert('editorial_feedback', ['draft_id' => $d['id'], 'category' => mb_substr((string)($b['category'] ?? ''), 0, 40), 'severity' => mb_substr((string)($b['severity'] ?? ''), 0, 20),
            'excerpt' => mb_substr((string)($b['excerpt'] ?? ''), 0, 300), 'suggestion' => mb_substr((string)($b['suggestion'] ?? ''), 0, 500), 'decision' => $dec,
            'note' => mb_substr(trim((string)($b['note'] ?? '')), 0, 500), 'user_id' => $u['id'], 'at' => now_iso()]);
        audit_record($u, 'editorial.umpan_balik', 'draft', $d['id'], ['keputusan' => $dec, 'kategori' => $b['category'] ?? '']);
        send_json(['id' => $fid, 'decision' => $dec], 201);
    });
    $r->add('GET', '/api/m4/feedback/stats', function () {
        current_user();
        $rows = all("SELECT category, COUNT(*) n, SUM(decision = 'diterima') ok FROM editorial_feedback GROUP BY category ORDER BY n DESC");
        $recent = all('SELECT f.*, d.title FROM editorial_feedback f JOIN drafts d ON d.id = f.draft_id ORDER BY f.id DESC LIMIT 15');
        send_json(['by_category' => array_map(fn($x) => ['category' => $x['category'], 'total' => (int)$x['n'], 'accepted' => (int)$x['ok'], 'rate' => $x['n'] ? round($x['ok'] / $x['n'], 3) : 0], $rows),
            'recent' => array_map(fn($x) => ['draft_id' => (int)$x['draft_id'], 'draft_title' => $x['title'], 'category' => $x['category'], 'excerpt' => $x['excerpt'], 'decision' => $x['decision'],
                'note' => $x['note'], 'user_name' => user_name((int)$x['user_id']), 'at' => $x['at']], $recent)]);
    });
    // ---------------- M4 · S29–S31 infografis, video, podcast ----------------
    $r->add('POST', '/api/m4/drafts/(\d+)/media', function ($id) {
        $u = require_roles(...AUTHOR_ROLES);
        $d = get_draft_row((int)$id);
        $b = json_body();
        $kind = (string)($b['kind'] ?? ''); $t0 = microtime(true);
        $facts = draft_facts($d);
        if ($kind === 'infografis') { $res = ai_infographic($u, $d, $facts); $tpl = (string)($b['template_code'] ?? 'INF-STD'); }
        elseif ($kind === 'video') { $res = ai_video($u, $d, in_array($b['aspect'] ?? '', ['9:16', '16:9'], true) ? $b['aspect'] : '9:16', (int)($b['seconds'] ?? 45)); $tpl = (string)($b['template_code'] ?? 'VID-STD'); }
        elseif ($kind === 'podcast') { $res = ai_podcast($u, $d, (int)($b['minutes'] ?? 2)); $tpl = null; }
        else fail(422, 'Jenis keluaran tidak dikenal (infografis/video/podcast)');
        if ($tpl && !one('SELECT id FROM visual_templates WHERE code = ? AND is_current = 1', [$tpl])) $tpl = null;
        $now = now_iso();
        $mid = insert('media_outputs', ['draft_id' => $d['id'], 'kind' => $kind, 'title' => $d['title'], 'spec' => jenc($res['spec']), 'template_code' => $tpl, 'ai_mode' => $res['mode'],
            'ms' => (int)((microtime(true) - $t0) * 1000), 'created_by' => $u['id'], 'created_at' => $now, 'updated_at' => $now]);
        audit_record($u, 'produksi.' . $kind, 'draft', $d['id'], ['media_id' => $mid, 'mode' => $res['mode']]);
        send_json(media_out(one('SELECT * FROM media_outputs WHERE id = ?', [$mid])), 201);
    });
    $r->add('GET', '/api/m4/media', function () {
        current_user();
        $did = (int)qparam('draft_id', 0); $k = qparam('kind'); $qq = trim((string)qparam('q', ''));
        $limit = qint('limit', 100, 1, 200); $offset = qint('offset', 0, 0, 1000000);
        $w = ['1=1']; $p = [];
        if ($did) { $w[] = 'draft_id = ?'; $p[] = $did; }
        if ($k) { $w[] = 'kind = ?'; $p[] = $k; }
        if ($qq !== '') { $w[] = '(title LIKE ? OR CAST(draft_id AS TEXT) = ?)'; $p[] = '%' . $qq . '%'; $p[] = ltrim($qq, '#'); }
        send_json(array_map('media_out', all('SELECT * FROM media_outputs WHERE ' . implode(' AND ', $w) . " ORDER BY created_at DESC, id DESC LIMIT $limit OFFSET $offset", $p)));
    });
    $r->add('GET', '/api/m4/media/stats', function () {
        current_user();
        $by = []; foreach (all('SELECT kind, COUNT(*) n FROM media_outputs GROUP BY kind') as $x) $by[$x['kind']] = (int)$x['n'];
        $eligible = (int)val("SELECT COUNT(*) FROM drafts WHERE status IN ('disetujui','tayang')");
        $full = (int)val("SELECT COUNT(*) FROM drafts d WHERE d.status IN ('disetujui','tayang') AND (SELECT COUNT(DISTINCT kind) FROM media_outputs m WHERE m.draft_id = d.id) >= 3");
        send_json(['total' => array_sum($by), 'by_kind' => ['infografis' => $by['infografis'] ?? 0, 'video' => $by['video'] ?? 0, 'podcast' => $by['podcast'] ?? 0],
            'drafts_with_media' => (int)val('SELECT COUNT(DISTINCT draft_id) FROM media_outputs'), 'eligible_drafts' => $eligible, 'complete_drafts' => $full,
            'exported_assets' => (int)val('SELECT COUNT(*) FROM media_outputs WHERE asset_id IS NOT NULL'), 'last_at' => val('SELECT MAX(created_at) FROM media_outputs')]);
    });
    $r->add('PATCH', '/api/m4/media/(\d+)', function ($id) {
        $u = require_roles(...AUTHOR_ROLES);
        $m = one('SELECT * FROM media_outputs WHERE id = ?', [(int)$id]); if (!$m) fail(404, 'Keluaran tidak ditemukan');
        $b = json_body();
        if (!is_array($b['spec'] ?? null)) fail(422, 'spec wajib berupa objek');
        $spec = jenc($b['spec']); if (strlen($spec) > 60000) fail(413, 'Spesifikasi terlalu besar');
        $tpl = isset($b['template_code']) ? (string)$b['template_code'] : $m['template_code'];
        q('UPDATE media_outputs SET spec = ?, template_code = ?, updated_at = ? WHERE id = ?', [$spec, $tpl, now_iso(), $m['id']]);
        audit_record($u, 'produksi.sunting_media', 'media_output', $m['id']);
        send_json(media_out(one('SELECT * FROM media_outputs WHERE id = ?', [$m['id']])));
    });

    // ---------------- M5 · S34 kalender terpadu ----------------
    $r->add('GET', '/api/m5/calendar', function () {
        current_user();
        $from = parse_input_datetime((string)qparam('from', '')) ?? iso_from_ts(microtime(true) - 7 * 86400);
        $to = parse_input_datetime((string)qparam('to', '')) ?? iso_from_ts(microtime(true) + 30 * 86400);
        $ev = [];
        foreach (all('SELECT e.*, d.title, c.name cname, c.code ccode FROM calendar_entries e JOIN drafts d ON d.id = e.draft_id JOIN channels c ON c.id = e.channel_id WHERE e.planned_at >= ? AND e.planned_at < ?', [$from, $to]) as $x)
            $ev[] = ['id' => 'cal-' . $x['id'], 'type' => 'tayang', 'title' => $x['title'], 'start' => $x['planned_at'], 'resource' => $x['cname'], 'resource_key' => 'ch:' . $x['ccode'], 'status' => $x['status'], 'link' => '/editorial?id=' . $x['draft_id']];
        foreach (all('SELECT t.*, d.title, a.platform, a.handle FROM post_tasks t JOIN drafts d ON d.id = t.draft_id JOIN social_accounts a ON a.id = t.account_id WHERE t.planned_at >= ? AND t.planned_at < ? AND t.status != \'batal\'', [$from, $to]) as $x)
            $ev[] = ['id' => 'post-' . $x['id'], 'type' => 'unggah', 'title' => $x['title'], 'start' => $x['planned_at'], 'resource' => (PLATFORMS[$x['platform']] ?? $x['platform']) . ' ' . $x['handle'], 'resource_key' => 'acc:' . $x['account_id'], 'status' => $x['status'], 'link' => '/kanal-resmi?tab=tugas'];
        foreach (all("SELECT * FROM drafts WHERE due_at >= ? AND due_at < ? AND status NOT IN ('tayang','ditarik')", [$from, $to]) as $x)
            $ev[] = ['id' => 'due-' . $x['id'], 'type' => 'tenggat', 'title' => $x['title'], 'start' => $x['due_at'], 'resource' => user_name($x['assignee_id'] ? (int)$x['assignee_id'] : null) ?? '-', 'resource_key' => null, 'status' => $x['status'], 'link' => '/editorial?id=' . $x['id']];
        foreach (all('SELECT * FROM coverage WHERE event_at >= ? AND event_at < ?', [$from, $to]) as $x)
            $ev[] = ['id' => 'cov-' . $x['id'], 'type' => 'liputan', 'title' => $x['title'], 'start' => $x['event_at'], 'resource' => $x['location'], 'resource_key' => null, 'status' => $x['status'], 'link' => '/aset?tab=liputan'];
        foreach (all("SELECT x.*, d.title, c.name cname, c.code ccode FROM distributions x JOIN drafts d ON d.id = x.draft_id JOIN channels c ON c.id = x.channel_id WHERE x.status = 'terjadwal' AND x.scheduled_at >= ? AND x.scheduled_at < ?", [$from, $to]) as $x)
            $ev[] = ['id' => 'dist-' . $x['id'], 'type' => 'distribusi', 'title' => $x['title'], 'start' => $x['scheduled_at'], 'resource' => $x['cname'], 'resource_key' => 'ch:' . $x['ccode'], 'status' => 'terjadwal', 'link' => '/distribusi'];
        foreach (all('SELECT * FROM campaigns WHERE end_date >= ? AND start_date <= ?', [substr($from, 0, 10), substr($to, 0, 10)]) as $x)
            $ev[] = ['id' => 'kmp-' . $x['id'], 'type' => 'kampanye', 'title' => $x['name'], 'start' => $x['start_date'] . 'T00:00:00Z', 'end' => $x['end_date'] . 'T23:59:59Z', 'resource' => 'Kampanye', 'resource_key' => null, 'status' => $x['status'], 'link' => '/kanal-resmi?tab=kampanye'];
        // deteksi bentrok: sumber daya sama (kanal/akun) dengan selisih < 60 menit
        $byRes = [];
        foreach ($ev as $i => $e) if ($e['resource_key'] && in_array($e['type'], ['tayang', 'unggah', 'distribusi'], true)) $byRes[$e['resource_key']][] = $i;
        $conf = [];
        foreach ($byRes as $key => $idx) {
            usort($idx, fn($a, $b) => strcmp($ev[$a]['start'], $ev[$b]['start']));
            for ($i = 1; $i < count($idx); $i++) {
                $a = $ev[$idx[$i - 1]]; $b = $ev[$idx[$i]];
                if ((float)ts_from_iso($b['start']) - (float)ts_from_iso($a['start']) < 3600) {
                    $ev[$idx[$i - 1]]['conflict'] = true; $ev[$idx[$i]]['conflict'] = true;
                    $conf[] = ['resource' => $a['resource'], 'a' => $a['title'], 'b' => $b['title'], 'at' => $a['start']];
                }
            }
        }
        foreach ($ev as &$e) $e['conflict'] = $e['conflict'] ?? false;
        unset($e);
        usort($ev, fn($a, $b) => strcmp($a['start'], $b['start']));
        send_json(['from' => $from, 'to' => $to, 'events' => $ev, 'conflicts' => $conf]);
    });
    // ---------------- M5 · S35 revisi (diff) & notifikasi ----------------
    $r->add('GET', '/api/m5/drafts/(\d+)/revisions', function ($id) {
        $d = require_draft_reader((int)$id);
        send_json(array_map(fn($x) => ['version' => (int)$x['version'], 'title' => $x['title'], 'body' => $x['body'], 'editor_name' => user_name((int)$x['edited_by']), 'at' => $x['at']],
            all('SELECT * FROM draft_revisions WHERE draft_id = ? ORDER BY version', [$d['id']])));
    });
    $r->add('GET', '/api/notifications', function () {
        $u = current_user();
        send_json(['unread' => (int)val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$u['id']]),
            'items' => array_map(fn($n) => ['id' => (int)$n['id'], 'kind' => $n['kind'], 'text' => $n['text'], 'link' => $n['link'], 'read' => $n['read_at'] !== null, 'at' => $n['at']],
                all('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 40', [$u['id']]))]);
    });
    $r->add('POST', '/api/notifications/read', function () {
        $u = current_user();
        $ids = int_list(json_body()['ids'] ?? []);
        if ($ids) q('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL AND id IN (' . implode(',', $ids) . ')', [now_iso(), $u['id']]);
        else q('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [now_iso(), $u['id']]);
        send_json(['ok' => true]);
    });
    // ---------------- M5 · S37 DAM ----------------
    $r->add('GET', '/api/m5/assets', function () {
        current_user();
        $w = ['1=1']; $p = [];
        if ($qq = trim((string)qparam('q', ''))) { $w[] = '(title LIKE ? ESCAPE \'\\\' OR tags LIKE ? ESCAPE \'\\\' OR ai_tags LIKE ? ESCAPE \'\\\' OR description LIKE ? ESCAPE \'\\\' OR credit LIKE ? ESCAPE \'\\\')'; array_push($p, ...array_fill(0, 5, like_escape($qq))); }
        if ($k = qparam('kind')) { $w[] = 'kind = ?'; $p[] = $k; }
        if ($l = qparam('license')) { $w[] = 'license = ?'; $p[] = $l; }
        $media = qparam('media');
        if ($media === 'foto') $w[] = "mime IN ('image/jpeg','image/png','image/webp')";
        elseif ($media === 'grafis') $w[] = "mime = 'image/svg+xml'";
        if ($st = qparam('state')) {
            $now = now_iso(); $soon = iso_from_ts(microtime(true) + 30 * 86400);
            if ($st === 'kedaluwarsa') { $w[] = 'license_expires_at IS NOT NULL AND license_expires_at < ?'; $p[] = $now; }
            elseif ($st === 'segera_kedaluwarsa') { $w[] = 'license_expires_at >= ? AND license_expires_at < ?'; array_push($p, $now, $soon); }
            elseif ($st === 'aktif') { $w[] = '(license_expires_at IS NULL OR license_expires_at >= ?)'; $p[] = $soon; }
        }
        $rows = array_map('asset_out', all('SELECT * FROM assets WHERE ' . implode(' AND ', $w) . ' ORDER BY id DESC LIMIT 120', $p));
        $now = now_iso(); $soon = iso_from_ts(microtime(true) + 30 * 86400);
        $st = one('SELECT COUNT(*) n, COALESCE(SUM(size),0) b, SUM(license_expires_at IS NOT NULL AND license_expires_at < ?) ex, SUM(license_expires_at >= ? AND license_expires_at < ?) soon FROM assets', [$now, $now, $soon]);
        send_json(['items' => $rows, 'stats' => ['total' => (int)$st['n'], 'expiring' => (int)$st['soon'], 'expired' => (int)$st['ex'], 'bytes' => (int)$st['b']], 'licenses' => ASSET_LICENSES]);
    });
    $r->add('POST', '/api/m5/assets', function () {
        $u = require_roles('redaktur', 'produser', 'analis', 'operator', 'pimpinan');
        [$fn, $mime, $data] = read_upload();
        $f = fn($k) => trim((string)($_POST[$k] ?? ''));
        $lic = $f('license') ?: 'milik_sendiri'; if (!isset(ASSET_LICENSES[$lic])) fail(422, 'Lisensi tidak dikenal');
        $exp = $f('license_expires_at') !== '' ? (parse_input_datetime($f('license_expires_at')) ?? fail(422, 'Tanggal kedaluwarsa tidak valid')) : null;
        if ($lic === 'berlisensi' && !$exp) fail(422, 'Aset berlisensi wajib memiliki tanggal kedaluwarsa lisensi');
        $title = $f('title') ?: pathinfo($fn, PATHINFO_FILENAME);
        $kind = str_starts_with($mime, 'image/') ? 'gambar' : (str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'audio/') ? 'audio' : 'dokumen'));
        $sha = blob_put($data);
        $tag = ai_tag_asset($u, $title, $mime, $kind === 'gambar' ? $data : null);
        $iss = issue_by_code($f('issue_code') ?: null);
        $cov = (int)$f('coverage_id');
        $id = insert('assets', ['kind' => $kind, 'title' => mb_substr($title, 0, 200), 'filename' => $fn, 'mime' => $mime, 'size' => strlen($data), 'sha256' => $sha,
            'tags' => jenc(parse_tags($f('tags'))), 'ai_tags' => jenc($tag['tags']), 'description' => $f('description') ?: $tag['description'], 'credit' => mb_substr($f('credit'), 0, 200),
            'license' => $lic, 'license_expires_at' => $exp, 'issue_id' => $iss['id'] ?? null, 'province_code' => isset(PROVINCES[$f('province_code')]) ? $f('province_code') : null,
            'coverage_id' => $cov && one('SELECT id FROM coverage WHERE id = ?', [$cov]) ? $cov : null, 'created_by' => $u['id'], 'created_at' => now_iso()]);
        if (($did = (int)$f('draft_id')) && one('SELECT id FROM drafts WHERE id = ?', [$did])) q('INSERT OR IGNORE INTO draft_assets (draft_id, asset_id) VALUES (?, ?)', [$did, $id]);
        if (($mid = (int)$f('media_id')) && one('SELECT id FROM media_outputs WHERE id = ?', [$mid])) q('UPDATE media_outputs SET asset_id = ? WHERE id = ?', [$id, $mid]);
        audit_record($u, 'aset.unggah', 'asset', $id, ['sha256' => $sha, 'lisensi' => $lic, 'ai_tag' => $tag['mode']]);
        send_json(asset_out(one('SELECT * FROM assets WHERE id = ?', [$id])), 201);
    });
    $r->add('GET', '/api/m5/assets/(\d+)/file', function ($id) {
        current_user();
        $a = one('SELECT * FROM assets WHERE id = ?', [(int)$id]); if (!$a || !$a['sha256']) fail(404, 'Aset tidak ditemukan');
        // Isi berkas tidak pernah berubah (alamat = sha256), jadi peramban boleh menyimpannya; hemat proses hosting.
        $w = qparam('w') !== null ? qint('w', 480, 120, 1600) : 0;
        $etag = '"' . substr($a['sha256'], 0, 32) . ($w ? "-w$w" : '') . '"';
        header('Cache-Control: private, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); return; }
        [$data, $mime] = $w ? asset_thumb($a, $w) : [blob_get($a['sha256']), $a['mime']];
        header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
        header('Content-Security-Policy: sandbox; default-src \'none\'; style-src \'unsafe-inline\'; img-src data:');
        header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($a['filename'] ?: 'aset'));
        header('Content-Length: ' . strlen($data));
        echo $data;
    });
    $r->add('PATCH', '/api/m5/assets/(\d+)', function ($id) {
        $u = require_roles('redaktur', 'produser', 'pimpinan', 'analis');
        $a = one('SELECT * FROM assets WHERE id = ?', [(int)$id]); if (!$a) fail(404, 'Aset tidak ditemukan');
        $b = json_body(); $set = [];
        if (isset($b['title'])) $set['title'] = trim(str_field($b, 'title', 2, 200));
        if (isset($b['tags'])) $set['tags'] = jenc(parse_tags(is_array($b['tags']) ? implode(',', $b['tags']) : (string)$b['tags']));
        if (isset($b['credit'])) $set['credit'] = mb_substr((string)$b['credit'], 0, 200);
        if (isset($b['license'])) { if (!isset(ASSET_LICENSES[$b['license']])) fail(422, 'Lisensi tidak dikenal'); $set['license'] = $b['license']; }
        if (array_key_exists('license_expires_at', $b)) $set['license_expires_at'] = opt_dt($b['license_expires_at']);
        foreach ($set as $k => $v) q("UPDATE assets SET $k = ? WHERE id = ?", [$v, $a['id']]);
        audit_record($u, 'aset.ubah', 'asset', $a['id'], array_keys($set));
        send_json(asset_out(one('SELECT * FROM assets WHERE id = ?', [$a['id']])));
    });
    $r->add('POST', '/api/m5/assets/(\d+)/link', function ($id) {
        $u = require_roles(...AUTHOR_OR_EDITOR);
        $a = one('SELECT * FROM assets WHERE id = ?', [(int)$id]); if (!$a) fail(404, 'Aset tidak ditemukan');
        if (asset_state($a['license_expires_at']) === 'kedaluwarsa') fail(409, 'Lisensi aset sudah kedaluwarsa; tidak boleh dipakai');
        $d = get_draft_row((int)(json_body()['draft_id'] ?? 0));
        q('INSERT OR IGNORE INTO draft_assets (draft_id, asset_id) VALUES (?, ?)', [$d['id'], $a['id']]);
        audit_record($u, 'aset.tautkan', 'asset', $a['id'], ['draft_id' => (int)$d['id']]);
        send_json(asset_out(one('SELECT * FROM assets WHERE id = ?', [$a['id']])));
    });
    $r->add('GET', '/api/m5/drafts/(\d+)/assets', function ($id) {
        $d = require_draft_reader((int)$id);
        send_json(array_map('asset_out', all('SELECT a.* FROM assets a JOIN draft_assets x ON x.asset_id = a.id WHERE x.draft_id = ?', [$d['id']])));
    });
    // ---------------- M5 · S38 templat visual ----------------
    $r->add('GET', '/api/m5/templates', function () {
        current_user();
        send_json(array_map(fn($t) => tpl_out($t) + ['versions' => (int)val('SELECT COUNT(*) FROM visual_templates WHERE code = ?', [$t['code']])],
            all('SELECT * FROM visual_templates WHERE is_current = 1 ORDER BY kind, name')));
    });
    $r->add('GET', '/api/m5/templates/([A-Z0-9-]+)/versions', function ($code) {
        current_user();
        send_json(array_map('tpl_out', all('SELECT * FROM visual_templates WHERE code = ? ORDER BY version DESC', [$code])));
    });
    $r->add('POST', '/api/m5/templates/([A-Z0-9-]+)', function ($code) {
        $u = require_roles('produser', 'redaktur');
        $cur = one('SELECT * FROM visual_templates WHERE code = ? AND is_current = 1', [$code]); if (!$cur) fail(404, 'Templat tidak ditemukan');
        if ($cur['locked']) fail(409, 'Templat terkunci. Minta redaktur membuka kunci sebelum mengubah.');
        $b = json_body();
        if (!is_array($b['spec'] ?? null)) fail(422, 'spec wajib berupa objek');
        foreach (['bg', 'fg', 'accent'] as $k) if (isset($b['spec'][$k]) && !preg_match('/^#[0-9a-fA-F]{6}$/', (string)$b['spec'][$k])) fail(422, "Warna $k harus berformat #RRGGBB");
        q('UPDATE visual_templates SET is_current = 0 WHERE code = ?', [$code]);
        $id = insert('visual_templates', ['code' => $code, 'name' => trim((string)($b['name'] ?? $cur['name'])) ?: $cur['name'], 'kind' => $cur['kind'], 'version' => (int)$cur['version'] + 1,
            'spec' => jenc(array_merge(jdec($cur['spec']), $b['spec'])), 'locked' => 0, 'is_current' => 1, 'note' => mb_substr(trim((string)($b['note'] ?? '')), 0, 300), 'created_by' => $u['id'], 'created_at' => now_iso()]);
        audit_record($u, 'templat.versi_baru', 'template', $code, ['version' => (int)$cur['version'] + 1]);
        send_json(tpl_out(one('SELECT * FROM visual_templates WHERE id = ?', [$id])), 201);
    });
    $r->add('POST', '/api/m5/templates/([A-Z0-9-]+)/lock', function ($code) {
        $u = require_roles('redaktur', 'pimpinan');
        $cur = one('SELECT * FROM visual_templates WHERE code = ? AND is_current = 1', [$code]); if (!$cur) fail(404, 'Templat tidak ditemukan');
        $lock = !empty(json_body()['locked']);
        q('UPDATE visual_templates SET locked = ? WHERE id = ?', [$lock ? 1 : 0, $cur['id']]);
        audit_record($u, $lock ? 'templat.kunci' : 'templat.buka_kunci', 'template', $code);
        send_json(tpl_out(one('SELECT * FROM visual_templates WHERE id = ?', [$cur['id']])));
    });
    // ---------------- M5 · S39 liputan lapangan ----------------
    $r->add('GET', '/api/m5/coverage', function () { current_user(); send_json(array_map('coverage_out', all('SELECT * FROM coverage ORDER BY event_at DESC LIMIT 100'))); });
    $r->add('POST', '/api/m5/coverage', function () {
        $u = require_roles('redaktur', 'pimpinan');
        $b = json_body();
        $prov = ($b['province_code'] ?? null) ?: null; if ($prov !== null && !isset(PROVINCES[$prov])) fail(422, 'Kode provinsi tidak dikenal');
        $rep = ($b['reporter_id'] ?? null) ? (int)$b['reporter_id'] : null;
        if ($rep && !one('SELECT id FROM users WHERE id = ? AND active = 1', [$rep])) fail(422, 'Petugas tidak ditemukan');
        $now = now_iso();
        $id = insert('coverage', ['title' => trim(str_field($b, 'title', 5, 200)), 'event_at' => opt_dt($b['event_at'] ?? null) ?? fail(422, 'Waktu kegiatan wajib diisi'),
            'location' => trim(str_field($b, 'location', 3, 200)), 'province_code' => $prov, 'reporter_id' => $rep, 'status' => 'direncanakan', 'brief' => trim((string)($b['brief'] ?? '')),
            'created_by' => $u['id'], 'created_at' => $now, 'updated_at' => $now]);
        if ($rep) notify([$rep], 'liputan', 'Anda ditugaskan meliput: ' . trim((string)$b['title']), '/aset?tab=liputan', (int)$u['id']);
        audit_record($u, 'liputan.buat', 'coverage', $id);
        send_json(coverage_out(one('SELECT * FROM coverage WHERE id = ?', [$id])), 201);
    });
    $r->add('PATCH', '/api/m5/coverage/(\d+)', function ($id) {
        $u = require_roles('redaktur', 'pimpinan', 'produser', 'analis');
        $c = one('SELECT * FROM coverage WHERE id = ?', [(int)$id]); if (!$c) fail(404, 'Liputan tidak ditemukan');
        $b = json_body();
        if (isset($b['status'])) {
            if (!in_array($b['status'], ['direncanakan', 'berlangsung', 'selesai', 'batal'], true)) fail(422, 'Status tidak dikenal');
            q('UPDATE coverage SET status = ?, updated_at = ? WHERE id = ?', [$b['status'], now_iso(), $c['id']]);
        }
        if (array_key_exists('reporter_id', $b) && has_role($u, 'redaktur', 'pimpinan')) {
            $rep = $b['reporter_id'] ? (int)$b['reporter_id'] : null;
            q('UPDATE coverage SET reporter_id = ?, updated_at = ? WHERE id = ?', [$rep, now_iso(), $c['id']]);
            if ($rep) notify([$rep], 'liputan', "Anda ditugaskan meliput: {$c['title']}", '/aset?tab=liputan', (int)$u['id']);
        }
        audit_record($u, 'liputan.ubah', 'coverage', $c['id'], array_keys($b));
        send_json(coverage_out(one('SELECT * FROM coverage WHERE id = ?', [$c['id']])));
    });
    $r->add('POST', '/api/m5/coverage/(\d+)/brief', function ($id) {
        $u = require_roles(...AUTHOR_ROLES);
        $c = one('SELECT * FROM coverage WHERE id = ?', [(int)$id]); if (!$c) fail(404, 'Liputan tidak ditemukan');
        if ($c['brief_id']) fail(409, 'Liputan ini sudah memiliki brief');
        $iss = issue_by_code((string)(json_body()['issue_code'] ?? '')) ?? fail(422, 'Pilih isu');
        $now = now_iso();
        $bid = insert('briefs', ['title' => 'Liputan: ' . $c['title'], 'issue_id' => $iss['id'], 'format' => 'artikel', 'objective' => 'Melaporkan kegiatan lapangan', 'audience' => 'Warga',
            'region_code' => $c['province_code'], 'tone' => 'informatif', 'key_messages' => $c['brief'] ?: $c['title'],
            'facts' => jenc([['text' => "Kegiatan berlangsung di {$c['location']} pada " . substr($c['event_at'], 0, 10), 'source' => 'Catatan liputan lapangan', 'url' => '', 'reliability' => 'B']]),
            'status' => 'baru', 'created_by' => $u['id'], 'created_at' => $now, 'updated_at' => $now]);
        q('UPDATE coverage SET brief_id = ?, updated_at = ? WHERE id = ?', [$bid, $now, $c['id']]);
        audit_record($u, 'liputan.jadikan_brief', 'coverage', $c['id'], ['brief_id' => $bid]);
        send_json(['brief_id' => $bid], 201);
    });

    register_routes_kak_m6($r);
    register_routes_kak_m7($r);
    register_routes_kak_m8($r);
}
