<?php
/** Layanan domain: audit berantai, penyerapan, metrik isu, peringatan, alur naskah, ACL repositori, penyimpanan. */
declare(strict_types=1);

// ================= Audit WORM berantai hash =================
const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

function ksort_rec($v)
{
    if (is_array($v)) {
        if (!array_is_list($v)) ksort($v, SORT_STRING);
        foreach ($v as $k => $x) $v[$k] = ksort_rec($x);
    }
    return $v;
}
function audit_canonical(array $e): string
{
    $detail = $e['detail'];
    $body = [
        'action' => $e['action'], 'actor_id' => $e['actor_id'] === null ? null : (int)$e['actor_id'],
        'actor_username' => $e['actor_username'], 'at' => $e['at'],
        'detail' => (is_array($detail) && $detail) ? ksort_rec($detail) : new stdClass(),
        'ip' => $e['ip'], 'object_id' => (string)$e['object_id'], 'object_type' => $e['object_type'],
    ];
    return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}
function audit_hash(string $prev, array $e): string { return hash('sha256', $prev . audit_canonical($e)); }

/** Catat peristiwa audit. Wajib dipanggil di dalam transaksi (BEGIN IMMEDIATE) agar rantai tidak bercabang. */
function audit_record(?array $actor, string $action, string $objectType, $objectId = '-', array $detail = [], ?string $actorUsername = null): void
{
    $prev = val('SELECT hash FROM audit_events ORDER BY seq DESC LIMIT 1') ?: GENESIS_HASH;
    $e = [
        'at' => now_iso(), 'actor_id' => $actor['id'] ?? null,
        'actor_username' => $actorUsername ?? ($actor['username'] ?? 'system'),
        'action' => $action, 'object_type' => $objectType, 'object_id' => (string)$objectId,
        'ip' => PHP_SAPI === 'cli' ? '-' : client_ip(), 'detail' => $detail,
    ];
    $e['hash'] = audit_hash($prev, $e);
    insert('audit_events', [
        'at' => $e['at'], 'actor_id' => $e['actor_id'], 'actor_username' => $e['actor_username'], 'action' => $action,
        'object_type' => $objectType, 'object_id' => $e['object_id'], 'ip' => $e['ip'],
        'detail' => json_encode($detail ? $detail : new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
        'prev_hash' => $prev, 'hash' => $e['hash'],
    ]);
}
function audit_verify(): array
{
    $prev = GENESIS_HASH; $checked = 0; $last = 0;
    while (true) {
        $rows = all('SELECT * FROM audit_events WHERE seq > ? ORDER BY seq LIMIT 5000', [$last]);
        if (!$rows) break;
        foreach ($rows as $r) {
            if ($r['prev_hash'] !== $prev) return ['ok' => false, 'checked' => $checked, 'first_broken_seq' => (int)$r['seq'], 'reason' => 'prev_hash tidak cocok (baris hilang/disisipkan)', 'head_hash' => null];
            $r['detail'] = json_decode($r['detail'], true) ?? [];
            if (audit_hash($prev, $r) !== $r['hash']) return ['ok' => false, 'checked' => $checked, 'first_broken_seq' => (int)$r['seq'], 'reason' => 'hash tidak cocok (isi peristiwa diubah)', 'head_hash' => null];
            $prev = $r['hash']; $checked++; $last = (int)$r['seq'];
        }
    }
    return ['ok' => true, 'checked' => $checked, 'first_broken_seq' => null, 'reason' => null, 'head_hash' => $checked ? $prev : null];
}

/** Jalankan fungsi dalam transaksi tulis tunggal (SQLite BEGIN IMMEDIATE). */
function tx(callable $fn)
{
    if (in_tx()) return $fn();
    tx_begin();
    try { $r = $fn(); tx_end(true); return $r; }
    catch (Throwable $e) { tx_end(false); throw $e; }
}

// ================= Analitik & metrik =================
function load_taxonomy(): array
{
    $tax = []; $ids = [];
    foreach (all('SELECT id, code, keywords FROM issues WHERE active = 1') as $i) { $tax[$i['code']] = jdec($i['keywords']); $ids[$i['code']] = (int)$i['id']; }
    return [$tax, $ids];
}

function day_key(string $iso): string { return substr($iso, 0, 10); }
function day_start_iso(float $ts): string { return gmdate('Y-m-d', (int)$ts) . 'T00:00:00.000000Z'; }

function issue_metrics(int $issueId, int $days): array
{
    $now = microtime(true);
    $startTs = (float)strtotime(gmdate('Y-m-d', (int)($now - ($days - 1) * 86400)) . 'T00:00:00Z');
    $rows = all('SELECT c.published_at, c.sentiment_label, c.sentiment_score, c.province_code FROM content_items c
        JOIN content_issues ci ON ci.content_id = c.id WHERE ci.issue_id = ? AND c.published_at >= ? AND c.published_at <= ?',
        [$issueId, iso_from_ts($startTs), iso_from_ts($now)]);
    $b = [];
    for ($d = 0; $d < $days; $d++) { $k = gmdate('Y-m-d', (int)$startTs + $d * 86400); $b[$k] = ['date' => $k, 'negative' => 0, 'positive' => 0, 'volume' => 0, 's' => 0.0]; }
    $prov = [];
    foreach ($rows as $r) {
        $k = day_key($r['published_at']);
        if (!isset($b[$k])) continue;
        $b[$k]['volume']++; $b[$k]['s'] += (float)$r['sentiment_score'];
        if ($r['sentiment_label'] === 'negatif') $b[$k]['negative']++;
        elseif ($r['sentiment_label'] === 'positif') $b[$k]['positive']++;
        if ($r['province_code']) $prov[$r['province_code']] = ($prov[$r['province_code']] ?? 0) + 1;
    }
    $series = [];
    foreach ($b as $x) {
        $v = $x['volume'];
        $series[] = ['date' => $x['date'], 'negative' => $x['negative'], 'positive' => $x['positive'], 'volume' => $v,
            'negative_ratio' => $v ? round($x['negative'] / $v, 3) : 0.0, 'sentiment_avg' => $v ? round($x['s'] / $v, 3) : 0.0];
    }
    arsort($prov);
    return ['issue_id' => $issueId, 'days' => $days, 'series' => $series, 'provinces' => $prov ?: new stdClass()];
}

function issue_window_stats(string $since, string $until): array
{
    $rows = all('SELECT ci.issue_id, c.sentiment_label, c.province_code, COUNT(*) n FROM content_issues ci
        JOIN content_items c ON c.id = ci.content_id WHERE c.published_at >= ? AND c.published_at < ?
        GROUP BY ci.issue_id, c.sentiment_label, c.province_code', [$since, $until]);
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['issue_id'];
        $out[$id] ??= ['volume' => 0, 'negative' => 0, 'provinces' => []];
        $out[$id]['volume'] += (int)$r['n'];
        if ($r['sentiment_label'] === 'negatif') $out[$id]['negative'] += (int)$r['n'];
        if ($r['province_code']) $out[$id]['provinces'][$r['province_code']] = true;
    }
    return $out;
}

// ================= Peringatan =================
const ALERT_LEVELS = ['hijau' => 0, 'kuning' => 1, 'oranye' => 2, 'merah' => 3];
const OPEN_STATUSES = ['open', 'ack', 'in_progress'];
const ALERT_TRANSITIONS = ['ack' => [['open'], 'ack'], 'tangani' => [['open', 'ack'], 'in_progress'], 'selesai' => [['open', 'ack', 'in_progress'], 'resolved']];

function pct(float $x): string { return round($x * 100) . '%'; }
function classify_level(int $vol, float $g, float $neg): array
{
    $gs = number_format($g, 1, '.', '');
    if ($vol >= 20 && $g >= 3.0 && $neg >= 0.6) return ['merah', "Lonjakan {$gs}× dengan sentimen negatif " . pct($neg) . " (volume $vol)"];
    if ($vol >= 10 && ($g >= 2.0 || $neg >= 0.6)) return ['oranye', "Pertumbuhan {$gs}×, sentimen negatif " . pct($neg) . " (volume $vol)"];
    if ($vol >= 5 && ($g >= 1.5 || $neg >= 0.5)) return ['kuning', "Indikasi peningkatan: {$gs}×, negatif " . pct($neg) . " (volume $vol)"];
    return ['hijau', 'Normal'];
}
function assess_issues(?float $now = null): array
{
    $now ??= microtime(true);
    $window = issue_window_stats(iso_from_ts($now - 86400), iso_from_ts($now));
    $base = issue_window_stats(iso_from_ts($now - 8 * 86400), iso_from_ts($now - 86400));
    $out = [];
    foreach (all('SELECT id FROM issues WHERE active = 1 ORDER BY id') as $i) {
        $id = (int)$i['id'];
        $w = $window[$id] ?? ['volume' => 0, 'negative' => 0, 'provinces' => []];
        $bd = ($base[$id]['volume'] ?? 0) / 7.0;
        $vol = $w['volume'];
        $g = $vol / max($bd, 1.0);
        $neg = $vol ? $w['negative'] / $vol : 0.0;
        [$level, $reason] = classify_level($vol, $g, $neg);
        $out[] = ['issue_id' => $id, 'level' => $level, 'reason' => $reason, 'volume_24h' => $vol, 'baseline_daily' => round($bd, 2),
            'growth' => round($g, 2), 'negative_ratio' => round($neg, 3), 'province_spread' => count($w['provinces'])];
    }
    return $out;
}
function metrics_of(array $a): array
{
    return ['volume_24h' => $a['volume_24h'], 'baseline_daily' => $a['baseline_daily'], 'growth' => $a['growth'],
        'negative_ratio' => $a['negative_ratio'], 'province_spread' => $a['province_spread']];
}
function evaluate_alerts(?array $actor, ?float $nowTs = null): array
{
    $nowTs ??= microtime(true);
    $now = iso_from_ts($nowTs);
    $created = []; $escalated = [];
    foreach (assess_issues($nowTs) as $a) {
        if ($a['level'] === 'hijau') continue;
        $m = metrics_of($a);
        $cur = one("SELECT * FROM alerts WHERE issue_id = ? AND status IN ('open','ack','in_progress') ORDER BY id DESC LIMIT 1", [$a['issue_id']]);
        if ($cur === null) {
            $id = insert('alerts', ['issue_id' => $a['issue_id'], 'level' => $a['level'], 'status' => 'open', 'reason' => $a['reason'],
                'metrics' => jenc($m), 'triggered_at' => $now, 'updated_at' => $now]);
            insert('alert_events', ['alert_id' => $id, 'at' => $now, 'action' => 'dibuat', 'level' => $a['level'], 'note' => $a['reason']]);
            $created[] = $id;
            audit_record($actor, 'peringatan.dibuat', 'alert', $id, ['issue_id' => $a['issue_id'], 'level' => $a['level']] + $m);
        } elseif (ALERT_LEVELS[$a['level']] > ALERT_LEVELS[$cur['level']]) {
            q('UPDATE alerts SET level = ?, reason = ?, metrics = ?, updated_at = ? WHERE id = ?', [$a['level'], $a['reason'], jenc($m), $now, $cur['id']]);
            insert('alert_events', ['alert_id' => $cur['id'], 'at' => $now, 'action' => 'eskalasi', 'level' => $a['level'], 'note' => "{$cur['level']} → {$a['level']}: {$a['reason']}"]);
            $escalated[] = (int)$cur['id'];
            audit_record($actor, 'peringatan.eskalasi', 'alert', $cur['id'], ['from' => $cur['level'], 'to' => $a['level']] + $m);
        } else {
            q('UPDATE alerts SET metrics = ? WHERE id = ?', [jenc($m), $cur['id']]);
        }
    }
    return ['created' => $created, 'escalated' => $escalated, 'evaluated_at' => $now];
}

// ================= Penyerapan =================
const MAX_FEED_BYTES = 5 * 1024 * 1024;

function enrich_item(int $id, string $title, string $body, ?string $sourceProvince, array $tax, array $ids): void
{
    $text = "$title. $body";
    $s = sentiment($text);
    $g = geotag($text);
    q('UPDATE content_items SET sentiment_label = ?, sentiment_score = ?, provinces = ?, province_code = ? WHERE id = ?',
        [$s['label'], $s['score'], jenc($g['provinces']), $g['primary'] ?? $sourceProvince, $id]);
    foreach (classify_issues($text, $tax) as $m) {
        insert('content_issues', ['content_id' => $id, 'issue_id' => $ids[$m['code']], 'confidence' => $m['confidence'], 'assigned_by' => 'model', 'created_at' => now_iso()]);
    }
}

function ingest_items(array $source, array $items, ?array $actor, bool $evaluate = true, ?float $nowTs = null): array
{
    $nowTs ??= microtime(true);
    $now = iso_from_ts($nowTs);
    $r = ['received' => count($items), 'inserted' => 0, 'exact_duplicates' => 0, 'near_duplicates' => 0, 'skipped' => 0, 'alerts_created' => [], 'alerts_escalated' => []];
    [$tax, $ids] = load_taxonomy();
    $cands = [];
    foreach (all('SELECT id, simhash, published_at, title, body FROM content_items WHERE published_at >= ? AND duplicate_of IS NULL ORDER BY id DESC LIMIT 5000',
        [iso_from_ts($nowTs - 10 * 86400)]) as $c) {
        $cands[] = [(int)$c['id'], (int)$c['simhash'], ts_from_iso($c['published_at']), shingles(normalize_text($c['title'], $c['body']))];
    }
    $seen = [];
    foreach ($items as $raw) {
        $title = mb_substr(trim((string)($raw['title'] ?? '')), 0, 500);
        $body = trim((string)($raw['body'] ?? ''));
        if ($title === '' && $body === '') { $r['skipped']++; continue; }
        if ($title === '') $title = mb_substr($body, 0, 120);
        $ch = content_hash_norm($title, $body);
        if (isset($seen[$ch]) || val('SELECT id FROM content_items WHERE content_hash = ?', [$ch])) { $r['exact_duplicates']++; continue; }
        $ext = mb_substr(($raw['external_id'] ?? '') ?: (($raw['url'] ?? '') ?: $ch), 0, 300);
        if (val('SELECT id FROM content_items WHERE source_id = ? AND external_id = ?', [$source['id'], $ext])) { $r['exact_duplicates']++; continue; }
        $seen[$ch] = true;
        $pubTs = ts_from_iso($raw['published_at'] ?? null) ?? $nowTs;
        if ($pubTs > $nowTs + 300) $pubTs = $nowTs;
        $norm = normalize_text($title, $body);
        $sh = simhash($norm);
        $set = shingles($norm);
        $dup = null;
        foreach ($cands as [$cid, $csh, $cpub, $cset]) {
            if (abs($cpub - $pubTs) <= 3 * 86400 && hamming($sh, $csh) <= SIMHASH_PREFILTER && jaccard($set, $cset) >= JACCARD_THRESHOLD) { $dup = $cid; break; }
        }
        $id = insert('content_items', ['source_id' => $source['id'], 'external_id' => $ext, 'url' => mb_substr((string)($raw['url'] ?? ''), 0, 1000),
            'title' => $title, 'body' => $body, 'author' => mb_substr((string)($raw['author'] ?? ''), 0, 200), 'published_at' => iso_from_ts($pubTs),
            'ingested_at' => $now, 'content_hash' => $ch, 'simhash' => $sh, 'duplicate_of' => $dup]);
        enrich_item($id, $title, $body, $source['province_code'], $tax, $ids);
        if ($dup === null) { $cands[] = [$id, $sh, $pubTs, $set]; $r['inserted']++; } else { $r['near_duplicates']++; }
    }
    q('UPDATE sources SET last_fetched_at = ? WHERE id = ?', [$now, $source['id']]);
    if ($evaluate && ($r['inserted'] || $r['near_duplicates'])) {
        $ev = evaluate_alerts($actor, $nowTs);
        $r['alerts_created'] = $ev['created']; $r['alerts_escalated'] = $ev['escalated'];
    }
    $d = $r; unset($d['alerts_created'], $d['alerts_escalated']);
    audit_record($actor, 'penyerapan.batch', 'source', $source['id'], $d);
    return $r;
}

function assert_public_host(string $url): array
{
    $p = parse_url($url);
    $host = $p['host'] ?? '';
    if ($host === '') throw new InvalidArgumentException('URL sumber tidak valid');
    $host = trim($host, '[]');
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : [];
    if (!$ips) {
        $recs = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        foreach ($recs as $rr) { if (isset($rr['ip'])) $ips[] = $rr['ip']; if (isset($rr['ipv6'])) $ips[] = $rr['ipv6']; }
        if (!$ips) { $g = gethostbynamel($host); if ($g) $ips = $g; }
    }
    if (!$ips) throw new InvalidArgumentException("Host tidak dapat di-resolve: $host");
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || str_starts_with($ip, '169.254.') || preg_match('/^(fe80|fc|fd|ff)/i', $ip)) {
            throw new InvalidArgumentException('URL sumber mengarah ke alamat internal dan ditolak');
        }
    }
    return [$host, $ips[0]];
}
function fetch_feed(string $url): array
{
    if (!preg_match('#^https?://#i', $url)) throw new InvalidArgumentException('URL sumber harus http(s)');
    [$host, $ip] = assert_public_host($url);
    $port = parse_url($url, PHP_URL_PORT) ?: (stripos($url, 'https://') === 0 ? 443 : 80);
    $buf = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'SISTRA-Ingest/0.1',
        CURLOPT_RESOLVE => ["$host:$port:$ip"], CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$buf) { $buf .= $chunk; return strlen($buf) > MAX_FEED_BYTES ? 0 : strlen($chunk); },
    ]);
    $ok = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);
    if (strlen($buf) > MAX_FEED_BYTES) throw new InvalidArgumentException('Feed melebihi batas ukuran 5 MB');
    if ($ok === false || $errno) throw new HttpError(502, 'Gagal mengambil feed: ConnectError');
    if ($code >= 400 || $code < 200 || $code >= 300) throw new HttpError(502, 'Gagal mengambil feed: HTTPStatusError');
    return parse_any($buf);
}

