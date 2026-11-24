<?php
// Pemasang pustaka Claude (Anthropic PHP SDK + dependensinya) langsung di server.
//
// Hosting cPanel tidak menyediakan shell/composer. Admin menekan "Pasang pustaka" di
// Pengaturan AI; server mengunduh setiap paket dari GitHub pada commit yang DIKUNCI di
// composer.lock, mengekstraknya ke ~/sistra-api/vendor/, lalu memasang berkas autoload
// Composer. Berkas runtime Composer diverifikasi SHA-256. Autoload dipasang paling akhir
// sehingga aplikasi tidak pernah memuat vendor yang setengah jadi. Sampai selesai, AI
// berjalan dalam mode simulasi.
declare(strict_types=1);

// Dibangkitkan dari composer.lock (nama paket => [repo GitHub, commit]).
const AI_LIB_PACKAGES = [
    'anthropic-ai/sdk' => ['anthropics/anthropic-sdk-php', '6872fb45974d78d06946e2c6aaa6a4a36cd2fb2e'],
    'guzzlehttp/guzzle' => ['guzzle/guzzle', 'ba6574fd975ab16f87dc356729a36777c03851e8'],
    'guzzlehttp/promises' => ['guzzle/promises', 'a201cc78db93065f891278f86eaeaa1c97c5a158'],
    'guzzlehttp/psr7' => ['guzzle/psr7', '1fa0f8319c046b7dec80030460254283d851388e'],
    'open-telemetry/api' => ['opentelemetry-php/api', 'b0884741f3f9d5dc60477784c8671bd2acef4ceb'],
    'open-telemetry/context' => ['opentelemetry-php/context', '711e7291d9cf8a984c059c5afd926e45ab444b7f'],
    'php-http/discovery' => ['php-http/discovery', '82fe4c73ef3363caed49ff8dd1539ba06044910d'],
    'psr/http-client' => ['php-fig/http-client', 'bb5906edc1c324c9a05aa0873d40117941e5fa90'],
    'psr/http-factory' => ['php-fig/http-factory', '2b4765fddfe3b508ac62f829e852b1501d3f6e8a'],
    'psr/http-message' => ['php-fig/http-message', '402d35bcb92c70c026d1a6a9883f06b2ead23d71'],
    'psr/log' => ['php-fig/log', 'f16e1d5863e37f8d8c2a01719f5b34baa2b714d3'],
    'standard-webhooks/standard-webhooks' => ['standard-webhooks/standard-webhooks', 'daee8284cfe9d4e435ec16e3e68a8f30084d6aaf'],
    'symfony/deprecation-contracts' => ['symfony/deprecation-contracts', 'feb1edf4e546af3b85c5caebe8359a20db230ca3'],
    'symfony/polyfill-php80' => ['symfony/polyfill-php80', '9c6a5d6b01ca51d486c813e9a9c0ed55f208bb74'],
    'symfony/polyfill-php82' => ['symfony/polyfill-php82', '843bbe8b2a7934307b749751eede0f111f5d1cfc'],
];
// Berkas runtime Composer 2.8.12 (identik dengan yang dihasilkan `composer install`).
const AI_LIB_COMPOSER_TAG = '2.8.12';
const AI_LIB_COMPOSER_FILES = [
    'ClassLoader.php' => ['src/Composer/Autoload/ClassLoader.php', 'd4b2313e562edf942838ec316d983c1a3459ca67da3e5658e861200b0b3693c2'],
    'InstalledVersions.php' => ['src/Composer/InstalledVersions.php', '1e4525198301d1ed1f87ac3df2a8555a6f139f8df30a703d0b775a61963c96e4'],
];
// Folder yang tidak dibutuhkan saat berjalan.
const AI_LIB_SKIP = ['tests/', 'test/', 'Tests/', 'examples/', 'fixtures/', 'docs/', '.github/', 'scripts/', 'vendor-bin/'];

/** Cermin opsional (server tanpa akses GitHub / uji lokal): {mirror}/{repo}/zip/{sha} dan {mirror}/composer/{tag}/{path}. */
function ai_lib_mirror(): ?string { $m = getenv('SISTRA_LIB_MIRROR'); return $m ? rtrim($m, '/') : null; }
function ai_vendor_dir(): string { return dirname(__DIR__) . '/vendor'; }
function ai_meta_dir(): string { return dirname(__DIR__) . '/vendor-meta'; }

/** Pustaka siap bila autoload terpasang dan kelas klien dapat dimuat. */
function ai_lib_ready(): bool { return class_exists('Anthropic\\Client'); }

function ai_lib_status(): array
{
    $done = 0;
    foreach (array_keys(AI_LIB_PACKAGES) as $p) if (is_file(ai_vendor_dir() . "/$p/.sistra-ok") || is_dir(ai_vendor_dir() . "/$p/src")) $done++;
    return ['ready' => ai_lib_ready(), 'packages_total' => count(AI_LIB_PACKAGES), 'packages_present' => $done,
        'meta_present' => is_file(ai_meta_dir() . '/autoload.php'), 'zip' => class_exists('ZipArchive')];
}

