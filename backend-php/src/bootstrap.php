<?php
/**
 * SISTRA — backend PHP (port dari backend Python/FastAPI MVP) untuk hosting cPanel tanpa Python.
 * Kontrak API identik dengan backend Python agar frontend React tidak berubah.
 */
declare(strict_types=1);

const SISTRA_VERSION = '0.1.0-mvp-php';
const LEVELS = ['TERBUKA' => 0, 'TERBATAS' => 1, 'RAHASIA' => 2, 'SANGAT_RAHASIA' => 3];
const LEVEL_LABELS = ['TERBUKA' => 'Terbuka', 'TERBATAS' => 'Terbatas', 'RAHASIA' => 'Rahasia', 'SANGAT_RAHASIA' => 'Sangat Rahasia'];
const ROLES = ['pimpinan', 'analis', 'redaktur', 'produser', 'operator', 'arsiparis', 'pelapor', 'admin', 'auditor'];
const TOKEN_TTL = 8 * 3600;
const MAX_UPLOAD_MB = 50;

final class HttpError extends Exception
{
    /** @var mixed */
    public $detail;
    public int $status;
    public function __construct(int $status, $detail)
    {
        parent::__construct(is_string($detail) ? $detail : 'error');
        $this->status = $status;
        $this->detail = $detail;
    }
}

function fail(int $status, $detail): void { throw new HttpError($status, $detail); }

function data_dir(): string
{
    static $d = null;
    if ($d === null) {
        $d = rtrim(getenv('SISTRA_DATA_DIR') ?: (defined('SISTRA_DATA_DIR') ? SISTRA_DATA_DIR : __DIR__ . '/../data'), '/');
        if (!is_dir($d)) { mkdir($d, 0700, true); }
    }
    return $d;
}

// ---------- waktu ----------
function now_iso(?int $offsetSeconds = 0): string
{
    $t = microtime(true) + ($offsetSeconds ?? 0);
    $dt = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $t), new DateTimeZone('UTC'));
    return $dt->format('Y-m-d\TH:i:s.u\Z');
}
function iso_from_ts(float $ts): string
{
    $dt = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $ts), new DateTimeZone('UTC'));
    return $dt->format('Y-m-d\TH:i:s.u\Z');
}
function ts_from_iso(?string $iso): ?float
{
    if ($iso === null || $iso === '') return null;
    try { $d = new DateTimeImmutable($iso); } catch (Exception $e) { return null; }
    return (float)$d->format('U.u');
}
function parse_input_datetime(?string $s): ?string
{
    if ($s === null || trim($s) === '') return null;
    $ts = ts_from_iso($s);
    return $ts === null ? null : iso_from_ts($ts);
}

