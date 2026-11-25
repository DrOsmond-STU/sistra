<?php
declare(strict_types=1);

// Peta sebaran: titik per provinsi untuk peta interaktif (Leaflet + klaster) di setiap dashboard.

/** Titik tengah daratan 38 provinsi [lintang, bujur, radius sebaran derajat]. */
const PROV_LATLNG = [
    '11' => [4.37, 96.75, .45], '12' => [2.19, 99.07, .5], '13' => [-0.74, 100.80, .4], '14' => [0.29, 101.71, .5], '15' => [-1.61, 102.78, .45],
    '16' => [-3.32, 104.0, .55], '17' => [-3.58, 102.35, .2], '18' => [-4.86, 105.03, .4], '19' => [-2.74, 106.44, .18], '21' => [0.95, 104.40, .07],
    '31' => [-6.21, 106.84, .05], '32' => [-6.90, 107.60, .35], '33' => [-7.25, 110.14, .3], '34' => [-7.87, 110.43, .1], '35' => [-7.60, 112.24, .35],
    '36' => [-6.41, 106.06, .15], '51' => [-8.41, 115.19, .12], '52' => [-8.65, 117.36, .2], '53' => [-8.66, 121.08, .25], '61' => [-0.28, 111.48, .7],
    '62' => [-1.68, 113.38, .7], '63' => [-3.00, 115.28, .35], '64' => [0.54, 116.42, .7], '65' => [3.07, 116.04, .45], '71' => [0.70, 124.20, .2],
    '72' => [-1.43, 121.10, .45], '73' => [-3.67, 119.97, .45], '74' => [-4.14, 122.17, .3], '75' => [0.70, 122.45, .15], '76' => [-2.84, 119.23, .3],
    '81' => [-3.24, 129.80, .25], '82' => [1.00, 127.80, .3], '91' => [-2.90, 139.60, .5], '92' => [-1.34, 133.17, .45], '93' => [-7.20, 139.80, .7],
    '94' => [-3.80, 136.30, .6], '95' => [-4.10, 139.00, .4], '96' => [-1.20, 132.00, .4],
];

const GEO_LAYERS = [
    'artikel' => 'Artikel tayang di kanal',
    'naskah' => 'Naskah bermuatan lokal',
    'liputan' => 'Liputan lapangan',
    'kanal' => 'Kanal web 38 provinsi',
    'akun' => 'Akun media sosial resmi',
    'mitra' => 'Media mitra & KOL',
];

/** Sebar titik di sekitar titik tengah provinsi secara deterministik agar klaster terurai saat diperbesar. */
function geo_point(string $prov, string $key): ?array
{
    $c = PROV_LATLNG[$prov] ?? null;
    if (!$c) return null;
    $h = crc32($key);
    $ang = ($h % 3600) / 3600 * 2 * M_PI;
    $r = $c[2] * sqrt((($h >> 12) % 1000) / 1000);
    return [round($c[0] + $r * sin($ang), 4), round($c[1] + $r * cos($ang) * 1.1, 4)];
}

