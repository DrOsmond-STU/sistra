<?php
/** Titik masuk aplikasi: dipanggil oleh front controller api.php di root dokumen. */
declare(strict_types=1);

date_default_timezone_set('UTC');
ini_set('display_errors', '0');
mb_internal_encoding('UTF-8');

if (is_file(__DIR__ . '/../vendor/autoload.php')) require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/text.php';
require __DIR__ . '/services.php';
require __DIR__ . '/routes.php';
require __DIR__ . '/seed.php';
require __DIR__ . '/ai_install.php';
require __DIR__ . '/ai.php';
require __DIR__ . '/m47.php';
require __DIR__ . '/seed_m47.php';
require __DIR__ . '/ai_kak.php';
require __DIR__ . '/kak.php';
require __DIR__ . '/kak_routes.php';
require __DIR__ . '/kak_routes_m6.php';
require __DIR__ . '/kak_routes_m78.php';
require __DIR__ . '/seed_kak.php';
require __DIR__ . '/crud.php';
require __DIR__ . '/seed_bulk.php';
require __DIR__ . '/geo.php';
require __DIR__ . '/pw_reset.php';
require __DIR__ . '/seed_media.php';

/** Migrasi M4–M7 sekali per basis data (penanda di data_dir). Data contoh hanya ditanam pada basis data demo. */
function ensure_m47(): void
{
    $mark = data_dir() . '/.m47-v1';
    if (is_file($mark)) return;
    $lock = fopen(data_dir() . '/.seed.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        if (is_file($mark)) return;
        migrate_m47();
        if (getenv('SISTRA_SEED_DEMO') !== '0' && (int)val('SELECT COUNT(*) FROM sources') > 0) ai_off(fn() => tx(fn() => seed_m47()));
        touch($mark);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

/** Migrasi katalog KAK S26–S65 (penanda .kak-v1). */
function ensure_kak(): void
{
    $mark = data_dir() . '/.kak-v1';
    if (is_file($mark)) return;
    $lock = fopen(data_dir() . '/.seed.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        if (is_file($mark)) return;
        migrate_kak();
        if (getenv('SISTRA_SEED_DEMO') !== '0' && (int)val('SELECT COUNT(*) FROM social_accounts') > 0) ai_off(fn() => tx(fn() => seed_kak()));
        touch($mark);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

/** Perbaikan data demo untuk peta sebaran (penanda .geo-v1). */
function ensure_geo(): void
{
    $mark = data_dir() . '/.geo-v1';
    if (is_file($mark) || !is_file(data_dir() . '/.bulk-v1')) return;
    $lock = fopen(data_dir() . '/.seed.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        if (is_file($mark)) return;
        tx(fn() => geo_fix_demo());
        touch($mark);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

/** Data contoh Studio Multimedia: 3 jenis keluaran untuk setiap naskah disetujui/tayang (penanda .media-v1). */
function ensure_media(): void
{
    $mark = data_dir() . '/.media-v1';
    if (is_file($mark) || !is_file(data_dir() . '/.bulk-v1') || getenv('SISTRA_SEED_DEMO') === '0') return;
    $lock = fopen(data_dir() . '/.bulk.lock', 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return; }
    try {
        if (is_file($mark)) return;
        if ((int)val('SELECT COUNT(*) FROM social_accounts') === 0) { touch($mark); return; } // bukan basis data demo
        if (seed_media(3.0)) touch($mark);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

/** Data demo massal (≥ 500 per fitur) ditanam bertahap ±3 dtk per permintaan baca sampai selesai (penanda .bulk-v1). */
function ensure_bulk(): void
{
    $mark = data_dir() . '/.bulk-v1';
    if (is_file($mark) || getenv('SISTRA_SEED_DEMO') === '0') return;
    $lock = fopen(data_dir() . '/.bulk.lock', 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return; } // permintaan lain sedang menanam
    try {
        if (is_file($mark)) return;
        if ((int)val('SELECT COUNT(*) FROM social_accounts') === 0) { touch($mark); return; } // bukan basis data demo
        if (seed_bulk(3.0)) touch($mark);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function ini_bytes(string $v): int
{
    $v = trim($v); $n = (int)$v;
    switch (strtolower(substr($v, -1))) { case 'g': $n *= 1024; case 'm': $n *= 1024; case 'k': $n *= 1024; }
    return $n;
}

function sistra_handle(): void
{
    header('Cache-Control: no-store');
    header('X-Accel-Expires: 0');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $path = rtrim($path, '/') ?: '/';
    $GLOBALS['SISTRA_COMMIT_ON_ERROR'] = false;
    $pdo = null;
    try {
        if (!is_file(data_dir() . '/.seeded')) {
            auto_seed(getenv('SISTRA_SEED_DEMO') !== '0');
            touch(data_dir() . '/.seeded');
        }
        ensure_password_reset(); // ringan; harus berlaku sebelum permintaan masuk diproses
        ensure_m47();
        ensure_kak();
        // Penanaman data demo massal hanya pada permintaan baca di luar proses masuk, dengan anggaran waktu kecil,
        // agar tidak pernah menahan login.
        if ($method === 'GET' && !str_starts_with($path, '/api/auth/')) { ensure_bulk(); ensure_media(); }
        ensure_geo();
        $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len > ini_bytes((string)ini_get('post_max_size'))) fail(413, 'Permintaan melebihi batas ukuran unggah server');
        $router = new Router();
        register_routes($router);
        register_routes_m47($router);
        register_routes_kak($router);
        register_routes_crud($router);
        register_routes_geo($router);
        $pdo = db();
        $writes = $method !== 'GET' || preg_match('#^/api/(documents/\d+(/versions/\d+/download)?|audit/verify|reports(/\d+/docx)?|m7/queue|m7/channels/[A-Z0-9-]+|public/v1/.*)$#', $path);
        if ($writes) tx_begin();
        ob_start();
        $router->dispatch($method, $path);
        tx_end(true);
        ob_end_flush();
    } catch (HttpError $e) {
        if (ob_get_level()) ob_end_clean();
        if ($pdo) tx_end((bool)$GLOBALS['SISTRA_COMMIT_ON_ERROR']);
        header_remove('Content-Disposition');
        if ($e->status === 401) header('WWW-Authenticate: Bearer');
        send_json(['detail' => $e->detail], $e->status);
    } catch (Throwable $e) {
        if (ob_get_level()) ob_end_clean();
        if ($pdo) tx_end(false);
        error_log('SISTRA: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        // salinan ringkas di folder data (log hosting bisa berada di tempat lain); dibatasi ±256 KB
        $log = data_dir() . '/error.log';
        if (!is_file($log) || filesize($log) < 262144)
            @file_put_contents($log, gmdate('c') . " $method $path " . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 300) . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n", FILE_APPEND);
        send_json(['detail' => 'Kesalahan internal server'], 500);
    }
}
