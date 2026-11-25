<?php
// Jalankan reset kata sandi (pemicu RESET-KATA-SANDI) dari baris perintah / cron cPanel, tanpa lewat web.
// Contoh cron: /opt/cpanel/ea-php83/root/usr/bin/php ~/sistra-api/bin/reset-kata-sandi.php >/dev/null 2>&1
// Hasil ringkas (tanpa kata sandi) ditulis ke sistra-data/reset-status.txt.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$home = dirname(__DIR__, 2);
if (!defined('SISTRA_DATA_DIR')) define('SISTRA_DATA_DIR', getenv('SISTRA_DATA_DIR') ?: $home . '/sistra-data');
// root dokumen dipakai untuk nama domain pengirim email
$_SERVER['DOCUMENT_ROOT'] = getenv('SISTRA_DOCROOT') ?: ($home . '/sistra.semestateknologiutama.com');
require dirname(__DIR__) . '/src/app.php';
$r = ensure_password_reset();
if (!$r['reset']) exit(0);
$line = gmdate('Y-m-d H:i:s') . " UTC: kata sandi diatur ulang untuk {$r['accounts']} akun; email "
    . ($r['email'] === null ? 'tidak diminta' : ($r['email'] ? 'diterima server email hosting' : 'GAGAL dikirim')) . "\n";
file_put_contents(SISTRA_DATA_DIR . '/reset-status.txt', $line, FILE_APPEND);
