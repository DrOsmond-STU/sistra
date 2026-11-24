<?php
/**
 * Fitur AI tambahan untuk katalog KAK (S29–S32, S37, S41, S48, S61).
 * Pola sama dengan ai.php: keluaran JSON terstruktur bila Claude aktif, templat deterministik bila mode simulasi.
 */
declare(strict_types=1);

function kak_fact_lines(array $facts): string
{
    if (!$facts) return '(tidak ada fakta — tandai klaim dengan [DATA PERLU DIVERIFIKASI])';
    $o = [];
    foreach (array_values($facts) as $i => $f) $o[] = '[F' . ($i + 1) . '] ' . $f['text'] . ($f['source'] ? " (sumber: {$f['source']})" : '');
    return implode("\n", $o);
}

/** Ambil angka/nilai menonjol dari daftar fakta untuk mode simulasi. */
function kak_numbers(array $facts): array
{
    // hanya angka bermakna: rupiah, persen, atau angka bersatuan/≥ 3 digit (bukan "H-2", "2027", dsb.)
    $out = [];
    foreach ($facts as $f) {
        $t = $f['text'];
        if (preg_match('/Rp\s?[\d.,]+(?:\s?(?:ribu|juta|miliar|triliun))?|\b\d[\d.,]*\s?(?:%|persen)|\b\d[\d.,]*\s(?:ribu|juta|miliar|triliun)(?:\s(?:ton|titik|keluarga|orang))?\b|\b\d[\d.,]*\s(?:ton|titik|kecamatan|situs|keluarga|orang|unit)\b|\b\d{1,3}(?:\.\d{3})+\b/u', $t, $m)) {
            $label = trim(preg_replace('/\s*\((?:data )?contoh\)/u', '', $t), " .,:;-");
            $out[] = ['value' => trim($m[0]), 'label' => mb_substr($label ?: $t, 0, 70)];
        }
    }
    return $out;
}

// ---------------- S32 generator judul ----------------
function ai_titles(?array $u, string $title, string $body, string $format): array
{
    if (!ai_live()) {
        ai_log($u, 'judul', 'simulasi', null, 0, 0, 0, true);
        $core = mb_substr(preg_replace('/\s+/', ' ', $title), 0, 70);
        return ['mode' => 'simulasi', 'titles' => [
            ['title' => $core, 'style' => 'Lugas'],
            ['title' => "Fakta Penting: $core", 'style' => 'Informatif'],
            ['title' => "Yang Perlu Warga Tahu tentang " . mb_strtolower(mb_substr($core, 0, 1)) . mb_substr($core, 1), 'style' => 'Ajakan'],
            ['title' => "$core, Ini Penjelasan Resminya", 'style' => 'Klarifikasi'],
            ['title' => "Cek Fakta: $core", 'style' => 'Cek fakta'],
        ]];
    }
    $schema = schema_obj(['titles' => ['type' => 'array', 'items' => schema_obj(['title' => STR, 'style' => STR])]]);
    $r = ai_call($u, 'judul', 'Tugas: generator judul untuk naskah komunikasi publik.', [['role' => 'user', 'content' =>
        "Buat 5 alternatif judul (gaya berbeda: lugas, informatif, ajakan, klarifikasi, cek fakta) untuk naskah format {$format}. "
        . "Maks. 90 karakter, tanpa clickbait, tanpa tanda seru berlebihan, sesuai PUEBI.\n\nJUDUL KERJA: {$title}\n\nISI:\n" . mb_substr($body, 0, 6000)]], $schema, 'low', 2000);
    return ['mode' => 'claude', 'model' => $r['model'], 'titles' => array_slice($r['json']['titles'] ?? [], 0, 5)];
}