// ================= Alur naskah =================
const AUTHOR_ROLES = ['redaktur', 'produser', 'analis'];
const AI_LABEL = 'Konten ini disusun dengan bantuan AI dan telah ditinjau oleh redaksi.';
const DRAFT_RULES = [
    'draf>ditinjau' => ['roles' => AUTHOR_ROLES, 'author_only' => true],
    'ditinjau>draf' => ['roles' => ['redaktur', 'pimpinan'], 'not_author' => true, 'comment' => true],
    'ditinjau>disetujui' => ['roles' => ['pimpinan'], 'not_author' => true, 'sign' => true],
    'disetujui>tayang' => ['roles' => ['operator'], 'verify' => true],
    'disetujui>ditarik' => ['roles' => ['pimpinan'], 'comment' => true],
    'tayang>ditarik' => ['roles' => ['pimpinan', 'redaktur'], 'comment' => true],
];
function draft_hash(string $title, string $body): string { return hash('sha256', "$title\n\n$body"); }
function signing_message(array $d, int $approver): string { return "sistra-draft|{$d['id']}|v{$d['version']}|{$d['content_hash']}|$approver"; }
function allowed_actions(array $d, array $u): array
{
    $out = [];
    foreach (DRAFT_RULES as $k => $r) {
        [$src, $dst] = explode('>', $k);
        if ($src !== $d['status'] || !has_role($u, ...$r['roles'])) continue;
        if (!empty($r['author_only']) && (int)$d['created_by'] !== (int)$u['id']) continue;
        if (!empty($r['not_author']) && (int)$d['created_by'] === (int)$u['id']) continue;
        $out[] = $dst;
    }
    return $out;
}
function check_transition(array $d, string $to, array $u, string $comment): array
{
    $r = DRAFT_RULES[$d['status'] . '>' . $to] ?? null;
    if ($r === null) fail(409, "Transisi '{$d['status']}' → '$to' tidak diizinkan");
    if (!has_role($u, ...$r['roles'])) fail(403, 'Transisi ini memerlukan peran: ' . implode(', ', $r['roles']));
    if (!empty($r['author_only']) && (int)$d['created_by'] !== (int)$u['id']) fail(403, 'Hanya penulis yang dapat mengajukan naskah ini');
    if (!empty($r['not_author']) && (int)$d['created_by'] === (int)$u['id']) fail(403, 'Penulis tidak boleh meninjau/menyetujui naskahnya sendiri');
    if (!empty($r['comment']) && trim($comment) === '') fail(422, 'Catatan wajib diisi untuk transisi ini');
    if (!empty($r['verify'])) {
        if ($d['approved_by'] === null || !$d['approval_signature']) fail(409, 'Naskah belum memiliki tanda tangan persetujuan');
        if (draft_hash($d['title'], $d['body']) !== $d['content_hash'] || !verify_signature(signing_message($d, (int)$d['approved_by']), $d['approval_signature'])) {
            fail(409, 'Verifikasi tanda tangan gagal: isi naskah berubah sejak disetujui');
        }
    }
    return $r;
}

