<?php
/**
 * Modul M4–M7: Produksi Narasi, Editorial & Persetujuan, Kanal Media Sosial Resmi, Distribusi 39 Kanal,
 * ditambah Dashboard Eksekutif/Analitik dan Asisten AI.
 */
declare(strict_types=1);

// ======================= migrasi skema =======================
function table_cols(string $t): array { return array_column(all("PRAGMA table_info($t)"), 'name'); }
function add_col(string $t, string $col, string $def): void { if (!in_array($col, table_cols($t), true)) db()->exec("ALTER TABLE $t ADD COLUMN $col $def"); }

function migrate_m47(): void
{
    db()->exec(<<<SQL
CREATE TABLE IF NOT EXISTS ai_logs (id INTEGER PRIMARY KEY, at TEXT, user_id INTEGER, feature TEXT, mode TEXT, model TEXT, input_tokens INTEGER DEFAULT 0,
  output_tokens INTEGER DEFAULT 0, ms INTEGER DEFAULT 0, ok INTEGER DEFAULT 1);
CREATE TABLE IF NOT EXISTS briefs (id INTEGER PRIMARY KEY, title TEXT, issue_id INTEGER, format TEXT, objective TEXT DEFAULT '', audience TEXT DEFAULT '',
  region_code TEXT, tone TEXT DEFAULT 'informatif', key_messages TEXT DEFAULT '', facts TEXT DEFAULT '[]', status TEXT DEFAULT 'baru', ai_mode TEXT,
  created_by INTEGER, created_at TEXT, updated_at TEXT);
CREATE TABLE IF NOT EXISTS brief_variants (id INTEGER PRIMARY KEY, brief_id INTEGER, angle TEXT, title TEXT, body TEXT, hashtags TEXT DEFAULT '[]',
  ai_mode TEXT, draft_id INTEGER, created_at TEXT);
CREATE TABLE IF NOT EXISTS draft_comments (id INTEGER PRIMARY KEY, draft_id INTEGER, user_id INTEGER, body TEXT, at TEXT);
CREATE TABLE IF NOT EXISTS social_accounts (id INTEGER PRIMARY KEY, platform TEXT, handle TEXT, name TEXT, scope TEXT DEFAULT 'nasional', province_code TEXT,
  pic_user_id INTEGER, followers INTEGER DEFAULT 0, token_status TEXT DEFAULT 'aman', mfa INTEGER DEFAULT 1, active INTEGER DEFAULT 1, url TEXT DEFAULT '', created_at TEXT);
CREATE TABLE IF NOT EXISTS post_tasks (id INTEGER PRIMARY KEY, draft_id INTEGER, account_id INTEGER, planned_at TEXT, caption TEXT DEFAULT '', status TEXT DEFAULT 'menunggu',
  assignee_id INTEGER, posted_url TEXT, posted_at TEXT, posted_by INTEGER, reach INTEGER DEFAULT 0, engagement INTEGER DEFAULT 0, created_by INTEGER, created_at TEXT);
CREATE TABLE IF NOT EXISTS social_comments (id INTEGER PRIMARY KEY, account_id INTEGER, post_task_id INTEGER, issue_id INTEGER, author TEXT, text TEXT, at TEXT,
  category TEXT, sentiment TEXT, priority TEXT, reason TEXT, bot_flag INTEGER DEFAULT 0, status TEXT DEFAULT 'baru', suggested_reply TEXT, reply_text TEXT,
  replied_by INTEGER, replied_at TEXT, ai_mode TEXT, triaged_at TEXT);
CREATE INDEX IF NOT EXISTS ix_sc_status ON social_comments(status);
CREATE TABLE IF NOT EXISTS kb_responses (id INTEGER PRIMARY KEY, issue_id INTEGER, title TEXT, question TEXT, answer TEXT, sources TEXT DEFAULT '', status TEXT DEFAULT 'disetujui',
  created_by INTEGER, updated_at TEXT);
CREATE TABLE IF NOT EXISTS distributions (id INTEGER PRIMARY KEY, draft_id INTEGER, channel_id INTEGER, status TEXT, sent_at TEXT, url TEXT, error TEXT,
  views INTEGER DEFAULT 0, clicks INTEGER DEFAULT 0, shares INTEGER DEFAULT 0, created_by INTEGER, created_at TEXT, UNIQUE(draft_id, channel_id));
SQL);
    foreach ([['format', "TEXT DEFAULT 'rilis'"], ['brief_id', 'INTEGER'], ['assignee_id', 'INTEGER'], ['due_at', 'TEXT'], ['priority', "TEXT DEFAULT 'normal'"],
        ['editorial_score', 'INTEGER'], ['editorial_json', 'TEXT'], ['hashtags', "TEXT DEFAULT '[]'"], ['published_at', 'TEXT']] as [$c, $d]) add_col('drafts', $c, $d);
    foreach ([['health', "TEXT DEFAULT 'normal'"], ['latency_ms', 'INTEGER DEFAULT 300'], ['uptime', 'REAL DEFAULT 99.9'], ['checked_at', 'TEXT'], ['audience', 'INTEGER DEFAULT 0']] as [$c, $d]) add_col('channels', $c, $d);
}

// ======================= utilitas =======================
const FORMATS = ['rilis', 'artikel', 'opini', 'editorial', 'briefing', 'caption', 'thread', 'carousel', 'video', 'podcast', 'infografis', 'faq'];
const TONES = ['informatif', 'empatik', 'tegas', 'mengajak', 'santai'];
const PLATFORMS = ['instagram' => 'Instagram', 'x' => 'X', 'tiktok' => 'TikTok', 'facebook' => 'Facebook', 'youtube' => 'YouTube', 'threads' => 'Threads'];
const AUTHOR_OR_EDITOR = ['redaktur', 'produser', 'analis', 'pimpinan'];

function issue_by_code(?string $code): ?array { return $code ? one('SELECT * FROM issues WHERE code = ?', [$code]) : null; }
function user_name(?int $id): ?string { return $id ? val('SELECT full_name FROM users WHERE id = ?', [$id]) : null; }
function int_list($v): array { return is_array($v) ? array_values(array_filter(array_map('intval', $v), fn($x) => $x > 0)) : []; }
function opt_dt($v): ?string { return ($v === null || $v === '') ? null : (parse_input_datetime((string)$v) ?? fail(422, 'Format waktu tidak valid')); }

/** Terbitkan naskah: disetujui → tayang (verifikasi tanda tangan), dipakai Distribusi. */
function publish_draft(array $d, array $u): array
{
    if ($d['status'] === 'tayang') return $d;
    $rule = check_transition($d, 'tayang', $u, '');
    $now = now_iso();
    $role = '';
    foreach ($rule['roles'] as $rr) if (has_role($u, $rr)) { $role = $rr; break; }
    insert('approval_steps', ['draft_id' => $d['id'], 'from_status' => $d['status'], 'to_status' => 'tayang', 'actor_id' => $u['id'], 'actor_role' => $role,
        'comment' => 'Ditayangkan melalui Distribusi 39 Kanal', 'version' => $d['version'], 'content_hash' => $d['content_hash'], 'signature' => null, 'at' => $now]);
    q("UPDATE drafts SET status = 'tayang', updated_at = ?, published_at = ? WHERE id = ?", [$now, $now, $d['id']]);
    q("UPDATE calendar_entries SET status = 'tayang' WHERE draft_id = ? AND status = 'rencana'", [$d['id']]);
    audit_record($u, 'naskah.transisi', 'draft', $d['id'], ['from' => $d['status'], 'to' => 'tayang', 'version' => (int)$d['version'], 'hash' => $d['content_hash'], 'signed' => false]);
    return get_draft_row((int)$d['id']);
}

function detect_bot_swarm(): int
{
    // Teks identik (dinormalisasi) dari ≥3 akun berbeda dalam 10 menit → tandai.
    $rows = all("SELECT id, author, text, at FROM social_comments WHERE at >= ? ORDER BY at", [iso_from_ts(microtime(true) - 14 * 86400)]);
    $groups = [];
    foreach ($rows as $r) { $r['ts'] = (float)ts_from_iso($r['at']); $groups[preg_replace('/\W+/u', '', mb_strtolower($r['text']))][] = $r; }
    $flag = [];
    foreach ($groups as $g) {
        if (count($g) < 3) continue;
        // jendela geser 10 menit atas data yang sudah urut waktu
        $j = 0;
        for ($i = 0; $i < count($g); $i++) {
            while ($g[$i]['ts'] - $g[$j]['ts'] > 600) $j++;
            $win = array_slice($g, $j, $i - $j + 1);
            if (count(array_unique(array_column($win, 'author'))) >= 3) foreach ($win as $w) $flag[(int)$w['id']] = true;
        }
    }
    $n = 0;
    foreach (array_keys($flag) as $id) $n += q('UPDATE social_comments SET bot_flag = 1 WHERE id = ? AND bot_flag = 0', [$id])->rowCount();
    return $n;
}

