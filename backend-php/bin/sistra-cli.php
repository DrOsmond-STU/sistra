<?php
// Alat baris perintah (pengembangan/uji): php bin/sistra-cli.php reset [--demo] [--password=SANDI]
// Menghapus basis data & blob di SISTRA_DATA_DIR lalu mengisi ulang. JANGAN dipakai di produksi.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../src/app.php';
$cmd = $argv[1] ?? '';
$opts = [];
foreach (array_slice($argv, 2) as $a) {
    if ($a === '--demo') $opts['demo'] = true;
    elseif ($a === '--bulk') $opts['bulk'] = true;
    elseif (str_starts_with($a, '--password=')) $opts['password'] = substr($a, 11);
}
if ($cmd === 'verify') { echo json_encode(audit_verify()), "\n"; exit(0); }
if ($cmd !== 'reset') { fwrite(STDERR, "Penggunaan: sistra-cli.php reset [--demo] [--password=SANDI]\n"); exit(2); }
$dir = data_dir();
foreach (['sistra.db', 'sistra.db-wal', 'sistra.db-shm', '.seeded', '.m47-v1', '.kak-v1', '.bulk-v1', '.bulk-progress', '.geo-v1', '.media-v1', '.photos-v1', '.fix-v1', 'kredensial.txt'] as $f) @unlink("$dir/$f");
if (is_dir("$dir/blobs")) exec('rm -rf ' . escapeshellarg("$dir/blobs"));
$pw = $opts['password'] ?? null;
if ($pw === null) { fwrite(STDERR, "--password wajib untuk reset uji\n"); exit(2); }
init_schema();
tx(function () use ($pw, $opts) {
    $users = seed_base(array_fill_keys(array_column(SEED_USERS, 0), $pw));
    if (isset($opts['demo'])) seed_demo($users);
});
migrate_m47();
if (isset($opts['demo'])) ai_off(fn() => tx(fn() => seed_m47()));
migrate_kak();
if (isset($opts['demo'])) ai_off(fn() => tx(fn() => seed_kak()));
migrate_bulk();
if (isset($opts['demo']) && isset($opts['bulk'])) { seed_bulk(1e9); touch("$dir/.bulk-v1"); }
elseif (isset($opts['demo'])) touch("$dir/.bulk-v1"); // uji cepat: tanpa data massal kecuali --bulk
if (isset($opts['demo'])) { tx(fn() => geo_fix_demo()); touch("$dir/.geo-v1"); }
if (isset($opts['demo']) && isset($opts['bulk'])) { seed_media(1e9); tx(fn() => seed_photos(1e9)); }
if (isset($opts['demo'])) touch("$dir/.photos-v1");
if (isset($opts['demo'])) touch("$dir/.media-v1");
if (isset($opts['demo'])) { tx(fn() => fix_demo_data()); touch("$dir/.fix-v1"); }
touch("$dir/.seeded");
echo "ok\n";