// ================= Repositori: ACL =================
function active_grant(array $u, array $doc): ?array
{
    $now = now_iso();
    return one('SELECT * FROM access_grants WHERE document_id = ? AND user_id = ? AND revoked_at IS NULL AND valid_from <= ? AND valid_until > ? LIMIT 1',
        [$doc['id'], $u['id'], $now, $now]);
}
function can_read(array $u, array $doc): bool
{
    $lvl = LEVELS[$doc['classification']];
    if (clearance($u) < $lvl) return false;
    if ($lvl <= 1) return true;
    if ((int)$doc['created_by'] === (int)$u['id']) return true;
    if ($lvl === 2 && (int)$doc['owner_unit_id'] === (int)$u['unit_id']) return true;
    return active_grant($u, $doc) !== null;
}
function can_write(array $u, array $doc): bool
{
    return can_read($u, $doc) && ((int)$doc['owner_unit_id'] === (int)$u['unit_id'] || (int)$doc['created_by'] === (int)$u['id'] || has_role($u, 'arsiparis'));
}
function can_manage(array $u, array $doc): bool
{
    if (!can_read($u, $doc)) return false;
    return has_role($u, 'arsiparis') || (has_role($u, 'pimpinan') && (int)$doc['owner_unit_id'] === (int)$u['unit_id']);
}
function like_escape(string $t): string { return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $t) . '%'; }