function kb_text(?int $issueId = null): string
{
    $rows = $issueId ? all("SELECT * FROM kb_responses WHERE status = 'disetujui' AND (issue_id = ? OR issue_id IS NULL) LIMIT 8", [$issueId])
        : all("SELECT * FROM kb_responses WHERE status = 'disetujui' ORDER BY updated_at DESC LIMIT 10");
    return implode("\n", array_map(fn($k) => "- {$k['title']}: {$k['answer']}", $rows)) ?: '(belum ada bahan respons)';
}

// ======================= representasi =======================
function brief_out(array $b, bool $detail = false): array
{
    $i = $b['issue_id'] ? one('SELECT code, name FROM issues WHERE id = ?', [$b['issue_id']]) : null;
    $o = ['id' => (int)$b['id'], 'title' => $b['title'], 'issue_code' => $i['code'] ?? null, 'issue_name' => $i['name'] ?? null, 'format' => $b['format'],
        'objective' => $b['objective'], 'audience' => $b['audience'], 'region_code' => $b['region_code'], 'region_name' => province_name($b['region_code']) ?? 'Nasional',
        'tone' => $b['tone'], 'key_messages' => $b['key_messages'], 'facts' => array_map(fn($f) => $f['text'], fact_items($b['facts'])), 'fact_items' => fact_items($b['facts']), 'gen_ms' => isset($b['gen_ms']) ? (int)$b['gen_ms'] : null,
        'status' => $b['status'], 'ai_mode' => $b['ai_mode'],
        'created_by' => (int)$b['created_by'], 'author_name' => user_name((int)$b['created_by']), 'created_at' => $b['created_at'],
        'variant_count' => (int)val('SELECT COUNT(*) FROM brief_variants WHERE brief_id = ?', [$b['id']])];
    if ($detail) $o['variants'] = array_map(fn($v) => ['id' => (int)$v['id'], 'angle' => $v['angle'], 'title' => $v['title'], 'body' => $v['body'],
        'hashtags' => jdec($v['hashtags']), 'ai_mode' => $v['ai_mode'], 'draft_id' => $v['draft_id'] ? (int)$v['draft_id'] : null, 'created_at' => $v['created_at']],
        all('SELECT * FROM brief_variants WHERE brief_id = ? ORDER BY id', [$b['id']]));
    return $o;
}
function board_card(array $d): array
{
    return ['id' => (int)$d['id'], 'title' => $d['title'], 'format' => $d['format'] ?: $d['kind'], 'status' => $d['status'], 'ai_generated' => (bool)$d['ai_generated'],
        'issue_code' => $d['issue_id'] ? val('SELECT code FROM issues WHERE id = ?', [$d['issue_id']]) : null, 'province_name' => province_name($d['province_code']),
        'author_name' => user_name((int)$d['created_by']), 'assignee_id' => $d['assignee_id'] ? (int)$d['assignee_id'] : null, 'assignee_name' => user_name($d['assignee_id'] ? (int)$d['assignee_id'] : null),
        'due_at' => $d['due_at'], 'priority' => $d['priority'] ?: 'normal', 'editorial_score' => $d['editorial_score'] === null ? null : (int)$d['editorial_score'],
        'comments' => (int)val('SELECT COUNT(*) FROM draft_comments WHERE draft_id = ?', [$d['id']]), 'updated_at' => $d['updated_at'],
        'overdue' => $d['due_at'] && !in_array($d['status'], ['tayang', 'ditarik'], true) && $d['due_at'] < now_iso()];
}
function account_out(array $a): array
{
    return ['id' => (int)$a['id'], 'platform' => $a['platform'], 'platform_label' => PLATFORMS[$a['platform']] ?? $a['platform'], 'handle' => $a['handle'], 'name' => $a['name'],
        'scope' => $a['scope'], 'province_code' => $a['province_code'], 'province_name' => province_name($a['province_code']), 'pic_user_id' => $a['pic_user_id'] ? (int)$a['pic_user_id'] : null,
        'pic_name' => user_name($a['pic_user_id'] ? (int)$a['pic_user_id'] : null), 'followers' => (int)$a['followers'], 'token_status' => $a['token_status'], 'mfa' => (bool)$a['mfa'],
        'active' => (bool)$a['active'], 'url' => $a['url'],
        'pending_tasks' => (int)val("SELECT COUNT(*) FROM post_tasks WHERE account_id = ? AND status = 'menunggu'", [$a['id']]),
        'new_comments' => (int)val("SELECT COUNT(*) FROM social_comments WHERE account_id = ? AND status IN ('baru','ditriase')", [$a['id']])];
}
function task_out(array $t): array
{
    $d = one('SELECT id, title, status, format, kind FROM drafts WHERE id = ?', [$t['draft_id']]);
    $a = one('SELECT platform, handle FROM social_accounts WHERE id = ?', [$t['account_id']]);
    return ['id' => (int)$t['id'], 'draft_id' => (int)$t['draft_id'], 'draft_title' => $d['title'] ?? '-', 'draft_status' => $d['status'] ?? '-', 'format' => $d['format'] ?? null,
        'account_id' => (int)$t['account_id'], 'platform' => $a['platform'] ?? '', 'handle' => $a['handle'] ?? '', 'planned_at' => $t['planned_at'], 'caption' => $t['caption'],
        'status' => $t['status'], 'overdue' => $t['status'] === 'menunggu' && $t['planned_at'] < now_iso(), 'assignee_name' => user_name($t['assignee_id'] ? (int)$t['assignee_id'] : null),
        'posted_url' => $t['posted_url'], 'posted_at' => $t['posted_at'], 'posted_by_name' => user_name($t['posted_by'] ? (int)$t['posted_by'] : null),
        'reach' => (int)$t['reach'], 'engagement' => (int)$t['engagement']];
}
function comment_out(array $c): array
{
    $a = one('SELECT platform, handle FROM social_accounts WHERE id = ?', [$c['account_id']]);
    [$text, $pii] = pii_mask((string)$c['text']);
    return ['id' => (int)$c['id'], 'account_id' => (int)$c['account_id'], 'platform' => $a['platform'] ?? '', 'handle' => $a['handle'] ?? '', 'author' => $c['author'], 'text' => $text,
        'pii_masked' => $pii, 'parent_id' => isset($c['parent_id']) && $c['parent_id'] ? (int)$c['parent_id'] : null,
        'replies' => (int)val('SELECT COUNT(*) FROM social_comments WHERE parent_id = ?', [$c['id']]),
        'at' => $c['at'], 'issue_code' => $c['issue_id'] ? val('SELECT code FROM issues WHERE id = ?', [$c['issue_id']]) : null, 'category' => $c['category'], 'sentiment' => $c['sentiment'],
        'priority' => $c['priority'], 'reason' => $c['reason'], 'bot_flag' => (bool)$c['bot_flag'], 'status' => $c['status'], 'suggested_reply' => $c['suggested_reply'],
        'reply_text' => $c['reply_text'], 'replied_by_name' => user_name($c['replied_by'] ? (int)$c['replied_by'] : null), 'replied_at' => $c['replied_at'], 'ai_mode' => $c['ai_mode']];
}
function kb_out(array $k): array
{
    return ['id' => (int)$k['id'], 'issue_code' => $k['issue_id'] ? val('SELECT code FROM issues WHERE id = ?', [$k['issue_id']]) : null, 'title' => $k['title'], 'question' => $k['question'],
        'answer' => $k['answer'], 'sources' => $k['sources'], 'status' => $k['status'], 'updated_at' => $k['updated_at']];
}
function channel_out(array $c): array
{
    $since = iso_from_ts(microtime(true) - 7 * 86400);
    $s = one("SELECT COUNT(*) n, SUM(status='terkirim') ok, SUM(status='gagal') bad, COALESCE(SUM(views),0) v, MAX(sent_at) last FROM distributions WHERE channel_id = ? AND created_at >= ?", [$c['id'], $since]);
    return ['id' => (int)$c['id'], 'code' => $c['code'], 'name' => $c['name'], 'type' => $c['type'], 'province_code' => $c['province_code'], 'health' => $c['health'] ?: 'normal',
        'latency_ms' => (int)$c['latency_ms'], 'uptime' => (float)$c['uptime'], 'checked_at' => $c['checked_at'], 'audience' => (int)$c['audience'],
        'sent_7d' => (int)$s['ok'], 'failed_7d' => (int)$s['bad'], 'views_7d' => (int)$s['v'], 'last_sent_at' => $s['last']];
}
function dist_out(array $x): array
{
    $c = one('SELECT code, name FROM channels WHERE id = ?', [$x['channel_id']]);
    return ['id' => (int)$x['id'], 'draft_id' => (int)$x['draft_id'], 'channel_code' => $c['code'], 'channel_name' => $c['name'], 'status' => $x['status'], 'sent_at' => $x['sent_at'],
        'url' => $x['url'], 'error' => $x['error'], 'views' => (int)$x['views'], 'clicks' => (int)$x['clicks'], 'shares' => (int)$x['shares']];
}

