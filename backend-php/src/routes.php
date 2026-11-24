<?php
/** Semua endpoint /api — kontrak identik dengan backend FastAPI. */
declare(strict_types=1);

/** Tandai agar transaksi tetap di-commit walau respons berupa galat (audit percobaan yang ditolak). */
function commit_then_fail(int $status, $detail): void { $GLOBALS['SISTRA_COMMIT_ON_ERROR'] = true; fail($status, $detail); }

function str_field(array $b, string $k, int $min = 0, int $max = 100000, bool $required = true): ?string
{
    if (!array_key_exists($k, $b) || $b[$k] === null) { if ($required) fail(422, "Kolom $k wajib diisi"); return null; }
    if (!is_string($b[$k])) fail(422, "Kolom $k harus berupa teks");
    $len = mb_strlen($b[$k]);
    if ($len < $min) fail(422, "Kolom $k minimal $min karakter");
    if ($len > $max) fail(422, "Kolom $k maksimal $max karakter");
    return $b[$k];
}
function list_field(array $b, string $k): ?array
{
    if (!array_key_exists($k, $b) || $b[$k] === null) return null;
    if (!is_array($b[$k]) || !array_is_list($b[$k])) fail(422, "Kolom $k harus berupa daftar");
    foreach ($b[$k] as $x) if (!is_string($x)) fail(422, "Isi $k harus berupa teks");
    return $b[$k];
}
function bool_q(string $k): bool { return in_array(strtolower((string)qparam($k, '')), ['1', 'true', 'yes', 'on'], true); }