// ---------------- S29 infografis ----------------
function ai_infographic(?array $u, array $draft, array $facts): array
{
    if (!ai_live()) {
        ai_log($u, 'infografis', 'simulasi', null, 0, 0, 0, true);
        $nums = array_slice(kak_numbers($facts), 0, 4);
        if (!$nums) $nums = [['value' => '[DATA]', 'label' => 'Angka kunci belum ada di fakta bersumber — lengkapi brief']];
        $points = array_slice(array_map(fn($f) => $f['text'], $facts), 0, 3) ?: ['Ikuti informasi resmi dari kanal pemerintah'];
        return ['mode' => 'simulasi', 'spec' => ['title' => mb_substr($draft['title'], 0, 80), 'subtitle' => 'Ringkasan data resmi', 'stats' => $nums,
            'points' => $points, 'source' => implode('; ', array_unique(array_filter(array_map(fn($f) => $f['source'], $facts)))) ?: 'Sumber resmi (perlu dilengkapi)',
            'cta' => 'Cek informasi resmi & laporkan hoaks melalui kanal pengaduan']];
    }
    $schema = schema_obj(['title' => STR, 'subtitle' => STR, 'stats' => ['type' => 'array', 'items' => schema_obj(['value' => STR, 'label' => STR])],
        'points' => ['type' => 'array', 'items' => STR], 'source' => STR, 'cta' => STR]);
    $r = ai_call($u, 'infografis', 'Tugas: menyusun isi infografis statistik dari fakta terverifikasi.', [['role' => 'user', 'content' =>
        "Susun isi infografis: judul ≤ 60 karakter, subjudul ≤ 90 karakter, 2–4 angka kunci (value singkat seperti '12,5 juta' atau '87%', label ≤ 60 karakter), "
        . "2–4 poin penjelas ≤ 100 karakter, baris sumber, dan ajakan (cta) ≤ 80 karakter. HANYA angka yang ada di daftar fakta.\n\nFAKTA:\n" . kak_fact_lines($facts)
        . "\n\nNASKAH:\n{$draft['title']}\n" . mb_substr($draft['body'], 0, 4000)]], $schema, 'low', 3000);
    return ['mode' => 'claude', 'model' => $r['model'], 'spec' => $r['json']];
}