function geo_layer(string $layer, ?int $issue, ?string $since): array
{
    $pts = []; $national = 0;
    $add = function (?string $prov, string $key, string $title, string $sub, string $tone, string $link, ?string $at = null) use (&$pts) {
        if ($prov === null || !($ll = geo_point($prov, $key))) return false;
        $pts[] = ['lat' => $ll[0], 'lng' => $ll[1], 'prov' => $prov, 'title' => $title, 'sub' => $sub, 'tone' => $tone, 'link' => $link, 'at' => $at];
        return true;
    };
    $w = []; $p = [];
    switch ($layer) {
        case 'artikel':
            $sql = "SELECT d.id, d.status, d.sent_at, d.views, c.name cname, c.province_code prov, r.id rid, r.title, r.format
                    FROM distributions d JOIN channels c ON c.id = d.channel_id JOIN drafts r ON r.id = d.draft_id WHERE d.status IN ('terkirim','terjadwal','gagal')";
            if ($issue) { $sql .= ' AND r.issue_id = ?'; $p[] = $issue; }
            if ($since) { $sql .= ' AND COALESCE(d.sent_at, d.created_at) >= ?'; $p[] = $since; }
            $tone = ['terkirim' => 'good', 'terjadwal' => 'info', 'gagal' => 'bad'];
            foreach (all($sql . ' ORDER BY d.id DESC LIMIT 5000', $p) as $r) {
                $sub = $r['cname'] . ' · ' . $r['status'] . ($r['status'] === 'terkirim' ? ' · ' . number_format((int)$r['views'], 0, ',', '.') . ' tayangan' : '');
                if (!$add($r['prov'], 'd' . $r['id'], (string)$r['title'], $sub, $tone[$r['status']], '/editorial?id=' . $r['rid'], $r['sent_at'])) $national++;
            }
            break;
        case 'naskah':
            $sql = "SELECT id, title, status, format, province_code prov, updated_at FROM drafts WHERE status <> 'ditarik'";
            if ($issue) { $sql .= ' AND issue_id = ?'; $p[] = $issue; }
            if ($since) { $sql .= ' AND updated_at >= ?'; $p[] = $since; }
            $tone = ['tayang' => 'good', 'disetujui' => 'info', 'ditinjau' => 'warn', 'draf' => 'muted'];
            foreach (all($sql . ' ORDER BY id DESC LIMIT 5000', $p) as $r)
                if (!$add($r['prov'], 'n' . $r['id'], (string)$r['title'], ($r['format'] ?? 'naskah') . ' · ' . $r['status'], $tone[$r['status']] ?? 'muted', '/editorial?id=' . $r['id'], $r['updated_at'])) $national++;
            break;
        case 'liputan':
            $sql = 'SELECT id, title, location, status, province_code prov, event_at FROM coverage WHERE 1=1';
            if ($since) { $sql .= ' AND event_at >= ?'; $p[] = $since; }
            $tone = ['selesai' => 'good', 'berlangsung' => 'warn', 'direncanakan' => 'info', 'batal' => 'muted'];
            foreach (all($sql . ' ORDER BY event_at DESC LIMIT 5000', $p) as $r)
                if (!$add($r['prov'], 'l' . $r['id'], (string)$r['title'], $r['location'] . ' · ' . $r['status'], $tone[$r['status']] ?? 'muted', '/aset?tab=liputan', $r['event_at'])) $national++;
            break;
        case 'kanal':
            $since7 = iso_from_ts(microtime(true) - 7 * 86400);
            $tone = ['normal' => 'good', 'lambat' => 'warn', 'gangguan' => 'bad'];
            foreach (all("SELECT * FROM channels WHERE type = 'provinsi' AND province_code IS NOT NULL ORDER BY province_code") as $c) {
                $sent = (int)val("SELECT COUNT(*) FROM distributions WHERE channel_id = ? AND status = 'terkirim' AND sent_at >= ?", [$c['id'], $since7]);
                $ll = PROV_LATLNG[$c['province_code']] ?? null;
                if (!$ll) continue;
                $h = $c['health'] ?: 'normal';
                $pts[] = ['lat' => $ll[0], 'lng' => $ll[1], 'prov' => $c['province_code'], 'title' => (string)$c['name'], 'sub' => "kanal $h · $sent artikel terkirim 7 hari · uptime " . round((float)$c['uptime'], 1) . '%',
                    'tone' => $tone[$h] ?? 'muted', 'link' => '/distribusi?tab=cms', 'at' => $c['checked_at'], 'w' => $sent];
            }
            $national = (int)val("SELECT COUNT(*) FROM channels WHERE type <> 'provinsi' AND active = 1");
            break;
        case 'akun':
            foreach (all('SELECT id, platform, handle, name, province_code prov, followers, token_status FROM social_accounts WHERE active = 1 ORDER BY followers DESC LIMIT 5000') as $r) {
                $sub = $r['platform'] . ' · ' . number_format((int)$r['followers'], 0, ',', '.') . ' pengikut';
                if (!$add($r['prov'], 'a' . $r['id'], $r['handle'] . ' — ' . $r['name'], $sub, $r['token_status'] === 'aman' ? 'good' : 'warn', '/kanal-resmi?tab=akun')) $national++;
            }
            break;
        case 'mitra':
            foreach (all('SELECT id, name, type, province_code prov FROM media_partners WHERE active = 1 LIMIT 5000') as $r)
                if (!$add($r['prov'], 'm' . $r['id'], (string)$r['name'], 'media ' . $r['type'], 'info', '/distribusi?tab=pers')) $national++;
            foreach (all("SELECT id, name, platform, province_code prov, status FROM partners WHERE status <> 'nonaktif' LIMIT 5000") as $r)
                if (!$add($r['prov'], 'k' . $r['id'], (string)$r['name'], 'KOL ' . $r['platform'] . ' · ' . $r['status'], 'good', '/kanal-resmi?tab=mitra')) $national++;
            break;
    }
    $by = [];
    foreach ($pts as $pt) $by[$pt['prov']] = ($by[$pt['prov']] ?? 0) + ($pt['w'] ?? 1);
    arsort($by);
    $prov = [];
    foreach ($by as $code => $n) $prov[] = ['code' => (string)$code, 'name' => province_name((string)$code), 'count' => $n];
    $covered = count(array_unique(array_column($pts, 'prov')));
    return ['layer' => $layer, 'label' => GEO_LAYERS[$layer], 'rank_label' => $layer === 'kanal' ? 'Artikel terkirim 7 hari' : 'Provinsi teratas', 'points' => $pts, 'national' => $national, 'total' => count($pts) + $national,
        'provinces_covered' => $covered, 'by_province' => $prov];
}