// ======================= konteks ringkas untuk asisten AI =======================
function ai_context_summary(): string
{
    $s = exec_summary();
    $k = $s['kpi'];
    $lines = ["Naskah 30 hari: {$k['produced_30d']} disusun, {$k['published_30d']} tayang; menunggu persetujuan: {$k['awaiting_approval']}; disetujui belum tayang: {$k['approved_not_published']}.",
        "Rata-rata waktu persetujuan: {$k['avg_approval_hours']} jam. Tugas unggah medsos menunggu: {$k['post_tasks_pending']} (terlambat {$k['post_tasks_overdue']}).",
        "Komentar belum ditangani: {$k['comments_open']} (prioritas tinggi {$k['comments_high']}, terindikasi bot {$k['comments_bot']}).",
        "Kanal web sehat: {$k['channels_ok']} dari {$k['channels_total']}; distribusi 7 hari: {$k['dist_sent_7d']} terkirim, {$k['dist_failed_7d']} gagal; pembaca 7 hari: {$k['views_7d']}.",
        'Isu prioritas: ' . implode('; ', array_map(fn($i) => "{$i['name']} ({$i['drafts']} naskah, {$i['negative_comments']} komentar negatif)", array_slice($s['issues'], 0, 5)))];
    foreach (array_slice($s['decisions'], 0, 5) as $d) $lines[] = "Menunggu keputusan: \"{$d['title']}\" ({$d['format']}, oleh {$d['author_name']})";
    return implode("\n", $lines);
}