// ---------------- S30 video pendek ----------------
function ai_video(?array $u, array $draft, string $aspect, int $seconds): array
{
    $seconds = max(15, min(60, $seconds));
    if (!ai_live()) {
        ai_log($u, 'video', 'simulasi', null, 0, 0, 0, true);
        $sent = array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+|\n+/u', $draft['body'])), fn($s) => mb_strlen($s) > 20 && !str_starts_with($s, '[')));
        $sent = array_slice($sent, 0, 5) ?: [$draft['title']];
        $n = count($sent) + 2; $dur = $seconds / $n; $t = 0.0; $scenes = [];
        $scenes[] = ['start' => 0, 'end' => round($dur, 1), 'visual' => 'Judul besar di atas latar identitas visual', 'narration' => $draft['title'], 'caption_id' => $draft['title'], 'caption_en' => '[EN] ' . $draft['title']];
        $t = $dur;
        foreach ($sent as $s) {
            $scenes[] = ['start' => round($t, 1), 'end' => round($t + $dur, 1), 'visual' => 'Teks kinetik + ikon pendukung', 'narration' => $s, 'caption_id' => mb_substr($s, 0, 90), 'caption_en' => '[EN] ' . mb_substr($s, 0, 80)];
            $t += $dur;
        }
        $scenes[] = ['start' => round($t, 1), 'end' => (float)$seconds, 'visual' => 'Penutup: logo dan kanal resmi', 'narration' => 'Cek informasi resmi hanya dari kanal pemerintah.', 'caption_id' => 'Cek informasi resmi hanya dari kanal pemerintah.', 'caption_en' => 'Check official information only from government channels.'];
        return ['mode' => 'simulasi', 'spec' => ['title' => $draft['title'], 'aspect' => $aspect, 'duration' => $seconds, 'scenes' => $scenes, 'music' => 'Instrumental netral, volume rendah']];
    }
    $scene = schema_obj(['start' => ['type' => 'number'], 'end' => ['type' => 'number'], 'visual' => STR, 'narration' => STR, 'caption_id' => STR, 'caption_en' => STR]);
    $schema = schema_obj(['title' => STR, 'scenes' => ['type' => 'array', 'items' => $scene], 'music' => STR]);
    $r = ai_call($u, 'video', 'Tugas: menyusun naskah dan papan cerita video pendek resmi.', [['role' => 'user', 'content' =>
        "Ubah naskah menjadi video {$aspect} berdurasi {$seconds} detik, 5–8 adegan berurutan tanpa celah (start/end dalam detik, adegan terakhir berakhir tepat {$seconds}). "
        . "Tiap adegan: arahan visual (tanpa wajah orang sungguhan), narasi suara, takarir Indonesia ≤ 90 karakter, dan terjemahan takarir Inggris. Jangan menambah fakta.\n\n"
        . "JUDUL: {$draft['title']}\n\nISI:\n" . mb_substr($draft['body'], 0, 6000)]], $schema, 'low', 5000);
    return ['mode' => 'claude', 'model' => $r['model'], 'spec' => ['aspect' => $aspect, 'duration' => $seconds] + $r['json']];
}

// ---------------- S31 podcast ----------------
function ai_podcast(?array $u, array $draft, int $minutes): array
{
    $minutes = max(1, min(10, $minutes));
    if (!ai_live()) {
        ai_log($u, 'podcast', 'simulasi', null, 0, 0, 0, true);
        $paras = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/u', $draft['body'])), fn($p) => $p !== '' && !str_starts_with($p, '[')));
        $seg = [['speaker' => 'Pembawa acara', 'text' => "Halo, Sahabat. Ini warta singkat hari ini: {$draft['title']}."]];
        foreach (array_slice($paras, 0, 4) as $i => $p) $seg[] = ['speaker' => $i % 2 ? 'Pembawa acara' : 'Narasumber', 'text' => $p];
        $seg[] = ['speaker' => 'Pembawa acara', 'text' => 'Sekian warta hari ini. Cek informasi resmi hanya dari kanal pemerintah. Sampai jumpa.'];
        return ['mode' => 'simulasi', 'spec' => ['title' => $draft['title'], 'minutes' => $minutes, 'segments' => $seg]];
    }
    $schema = schema_obj(['title' => STR, 'segments' => ['type' => 'array', 'items' => schema_obj(['speaker' => STR, 'text' => STR])]]);
    $r = ai_call($u, 'podcast', 'Tugas: menulis naskah warta audio/podcast ringkas.', [['role' => 'user', 'content' =>
        "Tulis naskah audio ±{$minutes} menit (±130 kata per menit) dengan dua suara: 'Pembawa acara' dan 'Narasumber' (peran generik, bukan nama orang). "
        . "Bahasa lisan yang santun dan jelas. Jangan menambah fakta.\n\nJUDUL: {$draft['title']}\n\nISI:\n" . mb_substr($draft['body'], 0, 6000)]], $schema, 'low', 5000);
    return ['mode' => 'claude', 'model' => $r['model'], 'spec' => ['minutes' => $minutes] + $r['json']];
}

// ---------------- S37 penandaan aset (visi) ----------------
function ai_tag_asset(?array $u, string $title, string $mime, ?string $data): array
{
    $isImg = in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true) && $data !== null && strlen($data) <= 4_500_000;
    if (!ai_live() || !$isImg) {
        if (ai_live() === false) ai_log($u, 'tag_aset', 'simulasi', null, 0, 0, 0, true);
        $words = array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title)), fn($w) => mb_strlen($w) > 3)));
        return ['mode' => 'simulasi', 'tags' => array_slice($words, 0, 6), 'description' => ''];
    }
    $schema = schema_obj(['tags' => ['type' => 'array', 'items' => STR], 'description' => STR]);
    $r = ai_call($u, 'tag_aset', 'Tugas: memberi tag dan deskripsi singkat untuk aset visual di bank aset redaksi.', [['role' => 'user', 'content' => [
        ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mime, 'data' => base64_encode($data)]],
        ['type' => 'text', 'text' => "Beri 5–8 tag Bahasa Indonesia (huruf kecil) dan deskripsi ≤ 160 karakter untuk aset berjudul \"{$title}\". Jangan mengidentifikasi orang berdasarkan wajah; sebut saja 'orang', 'petugas', dsb."],
    ]]], $schema, 'low', 1000);
    return ['mode' => 'claude', 'model' => $r['model'], 'tags' => array_slice(array_map(fn($t) => mb_strtolower(trim((string)$t)), $r['json']['tags'] ?? []), 0, 8), 'description' => (string)($r['json']['description'] ?? '')];
}

