<?php
/** Titik masuk aplikasi: dipanggil oleh front controller api.php di root dokumen. */
declare(strict_types=1);

date_default_timezone_set('UTC');
ini_set('display_errors', '0');
mb_internal_encoding('UTF-8');

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/text.php';
require __DIR__ . '/services.php';
require __DIR__ . '/routes.php';
require __DIR__ . '/seed.php';

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
        $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len > ini_bytes((string)ini_get('post_max_size'))) fail(413, 'Permintaan melebihi batas ukuran unggah server');
        $router = new Router();
        register_routes($router);
        $pdo = db();
        $writes = $method !== 'GET' || preg_match('#^/api/(documents/\d+(/versions/\d+/download)?|audit/verify)$#', $path);
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
        send_json(['detail' => 'Kesalahan internal server'], 500);
    }
}