function search_documents(array $u, ?string $qq = null, ?string $cls = null, ?int $unitId = null, ?string $tag = null, ?string $status = null): array
{
    $allowed = array_keys(array_filter(LEVELS, fn($l) => $l <= clearance($u)));
    $sql = 'SELECT d.*, v.id v_id, v.version_no v_version_no, v.filename v_filename, v.mime v_mime, v.size v_size, v.sha256 v_sha256, v.note v_note,
        v.created_by v_created_by, v.created_at v_created_at, v.text_content v_text FROM documents d
        JOIN document_versions v ON v.document_id = d.id AND v.version_no = d.current_version_no
        WHERE d.classification IN (' . implode(',', array_fill(0, count($allowed), '?')) . ')';
    $p = $allowed;
    if ($cls) { $sql .= ' AND d.classification = ?'; $p[] = parse_level($cls); }
    if ($unitId) { $sql .= ' AND d.owner_unit_id = ?'; $p[] = $unitId; }
    if ($status) { $sql .= ' AND d.status = ?'; $p[] = $status; }
    foreach (preg_split('/\s+/', trim((string)$qq), -1, PREG_SPLIT_NO_EMPTY) as $t) {
        $sql .= " AND (d.title LIKE ? ESCAPE '\\' OR d.description LIKE ? ESCAPE '\\' OR v.text_content LIKE ? ESCAPE '\\')";
        $pat = like_escape($t); array_push($p, $pat, $pat, $pat);
    }
    $sql .= ' ORDER BY d.updated_at DESC LIMIT 2000';
    $out = [];
    foreach (all($sql, $p) as $row) {
        if ($tag && !in_array($tag, jdec($row['tags']), true)) continue;
        if (can_read($u, $row)) $out[] = $row;
    }
    return $out;
}
function make_snippet(string $text, ?string $qq, int $width = 110): string
{
    if ($text === '') return '';
    $clean = trim(preg_replace('/\s+/u', ' ', $text));
    if (!$qq) return mb_substr($clean, 0, $width * 2);
    $low = mb_strtolower($clean);
    $pos = [];
    foreach (preg_split('/\s+/', trim($qq), -1, PREG_SPLIT_NO_EMPTY) as $t) { $x = mb_strpos($low, mb_strtolower($t)); if ($x !== false) $pos[] = $x; }
    if (!$pos) return mb_substr($clean, 0, $width * 2);
    $start = max(min($pos) - $width, 0);
    $len = mb_strlen($clean);
    $end = min($start + $width * 2, $len);
    return ($start > 0 ? '…' : '') . mb_substr($clean, $start, $end - $start) . ($end < $len ? '…' : '');
}

