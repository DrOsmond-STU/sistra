<?php
declare(strict_types=1);

/**
 * Data contoh Studio Multimedia: setiap naskah berstatus disetujui/tayang memiliki ketiga jenis keluaran
 * (infografis, video pendek, naskah audio). Selalu mode simulasi (tanpa panggilan mesin SISTRA AGENT),
 * bertahap sesuai anggaran waktu, dan aman diulang (hanya mengisi yang belum ada).
 */
/** Angka contoh per isu untuk infografis data contoh (sintetis, ditandai "contoh"). */
const MEDIA_DEMO_STATS = [
    'EKO-PANGAN' => [['1.200', 'titik operasi pasar beras SPHP (contoh)'], ['Rp12.500', 'HET beras medium per kg (contoh)'], ['4 bln', 'stok beras pemerintah aman (contoh)']],
    'GIZI-MBG' => [['100%', 'dapur wajib lulus uji higiene (contoh)'], ['1 pekan', 'menu diumumkan di kanal resmi (contoh)'], ['24 jam', 'layanan aduan menu sekolah (contoh)']],
    'BEN-ALAM' => [['Des–Feb', 'puncak musim hujan (contoh)'], ['1 posko', 'pengungsian per kecamatan rawan (contoh)'], ['3 jam', 'pembaruan peringatan dini cuaca (contoh)']],
    'DIG-JUDOL' => [['5.000+', 'situs judi daring diblokir per pekan (contoh)'], ['1.300', 'rekening penampung dibekukan (contoh)'], ['1 kanal', 'aduan konten resmi (contoh)']],
    'EKO-KERJA' => [['38', 'provinsi menetapkan UMP 2027 (contoh)'], ['6 bln', 'manfaat jaminan kehilangan pekerjaan (contoh)'], ['12.000+', 'lowongan aktif di bursa kerja daring (contoh)']],
    'KES-PUBLIK' => [['3M Plus', 'sepekan sekali di rumah (contoh)'], ['Gratis', 'cek demam di puskesmas (contoh)'], ['2 hari', 'demam tinggi? segera periksa (contoh)']],
    'DIS-HOAKS' => [['0 rupiah', 'pendaftaran bansos tidak dipungut biaya (contoh)'], ['1 situs', 'cek status penerima resmi (contoh)'], ['OTP', 'kode rahasia, jangan dibagikan (contoh)']],
    'TRA-NATARU' => [['2.400', 'pos pelayanan mudik Nataru (contoh)'], ['24 jam', 'layanan informasi lalu lintas (contoh)'], ['10%', 'diskon tarif tol periode tertentu (contoh)']],
    'DIG-PDP' => [['NIK', 'jangan unggah foto KTP ke media sosial (contoh)'], ['3x24 jam', 'batas pemberitahuan kebocoran data (contoh)'], ['1 kanal', 'aduan pelindungan data pribadi (contoh)']],
];

/** Infografis contoh tanpa fakta bersumber diberi angka & poin contoh per isu (hanya untuk data demo). */
function media_demo_spec(array $d, array $spec): array
{
    if (($spec['stats'][0]['value'] ?? '') !== '[DATA]') return $spec;
    $code = (string)val('SELECT code FROM issues WHERE id = ?', [$d['issue_id']]);
    $stats = MEDIA_DEMO_STATS[$code] ?? [['3', 'langkah cek informasi resmi (contoh)'], ['24 jam', 'layanan aduan warga (contoh)'], ['38', 'kanal provinsi resmi (contoh)']];
    $spec['stats'] = array_map(fn($x) => ['value' => $x[0], 'label' => $x[1]], $stats);
    if (defined('M47_FACTS') && isset(M47_FACTS[$code])) $spec['points'] = array_slice(M47_FACTS[$code], 0, 3);
    $spec['source'] = 'Data contoh SISTRA (sintetis) · kanal resmi kementerian/lembaga terkait';
    return $spec;
}

