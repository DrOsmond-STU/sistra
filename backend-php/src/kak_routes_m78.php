<?php
/** Rute KAK Modul 7 (S49–S55) dan Modul 8 (S56–S64). */
declare(strict_types=1);

function og_package(array $d): array
{
    $plain = trim(preg_replace('/\s+/u', ' ', preg_replace('/\[[^\]]*\]/u', '', $d['body'])));
    $desc = mb_substr($plain, 0, 157) . (mb_strlen($plain) > 157 ? '…' : '');
    $url = 'https://contoh.invalid/nasional/naskah-' . $d['id'];
    $img = $url . '/og-1200x630.jpg';
    $og = ['og:title' => $d['title'], 'og:description' => $desc, 'og:type' => 'article', 'og:url' => $url, 'og:image' => $img, 'og:image:width' => '1200', 'og:image:height' => '630',
        'og:locale' => 'id_ID', 'og:site_name' => 'Kanal Informasi Resmi (contoh)', 'article:published_time' => $d['published_at'] ?? $d['approved_at'] ?? $d['updated_at'],
        'twitter:card' => 'summary_large_image'];
    $ld = ['@context' => 'https://schema.org', '@type' => 'NewsArticle', 'headline' => mb_substr($d['title'], 0, 110), 'description' => $desc, 'image' => [$img],
        'datePublished' => $d['published_at'] ?? $d['approved_at'] ?? $d['updated_at'], 'dateModified' => $d['updated_at'], 'inLanguage' => 'id-ID', 'mainEntityOfPage' => $url,
        'author' => ['@type' => 'Organization', 'name' => 'Tim Komunikasi Publik (contoh)'], 'publisher' => ['@type' => 'GovernmentOrganization', 'name' => 'Kanal Informasi Resmi (contoh)']];
    if ($d['ai_generated']) $ld['creditText'] = AI_LABEL;
    $checks = [
        ['rule' => 'og:title 10–95 karakter', 'ok' => mb_strlen($d['title']) >= 10 && mb_strlen($d['title']) <= 95],
        ['rule' => 'og:description 50–160 karakter', 'ok' => mb_strlen($desc) >= 50 && mb_strlen($desc) <= 160],
        ['rule' => 'og:image 1200×630 tersedia', 'ok' => true],
        ['rule' => 'Schema.org headline ≤ 110 karakter', 'ok' => mb_strlen($d['title']) <= 110],
        ['rule' => 'datePublished berformat ISO 8601', 'ok' => (bool)ts_from_iso($ld['datePublished'])],
        ['rule' => 'Label konten dibantu SISTRA AGENT (bila berlaku)', 'ok' => !$d['ai_generated'] || isset($ld['creditText'])],
        ['rule' => 'Naskah berstatus disetujui/tayang', 'ok' => in_array($d['status'], ['disetujui', 'tayang'], true)],
    ];
    return ['opengraph' => $og, 'jsonld' => $ld, 'checks' => $checks, 'valid' => !in_array(false, array_column($checks, 'ok'), true)];
}
function uptime_stats(int $channelId, int $days = 7): array
{
    $since = iso_from_ts(microtime(true) - $days * 86400);
    $r = one('SELECT COUNT(*) n, SUM(ok) ok FROM channel_checks WHERE channel_id = ? AND at >= ?', [$channelId, $since]);
    $pts = [];
    foreach (all('SELECT point, AVG(CASE WHEN ok = 1 THEN latency_ms END) lat, SUM(ok) ok, COUNT(*) n FROM channel_checks WHERE channel_id = ? AND at >= ? GROUP BY point', [$channelId, iso_from_ts(microtime(true) - 86400)]) as $p)
        $pts[] = ['point' => $p['point'], 'latency_ms' => (int)round((float)$p['lat']), 'ok_ratio' => $p['n'] ? round($p['ok'] / $p['n'], 3) : 1.0];
    return ['uptime' => $r['n'] ? round(100 * $r['ok'] / $r['n'], 2) : 100.0, 'checks' => (int)$r['n'], 'points' => $pts];
}
function api_client_out(array $c): array
{
    $since = iso_from_ts(microtime(true) - 86400);
    return ['id' => (int)$c['id'], 'name' => $c['name'], 'org' => $c['org'], 'token_prefix' => $c['token_prefix'] . '…', 'scopes' => jdec($c['scopes']), 'rate_per_min' => (int)$c['rate_per_min'],
        'expires_at' => $c['expires_at'], 'active' => (bool)$c['active'] && $c['expires_at'] > now_iso(), 'revoked' => !(bool)$c['active'], 'last_used_at' => $c['last_used_at'],
        'calls_24h' => (int)val('SELECT COUNT(*) FROM api_calls WHERE client_id = ? AND at >= ?', [$c['id'], $since]),
        'denied_24h' => (int)val('SELECT COUNT(*) FROM api_calls WHERE client_id = ? AND at >= ? AND status >= 400', [$c['id'], $since]), 'created_at' => $c['created_at']];
}
/** S54: autentikasi klien API mitra + pembatasan laju + log. */
function api_client_auth(string $path): array
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($h === '' && function_exists('getallheaders')) foreach (getallheaders() as $k => $v) if (strtolower($k) === 'authorization') $h = $v;
    $tok = preg_match('/^Bearer\s+(sst_[A-Za-z0-9_-]{20,})$/i', trim($h), $m) ? $m[1] : '';
    $c = $tok ? one('SELECT * FROM api_clients WHERE token_hash = ?', [hash('sha256', $tok)]) : null;
    $log = fn(int $st) => insert('api_calls', ['client_id' => $c['id'] ?? null, 'path' => mb_substr($path, 0, 200), 'status' => $st, 'ip' => client_ip(), 'at' => now_iso()]);
    if (!$c || !$c['active'] || $c['expires_at'] <= now_iso()) { $log(401); $GLOBALS['SISTRA_COMMIT_ON_ERROR'] = true; fail(401, 'Token API tidak valid, dicabut, atau kedaluwarsa'); }
    if (!in_array('publikasi:baca', jdec($c['scopes']), true)) { $log(403); $GLOBALS['SISTRA_COMMIT_ON_ERROR'] = true; fail(403, 'Cakupan token tidak mencukupi'); }
    $n = (int)val('SELECT COUNT(*) FROM api_calls WHERE client_id = ? AND at >= ? AND status < 400', [$c['id'], iso_from_ts(microtime(true) - 60)]);
    header('X-RateLimit-Limit: ' . $c['rate_per_min']); header('X-RateLimit-Remaining: ' . max(0, $c['rate_per_min'] - $n - 1));
    if ($n >= (int)$c['rate_per_min']) { $log(429); header('Retry-After: 60'); $GLOBALS['SISTRA_COMMIT_ON_ERROR'] = true; fail(429, 'Batas laju terlampaui'); }
    $log(200);
    q('UPDATE api_clients SET last_used_at = ? WHERE id = ?', [now_iso(), $c['id']]);
    return $c;
}
function public_pub(array $d): array
{
    $p = og_package($d);
    return ['id' => (int)$d['id'], 'title' => $d['title'], 'body' => $d['body'], 'format' => $d['format'] ?: $d['kind'], 'province' => province_name($d['province_code']),
        'issue' => $d['issue_id'] ? val('SELECT name FROM issues WHERE id = ?', [$d['issue_id']]) : null, 'published_at' => $d['published_at'] ?? $d['updated_at'],
        'ai_label' => $d['ai_generated'] ? AI_LABEL : null, 'url' => $p['opengraph']['og:url'], 'opengraph' => $p['opengraph'], 'content_hash' => $d['content_hash']];
}
function press_link_row(string $token): array
{
    if (!preg_match('/^pers_[A-Za-z0-9_-]{20,}$/', $token)) fail(404, 'Tautan tidak dikenal');
    $l = one('SELECT * FROM press_links WHERE token_hash = ?', [hash('sha256', $token)]);
    if (!$l) fail(404, 'Tautan tidak dikenal');
    if ($l['revoked_at']) fail(410, 'Tautan sudah dicabut');
    if ($l['expires_at'] < now_iso()) fail(410, 'Tautan sudah kedaluwarsa');
    return $l;
}