/**
 * Perbaikan sekali jalan data demo: naskah bermuatan lokal yang (secara sintetis) tersalur ke kanal provinsi lain
 * dipindahkan ke kanal provinsinya sendiri. Hanya menyentuh baris buatan akun demo operator.
 */
function geo_fix_demo(): void
{
    $op = val("SELECT id FROM users WHERE username = 'operator'");
    if (!$op) return;
    $rows = all("SELECT d.id, d.draft_id, d.status, r.province_code prov FROM distributions d JOIN channels c ON c.id = d.channel_id JOIN drafts r ON r.id = d.draft_id
                 WHERE d.created_by = ? AND r.province_code IS NOT NULL AND c.type = 'provinsi' AND c.province_code <> r.province_code ORDER BY d.draft_id, d.id", [$op]);
    $chan = [];
    foreach (all("SELECT id, code, province_code FROM channels WHERE type = 'provinsi'") as $c) $chan[$c['province_code']] = $c;
    foreach ($rows as $x) {
        $t = $chan[$x['prov']] ?? null;
        $has = $t ? val('SELECT 1 FROM distributions WHERE draft_id = ? AND channel_id = ?', [$x['draft_id'], $t['id']]) : true;
        if ($has) { q('DELETE FROM distributions WHERE id = ?', [$x['id']]); continue; }
        q('UPDATE distributions SET channel_id = ?, url = CASE WHEN url IS NULL THEN NULL ELSE ? END WHERE id = ?',
            [$t['id'], 'https://contoh.invalid/' . strtolower($t['code']) . '/naskah-' . $x['draft_id'], $x['id']]);
    }
}

function register_routes_geo(Router $r): void
{
    $r->add('GET', '/api/geo/layers', function () {
        current_user();
        send_json(array_map(fn($k) => ['key' => $k, 'label' => GEO_LAYERS[$k]], array_keys(GEO_LAYERS)));
    });
    $r->add('GET', '/api/geo/points', function () {
        current_user();
        $layer = (string)qparam('layer', 'artikel');
        if (!isset(GEO_LAYERS[$layer])) fail(422, 'Lapisan peta tidak dikenal');
        $days = qint('days', 0, 0, 3650);
        $issue = qparam('issue_id') !== null ? qint('issue_id', 0, 1, PHP_INT_MAX) : null;
        send_json(geo_layer($layer, $issue, $days ? iso_from_ts(microtime(true) - $days * 86400) : null));
    });
}