// ---------------- representasi ----------------
function source_out(array $s, int $count = 0): array
{
    return ['id' => (int)$s['id'], 'type' => $s['type'], 'name' => $s['name'], 'url' => $s['url'], 'platform' => $s['platform'],
        'province_code' => $s['province_code'], 'reliability' => $s['reliability'], 'access_basis' => $s['access_basis'],
        'active' => (bool)$s['active'], 'last_fetched_at' => $s['last_fetched_at'], 'item_count' => $count];
}
function issue_out(array $i): array
{
    return ['id' => (int)$i['id'], 'code' => $i['code'], 'name' => $i['name'], 'taxonomy_path' => $i['taxonomy_path'], 'description' => $i['description'],
        'risk_level' => $i['risk_level'], 'keywords' => jdec($i['keywords']), 'active' => (bool)$i['active']];
}
function issues_for(array $ids): array
{
    $out = array_fill_keys($ids, []);
    if (!$ids) return $out;
    $rows = all('SELECT ci.content_id, ci.confidence, ci.assigned_by, i.code, i.name FROM content_issues ci JOIN issues i ON i.id = ci.issue_id
        WHERE ci.content_id IN (' . implode(',', array_map('intval', $ids)) . ')');
    foreach ($rows as $r) $out[(int)$r['content_id']][] = ['code' => $r['code'], 'name' => $r['name'], 'confidence' => (float)$r['confidence'], 'assigned_by' => $r['assigned_by']];
    foreach ($out as &$l) usort($l, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
    return $out;
}
function content_out(array $c, array $issues, bool $detail = false): array
{
    $body = (string)$c['body'];
    $o = ['id' => (int)$c['id'], 'source_id' => (int)$c['source_id'], 'source_name' => $c['source_name'], 'source_reliability' => $c['source_reliability'],
        'url' => $c['url'], 'title' => $c['title'], 'excerpt' => mb_substr($body, 0, 280) . (mb_strlen($body) > 280 ? '…' : ''), 'author' => $c['author'],
        'published_at' => $c['published_at'], 'ingested_at' => $c['ingested_at'], 'sentiment_label' => $c['sentiment_label'],
        'sentiment_score' => (float)$c['sentiment_score'], 'province_code' => $c['province_code'], 'province_name' => province_name($c['province_code']),
        'provinces' => jdec($c['provinces']), 'duplicate_of' => $c['duplicate_of'] === null ? null : (int)$c['duplicate_of'], 'issues' => $issues];
    if ($detail) $o['body'] = $body;
    return $o;
}
const CONTENT_SELECT = 'SELECT c.*, s.name source_name, s.reliability source_reliability FROM content_items c JOIN sources s ON s.id = c.source_id';
function content_detail(int $id): array
{
    $c = one(CONTENT_SELECT . ' WHERE c.id = ?', [$id]);
    if ($c === null) fail(404, 'Konten tidak ditemukan');
    return content_out($c, issues_for([$id])[$id], true);
}
function alert_out(array $a, bool $events = false): array
{
    $i = one('SELECT code, name FROM issues WHERE id = ?', [$a['issue_id']]);
    $o = ['id' => (int)$a['id'], 'issue_id' => (int)$a['issue_id'], 'issue_code' => $i['code'], 'issue_name' => $i['name'], 'level' => $a['level'],
        'status' => $a['status'], 'reason' => $a['reason'], 'metrics' => jdec($a['metrics'], []) ?: new stdClass(),
        'owner_id' => $a['owner_id'] === null ? null : (int)$a['owner_id'], 'triggered_at' => $a['triggered_at'], 'updated_at' => $a['updated_at'],
        'resolved_at' => $a['resolved_at'], 'events' => []];
    if ($events) {
        foreach (all('SELECT * FROM alert_events WHERE alert_id = ? ORDER BY id', [$a['id']]) as $e) {
            $o['events'][] = ['at' => $e['at'], 'action' => $e['action'], 'level' => $e['level'], 'note' => $e['note'], 'actor_id' => $e['actor_id'] === null ? null : (int)$e['actor_id']];
        }
    }
    return $o;
}
function get_alert_row(int $id): array { $a = one('SELECT * FROM alerts WHERE id = ?', [$id]); if ($a === null) fail(404, 'Peringatan tidak ditemukan'); return $a; }

function user_names(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $out = [];
    foreach (all('SELECT id, full_name FROM users WHERE id IN (' . implode(',', $ids) . ')') as $r) $out[(int)$r['id']] = $r['full_name'];
    return $out;
}
function calendar_out(array $e, array $d): array
{
    $ch = one('SELECT code, name FROM channels WHERE id = ?', [$e['channel_id']]);
    return ['id' => (int)$e['id'], 'draft_id' => (int)$d['id'], 'draft_title' => $d['title'], 'draft_status' => $d['status'], 'channel_code' => $ch['code'],
        'channel_name' => $ch['name'], 'planned_at' => $e['planned_at'], 'status' => $e['status']];
}
function draft_out(array $d, array $u, bool $detail = false): array
{
    $steps = $detail ? all('SELECT * FROM approval_steps WHERE draft_id = ? ORDER BY id', [$d['id']]) : [];
    $names = user_names(array_merge([$d['created_by']], array_column($steps, 'actor_id')));
    $o = ['id' => (int)$d['id'], 'kind' => $d['kind'], 'title' => $d['title'], 'body' => $d['body'], 'province_code' => $d['province_code'],
        'province_name' => province_name($d['province_code']), 'issue_code' => $d['issue_id'] ? val('SELECT code FROM issues WHERE id = ?', [$d['issue_id']]) : null,
        'ai_generated' => (bool)$d['ai_generated'], 'ai_label' => $d['ai_generated'] ? AI_LABEL : null, 'status' => $d['status'], 'version' => (int)$d['version'],
        'content_hash' => $d['content_hash'], 'created_by' => (int)$d['created_by'], 'author_name' => $names[(int)$d['created_by']] ?? '-',
        'created_at' => $d['created_at'], 'updated_at' => $d['updated_at'], 'approved_by' => $d['approved_by'] === null ? null : (int)$d['approved_by'],
        'approved_at' => $d['approved_at'], 'allowed_actions' => allowed_actions($d, $u),
        'format' => ($d['format'] ?? null) ?: $d['kind'], 'brief_id' => isset($d['brief_id']) && $d['brief_id'] ? (int)$d['brief_id'] : null,
        'assignee_id' => !empty($d['assignee_id']) ? (int)$d['assignee_id'] : null, 'assignee_name' => !empty($d['assignee_id']) ? val('SELECT full_name FROM users WHERE id = ?', [$d['assignee_id']]) : null,
        'due_at' => $d['due_at'] ?? null, 'priority' => ($d['priority'] ?? null) ?: 'normal', 'editorial_score' => isset($d['editorial_score']) ? (int)$d['editorial_score'] : null,
        'hashtags' => jdec($d['hashtags'] ?? '[]'), 'published_at' => $d['published_at'] ?? null];
    if (!$detail) return $o;
    $o['editorial'] = !empty($d['editorial_json']) ? jdec($d['editorial_json'], null) : null;
    $o['steps'] = array_map(fn($s) => ['from_status' => $s['from_status'], 'to_status' => $s['to_status'], 'actor_id' => (int)$s['actor_id'],
        'actor_name' => $names[(int)$s['actor_id']] ?? '-', 'actor_role' => $s['actor_role'], 'comment' => $s['comment'], 'version' => (int)$s['version'],
        'signature' => $s['signature'], 'at' => $s['at']], $steps);
    $o['revisions'] = array_map(fn($r) => ['version' => (int)$r['version'], 'title' => $r['title'], 'edited_by' => (int)$r['edited_by'], 'at' => $r['at']],
        all('SELECT version, title, edited_by, at FROM draft_revisions WHERE draft_id = ? ORDER BY id', [$d['id']]));
    $o['schedule'] = array_map(fn($e) => calendar_out($e, $d), all('SELECT * FROM calendar_entries WHERE draft_id = ? ORDER BY planned_at', [$d['id']]));
    return $o;
}
function get_draft_row(int $id): array { $d = one('SELECT * FROM drafts WHERE id = ?', [$id]); if ($d === null) fail(404, 'Naskah tidak ditemukan'); return $d; }

function version_out(array $v): array
{
    return ['version_no' => (int)$v['version_no'], 'filename' => $v['filename'], 'mime' => $v['mime'], 'size' => (int)$v['size'], 'sha256' => $v['sha256'],
        'note' => $v['note'], 'created_by' => (int)$v['created_by'], 'created_at' => $v['created_at']];
}
function doc_out(array $u, array $doc, ?string $qq = null): array
{
    $ver = one('SELECT * FROM document_versions WHERE document_id = ? AND version_no = ?', [$doc['id'], $doc['current_version_no']]);
    $unit = one('SELECT code, name FROM units WHERE id = ?', [$doc['owner_unit_id']]);
    return ['id' => (int)$doc['id'], 'title' => $doc['title'], 'description' => $doc['description'], 'owner_unit_code' => $unit['code'],
        'owner_unit_name' => $unit['name'], 'classification' => $doc['classification'], 'classification_label' => LEVEL_LABELS[$doc['classification']],
        'period_start' => $doc['period_start'], 'period_end' => $doc['period_end'], 'origin' => $doc['origin'], 'retention_class' => $doc['retention_class'],
        'tags' => jdec($doc['tags']), 'status' => $doc['status'], 'checked_out_by' => $doc['checked_out_by'] === null ? null : (int)$doc['checked_out_by'],
        'current_version' => version_out($ver), 'created_by' => (int)$doc['created_by'], 'created_at' => $doc['created_at'], 'updated_at' => $doc['updated_at'],
        'snippet' => $qq ? make_snippet((string)($ver['text_content'] ?: $doc['description']), $qq) : '',
        'permissions' => ['write' => can_write($u, $doc), 'manage' => can_manage($u, $doc)]];
}
function doc_detail(array $u, int $id): array
{
    $doc = one('SELECT * FROM documents WHERE id = ?', [$id]);
    $o = doc_out($u, $doc);
    $o['versions'] = array_map('version_out', all('SELECT * FROM document_versions WHERE document_id = ? ORDER BY version_no', [$id]));
    $o['grants'] = [];
    if (can_manage($u, $doc)) {
        foreach (all('SELECT g.*, u.username FROM access_grants g JOIN users u ON u.id = g.user_id WHERE g.document_id = ? ORDER BY g.created_at DESC', [$id]) as $g) {
            $o['grants'][] = ['id' => (int)$g['id'], 'user_id' => (int)$g['user_id'], 'username' => $g['username'], 'reason' => $g['reason'],
                'valid_from' => $g['valid_from'], 'valid_until' => $g['valid_until'], 'revoked_at' => $g['revoked_at']];
        }
    }
    return $o;
}
function load_readable(array $u, int $id): array
{
    $doc = one('SELECT * FROM documents WHERE id = ?', [$id]);
    if ($doc === null || !can_read($u, $doc)) {
        if ($doc !== null) { audit_record($u, 'dokumen.akses_ditolak', 'document', $id); commit_then_fail(404, 'Dokumen tidak ditemukan'); }
        fail(404, 'Dokumen tidak ditemukan');
    }
    return $doc;
}
function read_upload(string $field = 'file'): array
{
    $f = $_FILES[$field] ?? null;
    if ($f === null || is_array($f['name'])) fail(422, "Kolom $field wajib diisi");
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > MAX_UPLOAD_MB * 1024 * 1024) fail(413, 'Berkas melebihi ' . MAX_UPLOAD_MB . ' MB');
    if ($f['error'] !== UPLOAD_ERR_OK) fail(422, 'Unggahan berkas gagal');
    $data = (string)file_get_contents($f['tmp_name']);
    if ($data === '') fail(422, 'Berkas kosong');
    $name = basename(str_replace('\\', '/', (string)$f['name']));
    $name = mb_substr($name, 0, 255) ?: 'berkas';
    $mime = (string)($f['type'] ?? '');
    if ($mime === '' || $mime === 'application/octet-stream') $mime = guess_mime($name);
    return [$name, $mime, $data];
}
function guess_mime(string $fn): string
{
    $map = ['txt' => 'text/plain', 'md' => 'text/markdown', 'csv' => 'text/csv', 'json' => 'application/json', 'html' => 'text/html', 'htm' => 'text/html',
        'pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'zip' => 'application/zip'];
    return $map[strtolower(pathinfo($fn, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
}
function parse_tags(string $raw): array
{
    $t = array_values(array_unique(array_filter(array_map(fn($x) => mb_strtolower(trim($x)), explode(',', $raw)), fn($x) => $x !== '')));
    sort($t, SORT_STRING);
    return array_slice($t, 0, 20);
}
function valid_date(string $s): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s, new DateTimeZone('UTC'));
    if (!$d || $d->format('Y-m-d') !== $s) fail(422, "Invalid isoformat string: '$s'");
    return $s;
}

// ================================================================
function register_routes(Router $r): void
{
    $r->add('GET', '/api/health', fn() => send_json(['status' => 'ok', 'version' => '0.1.0-mvp']));

    // ---------- identitas ----------
    $r->add('POST', '/api/auth/login', function () {
        $b = json_body();
        $un = (string)($b['username'] ?? ''); $pw = (string)($b['password'] ?? '');
        $u = one('SELECT * FROM users WHERE username = ?', [$un]);
        if ($u === null || !$u['active'] || !password_verify($pw, $u['password_hash'])) {
            audit_record(null, 'auth.login_gagal', 'user', mb_substr($un, 0, 64), [], mb_substr($un, 0, 64));
            commit_then_fail(401, 'Nama pengguna atau kata sandi salah');
        }
        audit_record($u, 'auth.login', 'user', $u['id']);
        $tok = jwt_encode(['sub' => (string)$u['id'], 'username' => $u['username'], 'iat' => time(), 'exp' => time() + TOKEN_TTL]);
        send_json(['access_token' => $tok, 'token_type' => 'bearer', 'user' => user_out($u)]);
    });
    $r->add('GET', '/api/auth/me', fn() => send_json(user_out(current_user())));
    $r->add('GET', '/api/units', function () { current_user(); send_json(array_map('unit_out', all('SELECT * FROM units ORDER BY code'))); });
    $r->add('GET', '/api/users', function () { require_roles('admin'); send_json(array_map('user_out', all('SELECT * FROM users ORDER BY username'))); });
    $r->add('POST', '/api/users', function () {
        $admin = require_roles('admin');
        $b = json_body();
        $un = str_field($b, 'username', 3, 64);
        if (!preg_match('/^[a-z0-9._-]+$/', $un)) fail(422, 'Nama pengguna hanya boleh huruf kecil, angka, titik, garis bawah, dan tanda hubung');
        $fn = str_field($b, 'full_name', 1, 200);
        $pw = str_field($b, 'password', 8);
        $roles = validate_roles(list_field($b, 'roles') ?? []);
        if (!$roles) fail(422, 'Minimal satu peran');
        $clr = parse_level((string)($b['clearance'] ?? 'TERBUKA'));
        if (one('SELECT id FROM users WHERE username = ?', [$un])) fail(409, 'Nama pengguna sudah dipakai');
        $unit = unit_by_code((string)($b['unit_code'] ?? ''));
        $id = insert('users', ['username' => $un, 'full_name' => $fn, 'nip' => (string)($b['nip'] ?? ''), 'unit_id' => $unit['id'], 'roles' => jenc($roles),
            'clearance' => $clr, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'active' => 1, 'created_at' => now_iso()]);
        audit_record($admin, 'user.buat', 'user', $id, ['username' => $un, 'roles' => $roles, 'clearance' => $clr]);
        send_json(user_out(one('SELECT * FROM users WHERE id = ?', [$id])), 201);
    });
    $r->add('PATCH', '/api/users/(\d+)', function ($id) {
        $admin = require_roles('admin');
        $b = json_body();
        $u = one('SELECT * FROM users WHERE id = ?', [(int)$id]);
        if ($u === null) fail(404, 'Pengguna tidak ditemukan');
        $roles = isset($b['roles']) ? validate_roles(list_field($b, 'roles')) : null;
        $clr = isset($b['clearance']) ? parse_level((string)$b['clearance']) : null;
        $active = array_key_exists('active', $b) && $b['active'] !== null ? (bool)$b['active'] : null;
        if ((int)$u['id'] === (int)$admin['id'] && ($active === false || ($roles !== null && !in_array('admin', $roles, true)))) {
            fail(400, 'Admin tidak dapat menonaktifkan/mencabut perannya sendiri');
        }
        $ch = [];
        if ($roles !== null) { $ch['roles'] = [jdec($u['roles']), $roles]; q('UPDATE users SET roles = ? WHERE id = ?', [jenc($roles), $u['id']]); }
        if ($clr !== null) { $ch['clearance'] = [$u['clearance'], $clr]; q('UPDATE users SET clearance = ? WHERE id = ?', [$clr, $u['id']]); }
        if ($active !== null) { $ch['active'] = [(bool)$u['active'], $active]; q('UPDATE users SET active = ? WHERE id = ?', [$active ? 1 : 0, $u['id']]); }
        if (isset($b['unit_code'])) {
            $unit = unit_by_code((string)$b['unit_code']);
            $ch['unit'] = [val('SELECT code FROM units WHERE id = ?', [$u['unit_id']]), $unit['code']];
            q('UPDATE users SET unit_id = ? WHERE id = ?', [$unit['id'], $u['id']]);
        }
        audit_record($admin, 'user.ubah', 'user', $u['id'], $ch);
        send_json(user_out(one('SELECT * FROM users WHERE id = ?', [$u['id']])));
    });

    // ---------- dashboard & audit ----------
    $r->add('GET', '/api/dashboard/summary', function () {
        $u = current_user();
        $hours = qint('hours', 24, 1, 168); $days = qint('days', 14, 2, 60);
        $nowTs = microtime(true); $since = iso_from_ts($nowTs - $hours * 3600);
        $sent = [];
        foreach (all('SELECT sentiment_label l, COUNT(*) n FROM content_items WHERE published_at >= ? GROUP BY sentiment_label', [$since]) as $x) $sent[$x['l']] = (int)$x['n'];
        $startTs = (int)strtotime(gmdate('Y-m-d', (int)($nowTs - ($days - 1) * 86400)) . 'T00:00:00Z');
        $daily = [];
        for ($i = 0; $i < $days; $i++) $daily[gmdate('Y-m-d', $startTs + $i * 86400)] = ['positif' => 0, 'netral' => 0, 'negatif' => 0, 'total' => 0];
        foreach (all('SELECT substr(published_at,1,10) d, sentiment_label l, COUNT(*) n FROM content_items WHERE published_at >= ? GROUP BY d, l', [iso_from_ts($startTs)]) as $x) {
            if (!isset($daily[$x['d']])) continue;
            $daily[$x['d']][$x['l']] = ($daily[$x['d']][$x['l']] ?? 0) + (int)$x['n'];
            $daily[$x['d']]['total'] += (int)$x['n'];
        }
        $timeline = [];
        foreach ($daily as $k => $c) $timeline[] = ['date' => $k, 'positif' => $c['positif'], 'netral' => $c['netral'], 'negatif' => $c['negatif'], 'total' => $c['total']];
        $pc = []; $pn = [];
        foreach (all('SELECT province_code p, COUNT(*) n, SUM(sentiment_label = \'negatif\') g FROM content_items WHERE published_at >= ? AND province_code IS NOT NULL GROUP BY province_code', [$since]) as $x) {
            $pc[$x['p']] = (int)$x['n']; $pn[$x['p']] = (int)$x['g'];
        }
        $provinces = [];
        foreach (PROVINCES as $code => [$name]) $provinces[] = ['code' => (string)$code, 'name' => $name, 'volume' => $pc[(string)$code] ?? 0, 'negative' => $pn[(string)$code] ?? 0];
        usort($provinces, fn($a, $b) => $b['volume'] <=> $a['volume']);
        $imap = [];
        foreach (all('SELECT * FROM issues') as $i) $imap[(int)$i['id']] = $i;
        $issues = [];
        foreach (assess_issues($nowTs) as $a) {
            $i = $imap[$a['issue_id']];
            $issues[] = ['issue_id' => $a['issue_id'], 'code' => $i['code'], 'name' => $i['name'], 'risk_level' => $i['risk_level'], 'level' => $a['level'], 'reason' => $a['reason']] + metrics_of($a);
        }
        usort($issues, fn($a, $b) => (ALERT_LEVELS[$b['level']] <=> ALERT_LEVELS[$a['level']]) ?: ($b['volume_24h'] <=> $a['volume_24h']));
        $oa = [];
        foreach (all("SELECT level, COUNT(*) n FROM alerts WHERE status IN ('open','ack','in_progress') GROUP BY level") as $x) $oa[$x['level']] = (int)$x['n'];
        $dr = [];
        foreach (all('SELECT status, COUNT(*) n FROM drafts GROUP BY status') as $x) $dr[$x['status']] = (int)$x['n'];
        $covered = count(array_filter($provinces, fn($p) => $p['volume'] > 0));
        send_json([
            'generated_at' => iso_from_ts($nowTs), 'window_hours' => $hours,
            'content' => ['total_window' => array_sum($sent), 'total_all' => (int)val('SELECT COUNT(*) FROM content_items'),
                'sentiment' => ['positif' => $sent['positif'] ?? 0, 'netral' => $sent['netral'] ?? 0, 'negatif' => $sent['negatif'] ?? 0], 'provinces_covered' => $covered],
            'timeline' => $timeline, 'provinces' => $provinces, 'issues' => $issues,
            'alerts' => ['merah' => $oa['merah'] ?? 0, 'oranye' => $oa['oranye'] ?? 0, 'kuning' => $oa['kuning'] ?? 0],
            'drafts' => array_combine(['draf', 'ditinjau', 'disetujui', 'tayang', 'ditarik'], array_map(fn($s) => $dr[$s] ?? 0, ['draf', 'ditinjau', 'disetujui', 'tayang', 'ditarik'])),
            'documents_accessible' => count(search_documents($u)),
            'sources' => (int)val('SELECT COUNT(*) FROM sources WHERE active = 1'),
            'last_ingest_at' => val('SELECT MAX(last_fetched_at) FROM sources'),
        ]);
    });
    $r->add('GET', '/api/audit', function () {
        require_roles('auditor');
        $sql = 'SELECT * FROM audit_events WHERE 1=1'; $p = [];
        if ($a = qparam('action')) { $sql .= " AND action LIKE ? ESCAPE '\\'"; $p[] = substr(like_escape($a), 1); }
        if ($a = qparam('actor')) { $sql .= ' AND actor_username = ?'; $p[] = $a; }
        if ($bs = qint('before_seq', 0, 0, PHP_INT_MAX)) { $sql .= ' AND seq < ?'; $p[] = $bs; }
        $sql .= ' ORDER BY seq DESC LIMIT ' . qint('limit', 100, 1, 500);
        send_json(array_map(fn($e) => ['seq' => (int)$e['seq'], 'at' => $e['at'], 'actor_username' => $e['actor_username'], 'action' => $e['action'],
            'object_type' => $e['object_type'], 'object_id' => $e['object_id'], 'ip' => $e['ip'], 'detail' => jdec($e['detail'], []) ?: new stdClass(), 'hash' => $e['hash']], all($sql, $p)));
    });
    $r->add('GET', '/api/audit/verify', function () {
        $u = require_roles('auditor', 'admin');
        $res = audit_verify();
        audit_record($u, 'audit.verifikasi', 'audit', '-', ['ok' => $res['ok'], 'checked' => $res['checked'], 'first_broken_seq' => $res['first_broken_seq']]);
        send_json($res);
    });

    // ---------- sumber & penyerapan ----------
    $r->add('GET', '/api/sources', function () {
        current_user();
        $cnt = [];
        foreach (all('SELECT source_id, COUNT(*) n FROM content_items GROUP BY source_id') as $x) $cnt[(int)$x['source_id']] = (int)$x['n'];
        send_json(array_map(fn($s) => source_out($s, $cnt[(int)$s['id']] ?? 0), all('SELECT * FROM sources ORDER BY name')));
    });
    $r->add('POST', '/api/sources', function () {
        $u = require_roles('analis', 'admin');
        $b = json_body();
        $type = str_field($b, 'type');
        if (!in_array($type, ['news', 'social', 'tv', 'radio', 'print', 'report'], true)) fail(422, 'Jenis sumber tidak dikenal');
        $row = ['type' => $type, 'name' => str_field($b, 'name', 2, 200), 'url' => str_field($b, 'url', 0, 500, false) ?? '',
            'platform' => str_field($b, 'platform', 0, 100, false) ?? '', 'province_code' => ($b['province_code'] ?? null) ?: null,
            'reliability' => str_field($b, 'reliability', 1, 1, false) ?? 'C', 'access_basis' => str_field($b, 'access_basis', 3, 300, false) ?? 'Sumber terbuka/publik'];
        if (!preg_match('/^[A-F]$/', $row['reliability'])) fail(422, 'Keandalan harus A–F');
        if ($row['province_code'] !== null && !isset(PROVINCES[$row['province_code']])) fail(422, 'Kode provinsi tidak dikenal');
        $id = insert('sources', $row + ['active' => 1, 'created_at' => now_iso()]);
        audit_record($u, 'sumber.buat', 'source', $id, $row);
        send_json(source_out(one('SELECT * FROM sources WHERE id = ?', [$id])), 201);
    });
    $getSource = function (int $id): array {
        $s = one('SELECT * FROM sources WHERE id = ?', [$id]);
        if ($s === null || !$s['active']) fail(404, 'Sumber tidak ditemukan/aktif');
        return $s;
    };
    $r->add('POST', '/api/ingest/upload', function () use ($getSource) {
        $u = require_roles('analis', 'admin');
        $sid = $_POST['source_id'] ?? null;
        if ($sid === null || !ctype_digit((string)$sid)) fail(422, 'Kolom source_id wajib diisi');
        $src = $getSource((int)$sid);
        $f = $_FILES['file'] ?? null;
        if ($f === null) fail(422, 'Kolom file wajib diisi');
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['size'] > MAX_FEED_BYTES) fail(413, 'Berkas melebihi 5 MB');
        if ($f['error'] !== UPLOAD_ERR_OK) fail(422, 'Unggahan berkas gagal');
        try { $items = parse_any((string)file_get_contents($f['tmp_name'])); } catch (InvalidArgumentException $e) { fail(422, $e->getMessage()); }
        send_json(ingest_items($src, $items, $u));
    });
    $r->add('POST', '/api/ingest/fetch/(\d+)', function ($id) use ($getSource) {
        $u = require_roles('analis', 'admin');
        $src = $getSource((int)$id);
        if (!$src['url']) fail(422, 'Sumber tidak memiliki URL');
        try { $items = fetch_feed($src['url']); } catch (InvalidArgumentException $e) { fail(422, $e->getMessage()); }
        send_json(ingest_items($src, $items, $u));
    });

    // ---------- isu, provinsi, konten ----------
    $r->add('GET', '/api/issues', function () { current_user(); send_json(array_map('issue_out', all('SELECT * FROM issues ORDER BY code'))); });
    $r->add('POST', '/api/issues', function () {
        $u = require_roles('analis', 'admin');
        $b = json_body();
        $code = str_field($b, 'code');
        if (!preg_match('/^[A-Z0-9-]{3,32}$/', $code)) fail(422, 'Kode isu harus 3–32 karakter huruf besar/angka/tanda hubung');
        $risk = $b['risk_level'] ?? 'sedang';
        if (!in_array($risk, ['rendah', 'sedang', 'tinggi'], true)) fail(422, 'Tingkat risiko tidak dikenal');
        if (one('SELECT id FROM issues WHERE code = ?', [$code])) fail(409, 'Kode isu sudah ada');
        $id = insert('issues', ['code' => $code, 'name' => str_field($b, 'name', 3, 200), 'taxonomy_path' => (string)($b['taxonomy_path'] ?? ''),
            'description' => (string)($b['description'] ?? ''), 'risk_level' => $risk, 'keywords' => jenc(clean_keywords(list_field($b, 'keywords') ?? [])), 'active' => 1, 'created_at' => now_iso()]);
        audit_record($u, 'isu.buat', 'issue', $id, ['code' => $code]);
        send_json(issue_out(one('SELECT * FROM issues WHERE id = ?', [$id])), 201);
    });
    $r->add('PATCH', '/api/issues/(\d+)', function ($id) {
        $u = require_roles('analis', 'admin');
        $i = one('SELECT * FROM issues WHERE id = ?', [(int)$id]);
        if ($i === null) fail(404, 'Isu tidak ditemukan');
        $b = json_body(); $data = [];
        foreach (['name', 'description', 'risk_level'] as $k) if (isset($b[$k])) $data[$k] = (string)$b[$k];
        if (isset($data['risk_level']) && !in_array($data['risk_level'], ['rendah', 'sedang', 'tinggi'], true)) fail(422, 'Tingkat risiko tidak dikenal');
        if (isset($b['keywords'])) $data['keywords'] = clean_keywords(list_field($b, 'keywords'));
        if (isset($b['active'])) $data['active'] = (bool)$b['active'];
        foreach ($data as $k => $v) q("UPDATE issues SET $k = ? WHERE id = ?", [is_array($v) ? jenc($v) : (is_bool($v) ? (int)$v : $v), $i['id']]);
        audit_record($u, 'isu.ubah', 'issue', $i['id'], $data);
        send_json(issue_out(one('SELECT * FROM issues WHERE id = ?', [$i['id']])));
    });
    $r->add('GET', '/api/issues/(\d+)/metrics', function ($id) {
        current_user();
        $days = qint('days', 14, 1, 90);
        if (!one('SELECT id FROM issues WHERE id = ?', [(int)$id])) fail(404, 'Isu tidak ditemukan');
        send_json(issue_metrics((int)$id, $days));
    });
    $r->add('GET', '/api/provinces', function () {
        current_user();
        $o = []; foreach (PROVINCES as $c => [$n]) $o[] = ['code' => (string)$c, 'name' => $n];
        send_json($o);
    });
    $r->add('GET', '/api/content', function () {
        current_user();
        $limit = qint('limit', 50, 1, 200); $offset = qint('offset', 0, 0, PHP_INT_MAX);
        $where = []; $p = [];
        if (!bool_q('include_duplicates')) $where[] = 'c.duplicate_of IS NULL';
        if ($v = qparam('issue')) { $where[] = 'c.id IN (SELECT ci.content_id FROM content_issues ci JOIN issues i ON i.id = ci.issue_id WHERE i.code = ?)'; $p[] = $v; }
        if ($v = qparam('province')) { $where[] = 'c.province_code = ?'; $p[] = $v; }
        if ($v = qparam('sentiment')) { if (!in_array($v, ['positif', 'negatif', 'netral'], true)) fail(422, 'Sentimen tidak dikenal'); $where[] = 'c.sentiment_label = ?'; $p[] = $v; }
        if ($v = qparam('source_id')) { $where[] = 'c.source_id = ?'; $p[] = (int)$v; }
        if ($v = qparam('since')) { $t = parse_input_datetime($v); if (!$t) fail(422, 'Format since tidak valid'); $where[] = 'c.published_at >= ?'; $p[] = $t; }
        if ($v = qparam('until')) { $t = parse_input_datetime($v); if (!$t) fail(422, 'Format until tidak valid'); $where[] = 'c.published_at < ?'; $p[] = $t; }
        foreach (preg_split('/\s+/', trim((string)qparam('q', '')), -1, PREG_SPLIT_NO_EMPTY) as $t) {
            $where[] = "(c.title LIKE ? ESCAPE '\\' OR c.body LIKE ? ESCAPE '\\')"; $pat = like_escape($t); $p[] = $pat; $p[] = $pat;
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int)val('SELECT COUNT(*) FROM content_items c' . $w, $p);
        $rows = all(CONTENT_SELECT . $w . " ORDER BY c.published_at DESC, c.id DESC LIMIT $limit OFFSET $offset", $p);
        $iss = issues_for(array_map(fn($c) => (int)$c['id'], $rows));
        send_json(['total' => $total, 'items' => array_map(fn($c) => content_out($c, $iss[(int)$c['id']]), $rows)]);
    });
    $r->add('GET', '/api/content/(\d+)', function ($id) { current_user(); send_json(content_detail((int)$id)); });
    $r->add('PUT', '/api/content/(\d+)/issues', function ($id) {
        $u = require_roles('analis');
        $c = one('SELECT id FROM content_items WHERE id = ?', [(int)$id]);
        if ($c === null) fail(404, 'Konten tidak ditemukan');
        $codes = array_values(array_unique(list_field(json_body(), 'issue_codes') ?? [])); sort($codes, SORT_STRING);
        $found = [];
        foreach ($codes as $code) { $i = one('SELECT id, code FROM issues WHERE code = ?', [$code]); if ($i) $found[$code] = (int)$i['id']; }
        $unknown = array_diff($codes, array_keys($found));
        if ($unknown) fail(422, 'Isu tidak dikenal: ' . implode(', ', $unknown));
        $before = array_column(issues_for([(int)$id])[(int)$id], 'code'); sort($before, SORT_STRING);
        q('DELETE FROM content_issues WHERE content_id = ?', [(int)$id]);
        foreach ($found as $iid) insert('content_issues', ['content_id' => (int)$id, 'issue_id' => $iid, 'confidence' => 1.0, 'assigned_by' => 'analyst', 'corrected_by' => $u['id'], 'created_at' => now_iso()]);
        audit_record($u, 'konten.koreksi_isu', 'content', $id, ['before' => $before, 'after' => $codes]);
        send_json(content_detail((int)$id));
    });

    // ---------- peringatan ----------
    $r->add('GET', '/api/alerts', function () {
        current_user();
        $state = qparam('state', 'aktif');
        if (!in_array($state, ['aktif', 'selesai', 'semua'], true)) fail(422, 'state tidak dikenal');
        $limit = qint('limit', 100, 1, 500);
        $w = $state === 'aktif' ? " WHERE status IN ('open','ack','in_progress')" : ($state === 'selesai' ? " WHERE status = 'resolved'" : '');
        $rows = all("SELECT * FROM alerts$w ORDER BY updated_at DESC LIMIT $limit");
        usort($rows, fn($a, $b) => (($a['status'] === 'resolved') <=> ($b['status'] === 'resolved')) ?: (ALERT_LEVELS[$b['level']] <=> ALERT_LEVELS[$a['level']]) ?: strcmp($b['updated_at'], $a['updated_at']));
        send_json(array_map(fn($a) => alert_out($a), $rows));
    });
    $r->add('GET', '/api/alerts/assessment', function () {
        current_user();
        send_json(array_map(fn($a) => ['issue_id' => $a['issue_id'], 'level' => $a['level'], 'reason' => $a['reason']] + metrics_of($a), assess_issues()));
    });
    $r->add('POST', '/api/alerts/evaluate', function () {
        $u = require_roles('analis', 'admin');
        $res = evaluate_alerts($u);
        audit_record($u, 'peringatan.evaluasi', 'alert', '-', ['created' => count($res['created']), 'escalated' => count($res['escalated'])]);
        send_json($res);
    });
    $r->add('GET', '/api/alerts/(\d+)', function ($id) { current_user(); send_json(alert_out(get_alert_row((int)$id), true)); });
    $r->add('POST', '/api/alerts/(\d+)/([a-z_]+)', function ($id, $action) {
        $u = require_roles('analis', 'pimpinan');
        $a = get_alert_row((int)$id);
        $note = (string)(json_body()['note'] ?? '');
        if (mb_strlen($note) > 2000) fail(422, 'Catatan maksimal 2000 karakter');
        $now = now_iso();
        if ($action === 'catatan') {
            if (trim($note) === '') fail(422, 'Catatan wajib diisi');
            insert('alert_events', ['alert_id' => $a['id'], 'at' => $now, 'actor_id' => $u['id'], 'action' => 'catatan', 'level' => $a['level'], 'note' => $note]);
        } elseif (isset(ALERT_TRANSITIONS[$action])) {
            [$from, $to] = ALERT_TRANSITIONS[$action];
            if (!in_array($a['status'], $from, true)) fail(409, "Aksi '$action' tidak dapat dilakukan pada status '{$a['status']}'");
            if ($action === 'selesai' && trim($note) === '') fail(422, 'Catatan penyelesaian wajib diisi');
            $owner = $a['owner_id'];
            if (in_array($action, ['ack', 'tangani'], true) && $owner === null) $owner = $u['id'];
            q('UPDATE alerts SET status = ?, owner_id = ?, resolved_at = ? WHERE id = ?', [$to, $owner, $to === 'resolved' ? $now : $a['resolved_at'], $a['id']]);
            insert('alert_events', ['alert_id' => $a['id'], 'at' => $now, 'actor_id' => $u['id'], 'action' => $action, 'level' => $a['level'], 'note' => $note]);
            $a['status'] = $to;
        } else {
            fail(404, 'Aksi tidak dikenal');
        }
        q('UPDATE alerts SET updated_at = ? WHERE id = ?', [$now, $a['id']]);
        audit_record($u, "peringatan.$action", 'alert', $a['id'], ['status' => $a['status'], 'note' => mb_substr($note, 0, 200)]);
        send_json(alert_out(get_alert_row((int)$id), true));
    });

    // ---------- editorial ----------
    $r->add('GET', '/api/channels', function () {
        current_user();
        send_json(array_map(fn($c) => ['id' => (int)$c['id'], 'code' => $c['code'], 'name' => $c['name'], 'type' => $c['type'], 'province_code' => $c['province_code']],
            all('SELECT * FROM channels WHERE active = 1 ORDER BY code')));
    });
    $r->add('GET', '/api/drafts', function () {
        $u = current_user();
        $sql = 'SELECT * FROM drafts WHERE 1=1'; $p = [];
        if ($s = qparam('status')) { $sql .= ' AND status = ?'; $p[] = $s; }
        if (bool_q('mine')) { $sql .= ' AND created_by = ?'; $p[] = $u['id']; }
        send_json(array_map(fn($d) => draft_out($d, $u), all($sql . ' ORDER BY updated_at DESC LIMIT 300', $p)));
    });
    $r->add('POST', '/api/drafts', function () {
        $u = require_roles(...AUTHOR_ROLES);
        $b = json_body();
        $kind = str_field($b, 'kind');
        if (!in_array($kind, ['rilis', 'opini', 'editorial', 'briefing', 'infografis', 'video', 'audio', 'artikel', 'caption', 'thread', 'carousel', 'podcast', 'faq'], true)) fail(422, 'Jenis naskah tidak dikenal');
        $title = trim(str_field($b, 'title', 5, 300)); $body = trim(str_field($b, 'body', 20));
        $prov = ($b['province_code'] ?? null) ?: null;
        if ($prov !== null && !isset(PROVINCES[$prov])) fail(422, 'Kode provinsi tidak dikenal');
        $issueId = null;
        if ($ic = ($b['issue_code'] ?? null)) { $issueId = val('SELECT id FROM issues WHERE code = ?', [$ic]); if (!$issueId) fail(422, 'Kode isu tidak dikenal'); }
        $now = now_iso(); $ai = !empty($b['ai_generated']); $h = draft_hash($title, $body);
        $id = insert('drafts', ['kind' => $kind, 'format' => $kind, 'title' => $title, 'body' => $body, 'province_code' => $prov, 'issue_id' => $issueId, 'ai_generated' => (int)$ai,
            'status' => 'draf', 'version' => 1, 'content_hash' => $h, 'created_by' => $u['id'], 'created_at' => $now, 'updated_at' => $now]);
        insert('draft_revisions', ['draft_id' => $id, 'version' => 1, 'title' => $title, 'body' => $body, 'edited_by' => $u['id'], 'at' => $now]);
        audit_record($u, 'naskah.buat', 'draft', $id, ['kind' => $kind, 'ai_generated' => $ai, 'hash' => $h]);
        send_json(draft_out(get_draft_row($id), $u, true), 201);
    });
    $r->add('GET', '/api/drafts/(\d+)', function ($id) { $u = current_user(); send_json(draft_out(get_draft_row((int)$id), $u, true)); });
    $r->add('PUT', '/api/drafts/(\d+)', function ($id) {
        $u = current_user();
        $d = get_draft_row((int)$id);
        $b = json_body();
        $title = trim(str_field($b, 'title', 5, 300)); $body = trim(str_field($b, 'body', 20));
        if ($d['status'] !== 'draf') fail(409, 'Naskah hanya dapat disunting pada status draf');
        if ((int)$d['created_by'] !== (int)$u['id'] && !has_role($u, 'redaktur')) fail(403, 'Hanya penulis atau redaktur yang dapat menyunting');
        $h = draft_hash($title, $body);
        if ($h !== $d['content_hash']) {
            $now = now_iso(); $v = (int)$d['version'] + 1;
            q('UPDATE drafts SET title = ?, body = ?, content_hash = ?, version = ?, updated_at = ? WHERE id = ?', [$title, $body, $h, $v, $now, $d['id']]);
            insert('draft_revisions', ['draft_id' => $d['id'], 'version' => $v, 'title' => $title, 'body' => $body, 'edited_by' => $u['id'], 'at' => $now]);
            audit_record($u, 'naskah.sunting', 'draft', $d['id'], ['version' => $v, 'hash' => $h]);
        }
        send_json(draft_out(get_draft_row((int)$id), $u, true));
    });
    $r->add('POST', '/api/drafts/(\d+)/transition', function ($id) {
        $u = current_user();
        $d = get_draft_row((int)$id);
        $b = json_body();
        $to = (string)($b['to_status'] ?? ''); $comment = (string)($b['comment'] ?? '');
        if (mb_strlen($comment) > 2000) fail(422, 'Catatan maksimal 2000 karakter');
        if (!in_array($to, ['draf', 'ditinjau', 'disetujui', 'tayang', 'ditarik'], true)) fail(422, 'Status tujuan tidak dikenal');
        try { $rule = check_transition($d, $to, $u, $comment); }
        catch (HttpError $e) {
            audit_record($u, 'naskah.transisi_ditolak', 'draft', $d['id'], ['from' => $d['status'], 'to' => $to, 'reason' => $e->getMessage()]);
            commit_then_fail($e->status, $e->detail);
        }
        $now = now_iso(); $sig = null;
        if (!empty($rule['sign'])) {
            $sig = sign(signing_message($d, (int)$u['id']));
            q('UPDATE drafts SET approved_by = ?, approved_at = ?, approval_signature = ? WHERE id = ?', [$u['id'], $now, $sig, $d['id']]);
        }
        if ($to === 'draf') q('UPDATE drafts SET approved_by = NULL, approved_at = NULL, approval_signature = NULL WHERE id = ?', [$d['id']]);
        $role = '';
        foreach ($rule['roles'] as $rr) if (has_role($u, $rr)) { $role = $rr; break; }
        insert('approval_steps', ['draft_id' => $d['id'], 'from_status' => $d['status'], 'to_status' => $to, 'actor_id' => $u['id'], 'actor_role' => $role,
            'comment' => trim($comment), 'version' => $d['version'], 'content_hash' => $d['content_hash'], 'signature' => $sig, 'at' => $now]);
        q('UPDATE drafts SET status = ?, updated_at = ? WHERE id = ?', [$to, $now, $d['id']]);
        if ($to === 'tayang') q("UPDATE calendar_entries SET status = 'tayang' WHERE draft_id = ? AND status = 'rencana'", [$d['id']]);
        elseif ($to === 'ditarik') q("UPDATE calendar_entries SET status = 'batal' WHERE draft_id = ? AND status = 'rencana'", [$d['id']]);
        if ($to === 'ditarik') q("UPDATE distributions SET status = 'ditarik', taken_down_at = ?, takedown_reason = ? WHERE draft_id = ? AND status = 'terkirim'", [$now, 'Naskah ditarik: ' . mb_substr(trim($comment), 0, 200), $d['id']]);
        if ($to === 'ditarik') q("UPDATE distributions SET status = 'batal' WHERE draft_id = ? AND status = 'terjadwal'", [$d['id']]);
        $link = '/editorial?id=' . $d['id'];
        if ($to === 'ditinjau') notify(users_with_roles('redaktur', 'pimpinan'), 'persetujuan', "Menunggu tinjauan: {$d['title']}", $link, (int)$u['id']);
        if ($to === 'disetujui') { notify([(int)$d['created_by']], 'persetujuan', "Disetujui: {$d['title']}", $link, (int)$u['id']); notify(users_with_roles('operator'), 'distribusi', "Siap disalurkan: {$d['title']}", '/distribusi', (int)$u['id']); }
        if ($to === 'draf') notify([(int)$d['created_by']], 'persetujuan', "Dikembalikan untuk revisi: {$d['title']}" . (trim($comment) !== '' ? ' — ' . mb_substr(trim($comment), 0, 120) : ''), $link, (int)$u['id']);
        audit_record($u, 'naskah.transisi', 'draft', $d['id'], ['from' => $d['status'], 'to' => $to, 'version' => (int)$d['version'], 'hash' => $d['content_hash'], 'signed' => $sig !== null]);
        send_json(draft_out(get_draft_row((int)$id), $u, true));
    });
    $r->add('POST', '/api/drafts/(\d+)/schedule', function ($id) {
        $u = require_roles('redaktur', 'operator', 'pimpinan');
        $d = get_draft_row((int)$id);
        $b = json_body();
        $codes = list_field($b, 'channel_codes') ?? [];
        if (!$codes) fail(422, 'Pilih minimal satu kanal');
        $planned = parse_input_datetime((string)($b['planned_at'] ?? ''));
        if (!$planned) fail(422, 'Waktu tayang tidak valid');
        if (in_array($d['status'], ['tayang', 'ditarik'], true)) fail(409, "Naskah berstatus {$d['status']} tidak dapat dijadwalkan");
        $codes = array_values(array_unique($codes)); sort($codes, SORT_STRING);
        $chs = [];
        foreach ($codes as $c) { $ch = one('SELECT * FROM channels WHERE code = ? AND active = 1', [$c]); if ($ch) $chs[] = $ch; }
        $unknown = array_diff($codes, array_column($chs, 'code'));
        if ($unknown) fail(422, 'Kanal tidak dikenal: ' . implode(', ', $unknown));
        $pts = ts_from_iso($planned);
        $conf = []; $entries = [];
        foreach ($chs as $ch) {
            $clash = one("SELECT e.planned_at, d.title FROM calendar_entries e JOIN drafts d ON d.id = e.draft_id WHERE e.channel_id = ? AND e.status = 'rencana'
                AND e.draft_id != ? AND e.planned_at > ? AND e.planned_at < ? LIMIT 1", [$ch['id'], $d['id'], iso_from_ts($pts - 1800), iso_from_ts($pts + 1800)]);
            if ($clash) $conf[] = "{$ch['name']}: berdekatan dengan \"{$clash['title']}\" (" . gmdate('d-m H:i', (int)ts_from_iso($clash['planned_at'])) . ' UTC)';
            $eid = insert('calendar_entries', ['draft_id' => $d['id'], 'channel_id' => $ch['id'], 'planned_at' => $planned, 'status' => 'rencana', 'created_by' => $u['id'], 'created_at' => now_iso()]);
            $entries[] = calendar_out(one('SELECT * FROM calendar_entries WHERE id = ?', [$eid]), $d);
        }
        audit_record($u, 'kalender.jadwalkan', 'draft', $d['id'], ['channels' => $codes, 'planned_at' => $planned, 'conflicts' => count($conf)]);
        send_json(['entries' => $entries, 'conflicts' => $conf], 201);
    });
    $r->add('DELETE', '/api/calendar/(\d+)', function ($id) {
        $u = require_roles('redaktur', 'operator', 'pimpinan');
        $e = one('SELECT * FROM calendar_entries WHERE id = ?', [(int)$id]);
        if ($e === null) fail(404, 'Jadwal tidak ditemukan');
        if ($e['status'] !== 'rencana') fail(409, 'Hanya jadwal berstatus rencana yang dapat dibatalkan');
        q("UPDATE calendar_entries SET status = 'batal' WHERE id = ?", [$e['id']]);
        audit_record($u, 'kalender.batal', 'calendar', $e['id']);
        $e['status'] = 'batal';
        send_json(calendar_out($e, get_draft_row((int)$e['draft_id'])));
    });
    $r->add('GET', '/api/calendar', function () {
        current_user();
        $s = qparam('start') ? parse_input_datetime(qparam('start')) : iso_from_ts(microtime(true) - 7 * 86400);
        if (!$s) fail(422, 'start tidak valid');
        $e = qparam('end') ? parse_input_datetime(qparam('end')) : iso_from_ts(ts_from_iso($s) + 37 * 86400);
        if (!$e) fail(422, 'end tidak valid');
        $rows = all('SELECT e.*, d.id d_id, d.title d_title, d.status d_status FROM calendar_entries e JOIN drafts d ON d.id = e.draft_id
            WHERE e.planned_at >= ? AND e.planned_at < ? ORDER BY e.planned_at', [$s, $e]);
        send_json(array_map(fn($x) => calendar_out($x, ['id' => $x['d_id'], 'title' => $x['d_title'], 'status' => $x['d_status']]), $rows));
    });

    // ---------- repositori ----------
    $r->add('GET', '/api/documents', function () {
        $u = current_user();
        $limit = qint('limit', 50, 1, 200); $offset = qint('offset', 0, 0, PHP_INT_MAX);
        $unitId = null;
        if ($uc = qparam('unit_code')) { $unit = one('SELECT id FROM units WHERE code = ?', [$uc]); if (!$unit) { send_json(['total' => 0, 'items' => []]); return; } $unitId = (int)$unit['id']; }
        $qq = qparam('q');
        $rows = search_documents($u, $qq, qparam('classification'), $unitId, qparam('tag'), qparam('status'));
        send_json(['total' => count($rows), 'items' => array_map(fn($d) => doc_out($u, $d, $qq), array_slice($rows, $offset, $limit))]);
    });
    $r->add('POST', '/api/documents', function () {
        $u = current_user();
        $f = fn($k) => isset($_POST[$k]) ? (string)$_POST[$k] : null;
        $req = ['title', 'classification', 'owner_unit_code', 'period_start', 'period_end', 'origin'];
        $missing = array_values(array_filter($req, fn($k) => trim((string)$f($k)) === ''));
        if (!isset($_FILES['file'])) fail(422, 'Kolom file wajib diisi');
        if ($missing) fail(422, ['message' => 'Metadata wajib belum lengkap', 'missing' => $missing]);
        $lvl = parse_level($f('classification'));
        $ps = valid_date(trim($f('period_start'))); $pe = valid_date(trim($f('period_end')));
        if ($pe < $ps) fail(422, 'period_end lebih awal dari period_start');
        if (LEVELS[$lvl] > clearance($u)) fail(403, 'Tidak dapat membuat dokumen di atas clearance Anda');
        $unit = one('SELECT * FROM units WHERE code = ?', [$f('owner_unit_code')]);
        if ($unit === null) fail(422, 'Unit tidak ditemukan: ' . $f('owner_unit_code'));
        if ((int)$unit['id'] !== (int)$u['unit_id'] && !has_role($u, 'arsiparis')) fail(403, 'Hanya dapat mengunggah atas nama unit sendiri');
        [$fn, $mime, $data] = read_upload();
        $existed = is_file(blob_path(hash('sha256', $data)));
        $sha = blob_put($data);
        $now = now_iso();
        $id = insert('documents', ['title' => trim($f('title')), 'description' => trim((string)$f('description')), 'owner_unit_id' => $unit['id'], 'classification' => $lvl,
            'period_start' => $ps, 'period_end' => $pe, 'origin' => trim($f('origin')), 'retention_class' => trim((string)$f('retention_class')) ?: 'UMUM',
            'tags' => jenc(parse_tags((string)$f('tags'))), 'status' => 'active', 'current_version_no' => 1, 'created_by' => $u['id'], 'created_at' => $now, 'updated_at' => $now]);
        insert('document_versions', ['document_id' => $id, 'version_no' => 1, 'filename' => $fn, 'mime' => $mime, 'size' => strlen($data), 'sha256' => $sha,
            'text_content' => extract_text($fn, $mime, $data), 'note' => 'Versi awal', 'created_by' => $u['id'], 'created_at' => $now]);
        audit_record($u, 'dokumen.unggah', 'document', $id, ['title' => trim($f('title')), 'classification' => $lvl, 'sha256' => $sha, 'dedup' => $existed]);
        send_json(doc_detail($u, $id), 201);
    });
    $r->add('GET', '/api/documents/(\d+)', function ($id) {
        $u = current_user();
        $doc = load_readable($u, (int)$id);
        audit_record($u, 'dokumen.lihat', 'document', $doc['id']);
        send_json(doc_detail($u, (int)$id));
    });
    $r->add('PATCH', '/api/documents/(\d+)', function ($id) {
        $u = current_user();
        $doc = load_readable($u, (int)$id);
        if (!can_write($u, $doc)) fail(403, 'Tidak berwenang mengubah dokumen ini');
        $b = json_body(); $ch = []; $set = [];
        if (isset($b['classification'])) {
            $nl = parse_level((string)$b['classification']);
            if ($nl !== $doc['classification']) {
                if (!can_manage($u, $doc)) fail(403, 'Reklasifikasi hanya oleh pimpinan unit/arsiparis');
                if (LEVELS[$nl] > clearance($u)) fail(403, 'Tidak dapat menaikkan di atas clearance Anda');
                $ch['classification'] = [$doc['classification'], $nl]; $set['classification'] = $nl;
            }
        }
        if (isset($b['title'])) str_field($b, 'title', 3, 300);
        foreach (['title', 'description', 'retention_class'] as $k) {
            if (isset($b[$k]) && (string)$b[$k] !== $doc[$k]) { $ch[$k] = [$doc[$k], (string)$b[$k]]; $set[$k] = (string)$b[$k]; }
        }
        if (isset($b['tags'])) { $nt = parse_tags(implode(',', list_field($b, 'tags'))); $ch['tags'] = [jdec($doc['tags']), $nt]; $set['tags'] = jenc($nt); }
        if ($ch) {
            $set['updated_at'] = now_iso();
            foreach ($set as $k => $v) q("UPDATE documents SET $k = ? WHERE id = ?", [$v, $doc['id']]);
            audit_record($u, 'dokumen.ubah_metadata', 'document', $doc['id'], $ch);
        }
        send_json(doc_detail($u, (int)$id));
    });
    $r->add('POST', '/api/documents/(\d+)/checkout', function ($id) {
        $u = current_user();
        $doc = load_readable($u, (int)$id);
        if (!can_write($u, $doc)) fail(403, 'Tidak berwenang melakukan check-out');
        if ($doc['status'] === 'checked_out') fail(409, 'Dokumen sedang di-check-out pengguna lain');
        if ($doc['status'] !== 'active') fail(409, "Status dokumen {$doc['status']} tidak dapat di-check-out");
        q("UPDATE documents SET status = 'checked_out', checked_out_by = ?, updated_at = ? WHERE id = ?", [$u['id'], now_iso(), $doc['id']]);
        audit_record($u, 'dokumen.checkout', 'document', $doc['id']);
        send_json(doc_detail($u, (int)$id));
    });
    $r->add('POST', '/api/documents/(\d+)/cancel-checkout', function ($id) {
        $u = current_user();
        $doc = load_readable($u, (int)$id);
        if ($doc['status'] !== 'checked_out' || ((int)$doc['checked_out_by'] !== (int)$u['id'] && !can_manage($u, $doc))) fail(409, 'Tidak ada check-out milik Anda pada dokumen ini');
        q("UPDATE documents SET status = 'active', checked_out_by = NULL, updated_at = ? WHERE id = ?", [now_iso(), $doc['id']]);
        audit_record($u, 'dokumen.batal_checkout', 'document', $doc['id']);
        send_json(doc_detail($u, (int)$id));
    });
    $r->add('POST', '/api/documents/(\d+)/versions', function ($id) {
        $u = current_user();
        $doc = load_readable($u, (int)$id);
        if ($doc['status'] !== 'checked_out' || (int)$doc['checked_out_by'] !== (int)$u['id']) fail(409, 'Lakukan check-out terlebih dahulu sebelum check-in versi baru');
        [$fn, $mime, $data] = read_upload();
        $sha = hash('sha256', $data);
        if ($sha === val('SELECT sha256 FROM document_versions WHERE document_id = ? AND version_no = ?', [$doc['id'], $doc['current_version_no']])) fail(409, 'Isi berkas identik dengan versi terakhir');
        $existed = is_file(blob_path($sha));
        blob_put($data);
        $now = now_iso(); $no = (int)$doc['current_version_no'] + 1;
        insert('document_versions', ['document_id' => $doc['id'], 'version_no' => $no, 'filename' => $fn, 'mime' => $mime, 'size' => strlen($data), 'sha256' => $sha,
            'text_content' => extract_text($fn, $mime, $data), 'note' => mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500), 'created_by' => $u['id'], 'created_at' => $now]);
        q("UPDATE documents SET current_version_no = ?, status = 'active', checked_out_by = NULL, updated_at = ? WHERE id = ?", [$no, $now, $doc['id']]);
        audit_record($u, 'dokumen.checkin', 'document', $doc['id'], ['version' => $no, 'sha256' => $sha, 'dedup' => $existed]);
        send_json(doc_detail($u, (int)$id), 201);
    });
    $r->add('GET', '/api/documents/(\d+)/versions/(\d+)/download', function ($id, $no) {
        $u = current_user();
        $doc = load_readable($u, (int)$id);
        $ver = one('SELECT * FROM document_versions WHERE document_id = ? AND version_no = ?', [$doc['id'], (int)$no]);
        if ($ver === null) fail(404, 'Versi tidak ditemukan');
        $ip = client_ip();
        try { $data = blob_get($ver['sha256']); }
        catch (Throwable $e) {
            audit_record($u, 'dokumen.integritas_gagal', 'document', $doc['id'], ['version' => (int)$no, 'error' => $e->getMessage()]);
            commit_then_fail(500, 'Verifikasi integritas berkas gagal');
        }
        $wm = bin2hex(random_bytes(8));
        $now = now_iso();
        $mark = "{$doc['classification']} | {$u['full_name']} | NIP " . ($u['nip'] ?: '-') . " | IP $ip | " . gmdate('Y-m-d H:i:s') . " UTC | WM-$wm";
        [$payload, $emb] = apply_watermark($ver['filename'], $ver['mime'], $data, $mark);
        insert('download_logs', ['version_id' => $ver['id'], 'user_id' => $u['id'], 'ip' => $ip, 'watermark_id' => $wm, 'at' => $now]);
        audit_record($u, 'dokumen.unduh', 'document', $doc['id'], ['version' => (int)$no, 'watermark_id' => $wm, 'embedded' => $emb]);
        $GLOBALS['SISTRA_RAW'] = true;
        header('Content-Type: ' . $ver['mime']);
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($ver['filename']));
        header("X-SISTRA-Watermark: WM-$wm");
        header('X-SISTRA-Watermark-Embedded: ' . ($emb ? '1' : '0'));
        header('X-SISTRA-Source-SHA256: ' . $ver['sha256']);
        header('Content-Length: ' . strlen($payload));
        echo $payload;
    });
    $r->add('POST', '/api/documents/(\d+)/grants', function ($id) {
        $u = current_user();
        $doc = load_readable($u, (int)$id);
        if (!can_manage($u, $doc)) fail(403, 'Hanya pimpinan unit pemilik/arsiparis yang dapat memberi izin');
        $b = json_body();
        $reason = trim(str_field($b, 'reason', 5, 500));
        $days = $b['valid_days'] ?? null;
        if (!is_int($days) || $days < 1 || $days > 90) fail(422, 'Masa berlaku izin 1–90 hari');
        $g = one('SELECT * FROM users WHERE username = ?', [(string)($b['username'] ?? '')]);
        if ($g === null || !$g['active']) fail(422, 'Pengguna penerima tidak ditemukan');
        if (clearance($g) < LEVELS[$doc['classification']]) fail(422, 'Clearance penerima lebih rendah dari klasifikasi dokumen; izin tidak dapat diberikan');
        $nowTs = microtime(true);
        $gid = insert('access_grants', ['document_id' => $doc['id'], 'user_id' => $g['id'], 'reason' => $reason, 'valid_from' => iso_from_ts($nowTs),
            'valid_until' => iso_from_ts($nowTs + $days * 86400), 'granted_by' => $u['id'], 'created_at' => iso_from_ts($nowTs)]);
        audit_record($u, 'dokumen.izin_beri', 'document', $doc['id'], ['grant_id' => $gid, 'to' => $g['username'], 'days' => $days, 'reason' => $reason]);
        send_json(doc_detail($u, (int)$id), 201);
    });
    $r->add('DELETE', '/api/documents/(\d+)/grants/(\d+)', function ($id, $gid) {
        $u = current_user();
        $doc = load_readable($u, (int)$id);
        if (!can_manage($u, $doc)) fail(403, 'Tidak berwenang mencabut izin');
        $g = one('SELECT * FROM access_grants WHERE id = ?', [(int)$gid]);
        if ($g === null || (int)$g['document_id'] !== (int)$doc['id']) fail(404, 'Izin tidak ditemukan');
        if ($g['revoked_at'] === null) {
            q('UPDATE access_grants SET revoked_at = ? WHERE id = ?', [now_iso(), $g['id']]);
            audit_record($u, 'dokumen.izin_cabut', 'document', $doc['id'], ['grant_id' => (int)$g['id']]);
        }
        send_json(doc_detail($u, (int)$id));
    });
    $r->add('GET', '/api/documents/(\d+)/downloads', function ($id) {
        $u = current_user();
        $doc = load_readable($u, (int)$id);
        if (!can_manage($u, $doc)) fail(403, 'Tidak berwenang melihat log unduhan');
        $rows = all('SELECT l.*, v.version_no, u.username FROM download_logs l JOIN document_versions v ON v.id = l.version_id JOIN users u ON u.id = l.user_id
            WHERE v.document_id = ? ORDER BY l.at DESC LIMIT 500', [$doc['id']]);
        send_json(array_map(fn($x) => ['id' => (int)$x['id'], 'version_no' => (int)$x['version_no'], 'username' => $x['username'], 'ip' => $x['ip'], 'watermark_id' => $x['watermark_id'], 'at' => $x['at']], $rows));
    });
}

function validate_roles(array $roles): array
{
    $unknown = array_diff($roles, ROLES);
    if ($unknown) { sort($unknown); fail(422, 'Peran tidak dikenal: ' . implode(', ', $unknown)); }
    $r = array_values(array_unique($roles)); sort($r, SORT_STRING);
    return $r;
}
function unit_by_code(string $code): array { $u = one('SELECT * FROM units WHERE code = ?', [$code]); if ($u === null) fail(422, "Unit tidak ditemukan: $code"); return $u; }
function clean_keywords(array $kws): array
{
    $o = array_values(array_unique(array_filter(array_map(fn($k) => mb_strtolower(trim($k)), $kws), fn($k) => $k !== '')));
    sort($o, SORT_STRING);
    return $o;
}
