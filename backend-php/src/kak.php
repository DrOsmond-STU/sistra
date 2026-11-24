<?php
/**
 * Kelengkapan katalog KAK untuk Modul 4–8 (S26–S65): skema tambahan, logika, dan rute.
 * Rincian per fitur dipetakan di halaman "Matriks KAK" pada frontend.
 */
declare(strict_types=1);

function migrate_kak(): void
{
    db()->exec(<<<SQL
CREATE TABLE IF NOT EXISTS local_context (id INTEGER PRIMARY KEY, province_code TEXT NOT NULL, category TEXT NOT NULL, content TEXT NOT NULL, source TEXT NOT NULL DEFAULT '',
  verified INTEGER NOT NULL DEFAULT 0, verified_by INTEGER, verified_at TEXT, created_by INTEGER, created_at TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS ix_lc_prov ON local_context(province_code);
CREATE TABLE IF NOT EXISTS editorial_feedback (id INTEGER PRIMARY KEY, draft_id INTEGER NOT NULL, category TEXT, severity TEXT, excerpt TEXT, suggestion TEXT,
  decision TEXT NOT NULL, note TEXT NOT NULL DEFAULT '', user_id INTEGER NOT NULL, at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS media_outputs (id INTEGER PRIMARY KEY, draft_id INTEGER, kind TEXT NOT NULL, title TEXT NOT NULL DEFAULT '', spec TEXT NOT NULL, template_code TEXT,
  ai_mode TEXT, asset_id INTEGER, ms INTEGER NOT NULL DEFAULT 0, created_by INTEGER, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS assets (id INTEGER PRIMARY KEY, kind TEXT NOT NULL, title TEXT NOT NULL, filename TEXT NOT NULL DEFAULT '', mime TEXT NOT NULL DEFAULT '',
  size INTEGER NOT NULL DEFAULT 0, sha256 TEXT, tags TEXT NOT NULL DEFAULT '[]', ai_tags TEXT NOT NULL DEFAULT '[]', description TEXT NOT NULL DEFAULT '',
  credit TEXT NOT NULL DEFAULT '', license TEXT NOT NULL, license_expires_at TEXT, issue_id INTEGER, province_code TEXT, coverage_id INTEGER,
  created_by INTEGER, created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS draft_assets (draft_id INTEGER NOT NULL, asset_id INTEGER NOT NULL, PRIMARY KEY (draft_id, asset_id));
CREATE TABLE IF NOT EXISTS visual_templates (id INTEGER PRIMARY KEY, code TEXT NOT NULL, name TEXT NOT NULL, kind TEXT NOT NULL, version INTEGER NOT NULL, spec TEXT NOT NULL,
  locked INTEGER NOT NULL DEFAULT 0, is_current INTEGER NOT NULL DEFAULT 1, note TEXT NOT NULL DEFAULT '', created_by INTEGER, created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS coverage (id INTEGER PRIMARY KEY, title TEXT NOT NULL, event_at TEXT NOT NULL, location TEXT NOT NULL, province_code TEXT, reporter_id INTEGER,
  status TEXT NOT NULL, brief TEXT NOT NULL DEFAULT '', brief_id INTEGER, created_by INTEGER, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS notifications (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, kind TEXT NOT NULL, text TEXT NOT NULL, link TEXT NOT NULL DEFAULT '', read_at TEXT, at TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS ix_notif_user ON notifications(user_id, read_at);
CREATE TABLE IF NOT EXISTS response_packs (id INTEGER PRIMARY KEY, issue_id INTEGER, alert_id INTEGER, level TEXT NOT NULL, title TEXT NOT NULL, trigger_text TEXT NOT NULL DEFAULT '',
  holding_statement TEXT NOT NULL DEFAULT '', key_messages TEXT NOT NULL DEFAULT '[]', qa TEXT NOT NULL DEFAULT '[]', data_points TEXT NOT NULL DEFAULT '[]',
  spokesperson TEXT NOT NULL DEFAULT '', donts TEXT NOT NULL DEFAULT '[]', status TEXT NOT NULL, ai_mode TEXT, deadline_at TEXT NOT NULL, published_at TEXT,
  published_by INTEGER, created_by INTEGER, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS partners (id INTEGER PRIMARY KEY, name TEXT NOT NULL, handle TEXT NOT NULL, platform TEXT NOT NULL, category TEXT NOT NULL, followers INTEGER NOT NULL DEFAULT 0,
  province_code TEXT, contract_no TEXT NOT NULL DEFAULT '', contract_start TEXT, contract_end TEXT, contract_asset_id INTEGER, disclosure TEXT NOT NULL,
  status TEXT NOT NULL, notes TEXT NOT NULL DEFAULT '', created_by INTEGER, created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS partner_collabs (id INTEGER PRIMARY KEY, partner_id INTEGER NOT NULL, campaign_id INTEGER, draft_id INTEGER, posted_at TEXT, url TEXT NOT NULL DEFAULT '',
  disclosure_ok INTEGER NOT NULL DEFAULT 0, reach INTEGER NOT NULL DEFAULT 0, engagement INTEGER NOT NULL DEFAULT 0, notes TEXT NOT NULL DEFAULT '', created_by INTEGER, created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS campaigns (id INTEGER PRIMARY KEY, name TEXT NOT NULL, issue_id INTEGER, objective TEXT NOT NULL DEFAULT '', audience TEXT NOT NULL DEFAULT '',
  regions TEXT NOT NULL DEFAULT '[]', key_messages TEXT NOT NULL DEFAULT '', start_date TEXT NOT NULL, end_date TEXT NOT NULL, status TEXT NOT NULL, kpi TEXT NOT NULL DEFAULT '{}',
  approved_by INTEGER, approved_at TEXT, signature TEXT, evaluation TEXT, evaluation_mode TEXT, created_by INTEGER, created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS campaign_slots (id INTEGER PRIMARY KEY, campaign_id INTEGER NOT NULL, date TEXT NOT NULL, account_id INTEGER, partner_id INTEGER, draft_id INTEGER,
  post_task_id INTEGER, note TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS audience_curves (province_code TEXT PRIMARY KEY, hours TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS renditions (id INTEGER PRIMARY KEY, draft_id INTEGER NOT NULL, profile TEXT NOT NULL, status TEXT NOT NULL, size INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL,
  UNIQUE (draft_id, profile));
CREATE TABLE IF NOT EXISTS media_partners (id INTEGER PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL, province_code TEXT, contact TEXT NOT NULL DEFAULT '', active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS press_packs (id INTEGER PRIMARY KEY, draft_id INTEGER NOT NULL, title TEXT NOT NULL, note TEXT NOT NULL DEFAULT '', asset_ids TEXT NOT NULL DEFAULT '[]',
  expires_at TEXT NOT NULL, created_by INTEGER, created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS press_links (id INTEGER PRIMARY KEY, pack_id INTEGER NOT NULL, partner_id INTEGER NOT NULL, token_hash TEXT NOT NULL UNIQUE, expires_at TEXT NOT NULL,
  revoked_at TEXT, created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS press_downloads (id INTEGER PRIMARY KEY, link_id INTEGER NOT NULL, pack_id INTEGER NOT NULL, partner_id INTEGER NOT NULL, ip TEXT, at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS api_clients (id INTEGER PRIMARY KEY, name TEXT NOT NULL, org TEXT NOT NULL, token_prefix TEXT NOT NULL, token_hash TEXT NOT NULL UNIQUE, scopes TEXT NOT NULL,
  rate_per_min INTEGER NOT NULL, expires_at TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, created_by INTEGER, created_at TEXT NOT NULL, last_used_at TEXT);
CREATE TABLE IF NOT EXISTS api_calls (id INTEGER PRIMARY KEY, client_id INTEGER, path TEXT NOT NULL, status INTEGER NOT NULL, ip TEXT, at TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS ix_api_calls ON api_calls(client_id, at);
CREATE TABLE IF NOT EXISTS channel_checks (id INTEGER PRIMARY KEY, channel_id INTEGER NOT NULL, point TEXT NOT NULL, ok INTEGER NOT NULL, latency_ms INTEGER NOT NULL, at TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS ix_checks ON channel_checks(channel_id, at);
CREATE TABLE IF NOT EXISTS channel_incidents (id INTEGER PRIMARY KEY, channel_id INTEGER NOT NULL, started_at TEXT NOT NULL, ended_at TEXT, cause TEXT NOT NULL DEFAULT '', points TEXT NOT NULL DEFAULT '[]');
CREATE TABLE IF NOT EXISTS metric_hourly (channel_id INTEGER NOT NULL, hour TEXT NOT NULL, views INTEGER NOT NULL, uniques INTEGER NOT NULL, clicks INTEGER NOT NULL,
  avg_time_sec INTEGER NOT NULL, comments INTEGER NOT NULL, video_retention REAL NOT NULL, PRIMARY KEY (channel_id, hour));
CREATE TABLE IF NOT EXISTS reports (id INTEGER PRIMARY KEY, period TEXT NOT NULL, period_start TEXT NOT NULL, period_end TEXT NOT NULL, title TEXT NOT NULL, content TEXT NOT NULL,
  summary TEXT NOT NULL DEFAULT '', ai_mode TEXT, auto INTEGER NOT NULL DEFAULT 0, created_by INTEGER, created_at TEXT NOT NULL);
SQL);
    add_col('briefs', 'gen_ms', 'INTEGER');
    add_col('social_comments', 'parent_id', 'INTEGER');
    add_col('social_accounts', 'quota_daily', 'INTEGER NOT NULL DEFAULT 4');
    add_col('social_accounts', 'quota_weekly', 'INTEGER NOT NULL DEFAULT 20');
    add_col('social_accounts', 'token_rotated_at', 'TEXT');
    add_col('post_tasks', 'campaign_id', 'INTEGER');
    add_col('post_tasks', 'impressions', 'INTEGER NOT NULL DEFAULT 0');
    foreach (['sync_ms' => 'INTEGER', 'scheduled_at' => 'TEXT', 'uniques' => 'INTEGER NOT NULL DEFAULT 0', 'avg_time_sec' => 'INTEGER NOT NULL DEFAULT 0',
        'comments' => 'INTEGER NOT NULL DEFAULT 0', 'taken_down_at' => 'TEXT', 'takedown_reason' => 'TEXT'] as $c => $def) add_col('distributions', $c, $def);
}

// ======================= konstanta =======================
const LC_CATEGORIES = ['ibu_kota' => 'Ibu kota & pusat layanan', 'bahasa' => 'Bahasa & sapaan', 'layanan' => 'Layanan publik', 'istilah' => 'Istilah lokal', 'budaya' => 'Budaya & kearifan lokal', 'tokoh' => 'Tokoh/lembaga rujukan'];
const ASSET_LICENSES = ['milik_sendiri' => 'Milik institusi', 'cc_by' => 'CC BY 4.0', 'berlisensi' => 'Berlisensi (berbatas waktu)', 'editorial' => 'Hanya editorial', 'mitra' => 'Dari mitra (izin tertulis)'];
const RENDITION_PROFILES = [
    'og-1200x630' => ['Kartu OpenGraph/Facebook', 'image/jpeg', '1200×630'], 'x-1600x900' => ['X/Twitter 16:9', 'image/jpeg', '1600×900'],
    'ig-1080x1350' => ['Instagram feed 4:5', 'image/jpeg', '1080×1350'], 'ig-story-1080x1920' => ['Story/Reels 9:16', 'image/jpeg', '1080×1920'],
    'wa-800x800' => ['WhatsApp 1:1', 'image/jpeg', '800×800'], 'yt-1280x720' => ['Thumbnail YouTube', 'image/jpeg', '1280×720'],
    'video-916' => ['Video 9:16 H.264', 'video/mp4', '1080×1920'], 'video-169' => ['Video 16:9 H.264', 'video/mp4', '1920×1080'],
    'html-portal' => ['Artikel HTML portal', 'text/html', 'responsif'], 'amp' => ['Halaman AMP', 'text/html', 'AMP'],
    'rss' => ['Butir RSS/Atom', 'application/rss+xml', '—'], 'pdf-cetak' => ['PDF siap cetak A4', 'application/pdf', 'A4'],
];
const MONITOR_POINTS = ['Jakarta', 'Medan', 'Surabaya', 'Makassar', 'Jayapura'];
const IEKP_WEIGHTS = ['jangkauan' => 0.25, 'keterlibatan' => 0.20, 'sentimen' => 0.25, 'kecepatan_respons' => 0.15, 'cakupan_wilayah' => 0.15];
const WIT = ['81', '82', '91', '92', '93', '94', '95', '96'];
const WITA = ['51', '52', '53', '63', '64', '65', '71', '72', '73', '74', '75', '76'];

function tz_offset(?string $prov): int { return $prov === null ? 7 : (in_array($prov, WIT, true) ? 9 : (in_array($prov, WITA, true) ? 8 : 7)); }
function tz_label(?string $prov): string { return ['7' => 'WIB', '8' => 'WITA', '9' => 'WIT'][(string)tz_offset($prov)]; }

// ======================= helper umum =======================
function notify(array $userIds, string $kind, string $text, string $link = '', ?int $except = null): void
{
    $now = now_iso();
    foreach (array_unique(array_filter(array_map('intval', $userIds))) as $id) {
        if ($except !== null && $id === $except) continue;
        insert('notifications', ['user_id' => $id, 'kind' => $kind, 'text' => mb_substr($text, 0, 300), 'link' => $link, 'at' => $now]);
    }
}
function users_with_roles(string ...$roles): array
{
    return array_map(fn($u) => (int)$u['id'], array_values(array_filter(all('SELECT id, roles FROM users WHERE active = 1'), fn($u) => (bool)array_intersect($roles, jdec($u['roles'])))));
}

/** Normalisasi daftar fakta brief: string lama → objek bersumber. */
function fact_items($raw): array
{
    $arr = is_array($raw) ? $raw : jdec(is_string($raw) ? $raw : null);
    $out = [];
    foreach ($arr as $f) {
        if (is_string($f)) { $t = trim($f); if ($t !== '') $out[] = ['text' => $t, 'source' => '', 'url' => '', 'reliability' => '']; continue; }
        if (!is_array($f)) continue;
        $t = trim((string)($f['text'] ?? '')); if ($t === '') continue;
        $url = trim((string)($f['url'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = '';
        $rel = strtoupper(trim((string)($f['reliability'] ?? '')));
        $out[] = ['text' => mb_substr($t, 0, 500), 'source' => mb_substr(trim((string)($f['source'] ?? '')), 0, 200), 'url' => mb_substr($url, 0, 500),
            'reliability' => in_array($rel, ['A', 'B', 'C', 'D', 'E', 'F'], true) ? $rel : ''];
    }
    return array_slice($out, 0, 30);
}
function draft_facts(array $d): array
{
    return $d['brief_id'] ? fact_items(val('SELECT facts FROM briefs WHERE id = ?', [$d['brief_id']])) : [];
}

/** S33: skor verifikasi fakta — setiap kalimat berklaim dicocokkan ke daftar fakta bersumber. */
function verify_claims(string $body, array $facts): array
{
    // penanda verifikasi yang ditulis setelah titik melekat ke kalimat sebelumnya
    $body = preg_replace('/([.!?])[ \t]*(\[DATA PERLU DIVERIFIKASI\])/u', ' $2$1', $body);
    $sent = array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+|\n+/u', $body)), fn($s) => mb_strlen($s) > 8));
    $tok = function (string $s): array {
        $s = mb_strtolower($s);
        preg_match_all('/\d[\d.,]*|[\p{L}]{4,}/u', $s, $m);
        return array_values(array_unique(array_map(fn($x) => rtrim($x, '.,'), $m[0])));
    };
    $ft = array_map(fn($f) => $tok($f['text']), $facts);
    $claims = []; $ok = 0;
    foreach ($sent as $s) {
        if (str_starts_with($s, '[SIMULASI')) continue;
        $isClaim = (bool)preg_match('/\d|persen|%|\bRp\b|juta|ribu|miliar|meningkat|menurun|naik|turun|sebanyak|mencapai|tercatat/iu', $s);
        if (!$isClaim) continue;
        if (preg_match('/\[DATA PERLU DIVERIFIKASI\]/u', $s)) { $claims[] = ['text' => $s, 'status' => 'perlu_verifikasi', 'source' => null]; continue; }
        $st = $tok($s); preg_match_all('/\d[\d.,]*/u', $s, $nm); $nums = array_map(fn($x) => rtrim($x, '.,'), $nm[0]);
        $best = null; $bestScore = 0.0;
        if (preg_match('/\[F(\d+)\]/', $s, $fm) && isset($facts[(int)$fm[1] - 1])) { $best = (int)$fm[1] - 1; $bestScore = 1.0; }
        else foreach ($ft as $i => $t) {
            $numsOk = !$nums || !array_diff($nums, $t);
            $ov = $st ? count(array_intersect($st, $t)) / max(1, min(count($st), count($t))) : 0;
            $score = ($numsOk && $nums ? 0.6 : 0) + $ov;
            if ($score > $bestScore) { $bestScore = $score; $best = $i; }
        }
        if ($best !== null && $bestScore >= 0.6) { $ok++; $claims[] = ['text' => $s, 'status' => 'terverifikasi', 'source' => $facts[$best]['source'] ?: ('Fakta F' . ($best + 1)), 'fact' => $facts[$best]['text']]; }
        else $claims[] = ['text' => $s, 'status' => 'tidak_bersumber', 'source' => null];
    }
    $n = count($claims);
    return ['score' => $n ? (int)round(100 * $ok / $n) : 100, 'claims' => $claims, 'verified' => $ok, 'total' => $n, 'facts' => count($facts),
        'note' => $n ? '' : 'Tidak ada kalimat berklaim angka/data yang perlu dicocokkan.'];
}

/** S43: masking data pribadi (NIK, nomor telepon, surel, nomor rekening). */
function pii_mask(string $t): array
{
    $n = 0;
    $t = preg_replace_callback('/\b\d{16}\b/', function ($m) use (&$n) { $n++; return substr($m[0], 0, 4) . str_repeat('•', 8) . substr($m[0], -4); }, $t);
    $t = preg_replace_callback('/(\+62|62|0)8\d{1,2}[-\s]?\d{3,4}[-\s]?\d{3,5}/', function ($m) use (&$n) { $n++; return substr($m[0], 0, 4) . '••••' . substr($m[0], -2); }, $t);
    $t = preg_replace_callback('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', function ($m) use (&$n) { $n++; $p = explode('@', $m[0]); return mb_substr($p[0], 0, 2) . '•••@' . $p[1]; }, $t);
    $t = preg_replace_callback('/\b(rek(?:ening)?\.?\s*(?:no\.?|nomor)?\s*:?\s*)(\d{8,15})\b/iu', function ($m) use (&$n) { $n++; return $m[1] . str_repeat('•', strlen($m[2]) - 3) . substr($m[2], -3); }, $t);
    return [$t, $n];
}

function norm_cdf(float $x): float
{
    // Abramowitz–Stegun 7.1.26
    $t = 1 / (1 + 0.3275911 * abs($x) / sqrt(2));
    $y = 1 - ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x / 2);
    return $x >= 0 ? (1 + $y) / 2 : (1 - $y) / 2;
}

/** S57: pergeseran sentimen sebelum vs sesudah, uji z dua proporsi (proporsi negatif). */
function sentiment_shift(?int $issueId, string $start, string $end): array
{
    $startTs = (float)ts_from_iso($start); $endTs = min((float)ts_from_iso($end), microtime(true));
    $len = max(86400, $endTs - $startTs);
    $before = [iso_from_ts($startTs - $len), $start]; $after = [$start, iso_from_ts($endTs)];
    $cnt = function (array $w) use ($issueId) {
        $p = [$w[0], $w[1]]; $iss = '';
        if ($issueId) { $iss = 'AND issue_id = ?'; $p[] = $issueId; }
        $r = one("SELECT COUNT(*) n, SUM(sentiment='negatif') neg, SUM(sentiment='positif') pos FROM social_comments WHERE sentiment IS NOT NULL AND at >= ? AND at < ? $iss", $p);
        return ['n' => (int)$r['n'], 'neg' => (int)$r['neg'], 'pos' => (int)$r['pos']];
    };
    $a = $cnt($before); $b = $cnt($after);
    $p1 = $a['n'] ? $a['neg'] / $a['n'] : 0.0; $p2 = $b['n'] ? $b['neg'] / $b['n'] : 0.0;
    $z = null; $pv = null;
    if ($a['n'] >= 5 && $b['n'] >= 5) {
        $pp = ($a['neg'] + $b['neg']) / ($a['n'] + $b['n']);
        $se = sqrt(max(1e-9, $pp * (1 - $pp) * (1 / $a['n'] + 1 / $b['n'])));
        $z = ($p2 - $p1) / $se; $pv = 2 * (1 - norm_cdf(abs($z)));
    }
    return ['before' => ['from' => $before[0], 'to' => $before[1]] + $a + ['neg_ratio' => round($p1, 3), 'pos_ratio' => $a['n'] ? round($a['pos'] / $a['n'], 3) : 0.0],
        'after' => ['from' => $after[0], 'to' => $after[1]] + $b + ['neg_ratio' => round($p2, 3), 'pos_ratio' => $b['n'] ? round($b['pos'] / $b['n'], 3) : 0.0],
        'delta_neg' => round($p2 - $p1, 3), 'z' => $z === null ? null : round($z, 2), 'p_value' => $pv === null ? null : round($pv, 4),
        'significant' => $pv !== null && $pv < 0.05,
        'interpretation' => $pv === null ? 'Sampel kurang (minimal 5 komentar per periode).' : (($pv < 0.05 ? 'Perubahan signifikan (p < 0,05): ' : 'Perubahan belum signifikan: ') . 'proporsi komentar negatif ' . ($p2 <= $p1 ? 'turun' : 'naik') . ' dari ' . round($p1 * 100) . '% ke ' . round($p2 * 100) . '%.')];
}

/** S59: Indeks Efektivitas Komunikasi Publik 0–100 per isu. */
function iekp_for_issue(int $issueId, int $days = 30): array
{
    $since = iso_from_ts(microtime(true) - $days * 86400); $d7 = iso_from_ts(microtime(true) - 7 * 86400);
    $dIds = array_map('intval', array_column(all('SELECT id FROM drafts WHERE issue_id = ? AND created_at >= ?', [$issueId, $since]), 'id'));
    $in = $dIds ? implode(',', $dIds) : '0';
    $views = (int)val("SELECT COALESCE(SUM(views),0) FROM distributions WHERE draft_id IN ($in) AND status = 'terkirim'");
    $pt = one("SELECT COALESCE(SUM(reach),0) r, COALESCE(SUM(engagement),0) e FROM post_tasks WHERE draft_id IN ($in) AND status = 'diunggah'");
    $reach = $views + (int)$pt['r'];
    $er = $pt['r'] > 0 ? $pt['e'] / $pt['r'] : 0.0;
    $c = one('SELECT COUNT(*) n, SUM(sentiment = \'negatif\') neg FROM social_comments WHERE issue_id = ? AND sentiment IS NOT NULL AND at >= ?', [$issueId, $d7]);
    $neg = $c['n'] ? $c['neg'] / $c['n'] : 0.3;
    $h = val("SELECT AVG((julianday(replied_at) - julianday(at)) * 24) FROM social_comments WHERE issue_id = ? AND status = 'dibalas' AND at >= ?", [$issueId, $since]);
    $chs = (int)val("SELECT COUNT(DISTINCT channel_id) FROM distributions WHERE draft_id IN ($in) AND status = 'terkirim'");
    $comp = [
        'jangkauan' => min(100, $reach / 500000 * 100),
        'keterlibatan' => min(100, $er / 0.05 * 100),
        'sentimen' => (1 - $neg) * 100,
        'kecepatan_respons' => $h === null ? 50 : max(0, min(100, 100 - ((float)$h - 2) * 15)),
        'cakupan_wilayah' => min(100, $chs / 39 * 100),
    ];
    $score = 0.0; foreach (IEKP_WEIGHTS as $k => $w) $score += $w * $comp[$k];
    return ['score' => (int)round($score), 'components' => array_map(fn($v) => (int)round($v), $comp),
        'raw' => ['reach' => $reach, 'engagement_rate' => round($er, 4), 'negative_ratio' => round($neg, 3), 'avg_reply_hours' => $h === null ? null : round((float)$h, 1), 'channels' => $chs, 'drafts' => count($dIds)]];
}

/** S51: rekomendasi jam tayang berikutnya dari kurva jam puncak provinsi (waktu setempat). */
function curve_for(?string $prov): array
{
    $h = val('SELECT hours FROM audience_curves WHERE province_code = ?', [$prov ?? 'NAS']);
    return $h ? jdec($h) : array_fill(0, 24, 1);
}
function recommend_time(?string $prov, float $fromTs): array
{
    $curve = curve_for($prov); $off = tz_offset($prov);
    $best = null;
    for ($i = 1; $i <= 30; $i++) {
        $ts = (floor($fromTs / 3600) + $i) * 3600;
        $local = (int)gmdate('G', (int)$ts + $off * 3600);
        $score = $curve[$local] ?? 0;
        if ($best === null || $score > $best[1] + 1e-9) $best = [$ts, $score, $local];
    }
    return ['at' => iso_from_ts($best[0]), 'local_hour' => sprintf('%02d.00 %s', $best[2], tz_label($prov)), 'audience_index' => round($best[1], 2)];
}

/** Proses distribusi terjadwal yang sudah jatuh tempo (dipanggil saat antrean dibuka). */
function process_due_distributions(?array $u): int
{
    $due = all("SELECT x.*, c.health, c.code FROM distributions x JOIN channels c ON c.id = x.channel_id WHERE x.status = 'terjadwal' AND x.scheduled_at <= ?", [now_iso()]);
    $n = 0;
    foreach ($due as $x) {
        $d = one('SELECT * FROM drafts WHERE id = ?', [$x['draft_id']]);
        if (!$d || !in_array($d['status'], ['disetujui', 'tayang'], true)) { q("UPDATE distributions SET status = 'batal', error = 'Naskah tidak lagi berstatus disetujui' WHERE id = ?", [$x['id']]); continue; }
        if ($d['status'] === 'disetujui') {
            // ditayangkan atas nama operator yang menjadwalkan; tanda tangan persetujuan tetap diverifikasi
            $op = one('SELECT * FROM users WHERE id = ?', [(int)$x['created_by']]);
            try { publish_draft($d, $op ?? $u ?? []); }
            catch (HttpError $e) { q("UPDATE distributions SET status = 'gagal', error = ? WHERE id = ?", ['Gagal menayangkan: ' . mb_substr($e->getMessage(), 0, 200), $x['id']]); continue; }
        }
        $ok = ($x['health'] ?: 'normal') !== 'gangguan';
        q('UPDATE distributions SET status = ?, sent_at = ?, error = ?, url = ?, sync_ms = ? WHERE id = ?', [$ok ? 'terkirim' : 'gagal', $ok ? now_iso() : null,
            $ok ? null : 'Kanal tidak merespons saat jadwal tiba', $ok ? 'https://contoh.invalid/' . strtolower($x['code']) . '/naskah-' . $x['draft_id'] : null, $ok ? mt_rand(700, 4200) : null, $x['id']]);
        $n++;
    }
    return $n;
}

/** S55: uji ketersediaan dari 5 titik pantau (simulasi) + catat insiden. */
function run_uptime_checks(): array
{
    $now = now_iso(); $res = ['normal' => 0, 'lambat' => 0, 'gangguan' => 0];
    foreach (all("SELECT * FROM channels WHERE type IN ('nasional','provinsi')") as $c) {
        $roll = mt_rand(1, 100);
        $h = $c['health'] === 'gangguan' ? ($roll <= 50 ? 'normal' : 'gangguan') : ($roll <= 4 ? 'lambat' : ($roll <= 5 ? 'gangguan' : 'normal'));
        $fails = [];
        foreach (MONITOR_POINTS as $i => $pt) {
            $ok = $h !== 'gangguan' || ($i === 0 && mt_rand(0, 1));
            $lat = $ok ? ($h === 'lambat' ? mt_rand(900, 2600) : mt_rand(90, 420) + $i * 35) : 0;
            insert('channel_checks', ['channel_id' => $c['id'], 'point' => $pt, 'ok' => $ok ? 1 : 0, 'latency_ms' => $lat, 'at' => $now]);
            if (!$ok) $fails[] = $pt;
        }
        $lat = $h === 'normal' ? mt_rand(120, 450) : ($h === 'lambat' ? mt_rand(900, 2500) : 0);
        q('UPDATE channels SET health = ?, latency_ms = ?, checked_at = ? WHERE id = ?', [$h, $lat, $now, $c['id']]);
        $open = one('SELECT id FROM channel_incidents WHERE channel_id = ? AND ended_at IS NULL', [$c['id']]);
        if (count($fails) >= 3 && !$open) insert('channel_incidents', ['channel_id' => $c['id'], 'started_at' => $now, 'cause' => 'Tidak merespons dari ' . count($fails) . ' titik pantau', 'points' => jenc($fails)]);
        if (count($fails) < 3 && $open) q('UPDATE channel_incidents SET ended_at = ? WHERE id = ?', [$now, $open['id']]);
        $res[$h]++;
    }
    return $res;
}

// ---------------- DOCX minimal (S61) ----------------
function docx_xml(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function docx_p(string $text, bool $bold = false, int $size = 22, string $color = ''): string
{
    $rpr = ($bold ? '<w:b/>' : '') . "<w:sz w:val=\"$size\"/>" . ($color ? "<w:color w:val=\"$color\"/>" : '');
    $runs = [];
    foreach (explode("\n", $text) as $i => $line) $runs[] = ($i ? '<w:r><w:br/></w:r>' : '') . "<w:r><w:rPr>$rpr</w:rPr><w:t xml:space=\"preserve\">" . docx_xml($line) . '</w:t></w:r>';
    return '<w:p><w:pPr><w:spacing w:after="120"/></w:pPr>' . implode('', $runs) . '</w:p>';
}
function docx_table(array $head, array $rows): string
{
    $cell = fn(string $t, bool $b = false) => '<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/></w:tcPr><w:p><w:r><w:rPr>' . ($b ? '<w:b/>' : '') . '<w:sz w:val="18"/></w:rPr><w:t xml:space="preserve">' . docx_xml($t) . '</w:t></w:r></w:p></w:tc>';
    $x = '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>'
        . implode('', array_map(fn($s) => "<w:$s w:val=\"single\" w:sz=\"4\" w:color=\"999999\"/>", ['top', 'left', 'bottom', 'right', 'insideH', 'insideV'])) . '</w:tblBorders></w:tblPr>';
    $x .= '<w:tr>' . implode('', array_map(fn($h) => $cell((string)$h, true), $head)) . '</w:tr>';
    foreach ($rows as $r) $x .= '<w:tr>' . implode('', array_map(fn($c) => $cell((string)$c), $r)) . '</w:tr>';
    return $x . '</w:tbl>' . docx_p('');
}
function build_docx(string $bodyXml): string
{
    if (!class_exists('ZipArchive')) fail(501, 'Ekstensi zip tidak tersedia di server');
    $tmp = tempnam(sys_get_temp_dir(), 'docx');
    $z = new ZipArchive(); $z->open($tmp, ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $z->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        . $bodyXml . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr></w:body></w:document>');
    $z->close();
    $data = (string)file_get_contents($tmp); @unlink($tmp);
    return $data;
}

// ---------------- laporan berkala (S61) ----------------
function report_period_range(string $period, float $endTs): array
{
    $days = ['harian' => 1, 'mingguan' => 7, 'bulanan' => 30][$period];
    $end = (float)strtotime(gmdate('Y-m-d', (int)$endTs) . 'T00:00:00Z');
    return [iso_from_ts($end - $days * 86400), iso_from_ts($end), $days];
}
function build_report(?array $u, string $period, bool $auto, ?float $endTs = null): int
{
    [$from, $to, $days] = report_period_range($period, $endTs ?? microtime(true));
    $s = exec_summary(); $a = analytics_overview(max(7, $days)); $k = $s['kpi'];
    $iekp = [];
    foreach (array_slice($s['issues'], 0, 6) as $i) { $id = (int)val('SELECT id FROM issues WHERE code = ?', [$i['code']]); $iekp[] = ['issue' => $i['name'], 'score' => iekp_for_issue($id)['score']]; }
    $produced = (int)val('SELECT COUNT(*) FROM drafts WHERE created_at >= ? AND created_at < ?', [$from, $to]);
    $published = (int)val("SELECT COUNT(*) FROM drafts WHERE status = 'tayang' AND COALESCE(published_at, updated_at) >= ? AND COALESCE(published_at, updated_at) < ?", [$from, $to]);
    $sent = (int)val("SELECT COUNT(*) FROM distributions WHERE status = 'terkirim' AND sent_at >= ? AND sent_at < ?", [$from, $to]);
    $views = (int)val('SELECT COALESCE(SUM(views),0) FROM metric_hourly WHERE hour >= ? AND hour < ?', [$from, $to]);
    $replied = (int)val("SELECT COUNT(*) FROM social_comments WHERE status = 'dibalas' AND replied_at >= ? AND replied_at < ?", [$from, $to]);
    $content = ['kpi' => [
            ['label' => 'Naskah disusun', 'value' => $produced], ['label' => 'Naskah tayang', 'value' => $published], ['label' => 'Pengiriman kanal web', 'value' => $sent],
            ['label' => 'Tayangan kanal web', 'value' => $views], ['label' => 'Komentar dibalas', 'value' => $replied], ['label' => 'Kanal sehat', 'value' => "{$k['channels_ok']}/{$k['channels_total']}"],
        ],
        'issues' => array_map(fn($i) => [$i['name'], $i['drafts'], $i['published'], $i['comments'], round($i['negative_ratio'] * 100) . '%'], $s['issues']),
        'iekp' => $iekp,
        'channels_top' => array_map(fn($c) => [$c['name'], $c['sent'], $c['views'], $c['failed']], array_slice($a['distribution']['channels'], 0, 10)),
        'alerts' => array_map(fn($x) => $x['text'], $s['alerts']),
        'decisions' => array_map(fn($d) => $d['title'], $s['decisions'])];
    $data = "Periode {$period} " . substr($from, 0, 10) . ' s.d. ' . substr($to, 0, 10) . "\n" . implode("\n", array_map(fn($x) => "{$x['label']}: {$x['value']}", $content['kpi']))
        . "\nIsu: " . implode('; ', array_map(fn($i) => "{$i[0]} ({$i[1]} naskah, negatif {$i[4]})", array_slice($content['issues'], 0, 5)))
        . "\nIEKP: " . implode('; ', array_map(fn($i) => "{$i['issue']} {$i['score']}", $iekp)) . "\nPerlu perhatian: " . implode('; ', $content['alerts']);
    $nar = ai_narrative($u, 'laporan', 'menulis ringkasan eksekutif laporan berkala komunikasi publik untuk pimpinan.', $data,
        "Pada periode ini disusun {$produced} naskah dan {$published} tayang, dengan {$sent} pengiriman ke kanal web wilayah. " . ($content['alerts'] ? 'Perlu perhatian: ' . implode('; ', array_slice($content['alerts'], 0, 3)) . '.' : 'Tidak ada isu kritis.'));
    $label = ['harian' => 'Harian', 'mingguan' => 'Mingguan', 'bulanan' => 'Bulanan'][$period];
    return insert('reports', ['period' => $period, 'period_start' => $from, 'period_end' => $to, 'title' => "Laporan {$label} Komunikasi Publik · " . substr($from, 0, 10) . ($days > 1 ? ' s.d. ' . substr(iso_from_ts((float)ts_from_iso($to) - 1), 0, 10) : ''),
        'content' => jenc($content), 'summary' => $nar['text'], 'ai_mode' => $nar['mode'], 'auto' => $auto ? 1 : 0, 'created_by' => $u['id'] ?? null, 'created_at' => now_iso()]);
}
/** Terbitkan otomatis laporan harian (kemarin) dan mingguan (Senin) bila belum ada — dipanggil saat daftar laporan dibuka. */
function ensure_scheduled_reports(): void
{
    $today = (float)strtotime(gmdate('Y-m-d') . 'T00:00:00Z');
    if (!one("SELECT id FROM reports WHERE period = 'harian' AND period_end = ?", [iso_from_ts($today)])) build_report(null, 'harian', true, $today);
    $monday = $today - ((int)gmdate('N', (int)$today) - 1) * 86400;
    if (!one("SELECT id FROM reports WHERE period = 'mingguan' AND period_end = ?", [iso_from_ts($monday)])) build_report(null, 'mingguan', true, $monday);
}