function seed_media(float $budget = 3.0): bool
{
    return ai_off(function () use ($budget): bool {
        $t0 = microtime(true);
        $before = (int)val('SELECT COALESCE(MAX(id), 0) FROM ai_logs');
        $prod = (int)(val("SELECT id FROM users WHERE username = 'produser'") ?: val('SELECT MIN(id) FROM users'));
        $tplInf = array_column(all("SELECT code FROM visual_templates WHERE kind = 'infografis' AND is_current = 1 ORDER BY code"), 'code') ?: ['INF-STD'];
        $tplVid = val("SELECT code FROM visual_templates WHERE kind = 'video' AND is_current = 1 ORDER BY code LIMIT 1") ?: null;
        // infografis contoh lama (belum pernah disunting) yang masih berisi penanda [DATA] diberi angka contoh per isu
        $old = all("SELECT m.id, m.spec, d.issue_id FROM media_outputs m JOIN drafts d ON d.id = m.draft_id
                    WHERE m.kind = 'infografis' AND m.ai_mode = 'simulasi' AND m.created_by = ? AND m.updated_at = m.created_at AND m.spec LIKE '%[DATA]%'", [$prod]);
        if ($old) tx(function () use ($old) {
            foreach ($old as $m) q('UPDATE media_outputs SET spec = ? WHERE id = ?', [jenc(media_demo_spec(['issue_id' => $m['issue_id']], jdec($m['spec'], []))), $m['id']]);
        });
        $rows = all("SELECT d.* FROM drafts d WHERE d.status IN ('disetujui','tayang')
                     AND (SELECT COUNT(DISTINCT m.kind) FROM media_outputs m WHERE m.draft_id = d.id AND m.kind IN ('infografis','video','podcast')) < 3
                     ORDER BY d.id");
        $done = true;
        foreach ($rows as $i => $d) {
            if (microtime(true) - $t0 > $budget) { $done = false; break; }
            $have = array_column(all('SELECT DISTINCT kind FROM media_outputs WHERE draft_id = ?', [$d['id']]), 'kind');
            $base = (float)ts_from_iso($d['published_at'] ?: ($d['approved_at'] ?: $d['updated_at']));
            tx(function () use ($d, $have, $i, $base, $prod, $tplInf, $tplVid) {
                foreach (['infografis', 'video', 'podcast'] as $k => $kind) {
                    if (in_array($kind, $have, true)) continue;
                    if ($kind === 'infografis') { $res = ai_infographic(null, $d, draft_facts($d)); $res['spec'] = media_demo_spec($d, $res['spec']); $tpl = $tplInf[($d['id'] + $k) % count($tplInf)]; }
                    elseif ($kind === 'video') { $res = ai_video(null, $d, $d['id'] % 3 ? '9:16' : '16:9', [30, 45, 60][$d['id'] % 3]); $tpl = $tplVid; }
                    else { $res = ai_podcast(null, $d, [1, 2, 3][$d['id'] % 3]); $tpl = null; }
                    $at = iso_from_ts(min(microtime(true) - 60, $base + 600 + ($k + 1) * mt_rand(300, 5400)));
                    $mid = insert('media_outputs', ['draft_id' => $d['id'], 'kind' => $kind, 'title' => $d['title'], 'spec' => jenc($res['spec']), 'template_code' => $tpl,
                        'ai_mode' => 'simulasi', 'ms' => mt_rand(600, 9000), 'created_by' => $prod, 'created_at' => $at, 'updated_at' => $at]);
                    // sepertiga infografis contoh sudah diekspor ke bank aset (berkas SVG)
                    if ($kind === 'infografis' && $d['id'] % 3 === 0) {
                        $svg = seed_svg(mb_substr((string)$d['title'], 0, 90), 'Infografis · ' . ($tpl ?? 'INF-STD'), '#10243F', '#F2B705');
                        $aid = insert('assets', ['kind' => 'gambar', 'title' => mb_substr('Infografis: ' . $d['title'], 0, 200), 'filename' => "infografis-{$d['id']}.svg", 'mime' => 'image/svg+xml',
                            'size' => strlen($svg), 'sha256' => blob_put($svg), 'tags' => jenc(['infografis', 'studio', 'contoh']), 'ai_tags' => jenc(['infografis']),
                            'description' => 'Hasil ekspor Studio Multimedia (contoh sintetis).', 'credit' => 'Tim produksi (dibantu SISTRA AGENT)', 'license' => 'milik_sendiri',
                            'license_expires_at' => null, 'issue_id' => $d['issue_id'], 'province_code' => $d['province_code'], 'created_by' => $prod, 'created_at' => $at]);
                        q('UPDATE media_outputs SET asset_id = ? WHERE id = ?', [$aid, $mid]);
                    }
                }
            });
        }
        q('DELETE FROM ai_logs WHERE id > ?', [$before]); // pengisian data contoh bukan pemakaian mesin sungguhan
        return $done;
    });
}