// ================= Penyimpanan berkas =================
function blob_path(string $sha): string
{
    if (!preg_match('/^[0-9a-f]{64}$/', $sha)) throw new InvalidArgumentException('sha256 tidak valid');
    return data_dir() . '/blobs/' . substr($sha, 0, 2) . '/' . substr($sha, 2, 2) . '/' . $sha;
}
function blob_put(string $data): string
{
    $sha = hash('sha256', $data);
    $path = blob_path($sha);
    if (!is_file($path)) {
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $data);
        rename($tmp, $path);
    }
    return $sha;
}
function blob_get(string $sha): string
{
    $data = @file_get_contents(blob_path($sha));
    if ($data === false || hash('sha256', $data) !== $sha) throw new HttpError(500, 'Integritas berkas ' . substr($sha, 0, 12) . '… gagal diverifikasi');
    return $data;
}
const TEXT_MIMES = ['text/plain', 'text/markdown', 'text/csv', 'application/json', 'text/html'];
const TEXT_EXT = ['txt', 'md', 'csv', 'json', 'html', 'htm'];
function is_text_file(string $fn, string $mime): bool
{
    return in_array($mime, TEXT_MIMES, true) || str_starts_with($mime, 'text/') || in_array(strtolower(pathinfo($fn, PATHINFO_EXTENSION)), TEXT_EXT, true);
}
function extract_text(string $fn, string $mime, string $data, int $limit = 2000000): string
{
    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
    try {
        if (is_text_file($fn, $mime)) {
            $t = mb_convert_encoding($data, 'UTF-8', 'UTF-8');
            if (in_array($ext, ['html', 'htm'], true) || $mime === 'text/html') $t = preg_replace('/<[^>]+>/', ' ', $t);
            return mb_substr($t, 0, $limit);
        }
        if ($ext === 'docx' && class_exists('ZipArchive')) {
            $tmp = tempnam(sys_get_temp_dir(), 'sx');
            file_put_contents($tmp, $data);
            $z = new ZipArchive();
            $xml = '';
            if ($z->open($tmp) === true) { $xml = (string)$z->getFromName('word/document.xml'); $z->close(); }
            @unlink($tmp);
            $xml = str_replace('</w:p>', "\n", $xml);
            return mb_substr(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'), 0, $limit);
        }
        if ($ext === 'pdf' || $mime === 'application/pdf') {
            // Ekstraksi sederhana: teks dalam operator Tj/TJ pada stream tak terkompresi atau Flate.
            $out = '';
            if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $data, $mm)) {
                foreach ($mm[1] as $s) {
                    $dec = @gzuncompress($s); if ($dec === false) $dec = $s;
                    if (preg_match_all('/\((.*?)(?<!\\\\)\)\s*Tj|\[(.*?)\]\s*TJ/s', $dec, $tm, PREG_SET_ORDER)) {
                        foreach ($tm as $x) { $seg = $x[1] !== '' ? $x[1] : preg_replace('/\)\s*-?\d*\.?\d*\s*\(/', '', trim($x[2] ?? '', '()')); $out .= stripcslashes($seg) . ' '; }
                        $out .= "\n";
                    }
                    if (strlen($out) > $limit) break;
                }
            }
            return mb_substr(mb_convert_encoding($out, 'UTF-8', 'UTF-8'), 0, $limit);
        }
    } catch (Throwable $e) { return ''; }
    return '';
}
function apply_watermark(string $fn, string $mime, string $data, string $mark): array
{
    if (is_text_file($fn, $mime)) {
        $line = "[WATERMARK] $mark";
        $bar = str_repeat('=', min(mb_strlen($line), 120));
        return ["$line\n$bar\n\n" . mb_convert_encoding($data, 'UTF-8', 'UTF-8') . "\n\n$bar\n$line\n", true];
    }
    if (strtolower(pathinfo($fn, PATHINFO_EXTENSION)) === 'pdf' || $mime === 'application/pdf') {
        try { $w = pdf_watermark($data, $mark); } catch (Throwable $e) { $w = null; }
        if ($w !== null) return [$w, true];
    }
    return [$data, false];
}

