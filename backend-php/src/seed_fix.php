<?php
declare(strict_types=1);

/**
 * Perbaikan kelengkapan & konsistensi data contoh (penanda .fix-v1). Aman diulang dan hanya menyentuh baris yang
 * memang tidak konsisten/kosong; data yang disunting pengguna (judul bebas, naskah hasil varian, dsb.) tidak diubah.
 *  1. Judul varian brief kembali ke topik isu brief-nya.
 *  2. Naskah tertaut ke brief satu isu secara merata; status brief mengikuti isinya (baru → dihasilkan → dipakai).
 *  3. Alamat situs kanal, deskripsi isu, tagar, catatan kolaborasi & slot kampanye terisi.
 *  4. Log panggilan API untuk klien yang tercatat pernah memakai API.
 *  5. Aset terkait naskah (infografis ekspor + foto sewilayah/seisu), foto dokumentasi liputan, dan lampiran paket pers.
 */
const FIX_ISSUE_DESC = [
    'EKO-PANGAN' => 'Pemantauan harga bahan pokok, stok, dan inflasi pangan; meluruskan kabar kelangkaan serta menginformasikan operasi pasar resmi.',
    'POL-PEMILU' => 'Informasi tahapan pemilu/pilkada dari penyelenggara resmi, netralitas, dan klarifikasi kabar bohong seputar penyelenggaraan.',
    'SOS-SARA' => 'Pemantauan narasi yang menyulut perpecahan suku, agama, ras, dan antargolongan; mendorong pesan kerukunan dan rujukan kanal resmi.',
    'BEN-ALAM' => 'Peringatan dini banjir dan cuaca ekstrem, lokasi posko, serta langkah keselamatan warga dari BMKG dan BNPB.',
    'DIS-HOAKS' => 'Klarifikasi hoaks bansos, penipuan digital, dan tautan palsu; panduan mengecek status penerima lewat kanal resmi.',
    'KES-PUBLIK' => 'Informasi pencegahan DBD dan penyakit musim hujan, layanan puskesmas, dan gejala yang perlu diwaspadai.',
    'LIN-AGRARIA' => 'Informasi pengelolaan lingkungan, kebakaran lahan, dan sengketa agraria dari kementerian terkait beserta jalur pengaduan resmi.',
    'EKO-KERJA' => 'Penetapan UMP 2027, jaminan kehilangan pekerjaan, dan lowongan resmi; meluruskan kabar PHK dan upah yang beredar.',
];
const FIX_TAG_FORMATS = ['caption', 'thread', 'carousel', 'video', 'podcast', 'infografis'];

/** Kode isu yang memuat judul ini (tanpa awalan "Pembaruan: " dsb. dan akhiran " — Provinsi"). */
function fix_title_issues(string $title): array
{
    $pre = implode('|', array_map(fn($p) => preg_quote(rtrim($p, ': '), '/'), array_filter(BULK_PREFIX)));
    $base = explode(' — ', (string)preg_replace('/^(?:' . $pre . '): /u', '', $title))[0];
    $out = [];
    foreach (M47_TITLES as $code => $list) if (in_array($base, $list, true)) $out[] = $code;
    return $out;
}

