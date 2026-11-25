<?php
declare(strict_types=1);

/**
 * Data contoh Bank Aset berupa foto asli berlisensi terbuka (Wikimedia Commons).
 * Berkas dan metadatanya (pembuat, lisensi, tautan sumber) dibundel di demo-assets/ sehingga tidak perlu unduhan saat berjalan.
 * Bertahap sesuai anggaran waktu dan aman diulang (berkas yang sudah ada dilewati).
 */
function photo_manifest(): array
{
    $f = dirname(__DIR__) . '/demo-assets/manifest.json';
    return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
}

function photo_license(string $l): string
{
    if (preg_match('/^(CC0|Public domain|PD)/i', $l)) return 'domain_publik';
    if (preg_match('/BY-SA/i', $l)) return 'cc_by_sa';
    return 'cc_by';
}

function seed_photos(float $budget = 3.0): bool
{
    $man = photo_manifest();
    if (!$man) return true;
    $dir = dirname(__DIR__) . '/demo-assets/';
    $t0 = microtime(true);
    $prod = (int)(val("SELECT id FROM users WHERE username = 'produser'") ?: val('SELECT MIN(id) FROM users'));
    $issues = []; foreach (all('SELECT id, code FROM issues') as $i) $issues[$i['code']] = (int)$i['id'];
    $have = array_flip(array_column(all("SELECT filename FROM assets WHERE tags LIKE '%\"wikimedia\"%'"), 'filename'));
    $n = count($man);
    foreach ($man as $k => $p) {
        $fn = basename((string)$p['file']);
        if (isset($have[$fn])) continue;
        if (microtime(true) - $t0 > $budget) return false;
        $path = $dir . 'img/' . $fn;
        if (!is_file($path)) continue;
        $data = (string)file_get_contents($path);
        $words = array_values(array_unique(array_filter(preg_split('/[^\p{L}]+/u', mb_strtolower((string)$p['title'])), fn($w) => mb_strlen($w) > 3)));
        $desc = trim(($p['description'] ?? '') !== '' ? (string)$p['description'] : (string)$p['title']);
        insert('assets', ['kind' => 'gambar', 'title' => mb_substr((string)$p['title'], 0, 200), 'filename' => $fn, 'mime' => 'image/jpeg', 'size' => strlen($data), 'sha256' => blob_put($data),
            'tags' => jenc(array_values(array_unique(array_merge($p['tags'] ?? [], ['foto', 'wikimedia'])))), 'ai_tags' => jenc(array_slice($words, 0, 5)),
            'description' => mb_substr($desc . ' · Sumber: ' . ($p['source'] ?? 'Wikimedia Commons'), 0, 600),
            'credit' => mb_substr(($p['author'] ?? 'Tidak diketahui') . ' / Wikimedia Commons · ' . ($p['license'] ?? ''), 0, 200),
            'license' => photo_license((string)($p['license'] ?? '')), 'license_expires_at' => null,
            'issue_id' => isset($p['issue']) ? ($issues[$p['issue']] ?? null) : null, 'province_code' => $p['province'] ?? null,
            'created_by' => $prod, 'created_at' => iso_from_ts(microtime(true) - ($n - $k) * 1800)]);
    }
    return true;
}