// ================= Watermark PDF (pembaruan inkremental, tanpa pustaka) =================
function pdf_utf16(string $s): string { return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding($s, 'UTF-16BE', 'UTF-8'))) . '>'; }

/** Sisipkan anotasi FreeText di kaki setiap halaman + metadata /SISTRA-Watermark. null bila struktur PDF tidak didukung. */
function pdf_watermark(string $pdf, string $mark): ?string
{
    if (!preg_match_all('/startxref\s+(\d+)/', $pdf, $sx)) return null;
    $prev = (int)end($sx[1]);
    $tail = substr($pdf, $prev, 4096);
    if (str_starts_with(ltrim($tail), 'xref')) {
        $tp = strpos($pdf, 'trailer', $prev);
        if ($tp === false) return null;
        $trailer = substr($pdf, $tp, 2048);
    } else {
        $trailer = $tail; // aliran xref: kamus berada di objek itu sendiri
    }
    if (!preg_match('#/Root\s+(\d+)\s+(\d+)\s+R#', $trailer, $root) || !preg_match('#/Size\s+(\d+)#', $trailer, $size)) return null;
    $info = preg_match('#/Info\s+(\d+)\s+\d+\s+R#', $trailer, $im) ? (int)$im[1] : null;
    $id = preg_match('#/ID\s*(\[[^\]]*\])#', $trailer, $idm) ? $idm[1] : null;
    // Objek tak terkompresi; definisi terakhir menang (pembaruan inkremental sebelumnya).
    if (!preg_match_all('/(?<![0-9])(\d+)\s+(\d+)\s+obj\b(.*?)endobj/s', $pdf, $objs, PREG_SET_ORDER)) return null;
    $body = [];
    foreach ($objs as $o) $body[(int)$o[1]] = trim($o[3]);
    $pages = [];
    foreach ($body as $num => $c) if (preg_match('#/Type\s*/Page(?![a-zA-Z])#', $c) && str_starts_with($c, '<<')) $pages[] = $num;
    if (!$pages) return null;
    $next = (int)$size[1];
    $new = [];
    foreach ($pages as $pn) {
        $c = $body[$pn];
        $box = preg_match('#/MediaBox\s*\[\s*([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s*\]#', $c, $mb) ? array_map('floatval', array_slice($mb, 1)) : [0, 0, 595, 842];
        [$x0, $y0, $x1] = $box;
        $an = $next++;
        $new[$an] = sprintf('<< /Type /Annot /Subtype /FreeText /Rect [%.2f %.2f %.2f %.2f] /Contents %s /DA (/Helv 8 Tf 0.706 0 0 rg) /F 132 /Border [0 0 0] /P %d 0 R >>',
            $x0 + 20, $y0 + 8, $x1 - 20, $y0 + 26, pdf_utf16($mark), $pn);
        $ref = "$an 0 R";
        if (preg_match('#/Annots\s*\[([^\]]*)\]#', $c)) {
            $c = preg_replace('#/Annots\s*\[([^\]]*)\]#', '/Annots [$1 ' . $ref . ']', $c, 1);
        } elseif (preg_match('#/Annots\s+(\d+)\s+\d+\s+R#', $c, $ar) && isset($body[(int)$ar[1]]) && preg_match('#^\[(.*)\]$#s', $body[(int)$ar[1]], $arr)) {
            $c = preg_replace('#/Annots\s+\d+\s+\d+\s+R#', '/Annots [' . $arr[1] . ' ' . $ref . ']', $c, 1);
        } else {
            $c = preg_replace('#^<<#', "<< /Annots [$ref]", $c, 1);
        }
        if (preg_match('#\bstream\b#', $c)) return null;
        $new[$pn] = $c;
    }
    $infoBody = ($info !== null && isset($body[$info]) && preg_match('#^<<(.*)>>$#s', $body[$info], $ib)) ? preg_replace('#/SISTRA-Watermark\s*(\([^)]*\)|<[^>]*>)#', '', $ib[1]) : '';
    $infoNum = $next++;
    $new[$infoNum] = '<<' . $infoBody . ' /SISTRA-Watermark ' . pdf_utf16($mark) . ' >>';
    $out = $pdf;
    if (!str_ends_with($out, "\n")) $out .= "\n";
    $offsets = [];
    ksort($new);
    foreach ($new as $n => $c) { $offsets[$n] = strlen($out); $out .= "$n 0 obj\n$c\nendobj\n"; }
    $xref = strlen($out);
    $out .= "xref\n";
    $nums = array_keys($offsets);
    for ($i = 0; $i < count($nums);) {
        $j = $i; while ($j + 1 < count($nums) && $nums[$j + 1] === $nums[$j] + 1) $j++;
        $out .= $nums[$i] . ' ' . ($j - $i + 1) . "\n";
        for ($k = $i; $k <= $j; $k++) $out .= sprintf("%010d 00000 n \n", $offsets[$nums[$k]]);
        $i = $j + 1;
    }
    $out .= "trailer\n<< /Size $next /Root {$root[1]} {$root[2]} R /Info $infoNum 0 R /Prev $prev" . ($id ? " /ID $id" : '') . " >>\nstartxref\n$xref\n%%EOF\n";
    return $out;
}
