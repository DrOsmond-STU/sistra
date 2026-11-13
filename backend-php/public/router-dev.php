<?php
// Router untuk `php -S` lokal: /api/* → api.php, berkas statis dari SISTRA_STATIC_DIR, selain itu index.html.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/api/')) { require __DIR__ . '/api.php'; return true; }
$static = getenv('SISTRA_STATIC_DIR') ?: __DIR__ . '/../../frontend/dist';
$file = realpath($static . $path);
if ($file && str_starts_with($file, realpath($static)) && is_file($file)) {
    $types = ['js' => 'application/javascript', 'css' => 'text/css', 'html' => 'text/html', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'ico' => 'image/x-icon'];
    header('Content-Type: ' . ($types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    readfile($file); return true;
}
header('Content-Type: text/html'); readfile($static . '/index.html'); return true;