// ---------------- S41 paket respons cepat ----------------
function ai_response_pack(?array $u, string $issueName, string $trigger, array $facts, string $kb): array
{
    if (!ai_live()) {
        ai_log($u, 'paket_respons', 'simulasi', null, 0, 0, 0, true);
        $f = array_map(fn($x) => $x['text'], $facts);
        return ['mode' => 'simulasi', 'pack' => [
            'holding_statement' => "Pemerintah memantau perkembangan isu {$issueName} dan akan menyampaikan informasi resmi secara berkala. Warga diimbau merujuk hanya pada kanal resmi. [SIMULASI]",
            'key_messages' => array_slice(array_merge($f, ['Informasi resmi hanya dari kanal pemerintah', 'Laporkan informasi menyesatkan melalui kanal aduan resmi']), 0, 4),
            'qa' => [['q' => "Apa yang sebenarnya terjadi terkait {$issueName}?", 'a' => $f[0] ?? '[DATA PERLU DIVERIFIKASI]'],
                ['q' => 'Ke mana warga dapat bertanya atau melapor?', 'a' => 'Melalui kanal pengaduan resmi dan akun resmi pemerintah.'],
                ['q' => 'Apakah kabar yang beredar benar?', 'a' => 'Mohon merujuk pada pernyataan resmi; informasi tanpa sumber jelas jangan disebarkan.']],
            'data_points' => array_map(fn($x) => ['claim' => $x['text'], 'source' => $x['source'] ?: 'perlu sumber'], array_slice($facts, 0, 5)),
            'spokesperson' => "Tenang, empatik, fokus pada fakta dan langkah layanan. Jangan berspekulasi soal angka yang belum dirilis.",
            'donts' => ['Jangan menyebut angka yang belum diverifikasi', 'Jangan menyalahkan warga', 'Jangan menanggapi provokasi akun anonim'],
        ]];
    }
    $schema = schema_obj(['holding_statement' => STR, 'key_messages' => ['type' => 'array', 'items' => STR],
        'qa' => ['type' => 'array', 'items' => schema_obj(['q' => STR, 'a' => STR])], 'data_points' => ['type' => 'array', 'items' => schema_obj(['claim' => STR, 'source' => STR])],
        'spokesperson' => STR, 'donts' => ['type' => 'array', 'items' => STR]]);
    $r = ai_call($u, 'paket_respons', 'Tugas: menyusun paket respons cepat (rapid response) untuk juru bicara dan admin akun resmi.', [['role' => 'user', 'content' =>
        "Susun paket respons untuk isu \"{$issueName}\". Pemicu: {$trigger}.\nIsi: pernyataan sementara (holding statement) ≤ 80 kata, 3–5 pesan kunci, 4–6 tanya-jawab, "
        . "poin data berbasis fakta (claim + source, hanya dari daftar fakta), petunjuk juru bicara (nada, sikap), dan daftar larangan.\n\nFAKTA:\n" . kak_fact_lines($facts)
        . "\n\nBAHAN RESPONS YANG SUDAH ADA:\n{$kb}"]], $schema, 'low', 6000);
    return ['mode' => 'claude', 'model' => $r['model'], 'pack' => $r['json']];
}

// ---------------- S48 evaluasi kampanye & S61 laporan ----------------
function ai_narrative(?array $u, string $feature, string $task, string $data, string $fallback): array
{
    if (!ai_live()) {
        ai_log($u, $feature, 'simulasi', null, 0, 0, 0, true);
        return ['mode' => 'simulasi', 'text' => "[SIMULASI] {$fallback}"];
    }
    $r = ai_call($u, $feature, "Tugas: {$task}", [['role' => 'user', 'content' =>
        "Tulis dalam 3–5 paragraf pendek Bahasa Indonesia baku: temuan utama, apa yang berhasil, yang perlu diperbaiki, dan rekomendasi tindakan. "
        . "Gunakan HANYA angka pada data berikut.\n\nDATA:\n{$data}"]], null, 'low', 3000);
    return ['mode' => 'claude', 'model' => $r['model'], 'text' => trim($r['text'])];
}