function ai_lib_fetch(string $url, string $dest): void
{
    if (function_exists('curl_init')) {
        $fh = fopen($dest, 'wb');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120, CURLOPT_FAILONERROR => true, CURLOPT_USERAGENT => 'sistra-installer', CURLOPT_PROTOCOLS => ai_lib_mirror() ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS]);
        $ok = curl_exec($ch); $err = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch); fclose($fh);
        if (!$ok || $code !== 200) { @unlink($dest); throw new RuntimeException("Unduh gagal ($code) $url: $err"); }
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 120, 'user_agent' => 'sistra-installer', 'follow_location' => 1]]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false || $data === '') throw new RuntimeException("Unduh gagal: $url");
        file_put_contents($dest, $data);
    }
    if (filesize($dest) < 100) { @unlink($dest); throw new RuntimeException("Berkas unduhan kosong: $url"); }
}

function ai_lib_rmrf(string $p): void
{
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (!is_dir($p)) return;
    foreach (scandir($p) ?: [] as $f) if ($f !== '.' && $f !== '..') ai_lib_rmrf("$p/$f");
    @rmdir($p);
}

/** Ekstrak arsip zip GitHub (berawalan satu folder) ke $dest, tanpa folder tes/dokumen. */
function ai_lib_extract(string $zipFile, string $dest): int
{
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) throw new RuntimeException('Arsip tidak dapat dibuka');
    $n = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        $slash = strpos($name, '/');
        if ($slash === false) continue;
        $rel = substr($name, $slash + 1);
        if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/') || str_contains($rel, "\0")) continue;
        foreach (AI_LIB_SKIP as $s) if (str_starts_with($rel, $s)) continue 2;
        $target = "$dest/$rel";
        if (str_ends_with($name, '/')) { if (!is_dir($target)) mkdir($target, 0755, true); continue; }
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
        $in = $zip->getStream($name);
        if (!$in) throw new RuntimeException("Gagal membaca $rel");
        $out = fopen($target, 'wb'); stream_copy_to_stream($in, $out); fclose($out); fclose($in);
        $n++;
    }
    $zip->close();
    return $n;
}

/** Pasang (atau lanjutkan pemasangan) pustaka. Paket yang sudah lengkap dilewati. */
function ai_lib_install(): array
{
    if (ai_lib_ready()) return ['status' => 'sudah_terpasang', 'installed' => []];
    if (!class_exists('ZipArchive')) throw new RuntimeException('Ekstensi PHP zip tidak tersedia di server');
    if (!is_file(ai_meta_dir() . '/autoload.php')) throw new RuntimeException('Berkas vendor-meta belum diunggah ke server');
    @set_time_limit(600);
    ignore_user_abort(true);
    $lock = fopen(data_dir() . '/.ai-install.lock', 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Pemasangan sedang berjalan di permintaan lain');
    try {
        $vendor = ai_vendor_dir();
        if (!is_dir($vendor)) mkdir($vendor, 0755, true);
        $tmpDir = data_dir() . '/tmp'; if (!is_dir($tmpDir)) mkdir($tmpDir, 0700, true);
        $installed = [];
        $t0 = microtime(true);
        foreach (AI_LIB_PACKAGES as $pkg => [$repo, $sha]) {
            $dir = "$vendor/$pkg";
            if (is_file("$dir/.sistra-ok")) continue;
            // batasi waktu satu permintaan; sisanya dilanjutkan pada klik berikutnya
            if (microtime(true) - $t0 > 80) return ['status' => 'lanjutkan', 'installed' => $installed] + ai_lib_status();
            $zipFile = "$tmpDir/" . str_replace('/', '_', $pkg) . '.zip';
            ai_lib_fetch((ai_lib_mirror() ?? 'https://codeload.github.com') . "/$repo/zip/$sha", $zipFile);
            $part = "$dir.part";
            ai_lib_rmrf($part); mkdir($part, 0755, true);
            $files = ai_lib_extract($zipFile, $part);
            @unlink($zipFile);
            file_put_contents("$part/.sistra-ok", "$repo@$sha\n");
            ai_lib_rmrf($dir);
            if (!is_dir(dirname($dir))) mkdir(dirname($dir), 0755, true);
            rename($part, $dir);
            $installed[] = ['package' => $pkg, 'files' => $files];
        }
        // runtime Composer, diverifikasi SHA-256
        if (!is_dir("$vendor/composer")) mkdir("$vendor/composer", 0755, true);
        foreach (AI_LIB_COMPOSER_FILES as $name => [$path, $hash]) {
            $dest = "$vendor/composer/$name";
            if (is_file($dest) && hash_file('sha256', $dest) === $hash) continue;
            ai_lib_fetch((ai_lib_mirror() ? ai_lib_mirror() . '/composer/' : 'https://raw.githubusercontent.com/composer/composer/') . AI_LIB_COMPOSER_TAG . "/$path", "$dest.part");
            if (hash_file('sha256', "$dest.part") !== $hash) { @unlink("$dest.part"); throw new RuntimeException("Checksum $name tidak cocok"); }
            rename("$dest.part", $dest);
        }
        // berkas autoload hasil `composer dump-autoload`; autoload.php paling akhir
        foreach (glob(ai_meta_dir() . '/composer/*.php') ?: [] as $f) copy($f, "$vendor/composer/" . basename($f));
        copy(ai_meta_dir() . '/autoload.php', "$vendor/autoload.php");
        require_once "$vendor/autoload.php";
        if (!ai_lib_ready()) throw new RuntimeException('Pustaka terpasang tetapi kelas Anthropic\\Client tidak dapat dimuat');
        return ['status' => 'terpasang', 'installed' => $installed] + ai_lib_status();
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
}