// ---------- rahasia ----------
function secrets(): array
{
    static $s = null;
    if ($s !== null) return $s;
    $path = data_dir() . '/secrets.json';
    $lock = fopen(data_dir() . '/.secrets.lock', 'c');
    flock($lock, LOCK_EX);
    if (is_file($path)) {
        $s = json_decode((string)file_get_contents($path), true);
    } else {
        $s = ['secret_key' => b64url(random_bytes(48)), 'signing_key' => b64url(random_bytes(48))];
        $fh = fopen($path, 'x');
        chmod($path, 0600);
        fwrite($fh, json_encode($s));
        fclose($fh);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    return $s;
}
function b64url(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function b64url_decode(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
function sign(string $msg): string { return hash_hmac('sha256', $msg, secrets()['signing_key']); }
function verify_signature(string $msg, ?string $sig): bool { return hash_equals(sign($msg), (string)$sig); }

// ---------- JWT HS256 ----------
function jwt_encode(array $payload): string
{
    $h = b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $p = b64url(json_encode($payload));
    return "$h.$p." . b64url(hash_hmac('sha256', "$h.$p", secrets()['secret_key'], true));
}
function jwt_decode(string $tok): ?array
{
    $parts = explode('.', $tok);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    $hdr = json_decode(b64url_decode($h), true);
    if (!is_array($hdr) || ($hdr['alg'] ?? '') !== 'HS256') return null;
    $exp = b64url(hash_hmac('sha256', "$h.$p", secrets()['secret_key'], true));
    if (!hash_equals($exp, $s)) return null;
    $pl = json_decode(b64url_decode($p), true);
    if (!is_array($pl) || ($pl['exp'] ?? 0) < time()) return null;
    return $pl;
}

// ---------- basis data ----------
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $path = data_dir() . '/sistra.db';
    $new = !is_file($path);
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=8000; PRAGMA foreign_keys=ON;');
    if ($new) { @chmod($path, 0600); }
    return $pdo;
}
/** Status transaksi dilacak sendiri: PDO::inTransaction() tidak mengenali BEGIN IMMEDIATE. */
function tx_begin(): void { db()->exec('BEGIN IMMEDIATE'); $GLOBALS['SISTRA_TX'] = true; }
function tx_end(bool $commit): void { if (!empty($GLOBALS['SISTRA_TX'])) { db()->exec($commit ? 'COMMIT' : 'ROLLBACK'); $GLOBALS['SISTRA_TX'] = false; } }
function in_tx(): bool { return !empty($GLOBALS['SISTRA_TX']); }
function q(string $sql, array $params = []): PDOStatement { $st = db()->prepare($sql); $st->execute($params); return $st; }
function one(string $sql, array $params = []): ?array { $r = q($sql, $params)->fetch(); return $r === false ? null : $r; }
function all(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
function val(string $sql, array $params = []) { $r = q($sql, $params)->fetchColumn(); return $r === false ? null : $r; }
function insert(string $table, array $row): int
{
    $cols = array_keys($row);
    q('INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($row));
    return (int)db()->lastInsertId();
}
function jenc($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function jdec(?string $s, $default = []) { if ($s === null || $s === '') return $default; $v = json_decode($s, true); return $v === null ? $default : $v; }

function init_schema(): void
{
    db()->exec(<<<SQL
CREATE TABLE IF NOT EXISTS units (id INTEGER PRIMARY KEY, code TEXT UNIQUE NOT NULL, name TEXT NOT NULL, parent_id INTEGER);
CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, username TEXT UNIQUE NOT NULL, full_name TEXT NOT NULL, nip TEXT DEFAULT '',
  unit_id INTEGER NOT NULL, roles TEXT NOT NULL, clearance TEXT NOT NULL, password_hash TEXT NOT NULL, active INTEGER DEFAULT 1, created_at TEXT);
CREATE TABLE IF NOT EXISTS audit_events (seq INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, actor_id INTEGER, actor_username TEXT NOT NULL,
  action TEXT NOT NULL, object_type TEXT NOT NULL, object_id TEXT NOT NULL, ip TEXT NOT NULL, detail TEXT NOT NULL, prev_hash TEXT NOT NULL, hash TEXT UNIQUE NOT NULL);
CREATE INDEX IF NOT EXISTS ix_audit_action ON audit_events(action);
CREATE TRIGGER IF NOT EXISTS audit_events_no_update BEFORE UPDATE ON audit_events BEGIN SELECT RAISE(ABORT, 'audit_events bersifat append-only'); END;
CREATE TRIGGER IF NOT EXISTS audit_events_no_delete BEFORE DELETE ON audit_events BEGIN SELECT RAISE(ABORT, 'audit_events bersifat append-only'); END;
CREATE TABLE IF NOT EXISTS sources (id INTEGER PRIMARY KEY, type TEXT, name TEXT, url TEXT DEFAULT '', platform TEXT DEFAULT '', province_code TEXT,
  reliability TEXT DEFAULT 'C', access_basis TEXT DEFAULT 'Sumber terbuka/publik', active INTEGER DEFAULT 1, last_fetched_at TEXT, created_at TEXT);
CREATE TABLE IF NOT EXISTS content_items (id INTEGER PRIMARY KEY, source_id INTEGER NOT NULL, external_id TEXT NOT NULL, url TEXT DEFAULT '', title TEXT,
  body TEXT DEFAULT '', lang TEXT DEFAULT 'id', author TEXT DEFAULT '', published_at TEXT NOT NULL, ingested_at TEXT, content_hash TEXT UNIQUE NOT NULL,
  simhash INTEGER, duplicate_of INTEGER, sentiment_label TEXT DEFAULT 'netral', sentiment_score REAL DEFAULT 0, province_code TEXT, provinces TEXT DEFAULT '[]',
  UNIQUE(source_id, external_id));
CREATE INDEX IF NOT EXISTS ix_content_published ON content_items(published_at);
CREATE TABLE IF NOT EXISTS issues (id INTEGER PRIMARY KEY, code TEXT UNIQUE NOT NULL, name TEXT, taxonomy_path TEXT DEFAULT '', description TEXT DEFAULT '',
  risk_level TEXT DEFAULT 'sedang', keywords TEXT DEFAULT '[]', active INTEGER DEFAULT 1, created_at TEXT);
CREATE TABLE IF NOT EXISTS content_issues (content_id INTEGER NOT NULL, issue_id INTEGER NOT NULL, confidence REAL DEFAULT 1, assigned_by TEXT DEFAULT 'model',
  corrected_by INTEGER, created_at TEXT, PRIMARY KEY(content_id, issue_id));
CREATE INDEX IF NOT EXISTS ix_ci_issue ON content_issues(issue_id);
CREATE TABLE IF NOT EXISTS alerts (id INTEGER PRIMARY KEY, issue_id INTEGER NOT NULL, level TEXT, status TEXT DEFAULT 'open', reason TEXT DEFAULT '',
  metrics TEXT DEFAULT '{}', owner_id INTEGER, triggered_at TEXT, updated_at TEXT, resolved_at TEXT);
CREATE TABLE IF NOT EXISTS alert_events (id INTEGER PRIMARY KEY, alert_id INTEGER NOT NULL, at TEXT, actor_id INTEGER, action TEXT, level TEXT, note TEXT DEFAULT '');
CREATE TABLE IF NOT EXISTS channels (id INTEGER PRIMARY KEY, code TEXT UNIQUE, name TEXT, type TEXT, province_code TEXT, url TEXT DEFAULT '', active INTEGER DEFAULT 1);
CREATE TABLE IF NOT EXISTS drafts (id INTEGER PRIMARY KEY, kind TEXT, title TEXT, body TEXT, province_code TEXT, issue_id INTEGER, ai_generated INTEGER DEFAULT 0,
  status TEXT DEFAULT 'draf', version INTEGER DEFAULT 1, content_hash TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, approved_by INTEGER,
  approved_at TEXT, approval_signature TEXT);
CREATE TABLE IF NOT EXISTS approval_steps (id INTEGER PRIMARY KEY, draft_id INTEGER, from_status TEXT, to_status TEXT, actor_id INTEGER, actor_role TEXT,
  comment TEXT DEFAULT '', version INTEGER, content_hash TEXT, signature TEXT, at TEXT);
CREATE TABLE IF NOT EXISTS draft_revisions (id INTEGER PRIMARY KEY, draft_id INTEGER, version INTEGER, title TEXT, body TEXT, edited_by INTEGER, at TEXT);
CREATE TABLE IF NOT EXISTS calendar_entries (id INTEGER PRIMARY KEY, draft_id INTEGER, channel_id INTEGER, planned_at TEXT, status TEXT DEFAULT 'rencana',
  created_by INTEGER, created_at TEXT);
CREATE TABLE IF NOT EXISTS documents (id INTEGER PRIMARY KEY, title TEXT, description TEXT DEFAULT '', owner_unit_id INTEGER, classification TEXT,
  period_start TEXT, period_end TEXT, origin TEXT, retention_class TEXT DEFAULT 'UMUM', tags TEXT DEFAULT '[]', status TEXT DEFAULT 'active',
  checked_out_by INTEGER, current_version_no INTEGER DEFAULT 1, created_by INTEGER, created_at TEXT, updated_at TEXT);
CREATE TABLE IF NOT EXISTS document_versions (id INTEGER PRIMARY KEY, document_id INTEGER, version_no INTEGER, filename TEXT, mime TEXT, size INTEGER,
  sha256 TEXT, text_content TEXT DEFAULT '', note TEXT DEFAULT '', created_by INTEGER, created_at TEXT, UNIQUE(document_id, version_no));
CREATE TABLE IF NOT EXISTS access_grants (id INTEGER PRIMARY KEY, document_id INTEGER, user_id INTEGER, reason TEXT, valid_from TEXT, valid_until TEXT,
  granted_by INTEGER, revoked_at TEXT, created_at TEXT);
CREATE TABLE IF NOT EXISTS download_logs (id INTEGER PRIMARY KEY, version_id INTEGER, user_id INTEGER, ip TEXT, watermark_id TEXT UNIQUE, at TEXT);
SQL);
}

// ---------- HTTP ----------
function client_ip(): string
{
    $ra = $_SERVER['REMOTE_ADDR'] ?? '-';
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['HTTP_X_REAL_IP'] ?? '');
    $isInternal = !filter_var($ra, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    if ($xff !== '' && $isInternal) {
        $first = trim(explode(',', $xff)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
    }
    return $ra;
}

function json_body(): array
{
    static $b = null;
    if ($b !== null) return $b;
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return $b = [];
    $d = json_decode($raw, true);
    if (!is_array($d)) fail(422, 'Body JSON tidak valid');
    return $b = $d;
}
function qparam(string $k, $default = null)
{
    $v = $_GET[$k] ?? null;
    return ($v === null || $v === '') ? $default : $v;
}
function qint(string $k, int $default, int $min, int $max): int
{
    $v = qparam($k);
    if ($v === null) return $default;
    if (!preg_match('/^-?\d+$/', (string)$v)) fail(422, "Parameter $k harus bilangan bulat");
    $i = (int)$v;
    if ($i < $min || $i > $max) fail(422, "Parameter $k di luar rentang {$min}–{$max}");
    return $i;
}
function send_json($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo jenc($data);
}

// ---------- pengguna & otorisasi ----------
function user_out(array $u): array
{
    $unit = one('SELECT * FROM units WHERE id = ?', [$u['unit_id']]);
    return [
        'id' => (int)$u['id'], 'username' => $u['username'], 'full_name' => $u['full_name'], 'nip' => $u['nip'],
        'unit' => unit_out($unit), 'roles' => jdec($u['roles']), 'clearance' => $u['clearance'],
        'clearance_label' => LEVEL_LABELS[$u['clearance']], 'active' => (bool)$u['active'],
    ];
}
function unit_out(array $u): array { return ['id' => (int)$u['id'], 'code' => $u['code'], 'name' => $u['name'], 'parent_id' => $u['parent_id'] === null ? null : (int)$u['parent_id']]; }
function has_role(array $u, string ...$roles): bool { return (bool)array_intersect($roles, jdec($u['roles'])); }
function clearance(array $u): int { return LEVELS[$u['clearance']]; }

function current_user(): array
{
    static $u = null;
    if ($u !== null) return $u;
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) { if (strtolower($k) === 'authorization') $h = $v; }
    }
    if (!preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) throw new HttpError(401, 'Tidak terautentikasi');
    $p = jwt_decode($m[1]);
    if ($p === null) throw new HttpError(401, 'Token tidak valid atau kedaluwarsa');
    $row = one('SELECT * FROM users WHERE id = ?', [(int)$p['sub']]);
    if ($row === null || !$row['active']) throw new HttpError(401, 'Pengguna tidak aktif');
    if (function_exists('token_before_reset') && token_before_reset($row, $p)) throw new HttpError(401, 'Sesi berakhir karena kata sandi telah diatur ulang; silakan masuk kembali');
    return $u = $row;
}
function require_roles(string ...$roles): array
{
    $u = current_user();
    if (!has_role($u, ...$roles)) fail(403, 'Memerlukan peran: ' . implode(', ', $roles));
    return $u;
}
function parse_level(string $v): string
{
    $k = strtoupper(str_replace(' ', '_', trim($v)));
    if (!isset(LEVELS[$k])) fail(422, "Klasifikasi tidak dikenal: $v");
    return $k;
}

// ---------- router ----------
final class Router
{
    private array $routes = [];
    public function add(string $method, string $pattern, callable $fn): void { $this->routes[] = [$method, '#^' . $pattern . '$#', $fn]; }
    public function dispatch(string $method, string $path): void
    {
        $allowed = false;
        foreach ($this->routes as [$m, $re, $fn]) {
            if (preg_match($re, $path, $mm)) {
                if ($m !== $method) { $allowed = true; continue; }
                $fn(...array_slice($mm, 1));
                return;
            }
        }
        throw new HttpError($allowed ? 405 : 404, $allowed ? 'Metode tidak diizinkan' : 'Tidak ditemukan');
    }
}