// ======================= dashboard =======================
function exec_summary(): array
{
    $now = microtime(true); $d30 = iso_from_ts($now - 30 * 86400); $d7 = iso_from_ts($now - 7 * 86400); $nowIso = iso_from_ts($now);
    $avg = val("SELECT AVG((julianday(s2.at) - julianday(s1.at)) * 24) FROM approval_steps s2 JOIN approval_steps s1 ON s1.draft_id = s2.draft_id AND s1.to_status = 'ditinjau'
        WHERE s2.to_status = 'disetujui' AND s2.at >= ?", [$d30]);
    $kpi = [
        'produced_30d' => (int)val('SELECT COUNT(*) FROM drafts WHERE created_at >= ?', [$d30]),
        'published_30d' => (int)val("SELECT COUNT(*) FROM drafts WHERE status = 'tayang' AND COALESCE(published_at, updated_at) >= ?", [$d30]),
        'awaiting_approval' => (int)val("SELECT COUNT(*) FROM drafts WHERE status = 'ditinjau'"),
        'approved_not_published' => (int)val("SELECT COUNT(*) FROM drafts WHERE status = 'disetujui'"),
        'avg_approval_hours' => $avg === null ? 0 : round((float)$avg, 1),
        'ai_assisted_pct' => (int)round(100 * (float)val('SELECT AVG(ai_generated) FROM drafts WHERE created_at >= ?', [$d30])),
        'post_tasks_pending' => (int)val("SELECT COUNT(*) FROM post_tasks WHERE status = 'menunggu'"),
        'post_tasks_overdue' => (int)val("SELECT COUNT(*) FROM post_tasks WHERE status = 'menunggu' AND planned_at < ?", [$nowIso]),
        'comments_open' => (int)val("SELECT COUNT(*) FROM social_comments WHERE status IN ('baru','ditriase')"),
        'comments_high' => (int)val("SELECT COUNT(*) FROM social_comments WHERE status IN ('baru','ditriase') AND priority = 'tinggi'"),
        'comments_bot' => (int)val('SELECT COUNT(*) FROM social_comments WHERE bot_flag = 1 AND at >= ?', [$d7]),
        'channels_total' => (int)val("SELECT COUNT(*) FROM channels WHERE type IN ('nasional','provinsi')"),
        'channels_ok' => (int)val("SELECT COUNT(*) FROM channels WHERE type IN ('nasional','provinsi') AND health = 'normal'"),
        'dist_sent_7d' => (int)val("SELECT COUNT(*) FROM distributions WHERE status = 'terkirim' AND sent_at >= ?", [$d7]),
        'dist_failed_7d' => (int)val("SELECT COUNT(*) FROM distributions WHERE status = 'gagal' AND created_at >= ?", [$d7]),
        'views_7d' => (int)val('SELECT COALESCE(SUM(views),0) FROM distributions WHERE sent_at >= ?', [$d7]) + (int)val('SELECT COALESCE(SUM(reach),0) FROM post_tasks WHERE posted_at >= ?', [$d7]),
        'followers' => (int)val('SELECT COALESCE(SUM(followers),0) FROM social_accounts WHERE active = 1'),
    ];
    $handled = one("SELECT COUNT(*) n, AVG((julianday(replied_at) - julianday(at)) * 24) h FROM social_comments WHERE status = 'dibalas' AND replied_at >= ?", [$d30]);
    $kpi['comments_replied_30d'] = (int)$handled['n'];
    $kpi['avg_reply_hours'] = $handled['h'] === null ? 0 : round((float)$handled['h'], 1);
    // tren 14 hari: disusun vs tayang
    $start = (int)strtotime(gmdate('Y-m-d', (int)($now - 13 * 86400)) . 'T00:00:00Z');
    $trend = [];
    for ($i = 0; $i < 14; $i++) $trend[gmdate('Y-m-d', $start + $i * 86400)] = ['produced' => 0, 'published' => 0, 'views' => 0];
    foreach (all('SELECT substr(created_at,1,10) d, COUNT(*) n FROM drafts WHERE created_at >= ? GROUP BY d', [iso_from_ts($start)]) as $r) if (isset($trend[$r['d']])) $trend[$r['d']]['produced'] = (int)$r['n'];
    foreach (all("SELECT substr(COALESCE(published_at, updated_at),1,10) d, COUNT(*) n FROM drafts WHERE status = 'tayang' GROUP BY d") as $r) if (isset($trend[$r['d']])) $trend[$r['d']]['published'] = (int)$r['n'];
    foreach (all("SELECT substr(sent_at,1,10) d, SUM(views) n FROM distributions WHERE sent_at >= ? GROUP BY d", [iso_from_ts($start)]) as $r) if (isset($trend[$r['d']])) $trend[$r['d']]['views'] = (int)$r['n'];
    $trendOut = [];
    foreach ($trend as $d => $v) $trendOut[] = ['date' => $d] + $v;
    // isu prioritas
    $issues = [];
    foreach (all('SELECT id, code, name, risk_level FROM issues WHERE active = 1') as $i) {
        $dr = (int)val('SELECT COUNT(*) FROM drafts WHERE issue_id = ? AND created_at >= ?', [$i['id'], $d30]);
        $neg = (int)val("SELECT COUNT(*) FROM social_comments WHERE issue_id = ? AND sentiment = 'negatif' AND at >= ?", [$i['id'], $d7]);
        $tot = (int)val('SELECT COUNT(*) FROM social_comments WHERE issue_id = ? AND at >= ?', [$i['id'], $d7]);
        $pub = (int)val("SELECT COUNT(*) FROM drafts WHERE issue_id = ? AND status = 'tayang' AND COALESCE(published_at, updated_at) >= ?", [$i['id'], $d30]);
        if ($dr + $tot === 0) continue;
        $issues[] = ['code' => $i['code'], 'name' => $i['name'], 'risk_level' => $i['risk_level'], 'drafts' => $dr, 'published' => $pub, 'comments' => $tot, 'negative_comments' => $neg,
            'negative_ratio' => $tot ? round($neg / $tot, 2) : 0.0];
    }
    usort($issues, fn($a, $b) => ($b['negative_comments'] * 2 + $b['drafts']) <=> ($a['negative_comments'] * 2 + $a['drafts']));
    $decisions = array_map('board_card', all("SELECT * FROM drafts WHERE status = 'ditinjau' ORDER BY COALESCE(due_at, updated_at) LIMIT 6"));
    $health = ['normal' => 0, 'lambat' => 0, 'gangguan' => 0];
    foreach (all("SELECT health, COUNT(*) n FROM channels WHERE type IN ('nasional','provinsi') GROUP BY health") as $r) $health[$r['health'] ?: 'normal'] = (int)$r['n'];
    $alerts = [];
    if ($kpi['comments_bot'] > 0) $alerts[] = ['level' => 'tinggi', 'text' => "{$kpi['comments_bot']} komentar terindikasi serangan bot terkoordinasi dalam 7 hari"];
    if ($kpi['post_tasks_overdue'] > 0) $alerts[] = ['level' => 'sedang', 'text' => "{$kpi['post_tasks_overdue']} tugas unggah media sosial melewati jadwal"];
    foreach (all("SELECT name, health FROM channels WHERE health = 'gangguan'") as $c) $alerts[] = ['level' => 'tinggi', 'text' => "{$c['name']} mengalami gangguan"];
    if ($kpi['comments_high'] > 0) $alerts[] = ['level' => 'sedang', 'text' => "{$kpi['comments_high']} komentar prioritas tinggi belum ditangani"];
    return ['generated_at' => $nowIso, 'kpi' => $kpi, 'trend' => $trendOut, 'issues' => array_slice($issues, 0, 8), 'decisions' => $decisions, 'channel_health' => $health, 'alerts' => $alerts];
}

function analytics_overview(int $days): array
{
    $since = iso_from_ts(microtime(true) - $days * 86400);
    $pairs = fn(string $sql, array $p = []) => array_map(fn($r) => ['key' => (string)$r['k'], 'value' => (int)$r['n']], all($sql, $p));
    $byFormat = $pairs("SELECT COALESCE(format, kind) k, COUNT(*) n FROM drafts WHERE created_at >= ? GROUP BY k ORDER BY n DESC", [$since]);
    $byStatus = $pairs('SELECT status k, COUNT(*) n FROM drafts WHERE created_at >= ? GROUP BY status', [$since]);
    $byIssue = $pairs('SELECT COALESCE(i.name, "Tanpa isu") k, COUNT(*) n FROM drafts d LEFT JOIN issues i ON i.id = d.issue_id WHERE d.created_at >= ? GROUP BY k ORDER BY n DESC', [$since]);
    $byAuthor = $pairs('SELECT u.full_name k, COUNT(*) n FROM drafts d JOIN users u ON u.id = d.created_by WHERE d.created_at >= ? GROUP BY k ORDER BY n DESC', [$since]);
    $stage = [];
    foreach ([['draf', 'ditinjau', 'Penyusunan → pengajuan'], ['ditinjau', 'disetujui', 'Pengajuan → persetujuan'], ['disetujui', 'tayang', 'Persetujuan → tayang']] as [$a, $b, $label]) {
        $h = val("SELECT AVG((julianday(s2.at) - julianday(COALESCE(s1.at, d.created_at))) * 24) FROM approval_steps s2 JOIN drafts d ON d.id = s2.draft_id
            LEFT JOIN approval_steps s1 ON s1.draft_id = s2.draft_id AND s1.to_status = ? WHERE s2.to_status = ? AND s2.at >= ?", [$a, $b, $since]);
        $stage[] = ['stage' => $label, 'hours' => $h === null ? 0 : round((float)$h, 1)];
    }
    $returned = (int)val("SELECT COUNT(*) FROM approval_steps WHERE from_status = 'ditinjau' AND to_status = 'draf' AND at >= ?", [$since]);
    $editorial = one('SELECT AVG(editorial_score) a, COUNT(editorial_score) n FROM drafts WHERE created_at >= ?', [$since]);
    // distribusi per provinsi
    $prov = [];
    foreach (all("SELECT c.code, c.name, c.province_code, c.health, COUNT(x.id) sent, COALESCE(SUM(x.views),0) views, COALESCE(SUM(x.clicks),0) clicks, SUM(x.status='gagal') failed
        FROM channels c LEFT JOIN distributions x ON x.channel_id = c.id AND x.created_at >= ? WHERE c.type IN ('nasional','provinsi') GROUP BY c.id ORDER BY views DESC", [$since]) as $r) {
        $prov[] = ['code' => $r['code'], 'name' => $r['name'], 'province_code' => $r['province_code'], 'health' => $r['health'], 'sent' => (int)$r['sent'], 'views' => (int)$r['views'],
            'clicks' => (int)$r['clicks'], 'failed' => (int)$r['failed'], 'ctr' => $r['views'] ? round($r['clicks'] / $r['views'], 3) : 0.0];
    }
    // media sosial
    $cat = $pairs('SELECT COALESCE(category, "belum ditriase") k, COUNT(*) n FROM social_comments WHERE at >= ? GROUP BY k ORDER BY n DESC', [$since]);
    $sent = $pairs('SELECT COALESCE(sentiment, "belum") k, COUNT(*) n FROM social_comments WHERE at >= ? GROUP BY k', [$since]);
    $cstatus = $pairs('SELECT status k, COUNT(*) n FROM social_comments WHERE at >= ? GROUP BY status', [$since]);
    $accounts = [];
    foreach (all('SELECT * FROM social_accounts WHERE active = 1 ORDER BY followers DESC LIMIT 40') as $a) {
        $t = one("SELECT COUNT(*) n, COALESCE(SUM(reach),0) r, COALESCE(SUM(engagement),0) e FROM post_tasks WHERE account_id = ? AND status = 'diunggah' AND posted_at >= ?", [$a['id'], $since]);
        $cm = one("SELECT COUNT(*) n, SUM(status='dibalas') rep FROM social_comments WHERE account_id = ? AND at >= ?", [$a['id'], $since]);
        $accounts[] = ['handle' => $a['handle'], 'platform' => PLATFORMS[$a['platform']] ?? $a['platform'], 'followers' => (int)$a['followers'], 'posts' => (int)$t['n'],
            'reach' => (int)$t['r'], 'engagement' => (int)$t['e'], 'engagement_rate' => $t['r'] ? round($t['e'] / $t['r'], 3) : 0.0, 'comments' => (int)$cm['n'], 'replied' => (int)$cm['rep']];
    }
    $resp = one("SELECT AVG((julianday(replied_at) - julianday(at)) * 24) h, COUNT(*) n FROM social_comments WHERE status = 'dibalas' AND at >= ?", [$since]);
    $sla = (int)val("SELECT COUNT(*) FROM social_comments WHERE status = 'dibalas' AND at >= ? AND (julianday(replied_at) - julianday(at)) * 24 <= 2", [$since]);
    // AI
    $ai = array_map(fn($r) => ['feature' => $r['feature'], 'calls' => (int)$r['n'], 'live' => (int)$r['live'], 'input_tokens' => (int)$r['i'], 'output_tokens' => (int)$r['o'],
        'avg_ms' => (int)$r['ms'], 'errors' => (int)$r['err']],
        all("SELECT feature, COUNT(*) n, SUM(mode='claude') live, SUM(input_tokens) i, SUM(output_tokens) o, AVG(CASE WHEN mode='claude' THEN ms END) ms, SUM(ok=0) err FROM ai_logs WHERE at >= ? GROUP BY feature ORDER BY n DESC", [$since]));
    return ['days' => $days, 'production' => ['by_format' => $byFormat, 'by_status' => $byStatus, 'by_issue' => $byIssue, 'by_author' => $byAuthor,
            'ai_assisted' => (int)val('SELECT COUNT(*) FROM drafts WHERE ai_generated = 1 AND created_at >= ?', [$since]), 'total' => (int)val('SELECT COUNT(*) FROM drafts WHERE created_at >= ?', [$since])],
        'editorial' => ['stage_hours' => $stage, 'returned' => $returned, 'avg_score' => $editorial['a'] === null ? null : (int)round((float)$editorial['a']), 'checked' => (int)$editorial['n']],
        'distribution' => ['channels' => $prov, 'sent' => array_sum(array_column($prov, 'sent')), 'views' => array_sum(array_column($prov, 'views')), 'failed' => array_sum(array_column($prov, 'failed'))],
        'social' => ['categories' => $cat, 'sentiment' => $sent, 'status' => $cstatus, 'accounts' => $accounts, 'avg_reply_hours' => $resp['h'] === null ? 0 : round((float)$resp['h'], 1),
            'replied' => (int)$resp['n'], 'within_sla' => $sla],
        'ai' => $ai];
}

// ======================= rute =======================
function register_routes_m47(Router $r): void
{
    // ---------- AI ----------
    $r->add('GET', '/api/ai/status', function () { current_user(); send_json(ai_status()); });
    $r->add('PUT', '/api/ai/settings', function () {
        $u = require_roles('admin');
        $b = json_body(); $patch = []; $changes = [];
        if (array_key_exists('api_key', $b)) {
            $k = trim((string)$b['api_key']);
            if ($k !== '' && !preg_match('/^sk-ant-[A-Za-z0-9_\-]{20,}$/', $k)) fail(422, 'Format kunci API tidak dikenali (harus diawali sk-ant-)');
            $patch['api_key'] = $k; $changes['api_key'] = $k === '' ? 'dihapus' : 'diperbarui';
        }
        if (isset($b['model'])) { if (!isset(AI_MODELS[$b['model']])) fail(422, 'Model tidak dikenal'); $patch['model'] = $b['model']; $changes['model'] = $b['model']; }
        if (array_key_exists('enabled', $b)) { $patch['enabled'] = (bool)$b['enabled']; $changes['enabled'] = (bool)$b['enabled']; }
        ai_save_config($patch);
        audit_record($u, 'ai.pengaturan', 'ai', '-', $changes);
        send_json(ai_status());
    });
    $r->add('POST', '/api/ai/test', function () {
        $u = require_roles('admin');
        if (!ai_live()) fail(422, 'Kunci API belum diisi atau SISTRA AGENT dinonaktifkan');
        $res = ai_call($u, 'uji', 'Tugas: uji koneksi.', [['role' => 'user', 'content' => 'Balas dengan satu kata: SIAP']], null, 'low', 200);
        send_json(['ok' => true, 'model' => $res['model'], 'reply' => trim($res['text'])]);
    });
    $r->add('GET', '/api/ai/library', function () { require_roles('admin'); send_json(ai_lib_status()); });
    $r->add('POST', '/api/ai/library/install', function () {
        $u = require_roles('admin');
        try { $res = ai_lib_install(); }
        catch (RuntimeException $e) { audit_record($u, 'ai.pasang_pustaka_gagal', 'ai', '-', ['galat' => mb_substr($e->getMessage(), 0, 300)]); fail(502, 'Pemasangan pustaka gagal: ' . $e->getMessage()); }
        audit_record($u, 'ai.pasang_pustaka', 'ai', '-', ['status' => $res['status'], 'paket' => array_column($res['installed'], 'package')]);
        send_json($res);
    });
    $r->add('POST', '/api/ai/chat', function () {
        $u = current_user();
        $b = json_body();
        $msgs = is_array($b['messages'] ?? null) ? $b['messages'] : [];
        send_json(ai_chat($u, $msgs, mb_substr((string)($b['page'] ?? '-'), 0, 80), ai_context_summary()));
    });
    $r->add('POST', '/api/ai/rewrite', function () {
        $u = require_roles(...AUTHOR_OR_EDITOR);
        $b = json_body();
        $text = trim(str_field($b, 'text', 10, 20000)); $ins = trim(str_field($b, 'instruction', 3, 300));
        if (!ai_live()) { ai_log($u, 'tulis_ulang', 'simulasi', null, 0, 0, 0, true); send_json(['mode' => 'simulasi', 'text' => "[SIMULASI · {$ins}]\n\n" . $text]); return; }
        $res = ai_call($u, 'tulis_ulang', 'Tugas: menulis ulang naskah sesuai instruksi, tanpa menambah fakta baru.', [['role' => 'user', 'content' =>
            "Instruksi: {$ins}\n\nTulis ulang teks berikut. Kembalikan hanya teks hasil, tanpa pengantar.\n\n{$text}"]], null, 'low', 8000);
        send_json(['mode' => 'claude', 'model' => $res['model'], 'text' => trim($res['text'])]);
    });

    // ---------- M4 Produksi Narasi ----------
    $r->add('GET', '/api/m4/meta', function () {
        current_user();
        send_json(['formats' => array_map(fn($f) => ['id' => $f, 'label' => FORMAT_LABEL[$f]], FORMATS), 'tones' => TONES, 'angles' => ANGLES,
            'issues' => array_map(fn($i) => ['code' => $i['code'], 'name' => $i['name']], all('SELECT code, name FROM issues WHERE active = 1 ORDER BY name')), 'ai' => ai_status()]);
    });
    $r->add('GET', '/api/m4/briefs', function () {
        current_user();
        send_json(array_map('brief_out', all('SELECT * FROM briefs ORDER BY id DESC LIMIT 200')));
    });
    $r->add('POST', '/api/m4/briefs', function () {
        $u = require_roles(...AUTHOR_ROLES);
        $b = json_body();
        $fmt = str_field($b, 'format'); if (!in_array($fmt, FORMATS, true)) fail(422, 'Format tidak dikenal');
        $tone = (string)($b['tone'] ?? 'informatif'); if (!in_array($tone, TONES, true)) fail(422, 'Nada tidak dikenal');
        $iss = issue_by_code($b['issue_code'] ?? null); if (!$iss) fail(422, 'Pilih isu');
        $reg = ($b['region_code'] ?? null) ?: null; if ($reg !== null && !isset(PROVINCES[$reg])) fail(422, 'Kode provinsi tidak dikenal');
        $rawFacts = $b['facts'] ?? [];
        if (!is_array($rawFacts) || !array_is_list($rawFacts)) fail(422, 'Kolom facts harus berupa daftar');
        $facts = fact_items($rawFacts);
        $now = now_iso();
        $id = insert('briefs', ['title' => trim(str_field($b, 'title', 5, 200)), 'issue_id' => $iss['id'], 'format' => $fmt, 'objective' => trim((string)($b['objective'] ?? '')),
            'audience' => trim((string)($b['audience'] ?? '')), 'region_code' => $reg, 'tone' => $tone, 'key_messages' => trim(str_field($b, 'key_messages', 5, 2000)),
            'facts' => jenc(array_slice($facts, 0, 20)), 'status' => 'baru', 'created_by' => $u['id'], 'created_at' => $now, 'updated_at' => $now]);
        audit_record($u, 'brief.buat', 'brief', $id, ['format' => $fmt, 'issue' => $iss['code']]);
        send_json(brief_out(one('SELECT * FROM briefs WHERE id = ?', [$id]), true), 201);
    });
    $r->add('GET', '/api/m4/briefs/(\d+)', function ($id) {
        current_user();
        $b = one('SELECT * FROM briefs WHERE id = ?', [(int)$id]); if (!$b) fail(404, 'Brief tidak ditemukan');
        send_json(brief_out($b, true));
    });
    $r->add('POST', '/api/m4/briefs/(\d+)/generate', function ($id) {
        $u = require_roles(...AUTHOR_ROLES);
        $b = one('SELECT * FROM briefs WHERE id = ?', [(int)$id]); if (!$b) fail(404, 'Brief tidak ditemukan');
        $n = (int)(json_body()['n'] ?? 3);
        $bo = brief_out($b);
        $t0 = microtime(true);
        $res = ai_generate_content($u, $bo, $n);
        $now = now_iso();
        q('UPDATE briefs SET gen_ms = ? WHERE id = ?', [(int)((microtime(true) - $t0) * 1000), $b['id']]);
        foreach ($res['variants'] as $v) {
            insert('brief_variants', ['brief_id' => $b['id'], 'angle' => mb_substr((string)($v['angle'] ?? ''), 0, 120), 'title' => mb_substr((string)($v['title'] ?? ''), 0, 300),
                'body' => (string)($v['body'] ?? ''), 'hashtags' => jenc(array_slice(array_map('strval', (array)($v['hashtags'] ?? [])), 0, 10)), 'ai_mode' => $res['mode'], 'created_at' => $now]);
        }
        q("UPDATE briefs SET status = 'dihasilkan', ai_mode = ?, updated_at = ? WHERE id = ?", [$res['mode'], $now, $b['id']]);
        audit_record($u, 'brief.generate_ai', 'brief', $b['id'], ['mode' => $res['mode'], 'variants' => count($res['variants'])]);
        send_json(['notes' => $res['notes'] ?? '', 'mode' => $res['mode']] + brief_out(one('SELECT * FROM briefs WHERE id = ?', [$b['id']]), true));
    });
    $r->add('POST', '/api/m4/variants/(\d+)/draft', function ($id) {
        $u = require_roles(...AUTHOR_ROLES);
        $v = one('SELECT * FROM brief_variants WHERE id = ?', [(int)$id]); if (!$v) fail(404, 'Variasi tidak ditemukan');
        if ($v['draft_id']) fail(409, 'Variasi ini sudah dijadikan naskah');
        $b = one('SELECT * FROM briefs WHERE id = ?', [$v['brief_id']]);
        $in = json_body();
        $title = trim((string)($in['title'] ?? $v['title'])); $body = trim((string)($in['body'] ?? $v['body']));
        if (mb_strlen($title) < 5 || mb_strlen($body) < 20) fail(422, 'Judul minimal 5 karakter dan isi minimal 20 karakter');
        $now = now_iso(); $h = draft_hash($title, $body);
        $did = insert('drafts', ['kind' => $b['format'], 'format' => $b['format'], 'title' => $title, 'body' => $body, 'province_code' => $b['region_code'], 'issue_id' => $b['issue_id'],
            'ai_generated' => 1, 'status' => 'draf', 'version' => 1, 'content_hash' => $h, 'created_by' => $u['id'], 'created_at' => $now, 'updated_at' => $now,
            'brief_id' => $b['id'], 'hashtags' => $v['hashtags'], 'priority' => 'normal']);
        insert('draft_revisions', ['draft_id' => $did, 'version' => 1, 'title' => $title, 'body' => $body, 'edited_by' => $u['id'], 'at' => $now]);
        q('UPDATE brief_variants SET draft_id = ? WHERE id = ?', [$did, $v['id']]);
        q("UPDATE briefs SET status = 'dipakai', updated_at = ? WHERE id = ?", [$now, $b['id']]);
        audit_record($u, 'naskah.buat', 'draft', $did, ['kind' => $b['format'], 'ai_generated' => true, 'hash' => $h, 'brief_id' => (int)$b['id']]);
        send_json(draft_out(get_draft_row($did), $u, true), 201);
    });
    $r->add('POST', '/api/m4/drafts/(\d+)/localize', function ($id) {
        $u = require_roles(...AUTHOR_ROLES);
        $d = get_draft_row((int)$id);
        $codes = list_field(json_body(), 'province_codes') ?? [];
        $codes = array_values(array_unique(array_filter($codes, fn($c) => isset(PROVINCES[$c]))));
        if (!$codes) fail(422, 'Pilih minimal satu provinsi');
        if (count($codes) > 5) fail(422, 'Maksimal 5 provinsi per permintaan');
        $out = [];
        foreach ($codes as $c) {
            $ctx = array_map(fn($x) => "[{$x['category']}] {$x['content']} (sumber: {$x['source']})", all('SELECT * FROM local_context WHERE province_code = ? AND verified = 1 ORDER BY category', [$c]));
            $res = ai_localize($u, $d['title'], $d['body'], PROVINCES[$c][0], $ctx);
            $now = now_iso(); $t = mb_substr(trim((string)$res['title']), 0, 300) ?: $d['title']; $bd = trim((string)$res['body']);
            $did = insert('drafts', ['kind' => $d['kind'], 'format' => $d['format'], 'title' => $t, 'body' => $bd, 'province_code' => $c, 'issue_id' => $d['issue_id'], 'ai_generated' => 1,
                'status' => 'draf', 'version' => 1, 'content_hash' => draft_hash($t, $bd), 'created_by' => $u['id'], 'created_at' => $now, 'updated_at' => $now,
                'brief_id' => $d['brief_id'], 'hashtags' => $d['hashtags'], 'priority' => $d['priority'] ?: 'normal']);
            insert('draft_revisions', ['draft_id' => $did, 'version' => 1, 'title' => $t, 'body' => $bd, 'edited_by' => $u['id'], 'at' => $now]);
            audit_record($u, 'naskah.pelokalan_ai', 'draft', $did, ['dari' => (int)$d['id'], 'provinsi' => $c, 'mode' => $res['mode']]);
            $out[] = ['draft_id' => $did, 'province_code' => $c, 'province_name' => PROVINCES[$c][0], 'title' => $t, 'notes' => $res['notes'] ?? '', 'mode' => $res['mode']];
        }
        send_json(['created' => $out], 201);
    });
    $r->add('POST', '/api/m4/drafts/(\d+)/editorial', function ($id) {
        $u = require_roles(...AUTHOR_OR_EDITOR);
        $d = get_draft_row((int)$id);
        $facts = array_map(fn($f) => $f['text'] . ($f['source'] ? " (sumber: {$f['source']})" : ''), draft_facts($d));
        $res = ai_editorial_check($u, $d['title'], $d['body'], $facts);
        q('UPDATE drafts SET editorial_score = ?, editorial_json = ? WHERE id = ?', [(int)$res['score'], jenc($res), $d['id']]);
        audit_record($u, 'naskah.cek_editorial_ai', 'draft', $d['id'], ['score' => (int)$res['score'], 'temuan' => count($res['findings'] ?? []), 'mode' => $res['mode']]);
        send_json($res);
    });

    // ---------- M5 Editorial & Persetujuan ----------
    $r->add('GET', '/api/m5/board', function () {
        current_user();
        $cols = [];
        foreach (['draf', 'ditinjau', 'disetujui', 'tayang'] as $s) {
            $lim = $s === 'tayang' ? 'AND COALESCE(published_at, updated_at) >= ' . db()->quote(iso_from_ts(microtime(true) - 14 * 86400)) : '';
            $cols[] = ['status' => $s, 'cards' => array_map('board_card', all("SELECT * FROM drafts WHERE status = ? $lim ORDER BY CASE priority WHEN 'tinggi' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END, COALESCE(due_at, '9999'), updated_at DESC LIMIT 60", [$s]))];
        }
        send_json(['columns' => $cols, 'team' => array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['full_name'], 'roles' => jdec($x['roles'])],
            array_values(array_filter(all('SELECT * FROM users WHERE active = 1 ORDER BY full_name'), fn($x) => (bool)array_intersect(jdec($x['roles']), AUTHOR_OR_EDITOR))))]);
    });
    $r->add('PATCH', '/api/drafts/(\d+)/assign', function ($id) {
        $u = require_roles('redaktur', 'pimpinan');
        $d = get_draft_row((int)$id);
        $b = json_body(); $set = []; $ch = [];
        if (array_key_exists('assignee_id', $b)) {
            $aid = $b['assignee_id'] === null ? null : (int)$b['assignee_id'];
            if ($aid && !one('SELECT id FROM users WHERE id = ? AND active = 1', [$aid])) fail(422, 'Pengguna tidak ditemukan');
            $set['assignee_id'] = $aid; $ch['assignee'] = user_name($aid);
        }
        if (array_key_exists('due_at', $b)) { $set['due_at'] = opt_dt($b['due_at']); $ch['due_at'] = $set['due_at']; }
        if (isset($b['priority'])) { if (!in_array($b['priority'], ['tinggi', 'normal', 'rendah'], true)) fail(422, 'Prioritas tidak dikenal'); $set['priority'] = $b['priority']; $ch['priority'] = $b['priority']; }
        foreach ($set as $k => $v) q("UPDATE drafts SET $k = ? WHERE id = ?", [$v, $d['id']]);
        if (!empty($set['assignee_id'])) notify([(int)$set['assignee_id']], 'penugasan', "Anda ditugaskan: {$d['title']}" . (!empty($set['due_at']) ? ' (tenggat ' . substr($set['due_at'], 0, 16) . ' UTC)' : ''), '/editorial?id=' . $d['id'], (int)$u['id']);
        audit_record($u, 'naskah.penugasan', 'draft', $d['id'], $ch);
        send_json(board_card(get_draft_row((int)$id)));
    });
    $r->add('GET', '/api/drafts/(\d+)/comments', function ($id) {
        current_user(); get_draft_row((int)$id);
        send_json(array_map(fn($c) => ['id' => (int)$c['id'], 'user_name' => user_name((int)$c['user_id']), 'body' => $c['body'], 'at' => $c['at']],
            all('SELECT * FROM draft_comments WHERE draft_id = ? ORDER BY id', [(int)$id])));
    });
    $r->add('POST', '/api/drafts/(\d+)/comments', function ($id) {
        $u = current_user(); $d = get_draft_row((int)$id);
        $body = trim(str_field(json_body(), 'body', 1, 4000));
        $cid = insert('draft_comments', ['draft_id' => $d['id'], 'user_id' => $u['id'], 'body' => $body, 'at' => now_iso()]);
        notify([(int)$d['created_by'], (int)($d['assignee_id'] ?? 0)], 'diskusi', "{$u['full_name']} menulis catatan pada \"{$d['title']}\"", '/editorial?id=' . $d['id'], (int)$u['id']);
        audit_record($u, 'naskah.diskusi', 'draft', $d['id'], ['comment_id' => $cid]);
        send_json(['id' => $cid, 'user_name' => $u['full_name'], 'body' => $body, 'at' => now_iso()], 201);
    });

    // ---------- M6 Kanal Media Sosial Resmi ----------
    $r->add('GET', '/api/m6/accounts', function () { current_user(); send_json(array_map('account_out', all('SELECT * FROM social_accounts ORDER BY scope, followers DESC'))); });
    $r->add('POST', '/api/m6/accounts', function () {
        $u = require_roles('admin', 'operator');
        $b = json_body();
        $p = str_field($b, 'platform'); if (!isset(PLATFORMS[$p])) fail(422, 'Platform tidak dikenal');
        $handle = trim(str_field($b, 'handle', 2, 80));
        $prov = ($b['province_code'] ?? null) ?: null; if ($prov !== null && !isset(PROVINCES[$prov])) fail(422, 'Kode provinsi tidak dikenal');
        $id = insert('social_accounts', ['platform' => $p, 'handle' => $handle, 'name' => trim(str_field($b, 'name', 2, 120)), 'scope' => $prov ? 'provinsi' : 'nasional',
            'province_code' => $prov, 'pic_user_id' => ($b['pic_user_id'] ?? null) ? (int)$b['pic_user_id'] : null, 'followers' => max(0, (int)($b['followers'] ?? 0)),
            'token_status' => 'aman', 'mfa' => !empty($b['mfa']) ? 1 : 0, 'active' => 1, 'url' => (string)($b['url'] ?? ''), 'created_at' => now_iso()]);
        audit_record($u, 'akun_resmi.tambah', 'social_account', $id, ['platform' => $p, 'handle' => $handle]);
        send_json(account_out(one('SELECT * FROM social_accounts WHERE id = ?', [$id])), 201);
    });
    $r->add('GET', '/api/m6/tasks', function () {
        current_user();
        $st = qparam('status');
        $rows = $st ? all('SELECT * FROM post_tasks WHERE status = ? ORDER BY planned_at', [$st]) : all("SELECT * FROM post_tasks ORDER BY CASE status WHEN 'menunggu' THEN 0 ELSE 1 END, planned_at DESC LIMIT 200");
        send_json(array_map('task_out', $rows));
    });
    $r->add('POST', '/api/m6/tasks', function () {
        $u = require_roles('operator', 'redaktur', 'pimpinan');
        $b = json_body();
        $d = get_draft_row((int)($b['draft_id'] ?? 0));
        if (!in_array($d['status'], ['disetujui', 'tayang'], true)) fail(409, 'Hanya naskah yang sudah disetujui yang dapat dijadwalkan ke akun resmi');
        $acc = int_list($b['account_ids'] ?? []); if (!$acc) fail(422, 'Pilih minimal satu akun resmi');
        $planned = opt_dt($b['planned_at'] ?? null) ?? now_iso();
        $caption = trim((string)($b['caption'] ?? ''));
        $made = [];
        foreach ($acc as $aid) {
            if (!one('SELECT id FROM social_accounts WHERE id = ? AND active = 1', [$aid])) fail(422, "Akun #$aid tidak ditemukan");
            check_quota($aid, $planned, $u, !empty($b['override_quota']));
            $tid = insert('post_tasks', ['draft_id' => $d['id'], 'account_id' => $aid, 'planned_at' => $planned, 'caption' => $caption ?: $d['title'], 'status' => 'menunggu',
                'assignee_id' => ($b['assignee_id'] ?? null) ? (int)$b['assignee_id'] : (val('SELECT pic_user_id FROM social_accounts WHERE id = ?', [$aid]) ?: null), 'created_by' => $u['id'], 'created_at' => now_iso()]);
            $made[] = task_out(one('SELECT * FROM post_tasks WHERE id = ?', [$tid]));
            notify([(int)val('SELECT assignee_id FROM post_tasks WHERE id = ?', [$tid])], 'tugas_unggah', "Tugas unggah baru: {$d['title']}", '/kanal-resmi?tab=tugas', (int)$u['id']);
        }
        audit_record($u, 'medsos.tugas_unggah', 'draft', $d['id'], ['akun' => $acc, 'planned_at' => $planned]);
        send_json($made, 201);
    });
    $r->add('POST', '/api/m6/tasks/(\d+)/done', function ($id) {
        $u = require_roles('operator');
        $t = one('SELECT * FROM post_tasks WHERE id = ?', [(int)$id]); if (!$t) fail(404, 'Tugas tidak ditemukan');
        if ($t['status'] !== 'menunggu') fail(409, 'Tugas sudah tidak menunggu');
        $url = trim(str_field(json_body(), 'posted_url', 8, 500));
        if (!preg_match('#^https?://#i', $url)) fail(422, 'Tautan unggahan harus diawali http(s)://');
        q("UPDATE post_tasks SET status = 'diunggah', posted_url = ?, posted_at = ?, posted_by = ? WHERE id = ?", [$url, now_iso(), $u['id'], $t['id']]);
        audit_record($u, 'medsos.diunggah_manual', 'post_task', $t['id'], ['url' => $url]);
        send_json(task_out(one('SELECT * FROM post_tasks WHERE id = ?', [$t['id']])));
    });
    $r->add('POST', '/api/m6/tasks/(\d+)/cancel', function ($id) {
        $u = require_roles('operator', 'redaktur', 'pimpinan');
        $t = one('SELECT * FROM post_tasks WHERE id = ?', [(int)$id]); if (!$t) fail(404, 'Tugas tidak ditemukan');
        if ($t['status'] !== 'menunggu') fail(409, 'Tugas sudah tidak menunggu');
        q("UPDATE post_tasks SET status = 'batal' WHERE id = ?", [$t['id']]);
        audit_record($u, 'medsos.tugas_batal', 'post_task', $t['id']);
        send_json(task_out(one('SELECT * FROM post_tasks WHERE id = ?', [$t['id']])));
    });
    $r->add('GET', '/api/m6/comments', function () {
        current_user();
        $w = ['1=1']; $p = [];
        if ($s = qparam('status')) { if ($s === 'terbuka') $w[] = "status IN ('baru','ditriase')"; else { $w[] = 'status = ?'; $p[] = $s; } }
        if ($c = qparam('category')) { $w[] = 'category = ?'; $p[] = $c; }
        if ($a = qparam('account_id')) { $w[] = 'account_id = ?'; $p[] = (int)$a; }
        if (bool_q('bot')) $w[] = 'bot_flag = 1';
        $rows = all('SELECT * FROM social_comments WHERE ' . implode(' AND ', $w) . " ORDER BY CASE priority WHEN 'tinggi' THEN 0 WHEN 'sedang' THEN 1 ELSE 2 END, at DESC LIMIT 300", $p);
        send_json(array_map('comment_out', $rows));
    });
    $r->add('POST', '/api/m6/comments', function () {
        $u = require_roles('operator', 'analis');
        $b = json_body();
        $aid = (int)($b['account_id'] ?? 0); if (!one('SELECT id FROM social_accounts WHERE id = ?', [$aid])) fail(422, 'Pilih akun resmi');
        $items = is_array($b['items'] ?? null) ? $b['items'] : [];
        if (!$items || count($items) > 200) fail(422, 'Isi 1–200 komentar');
        $iss = issue_by_code($b['issue_code'] ?? null);
        $n = 0;
        foreach ($items as $it) {
            $text = trim((string)($it['text'] ?? '')); if ($text === '') continue;
            insert('social_comments', ['account_id' => $aid, 'issue_id' => $iss['id'] ?? null, 'author' => mb_substr(trim((string)($it['author'] ?? 'anonim')), 0, 80), 'text' => mb_substr($text, 0, 2000),
                'at' => opt_dt($it['at'] ?? null) ?? now_iso(), 'status' => 'baru']);
            $n++;
        }
        $bots = detect_bot_swarm();
        audit_record($u, 'medsos.komentar_masuk', 'social_account', $aid, ['jumlah' => $n]);
        send_json(['inserted' => $n, 'bot_flagged' => $bots], 201);
    });
    $r->add('POST', '/api/m6/comments/triage', function () {
        $u = require_roles('operator', 'analis');
        $ids = int_list(json_body()['ids'] ?? []);
        $rows = $ids ? all('SELECT * FROM social_comments WHERE id IN (' . implode(',', $ids) . ')') : all("SELECT * FROM social_comments WHERE status = 'baru' ORDER BY at DESC LIMIT 20");
        $rows = array_slice($rows, 0, 20);
        if (!$rows) { send_json(['mode' => ai_status()['mode'], 'triaged' => 0]); return; }
        $items = array_map(fn($c) => ['id' => (int)$c['id'], 'text' => $c['text'], 'platform' => val('SELECT platform FROM social_accounts WHERE id = ?', [$c['account_id']]) ?: ''], $rows);
        $res = ai_triage($u, $items, kb_text());
        $now = now_iso(); $n = 0;
        foreach ($res['results'] as $x) {
            $cid = (int)($x['id'] ?? 0); if (!in_array($cid, array_column($items, 'id'), true)) continue;
            q("UPDATE social_comments SET category = ?, sentiment = ?, priority = ?, reason = ?, suggested_reply = ?, ai_mode = ?, triaged_at = ?, status = CASE WHEN status = 'baru' THEN 'ditriase' ELSE status END WHERE id = ?",
                [$x['category'], $x['sentiment'], $x['priority'], mb_substr((string)($x['reason'] ?? ''), 0, 300), (string)($x['suggested_reply'] ?? ''), $res['mode'], $now, $cid]);
            $n++;
        }
        $bots = detect_bot_swarm();
        q("UPDATE social_comments SET priority = 'tinggi' WHERE bot_flag = 1 AND status IN ('baru','ditriase')");
        audit_record($u, 'medsos.triase_ai', 'social_comment', '-', ['jumlah' => $n, 'mode' => $res['mode'], 'bot' => $bots]);
        send_json(['mode' => $res['mode'], 'triaged' => $n, 'bot_flagged' => $bots]);
    });
    $r->add('POST', '/api/m6/comments/(\d+)/suggest', function ($id) {
        $u = require_roles('operator', 'analis');
        $c = one('SELECT * FROM social_comments WHERE id = ?', [(int)$id]); if (!$c) fail(404, 'Komentar tidak ditemukan');
        $res = ai_reply($u, $c['text'], kb_text($c['issue_id'] ? (int)$c['issue_id'] : null));
        q('UPDATE social_comments SET suggested_reply = ?, ai_mode = ? WHERE id = ?', [$res['reply'], $res['mode'], $c['id']]);
        send_json(comment_out(one('SELECT * FROM social_comments WHERE id = ?', [$c['id']])));
    });
    $r->add('POST', '/api/m6/comments/(\d+)/reply', function ($id) {
        $u = require_roles('operator');
        $c = one('SELECT * FROM social_comments WHERE id = ?', [(int)$id]); if (!$c) fail(404, 'Komentar tidak ditemukan');
        $text = trim(str_field(json_body(), 'text', 2, 1000));
        q("UPDATE social_comments SET status = 'dibalas', reply_text = ?, replied_by = ?, replied_at = ? WHERE id = ?", [$text, $u['id'], now_iso(), $c['id']]);
        audit_record($u, 'medsos.balas_manual', 'social_comment', $c['id'], ['panjang' => mb_strlen($text)]);
        send_json(comment_out(one('SELECT * FROM social_comments WHERE id = ?', [$c['id']])));
    });
    $r->add('POST', '/api/m6/comments/(\d+)/status', function ($id) {
        $u = require_roles('operator', 'analis');
        $c = one('SELECT * FROM social_comments WHERE id = ?', [(int)$id]); if (!$c) fail(404, 'Komentar tidak ditemukan');
        $st = (string)(json_body()['status'] ?? '');
        if (!in_array($st, ['diabaikan', 'dieskalasi', 'ditriase'], true)) fail(422, 'Status tidak dikenal');
        q('UPDATE social_comments SET status = ? WHERE id = ?', [$st, $c['id']]);
        audit_record($u, 'medsos.status_komentar', 'social_comment', $c['id'], ['status' => $st]);
        send_json(comment_out(one('SELECT * FROM social_comments WHERE id = ?', [$c['id']])));
    });
    $r->add('GET', '/api/m6/kb', function () { current_user(); send_json(array_map('kb_out', all('SELECT * FROM kb_responses ORDER BY updated_at DESC LIMIT 120'))); });
    $r->add('POST', '/api/m6/kb', function () {
        $u = require_roles('redaktur', 'pimpinan', 'analis');
        $b = json_body();
        $iss = issue_by_code($b['issue_code'] ?? null);
        $st = has_role($u, 'pimpinan', 'redaktur') ? 'disetujui' : 'draf';
        $id = insert('kb_responses', ['issue_id' => $iss['id'] ?? null, 'title' => trim(str_field($b, 'title', 3, 200)), 'question' => trim(str_field($b, 'question', 3, 1000)),
            'answer' => trim(str_field($b, 'answer', 10, 3000)), 'sources' => trim((string)($b['sources'] ?? '')), 'status' => $st, 'created_by' => $u['id'], 'updated_at' => now_iso()]);
        audit_record($u, 'medsos.bahan_respons', 'kb', $id, ['status' => $st]);
        send_json(kb_out(one('SELECT * FROM kb_responses WHERE id = ?', [$id])), 201);
    });

    // ---------- M7 Distribusi 39 Kanal ----------
    $r->add('GET', '/api/m7/channels', function () {
        current_user();
        send_json(array_map('channel_out', all("SELECT * FROM channels WHERE type IN ('nasional','provinsi') ORDER BY CASE type WHEN 'nasional' THEN 0 ELSE 1 END, name")));
    });
    $r->add('GET', '/api/m7/ready', function () {
        current_user();
        $rows = all("SELECT * FROM drafts WHERE status IN ('disetujui','tayang') ORDER BY updated_at DESC LIMIT 80");
        send_json(array_map(fn($d) => board_card($d) + ['distributed' => (int)val("SELECT COUNT(*) FROM distributions WHERE draft_id = ? AND status = 'terkirim'", [$d['id']]),
            'failed' => (int)val("SELECT COUNT(*) FROM distributions WHERE draft_id = ? AND status = 'gagal'", [$d['id']])], $rows));
    });
    $r->add('POST', '/api/m7/distribute', function () {
        $u = require_roles('operator');
        $b = json_body();
        $d = get_draft_row((int)($b['draft_id'] ?? 0));
        if (!in_array($d['status'], ['disetujui', 'tayang'], true)) fail(409, 'Hanya naskah yang sudah disetujui yang dapat disalurkan');
        $scope = (string)($b['scope'] ?? 'pilih');
        if ($scope === 'semua') $chs = all("SELECT * FROM channels WHERE type IN ('nasional','provinsi') AND active = 1");
        elseif ($scope === 'nasional') $chs = all("SELECT * FROM channels WHERE type = 'nasional' AND active = 1");
        else {
            $codes = list_field($b, 'channel_codes') ?? [];
            if (!$codes) fail(422, 'Pilih kanal tujuan');
            $chs = array_values(array_filter(array_map(fn($c) => one("SELECT * FROM channels WHERE code = ? AND type IN ('nasional','provinsi')", [$c]), $codes)));
        }
        if (!$chs) fail(422, 'Kanal tujuan tidak ditemukan');
        $d = publish_draft($d, $u);
        $sent = 0; $failed = 0; $now = now_iso();
        foreach ($chs as $c) {
            $ex = one('SELECT * FROM distributions WHERE draft_id = ? AND channel_id = ?', [$d['id'], $c['id']]);
            if ($ex && $ex['status'] === 'terkirim') continue;
            $ok = ($c['health'] ?: 'normal') !== 'gangguan';
            $row = ['status' => $ok ? 'terkirim' : 'gagal', 'sent_at' => $ok ? $now : null, 'error' => $ok ? null : 'Kanal tidak merespons (gangguan); coba ulang setelah pulih',
                'url' => $ok ? 'https://contoh.invalid/' . strtolower($c['code']) . '/naskah-' . $d['id'] : null];
            if ($ex) q('UPDATE distributions SET status = ?, sent_at = ?, error = ?, url = ? WHERE id = ?', [$row['status'], $row['sent_at'], $row['error'], $row['url'], $ex['id']]);
            else insert('distributions', ['draft_id' => $d['id'], 'channel_id' => $c['id'], 'created_by' => $u['id'], 'created_at' => $now] + $row);
            $ok ? $sent++ : $failed++;
        }
        audit_record($u, 'distribusi.salurkan', 'draft', $d['id'], ['kanal' => count($chs), 'terkirim' => $sent, 'gagal' => $failed]);
        send_json(['draft_status' => $d['status'], 'sent' => $sent, 'failed' => $failed,
            'items' => array_map('dist_out', all('SELECT * FROM distributions WHERE draft_id = ? ORDER BY status, id', [$d['id']]))]);
    });
    $r->add('GET', '/api/m7/distributions', function () {
        current_user();
        $did = (int)qparam('draft_id', 0);
        $rows = $did ? all('SELECT * FROM distributions WHERE draft_id = ? ORDER BY status, id', [$did]) : all('SELECT * FROM distributions ORDER BY created_at DESC LIMIT 200');
        send_json(array_map('dist_out', $rows));
    });
    $r->add('POST', '/api/m7/distributions/(\d+)/retry', function ($id) {
        $u = require_roles('operator');
        $x = one('SELECT * FROM distributions WHERE id = ?', [(int)$id]); if (!$x) fail(404, 'Distribusi tidak ditemukan');
        if ($x['status'] !== 'gagal') fail(409, 'Hanya distribusi gagal yang dapat dicoba ulang');
        $c = one('SELECT * FROM channels WHERE id = ?', [$x['channel_id']]);
        if (($c['health'] ?: 'normal') === 'gangguan') fail(409, "{$c['name']} masih mengalami gangguan");
        q("UPDATE distributions SET status = 'terkirim', sent_at = ?, error = NULL, url = ? WHERE id = ?", [now_iso(), 'https://contoh.invalid/' . strtolower($c['code']) . '/naskah-' . $x['draft_id'], $x['id']]);
        audit_record($u, 'distribusi.ulang', 'distribution', $x['id']);
        send_json(dist_out(one('SELECT * FROM distributions WHERE id = ?', [$x['id']])));
    });
    $r->add('POST', '/api/m7/health-check', function () {
        $u = require_roles('operator', 'admin');
        // Simulasi uji ketersediaan dari 5 titik pantau (MVP: belum terhubung CMS sungguhan).
        $res = run_uptime_checks();
        audit_record($u, 'distribusi.uji_ketersediaan', 'channel', '-', $res);
        send_json($res);
    });

    // ---------- dashboard ----------
    $r->add('GET', '/api/exec/summary', function () { current_user(); send_json(exec_summary()); });
    $r->add('GET', '/api/analytics/overview', function () { current_user(); send_json(analytics_overview(qint('days', 30, 7, 90))); });
}