function fix_demo_data(): void
{
    // 1. judul varian sesuai isu brief ---------------------------------------------------------------------------------------
    $vars = all('SELECT v.id, v.brief_id, v.title, v.draft_id, b.region_code, i.code FROM brief_variants v JOIN briefs b ON b.id = v.brief_id JOIN issues i ON i.id = b.issue_id ORDER BY v.brief_id, v.id');
    $used = [];
    $wrong = [];
    foreach ($vars as $v) {
        $codes = fix_title_issues((string)$v['title']);
        if ($codes && !in_array($v['code'], $codes, true)) $wrong[] = $v;
        else $used[$v['brief_id']][] = explode(' — ', (string)$v['title'])[0];
    }
    foreach ($wrong as $v) {
        if ($v['draft_id']) { // varian yang sudah jadi naskah: judulnya mengikuti naskah
            q('UPDATE brief_variants SET title = (SELECT title FROM drafts WHERE id = ?) WHERE id = ?', [$v['draft_id'], $v['id']]);
            continue;
        }
        $list = M47_TITLES[$v['code']] ?? null;
        if (!$list) continue;
        $title = null;
        for ($k = 0; $k < count($list) * count(BULK_PREFIX); $k++) {
            $pre = BULK_PREFIX[(intdiv((int)$v['id'], 7) + intdiv($k, count($list))) % count(BULK_PREFIX)];
            $t = $pre . $list[((int)$v['id'] + $k) % count($list)];
            if (!in_array($t, $used[$v['brief_id']] ?? [], true)) { $title = $t; break; }
        }
        $title ??= $list[0];
        $used[$v['brief_id']][] = $title;
        q('UPDATE brief_variants SET title = ? WHERE id = ?', [$v['region_code'] && isset(PROVINCES[$v['region_code']]) ? "$title — " . PROVINCES[$v['region_code']][0] : $title, $v['id']]);
    }

    // 2. naskah ↔ brief (satu isu, merata) dan status brief ------------------------------------------------------------------
    $byIssue = [];
    foreach (all('SELECT id, issue_id, title FROM briefs ORDER BY id') as $b) $byIssue[$b['issue_id']][] = $b;
    $bt = array_column(all('SELECT id, title FROM briefs'), 'title', 'id');
    $fromVariant = array_column(all('SELECT DISTINCT draft_id FROM brief_variants WHERE draft_id IS NOT NULL'), 'draft_id', 'draft_id');
    $turn = [];
    foreach (all('SELECT id, issue_id, title, brief_id FROM drafts ORDER BY id') as $d) {
        if (isset($fromVariant[$d['id']]) || empty($byIssue[$d['issue_id']])) continue; // tautan asli dari varian tidak diubah
        if ($d['brief_id'] && ($bt[$d['brief_id']] ?? null) === $d['title']) continue;
        $list = $byIssue[$d['issue_id']];
        $pick = $list[($turn[$d['issue_id']] = ($turn[$d['issue_id']] ?? -1) + 1) % count($list)];
        if ((int)$pick['id'] !== (int)$d['brief_id']) q('UPDATE drafts SET brief_id = ? WHERE id = ?', [$pick['id'], $d['id']]);
    }
    q("UPDATE briefs SET status = 'dipakai' WHERE status <> 'dipakai' AND (EXISTS (SELECT 1 FROM drafts d WHERE d.brief_id = briefs.id) OR EXISTS (SELECT 1 FROM brief_variants v WHERE v.brief_id = briefs.id AND v.draft_id IS NOT NULL))");
    q("UPDATE briefs SET status = 'dihasilkan' WHERE status = 'dipakai' AND NOT EXISTS (SELECT 1 FROM drafts d WHERE d.brief_id = briefs.id)
        AND NOT EXISTS (SELECT 1 FROM brief_variants v WHERE v.brief_id = briefs.id AND v.draft_id IS NOT NULL) AND EXISTS (SELECT 1 FROM brief_variants v WHERE v.brief_id = briefs.id)");
    q("UPDATE briefs SET status = 'baru' WHERE status IN ('dipakai', 'dihasilkan') AND NOT EXISTS (SELECT 1 FROM drafts d WHERE d.brief_id = briefs.id)
        AND NOT EXISTS (SELECT 1 FROM brief_variants v WHERE v.brief_id = briefs.id)");
    q("UPDATE briefs SET status = 'dihasilkan' WHERE status = 'baru' AND EXISTS (SELECT 1 FROM brief_variants v WHERE v.brief_id = briefs.id)");

    // 3. kelengkapan isian ---------------------------------------------------------------------------------------------------
    foreach (all("SELECT id, code FROM channels WHERE url IS NULL OR url = ''") as $c) q('UPDATE channels SET url = ? WHERE id = ?', ['https://contoh.invalid/' . strtolower($c['code']), $c['id']]);
    foreach (FIX_ISSUE_DESC as $code => $desc) q("UPDATE issues SET description = ? WHERE code = ? AND (description IS NULL OR description = '')", [$desc, $code]);
    $tag = fn(string $s) => '#' . preg_replace('/[^A-Za-z0-9]/', '', ucwords(strtolower($s)));
    $ph = implode(',', array_fill(0, count(FIX_TAG_FORMATS), '?'));
    foreach (all("SELECT d.id, d.province_code, i.name iname FROM drafts d JOIN issues i ON i.id = d.issue_id WHERE (d.hashtags IS NULL OR d.hashtags IN ('', '[]')) AND d.format IN ($ph)", FIX_TAG_FORMATS) as $d) {
        $tags = ['#InfoResmi', $tag((string)$d['iname'])];
        if ($d['province_code'] && isset(PROVINCES[$d['province_code']])) $tags[] = $tag(PROVINCES[$d['province_code']][0]);
        q('UPDATE drafts SET hashtags = ? WHERE id = ?', [jenc($tags), $d['id']]);
    }
    q("UPDATE partner_collabs SET notes = CASE WHEN disclosure_ok = 1 THEN 'Tayang sesuai brief kampanye; label kemitraan terpasang (contoh).' ELSE 'Label kemitraan belum terpasang; minta mitra memperbaiki unggahan (contoh).' END WHERE notes IS NULL OR notes = ''");
    q("UPDATE campaign_slots SET note = CASE WHEN account_id IS NOT NULL THEN 'Unggahan akun resmi sesuai rencana kampanye (contoh).' ELSE 'Unggahan mitra berlabel kemitraan' END WHERE note IS NULL OR note = ''");

    // 4. log panggilan API ---------------------------------------------------------------------------------------------------
    foreach (all('SELECT c.id, c.active, c.last_used_at FROM api_clients c WHERE c.last_used_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM api_calls a WHERE a.client_id = c.id)') as $c) {
        $last = ts_from_iso((string)$c['last_used_at']);
        $n = 3 + $c['id'] % 6;
        for ($k = 0; $k < $n; $k++) {
            $first = $k === 0;
            $st = $first && !$c['active'] ? 401 : (($c['id'] + $k) % 17 === 0 ? 404 : (($c['id'] + $k) % 23 === 0 ? 429 : 200));
            insert('api_calls', ['client_id' => $c['id'], 'status' => $st, 'ip' => '203.0.113.' . (($c['id'] * 7) % 250 + 1),
                'path' => ($c['id'] + $k) % 3 ? '/api/public/v1/publications' : '/api/public/v1/publications/' . (($c['id'] + $k) % 60 + 1),
                'at' => iso_from_ts($last - $k * (3600 * (6 + ($c['id'] % 11)) + ($k * 977 + $c['id'] * 31) % 1800))]);
        }
    }

    // 5. aset terkait naskah, liputan, dan paket pers ------------------------------------------------------------------------
    $photos = all("SELECT id, province_code, issue_id FROM assets WHERE mime = 'image/jpeg' AND (license_expires_at IS NULL OR license_expires_at > ?) ORDER BY id", [now_iso()]);
    $byProv = $byIss = [];
    foreach ($photos as $p) { if ($p['province_code']) $byProv[$p['province_code']][] = $p['id']; if ($p['issue_id']) $byIss[$p['issue_id']][] = $p['id']; }
    foreach (all("SELECT id, province_code, issue_id FROM drafts WHERE status IN ('disetujui', 'tayang') ORDER BY id") as $d) {
        foreach (all('SELECT DISTINCT asset_id FROM media_outputs WHERE draft_id = ? AND asset_id IS NOT NULL', [$d['id']]) as $m)
            q('INSERT OR IGNORE INTO draft_assets (draft_id, asset_id) VALUES (?, ?)', [$d['id'], $m['asset_id']]);
        $pool = ($d['province_code'] ? ($byProv[$d['province_code']] ?? []) : []) ?: ($byIss[$d['issue_id']] ?? []);
        if ($pool && (int)val('SELECT COUNT(*) FROM draft_assets WHERE draft_id = ?', [$d['id']]) < 2)
            q('INSERT OR IGNORE INTO draft_assets (draft_id, asset_id) VALUES (?, ?)', [$d['id'], $pool[$d['id'] % count($pool)]]);
    }
    foreach (all("SELECT id, province_code FROM assets WHERE mime = 'image/jpeg' AND province_code IS NOT NULL AND coverage_id IS NULL ORDER BY id") as $p) {
        $cv = val("SELECT id FROM coverage c WHERE c.province_code = ? AND c.status = 'selesai' AND NOT EXISTS (SELECT 1 FROM assets a WHERE a.coverage_id = c.id) ORDER BY c.id LIMIT 1", [$p['province_code']]);
        if ($cv) q('UPDATE assets SET coverage_id = ? WHERE id = ?', [$cv, $p['id']]);
    }
    foreach (all("SELECT id, draft_id FROM press_packs WHERE asset_ids IS NULL OR asset_ids IN ('', '[]')") as $pk) {
        $ids = array_map('intval', array_column(all('SELECT asset_id FROM draft_assets WHERE draft_id = ? ORDER BY asset_id LIMIT 3', [$pk['draft_id']]), 'asset_id'));
        if ($ids) q('UPDATE press_packs SET asset_ids = ? WHERE id = ?', [jenc($ids), $pk['id']]);
    }
}