function register_routes_kak_m7(Router $r): void
{
    // ---------- S49 CMS per kanal + takedown ----------
    $r->add('GET', '/api/m7/channels/([A-Z0-9-]+)', function ($code) {
        $u = current_user();
        if (has_role($u, 'operator')) process_due_distributions($u);
        $c = one("SELECT * FROM channels WHERE code = ? AND type IN ('nasional','provinsi')", [$code]); if (!$c) fail(404, 'Kanal tidak ditemukan');
        $arts = all('SELECT x.*, d.title, d.status dstatus, d.ai_generated FROM distributions x JOIN drafts d ON d.id = x.draft_id WHERE x.channel_id = ? ORDER BY COALESCE(x.sent_at, x.scheduled_at, x.created_at) DESC LIMIT 60', [$c['id']]);
        $inc = all('SELECT * FROM channel_incidents WHERE channel_id = ? ORDER BY started_at DESC LIMIT 10', [$c['id']]);
        $hours = all('SELECT hour, views, uniques FROM metric_hourly WHERE channel_id = ? AND hour >= ? ORDER BY hour', [$c['id'], iso_from_ts(microtime(true) - 48 * 3600)]);
        send_json(channel_out($c) + ['tz' => tz_label($c['province_code']), 'geo_target' => $c['province_code'] ? 'Pembaca berlokasi di ' . province_name($c['province_code']) . ' diprioritaskan (geolokasi IP)' : 'Seluruh Indonesia',
            'articles' => array_map(fn($x) => ['id' => (int)$x['id'], 'draft_id' => (int)$x['draft_id'], 'title' => $x['title'], 'status' => $x['status'], 'sent_at' => $x['sent_at'],
                'scheduled_at' => $x['scheduled_at'], 'url' => $x['url'], 'views' => (int)$x['views'], 'uniques' => (int)$x['uniques'], 'clicks' => (int)$x['clicks'], 'avg_time_sec' => (int)$x['avg_time_sec'],
                'sync_ms' => $x['sync_ms'] === null ? null : (int)$x['sync_ms'], 'error' => $x['error'], 'takedown_reason' => $x['takedown_reason'], 'ai_label' => (bool)$x['ai_generated']], $arts),
            'uptime_7d' => uptime_stats((int)$c['id']), 'incidents' => array_map(fn($i) => ['started_at' => $i['started_at'], 'ended_at' => $i['ended_at'], 'cause' => $i['cause'], 'points' => jdec($i['points'])], $inc),
            'curve' => curve_for($c['province_code'] ?? 'NAS'), 'hourly' => array_map(fn($h) => ['hour' => $h['hour'], 'views' => (int)$h['views'], 'uniques' => (int)$h['uniques']], $hours)]);
    });
    $r->add('POST', '/api/m7/distributions/(\d+)/takedown', function ($id) {
        $u = require_roles('operator', 'redaktur', 'pimpinan');
        $x = one('SELECT * FROM distributions WHERE id = ?', [(int)$id]); if (!$x) fail(404, 'Distribusi tidak ditemukan');
        if ($x['status'] !== 'terkirim') fail(409, 'Hanya artikel yang sudah terkirim yang dapat ditarik dari kanal');
        $reason = trim(str_field(json_body(), 'reason', 5, 300));
        q("UPDATE distributions SET status = 'ditarik', taken_down_at = ?, takedown_reason = ? WHERE id = ?", [now_iso(), $reason, $x['id']]);
        audit_record($u, 'distribusi.tarik', 'distribution', $x['id'], ['alasan' => $reason]);
        send_json(dist_out(one('SELECT * FROM distributions WHERE id = ?', [$x['id']])) + ['takedown_reason' => $reason]);
    });
    // ---------- S51 penjadwalan cerdas ----------
    $r->add('GET', '/api/m7/schedule/recommend', function () {
        current_user();
        $now = microtime(true);
        $rows = [];
        foreach (all("SELECT * FROM channels WHERE type IN ('nasional','provinsi') AND active = 1 ORDER BY CASE type WHEN 'nasional' THEN 0 ELSE 1 END, name") as $c) {
            $rec = recommend_time($c['province_code'], $now);
            $rows[] = ['code' => $c['code'], 'name' => $c['name'], 'tz' => tz_label($c['province_code']), 'curve' => curve_for($c['province_code'] ?? 'NAS'), 'health' => $c['health'] ?: 'normal'] + $rec;
        }
        send_json($rows);
    });
    $r->add('POST', '/api/m7/schedule', function () {
        $u = require_roles('operator');
        $b = json_body();
        $d = get_draft_row((int)($b['draft_id'] ?? 0));
        if (!in_array($d['status'], ['disetujui', 'tayang'], true)) fail(409, 'Hanya naskah yang sudah disetujui yang dapat dijadwalkan');
        $codes = list_field($b, 'channel_codes') ?? [];
        $chs = $codes ? array_values(array_filter(array_map(fn($c) => one("SELECT * FROM channels WHERE code = ? AND type IN ('nasional','provinsi')", [$c]), $codes)))
            : all("SELECT * FROM channels WHERE type IN ('nasional','provinsi') AND active = 1");
        if (!$chs) fail(422, 'Pilih kanal tujuan');
        $times = is_array($b['times'] ?? null) ? $b['times'] : [];
        $made = 0; $now = microtime(true);
        foreach ($chs as $c) {
            $at = isset($times[$c['code']]) ? (opt_dt($times[$c['code']]) ?? fail(422, "Waktu untuk {$c['code']} tidak valid")) : recommend_time($c['province_code'], $now)['at'];
            if ((float)ts_from_iso($at) < $now - 60) fail(422, "Waktu untuk {$c['name']} sudah lewat");
            $ex = one('SELECT * FROM distributions WHERE draft_id = ? AND channel_id = ?', [$d['id'], $c['id']]);
            if ($ex && in_array($ex['status'], ['terkirim', 'ditarik'], true)) continue;
            if ($ex) q("UPDATE distributions SET status = 'terjadwal', scheduled_at = ?, error = NULL WHERE id = ?", [$at, $ex['id']]);
            else insert('distributions', ['draft_id' => $d['id'], 'channel_id' => $c['id'], 'status' => 'terjadwal', 'scheduled_at' => $at, 'created_by' => $u['id'], 'created_at' => now_iso()]);
            $made++;
        }
        audit_record($u, 'distribusi.jadwalkan', 'draft', $d['id'], ['kanal' => $made, 'mode' => $times ? 'ditimpa_operator' : 'jam_puncak']);
        send_json(['scheduled' => $made]);
    });
    $r->add('GET', '/api/m7/queue', function () {
        $u = current_user();
        $processed = has_role($u, 'operator') ? process_due_distributions($u) : 0;
        $rows = all("SELECT x.*, d.title, c.name cname, c.province_code FROM distributions x JOIN drafts d ON d.id = x.draft_id JOIN channels c ON c.id = x.channel_id WHERE x.status = 'terjadwal' ORDER BY x.scheduled_at LIMIT 300");
        send_json(['processed' => $processed, 'items' => array_map(fn($x) => ['id' => (int)$x['id'], 'draft_id' => (int)$x['draft_id'], 'title' => $x['title'], 'channel' => $x['cname'],
            'scheduled_at' => $x['scheduled_at'], 'local' => sprintf('%02d.%s %s', ((int)gmdate('G', (int)ts_from_iso($x['scheduled_at'])) + tz_offset($x['province_code'])) % 24, gmdate('i', (int)ts_from_iso($x['scheduled_at'])), tz_label($x['province_code']))], $rows)]);
    });
    $r->add('POST', '/api/m7/distributions/(\d+)/cancel', function ($id) {
        $u = require_roles('operator');
        $x = one('SELECT * FROM distributions WHERE id = ?', [(int)$id]); if (!$x) fail(404, 'Distribusi tidak ditemukan');
        if ($x['status'] !== 'terjadwal') fail(409, 'Hanya jadwal yang belum berjalan yang dapat dibatalkan');
        q("UPDATE distributions SET status = 'batal' WHERE id = ?", [$x['id']]);
        audit_record($u, 'distribusi.batal_jadwal', 'distribution', $x['id']);
        send_json(['ok' => true]);
    });
    // ---------- S52 konversi aset & metadata ----------
    $r->add('GET', '/api/m7/drafts/(\d+)/package', function ($id) {
        $d = require_draft_reader((int)$id);
        $rend = [];
        foreach (all('SELECT * FROM renditions WHERE draft_id = ?', [$d['id']]) as $x) $rend[$x['profile']] = $x;
        send_json(og_package($d) + ['renditions' => array_map(fn($k, $p) => ['profile' => $k, 'label' => $p[0], 'mime' => $p[1], 'dimension' => $p[2],
            'status' => $rend[$k]['status'] ?? 'belum', 'size' => (int)($rend[$k]['size'] ?? 0), 'created_at' => $rend[$k]['created_at'] ?? null], array_keys(RENDITION_PROFILES), RENDITION_PROFILES)]);
    });
    $r->add('POST', '/api/m7/drafts/(\d+)/renditions', function ($id) {
        $u = require_roles('operator', 'produser', 'redaktur');
        $d = get_draft_row((int)$id);
        if (!in_array($d['status'], ['disetujui', 'tayang'], true)) fail(409, 'Konversi dilakukan setelah naskah disetujui');
        foreach (RENDITION_PROFILES as $k => $p) {
            $size = match (true) { str_starts_with($p[1], 'video') => mt_rand(4, 18) * 1048576, str_starts_with($p[1], 'image') => mt_rand(90, 480) * 1024, $p[1] === 'application/pdf' => mt_rand(150, 900) * 1024, default => mb_strlen($d['body']) + mt_rand(800, 4000) };
            q('INSERT INTO renditions (draft_id, profile, status, size, created_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT(draft_id, profile) DO UPDATE SET status = excluded.status, size = excluded.size, created_at = excluded.created_at',
                [$d['id'], $k, 'siap', $size, now_iso()]);
        }
        audit_record($u, 'distribusi.konversi', 'draft', $d['id'], ['profil' => count(RENDITION_PROFILES)]);
        send_json(['ok' => true, 'profiles' => count(RENDITION_PROFILES)]);
    });
    // ---------- S53 portal media konvensional ----------
    $r->add('GET', '/api/m7/media-partners', function () {
        current_user();
        send_json(array_map(fn($m) => ['id' => (int)$m['id'], 'name' => $m['name'], 'type' => $m['type'], 'province_name' => province_name($m['province_code']) ?? 'Nasional', 'contact' => $m['contact'],
            'active' => (bool)$m['active'], 'downloads' => (int)val('SELECT COUNT(*) FROM press_downloads WHERE partner_id = ?', [$m['id']])], all('SELECT * FROM media_partners WHERE active = 1 ORDER BY type, name LIMIT 200')));
    });
    $r->add('POST', '/api/m7/media-partners', function () {
        $u = require_roles('operator', 'redaktur');
        $b = json_body();
        $t = (string)($b['type'] ?? ''); if (!in_array($t, ['cetak', 'tv', 'radio', 'daring'], true)) fail(422, 'Jenis media tidak dikenal');
        $id = insert('media_partners', ['name' => trim(str_field($b, 'name', 3, 160)), 'type' => $t, 'province_code' => isset(PROVINCES[$b['province_code'] ?? '']) ? $b['province_code'] : null,
            'contact' => mb_substr(trim((string)($b['contact'] ?? '')), 0, 160), 'active' => 1, 'created_at' => now_iso()]);
        audit_record($u, 'media_mitra.tambah', 'media_partner', $id);
        send_json(['id' => $id], 201);
    });
    $r->add('GET', '/api/m7/press-packs', function () {
        current_user();
        send_json(array_map(fn($p) => ['id' => (int)$p['id'], 'draft_id' => (int)$p['draft_id'], 'title' => $p['title'], 'note' => $p['note'], 'expires_at' => $p['expires_at'],
            'assets' => count(jdec($p['asset_ids'])), 'links' => (int)val('SELECT COUNT(*) FROM press_links WHERE pack_id = ?', [$p['id']]),
            'downloads' => array_map(fn($x) => ['partner' => val('SELECT name FROM media_partners WHERE id = ?', [$x['partner_id']]), 'ip' => $x['ip'], 'at' => $x['at']],
                all('SELECT * FROM press_downloads WHERE pack_id = ? ORDER BY at DESC LIMIT 50', [$p['id']])), 'created_at' => $p['created_at']], all('SELECT * FROM press_packs ORDER BY id DESC LIMIT 40')));
    });
    $r->add('POST', '/api/m7/press-packs', function () {
        $u = require_roles('operator', 'redaktur');
        $b = json_body();
        $d = get_draft_row((int)($b['draft_id'] ?? 0));
        if (!in_array($d['status'], ['disetujui', 'tayang'], true)) fail(409, 'Paket media hanya untuk naskah yang sudah disetujui');
        $assets = array_values(array_filter(int_list($b['asset_ids'] ?? []), fn($a) => ($x = one('SELECT license_expires_at FROM assets WHERE id = ?', [$a])) && asset_state($x['license_expires_at']) !== 'kedaluwarsa'));
        $days = max(1, min(30, (int)($b['days'] ?? 7)));
        $id = insert('press_packs', ['draft_id' => $d['id'], 'title' => trim((string)($b['title'] ?? '')) ?: $d['title'], 'note' => trim((string)($b['note'] ?? '')), 'asset_ids' => jenc($assets),
            'expires_at' => iso_from_ts(microtime(true) + $days * 86400), 'created_by' => $u['id'], 'created_at' => now_iso()]);
        audit_record($u, 'paket_media.buat', 'press_pack', $id, ['draft_id' => (int)$d['id']]);
        send_json(['id' => $id], 201);
    });
    $r->add('POST', '/api/m7/press-packs/(\d+)/links', function ($id) {
        $u = require_roles('operator', 'redaktur');
        $p = one('SELECT * FROM press_packs WHERE id = ?', [(int)$id]); if (!$p) fail(404, 'Paket tidak ditemukan');
        $ids = int_list(json_body()['partner_ids'] ?? []); if (!$ids) fail(422, 'Pilih media penerima');
        $out = [];
        foreach ($ids as $pid) {
            $m = one('SELECT * FROM media_partners WHERE id = ? AND active = 1', [$pid]); if (!$m) continue;
            $tok = 'pers_' . b64url(random_bytes(24));
            insert('press_links', ['pack_id' => $p['id'], 'partner_id' => $pid, 'token_hash' => hash('sha256', $tok), 'expires_at' => $p['expires_at'], 'created_at' => now_iso()]);
            $out[] = ['partner' => $m['name'], 'token' => $tok, 'path' => '/pers/' . $tok];
        }
        audit_record($u, 'paket_media.tautan', 'press_pack', $p['id'], ['penerima' => count($out)]);
        send_json(['links' => $out, 'note' => 'Tautan hanya ditampilkan sekali. Kirim ke masing-masing media melalui kanal resmi.'], 201);
    });
    // publik (tanpa login): portal unduhan media dengan token berwaktu
    $r->add('GET', '/api/press/([A-Za-z0-9_-]+)', function ($token) {
        $l = press_link_row($token);
        $p = one('SELECT * FROM press_packs WHERE id = ?', [$l['pack_id']]); $d = one('SELECT * FROM drafts WHERE id = ?', [$p['draft_id']]);
        if (!in_array($d['status'], ['disetujui', 'tayang'], true)) fail(410, 'Naskah sudah ditarik');
        send_json(['partner' => val('SELECT name FROM media_partners WHERE id = ?', [$l['partner_id']]), 'title' => $p['title'], 'note' => $p['note'], 'expires_at' => $l['expires_at'],
            'article' => ['title' => $d['title'], 'body' => $d['body'], 'ai_label' => $d['ai_generated'] ? AI_LABEL : null, 'content_hash' => $d['content_hash']],
            'assets' => array_map(fn($a) => ['title' => $a['title'], 'mime' => $a['mime'], 'size' => (int)$a['size'], 'credit' => $a['credit'], 'license' => ASSET_LICENSES[$a['license']] ?? $a['license']],
                array_values(array_filter(array_map(fn($i) => one('SELECT * FROM assets WHERE id = ?', [$i]), jdec($p['asset_ids']))))),
            'downloads' => (int)val('SELECT COUNT(*) FROM press_downloads WHERE link_id = ?', [$l['id']])]);
    });
    $r->add('POST', '/api/press/([A-Za-z0-9_-]+)/download', function ($token) {
        $l = press_link_row($token);
        $p = one('SELECT * FROM press_packs WHERE id = ?', [$l['pack_id']]); $d = one('SELECT * FROM drafts WHERE id = ?', [$p['draft_id']]);
        if (!in_array($d['status'], ['disetujui', 'tayang'], true)) fail(410, 'Naskah sudah ditarik');
        insert('press_downloads', ['link_id' => $l['id'], 'pack_id' => $p['id'], 'partner_id' => $l['partner_id'], 'ip' => client_ip(), 'at' => now_iso()]);
        audit_record(null, 'paket_media.unduh', 'press_pack', $p['id'], ['mitra' => (int)$l['partner_id']], 'portal-media');
        $partner = val('SELECT name FROM media_partners WHERE id = ?', [$l['partner_id']]);
        $text = "{$d['title']}\n\n{$d['body']}\n\n" . ($d['ai_generated'] ? AI_LABEL . "\n" : '') . "—\nDiunduh oleh: {$partner} · " . now_iso() . "\nSidik isi (SHA-256): {$d['content_hash']}\n";
        send_json(['filename' => 'paket-media-' . $p['id'] . '.txt', 'content' => $text]);
    });
    // ---------- S54 gerbang API eksternal ----------
    $r->add('GET', '/api/m7/api-clients', function () { require_roles('admin'); send_json(array_map('api_client_out', all('SELECT * FROM api_clients ORDER BY active DESC, id DESC LIMIT 60'))); });
    $r->add('POST', '/api/m7/api-clients', function () {
        $u = require_roles('admin');
        $b = json_body();
        $days = max(1, min(365, (int)($b['days'] ?? 90))); $rate = max(1, min(600, (int)($b['rate_per_min'] ?? 60)));
        $tok = 'sst_' . b64url(random_bytes(32));
        $id = insert('api_clients', ['name' => trim(str_field($b, 'name', 3, 120)), 'org' => trim(str_field($b, 'org', 2, 160)), 'token_prefix' => substr($tok, 0, 10), 'token_hash' => hash('sha256', $tok),
            'scopes' => jenc(['publikasi:baca']), 'rate_per_min' => $rate, 'expires_at' => iso_from_ts(microtime(true) + $days * 86400), 'active' => 1, 'created_by' => $u['id'], 'created_at' => now_iso()]);
        audit_record($u, 'api.klien_baru', 'api_client', $id, ['org' => $b['org'] ?? '', 'hari' => $days]);
        send_json(api_client_out(one('SELECT * FROM api_clients WHERE id = ?', [$id])) + ['token' => $tok, 'note' => 'Token hanya ditampilkan sekali. Simpan di brankas sistem mitra.'], 201);
    });
    $r->add('POST', '/api/m7/api-clients/(\d+)/revoke', function ($id) {
        $u = require_roles('admin');
        $c = one('SELECT * FROM api_clients WHERE id = ?', [(int)$id]); if (!$c) fail(404, 'Klien tidak ditemukan');
        q('UPDATE api_clients SET active = 0 WHERE id = ?', [$c['id']]);
        audit_record($u, 'api.klien_cabut', 'api_client', $c['id']);
        send_json(api_client_out(one('SELECT * FROM api_clients WHERE id = ?', [$c['id']])));
    });
    $r->add('GET', '/api/m7/api-clients/(\d+)/calls', function ($id) {
        require_roles('admin');
        send_json(array_map(fn($x) => ['path' => $x['path'], 'status' => (int)$x['status'], 'ip' => $x['ip'], 'at' => $x['at']], all('SELECT * FROM api_calls WHERE client_id = ? ORDER BY id DESC LIMIT 50', [(int)$id])));
    });
    $r->add('GET', '/api/public/v1/publications', function () {
        api_client_auth('/api/public/v1/publications');
        $lim = qint('limit', 20, 1, 100);
        send_json(['data' => array_map('public_pub', all("SELECT * FROM drafts WHERE status = 'tayang' ORDER BY COALESCE(published_at, updated_at) DESC LIMIT $lim"))]);
    });
    $r->add('GET', '/api/public/v1/publications/(\d+)', function ($id) {
        api_client_auth("/api/public/v1/publications/$id");
        $d = one("SELECT * FROM drafts WHERE id = ? AND status = 'tayang'", [(int)$id]); if (!$d) fail(404, 'Publikasi tidak ditemukan');
        send_json(public_pub($d));
    });
    // ---------- S55 pemantauan uptime ----------
    $r->add('GET', '/api/m7/uptime', function () {
        current_user();
        $rows = [];
        foreach (all("SELECT * FROM channels WHERE type IN ('nasional','provinsi') ORDER BY CASE type WHEN 'nasional' THEN 0 ELSE 1 END, name") as $c)
            $rows[] = ['code' => $c['code'], 'name' => $c['name'], 'health' => $c['health'] ?: 'normal', 'latency_ms' => (int)$c['latency_ms'], 'checked_at' => $c['checked_at']] + uptime_stats((int)$c['id']);
        $inc = all('SELECT i.*, c.name FROM channel_incidents i JOIN channels c ON c.id = i.channel_id ORDER BY i.started_at DESC LIMIT 30');
        $avg = $rows ? round(array_sum(array_column($rows, 'uptime')) / count($rows), 2) : 100;
        send_json(['sla_target' => 99.9, 'avg_uptime' => $avg, 'below_sla' => count(array_filter($rows, fn($x) => $x['uptime'] < 99.9)), 'points' => MONITOR_POINTS, 'channels' => $rows,
            'incidents' => array_map(fn($i) => ['channel' => $i['name'], 'started_at' => $i['started_at'], 'ended_at' => $i['ended_at'], 'cause' => $i['cause'], 'points' => jdec($i['points']),
                'minutes' => (int)round(((float)ts_from_iso($i['ended_at'] ?? now_iso()) - (float)ts_from_iso($i['started_at'])) / 60)], $inc)]);
    });
}

function register_routes_kak_m8(Router $r): void
{
    // ---------- S56 metrik keterlibatan per jam/hari ----------
    $r->add('GET', '/api/m8/engagement', function () {
        current_user();
        $hours = qint('hours', 48, 6, 72);
        $code = qparam('channel');
        $since = iso_from_ts((floor(microtime(true) / 3600) - $hours + 1) * 3600);
        $w = 'hour >= ?'; $p = [$since];
        if ($code) { $w .= ' AND channel_id = (SELECT id FROM channels WHERE code = ?)'; $p[] = $code; }
        $series = all("SELECT hour, SUM(views) views, SUM(uniques) uniques, SUM(clicks) clicks, SUM(comments) comments, CAST(SUM(avg_time_sec * views) AS REAL) / MAX(1, SUM(views)) t, AVG(video_retention) ret FROM metric_hourly WHERE $w GROUP BY hour ORDER BY hour", $p);
        $daily = all("SELECT substr(hour,1,10) d, SUM(views) views, SUM(uniques) uniques, SUM(clicks) clicks, SUM(comments) comments FROM metric_hourly WHERE hour >= ? " . ($code ? 'AND channel_id = (SELECT id FROM channels WHERE code = ?)' : '') . ' GROUP BY d ORDER BY d',
            $code ? [iso_from_ts(microtime(true) - 7 * 86400), $code] : [iso_from_ts(microtime(true) - 7 * 86400)]);
        $tot = ['views' => array_sum(array_column($series, 'views')), 'uniques' => array_sum(array_column($series, 'uniques')), 'clicks' => array_sum(array_column($series, 'clicks')), 'comments' => array_sum(array_column($series, 'comments'))];
        send_json(['hours' => $hours, 'channel' => $code, 'totals' => $tot + ['avg_time_sec' => $series ? (int)round(array_sum(array_map(fn($x) => $x['t'] * $x['views'], $series)) / max(1, $tot['views'])) : 0,
            'video_retention' => $series ? round(array_sum(array_column($series, 'ret')) / count($series), 1) : 0.0, 'ctr' => $tot['views'] ? round($tot['clicks'] / $tot['views'], 4) : 0.0],
            'series' => array_map(fn($x) => ['hour' => $x['hour'], 'views' => (int)$x['views'], 'uniques' => (int)$x['uniques'], 'clicks' => (int)$x['clicks'], 'comments' => (int)$x['comments'], 'avg_time_sec' => (int)round((float)$x['t']), 'retention' => round((float)$x['ret'], 1)], $series),
            'daily' => array_map(fn($x) => ['date' => $x['d'], 'views' => (int)$x['views'], 'uniques' => (int)$x['uniques'], 'clicks' => (int)$x['clicks'], 'comments' => (int)$x['comments']], $daily)]);
    });
    // ---------- S57 pergeseran sentimen & S59 IEKP ----------
    $r->add('GET', '/api/m8/impact', function () {
        current_user();
        $issues = [];
        foreach (all('SELECT * FROM issues WHERE active = 1 ORDER BY name') as $i) {
            $k = iekp_for_issue((int)$i['id']);
            if ($k['raw']['drafts'] === 0 && $k['raw']['reach'] === 0) continue;
            $issues[] = ['code' => $i['code'], 'name' => $i['name']] + $k;
        }
        usort($issues, fn($a, $b) => $b['score'] <=> $a['score']);
        $camps = array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'status' => $c['status'], 'start_date' => $c['start_date'], 'end_date' => $c['end_date'],
            'shift' => sentiment_shift($c['issue_id'] ? (int)$c['issue_id'] : null, $c['start_date'] . 'T00:00:00Z', $c['end_date'] . 'T23:59:59Z')],
            all("SELECT * FROM campaigns WHERE status IN ('berjalan','selesai','disetujui') ORDER BY start_date DESC LIMIT 25"));
        send_json(['weights' => IEKP_WEIGHTS, 'method' => 'IEKP = 25% jangkauan (target 500 rb) + 20% keterlibatan (ER 5% = 100) + 25% sentimen (1 − rasio negatif 7 hari) + 15% kecepatan respons (≤ 2 jam = 100, −15/jam) + 15% cakupan wilayah (kanal/39).',
            'national' => $issues ? (int)round(array_sum(array_column($issues, 'score')) / count($issues)) : 0, 'issues' => $issues, 'campaigns' => $camps]);
    });
    // ---------- S60 peta 38 provinsi ----------
    $r->add('GET', '/api/m8/provinces', function () {
        current_user();
        $since = iso_from_ts(microtime(true) - 7 * 86400);
        $out = [];
        foreach (all("SELECT * FROM channels WHERE type = 'provinsi' AND province_code IS NOT NULL") as $c) {
            $v = (int)val('SELECT COALESCE(SUM(views),0) FROM metric_hourly WHERE channel_id = ? AND hour >= ?', [$c['id'], $since]);
            $out[] = ['code' => $c['province_code'], 'name' => province_name($c['province_code']), 'channel' => $c['code'], 'health' => $c['health'] ?: 'normal', 'views_7d' => $v,
                'local_drafts' => (int)val("SELECT COUNT(*) FROM drafts WHERE province_code = ? AND created_at >= ?", [$c['province_code'], iso_from_ts(microtime(true) - 30 * 86400)]),
                'published_7d' => (int)val("SELECT COUNT(*) FROM distributions WHERE channel_id = ? AND status = 'terkirim' AND sent_at >= ?", [$c['id'], $since]),
                'verified_context' => (int)val('SELECT COUNT(*) FROM local_context WHERE province_code = ? AND verified = 1', [$c['province_code']])];
        }
        send_json($out);
    });
    // ---------- S64 penjamin mutu AI ----------
    $r->add('GET', '/api/ai/quality', function () {
        current_user();
        $since = iso_from_ts(microtime(true) - 30 * 86400);
        $feat = all("SELECT feature, COUNT(*) n, SUM(mode='claude') live, SUM(ok=0) err, AVG(CASE WHEN mode='claude' THEN ms END) ms FROM ai_logs WHERE at >= ? GROUP BY feature ORDER BY n DESC", [$since]);
        $fb = all("SELECT category, COUNT(*) n, SUM(decision='diterima') ok FROM editorial_feedback WHERE at >= ? GROUP BY category", [$since]);
        $aiD = one("SELECT COUNT(*) n, SUM(status IN ('disetujui','tayang')) ok, AVG(editorial_score) sc FROM drafts WHERE ai_generated = 1 AND created_at >= ?", [$since]);
        $huD = one("SELECT COUNT(*) n, SUM(status IN ('disetujui','tayang')) ok, AVG(editorial_score) sc FROM drafts WHERE ai_generated = 0 AND created_at >= ?", [$since]);
        $ret = fn(int $ai) => (int)val("SELECT COUNT(DISTINCT s.draft_id) FROM approval_steps s JOIN drafts d ON d.id = s.draft_id WHERE s.from_status = 'ditinjau' AND s.to_status = 'draf' AND d.ai_generated = ? AND s.at >= ?", [$ai, $since]);
        // evaluasi bias: skor editorial & kelengkapan konteks lokal per kelompok wilayah
        $groups = ['Sumatera' => ['11', '12', '13', '14', '15', '16', '17', '18', '19', '21'], 'Jawa-Bali' => ['31', '32', '33', '34', '35', '36', '51'], 'Nusa Tenggara' => ['52', '53'],
            'Kalimantan' => ['61', '62', '63', '64', '65'], 'Sulawesi' => ['71', '72', '73', '74', '75', '76'], 'Maluku-Papua' => ['81', '82', '91', '92', '93', '94', '95', '96']];
        $bias = [];
        foreach ($groups as $g => $codes) {
            $in = "'" . implode("','", $codes) . "'";
            $bias[] = ['group' => $g, 'provinces' => count($codes), 'drafts' => (int)val("SELECT COUNT(*) FROM drafts WHERE province_code IN ($in) AND created_at >= ?", [$since]),
                'avg_score' => ($s = val("SELECT AVG(editorial_score) FROM drafts WHERE province_code IN ($in) AND editorial_score IS NOT NULL")) === null ? null : (int)round((float)$s),
                'context_verified' => (int)val("SELECT COUNT(*) FROM local_context WHERE province_code IN ($in) AND verified = 1"),
                'context_per_province' => round((int)val("SELECT COUNT(*) FROM local_context WHERE province_code IN ($in) AND verified = 1") / count($codes), 1)];
        }
        $cpp = array_column($bias, 'context_per_province');
        send_json(['features' => array_map(fn($f) => ['feature' => $f['feature'], 'calls' => (int)$f['n'], 'live' => (int)$f['live'], 'error_rate' => $f['n'] ? round($f['err'] / $f['n'], 3) : 0, 'avg_ms' => (int)round((float)$f['ms'])], $feat),
            'feedback' => array_map(fn($x) => ['category' => $x['category'], 'total' => (int)$x['n'], 'accepted' => (int)$x['ok'], 'precision' => $x['n'] ? round($x['ok'] / $x['n'], 3) : 0], $fb),
            'outcomes' => ['ai' => ['drafts' => (int)$aiD['n'], 'approved' => (int)$aiD['ok'], 'returned' => $ret(1), 'avg_score' => $aiD['sc'] === null ? null : (int)round((float)$aiD['sc'])],
                'human' => ['drafts' => (int)$huD['n'], 'approved' => (int)$huD['ok'], 'returned' => $ret(0), 'avg_score' => $huD['sc'] === null ? null : (int)round((float)$huD['sc'])]],
            'bias' => $bias, 'bias_flag' => $cpp && min($cpp) < 0.5 * max($cpp) ? 'Kelengkapan konteks lokal terverifikasi timpang antarwilayah (rasio < 50%). Prioritaskan verifikasi di wilayah terendah.' : null,
            'model' => ai_status()['model_label'], 'mode' => ai_status()['mode']]);
    });
    // ---------- S61 laporan berkala ----------
    $r->add('GET', '/api/reports', function () {
        current_user();
        ensure_scheduled_reports();
        send_json(array_map(fn($x) => ['id' => (int)$x['id'], 'period' => $x['period'], 'title' => $x['title'], 'period_start' => $x['period_start'], 'period_end' => $x['period_end'],
            'auto' => (bool)$x['auto'], 'ai_mode' => $x['ai_mode'], 'created_at' => $x['created_at'], 'author_name' => user_name($x['created_by'] ? (int)$x['created_by'] : null)],
            all('SELECT * FROM reports ORDER BY created_at DESC LIMIT 60')));
    });
    $r->add('POST', '/api/reports', function () {
        $u = require_roles('pimpinan', 'analis', 'redaktur');
        $p = (string)(json_body()['period'] ?? ''); if (!in_array($p, ['harian', 'mingguan', 'bulanan'], true)) fail(422, 'Periode harus harian/mingguan/bulanan');
        $id = build_report($u, $p, false, microtime(true) + 86400);
        audit_record($u, 'laporan.buat', 'report', $id, ['periode' => $p]);
        send_json(['id' => $id], 201);
    });
    $r->add('GET', '/api/reports/(\d+)', function ($id) {
        current_user();
        $x = one('SELECT * FROM reports WHERE id = ?', [(int)$id]); if (!$x) fail(404, 'Laporan tidak ditemukan');
        send_json(['id' => (int)$x['id'], 'period' => $x['period'], 'title' => $x['title'], 'period_start' => $x['period_start'], 'period_end' => $x['period_end'], 'summary' => $x['summary'],
            'ai_mode' => $x['ai_mode'], 'auto' => (bool)$x['auto'], 'content' => jdec($x['content'], new stdClass()), 'created_at' => $x['created_at']]);
    });
    $r->add('GET', '/api/reports/(\d+)/docx', function ($id) {
        $u = current_user();
        $x = one('SELECT * FROM reports WHERE id = ?', [(int)$id]); if (!$x) fail(404, 'Laporan tidak ditemukan');
        $c = jdec($x['content']);
        $xml = docx_p('SISTRA · Laporan Berkala Komunikasi Publik', true, 20, '1C5CAB') . docx_p($x['title'], true, 32)
            . docx_p('Disusun ' . substr($x['created_at'], 0, 16) . ' UTC · ' . ($x['auto'] ? 'terbit otomatis' : 'disusun manual') . ' · Data contoh bersifat sintetis', false, 18, '6F6E69')
            . docx_p('Ringkasan eksekutif', true, 26) . docx_p($x['summary'])
            . docx_p('Indikator utama', true, 26) . docx_table(['Indikator', 'Nilai'], array_map(fn($k) => [$k['label'], (string)$k['value']], $c['kpi'] ?? []))
            . docx_p('Isu prioritas', true, 26) . docx_table(['Isu', 'Naskah', 'Tayang', 'Komentar 7h', 'Negatif'], $c['issues'] ?? [])
            . docx_p('Indeks Efektivitas Komunikasi Publik (IEKP)', true, 26) . docx_table(['Isu', 'IEKP (0–100)'], array_map(fn($k) => [$k['issue'], (string)$k['score']], $c['iekp'] ?? []))
            . docx_p('Kanal dengan tayangan tertinggi', true, 26) . docx_table(['Kanal', 'Kirim', 'Tayangan', 'Gagal'], $c['channels_top'] ?? [])
            . docx_p('Perlu perhatian', true, 26) . docx_p(implode("\n", array_map(fn($a) => "• $a", $c['alerts'] ?? [])) ?: '-')
            . docx_p('Diunduh oleh ' . $u['full_name'] . ' (' . $u['username'] . ') · ' . now_iso(), false, 16, '6F6E69');
        $data = build_docx($xml);
        audit_record($u, 'laporan.unduh_docx', 'report', $x['id']);
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode('laporan-' . $x['period'] . '-' . substr($x['period_start'], 0, 10) . '.docx'));
        header('Content-Length: ' . strlen($data));
        echo $data;
    });
}
