<?php
/**
 * Asisten AI SISTRA — integrasi Claude (Anthropic) lewat SDK resmi PHP.
 *
 * - Kunci API diisi admin dari aplikasi, disimpan di data_dir()/ai.json (0600), tidak pernah dikirim balik ke klien.
 * - Tanpa kunci, semua fitur AI tetap berjalan dalam MODE SIMULASI (keluaran templat, ditandai jelas).
 * - Setiap keluaran AI hanyalah draf/saran: keputusan, publikasi, dan balasan tetap oleh manusia.
 */
declare(strict_types=1);

const AI_MODELS = ['claude-opus-5-5' => 'Claude Opus 5.5', 'claude-sonnet-5-5' => 'Claude Sonnet 5.5', 'claude-haiku-5-5' => 'Claude Haiku 5.5'];
const AI_DEFAULT_MODEL = 'claude-opus-5-5';

const AI_SYSTEM_BASE = <<<TXT
Anda adalah asisten AI di SISTRA (Sistem Informasi dan Aksi Strategis), alat kerja tim komunikasi publik pemerintah Indonesia.
Prinsip yang wajib dipegang:
- Bahasa Indonesia baku yang mengikuti PUEBI/EYD, jelas, santun, dan mudah dipahami warga.
- Hanya memakai fakta yang diberikan pengguna. Jangan mengarang angka, kutipan, nama pejabat, atau kejadian. Bila data kurang, tulis penanda seperti [DATA PERLU DIVERIFIKASI].
- Tidak memuat ujaran kebencian, SARA, serangan pribadi, atau disinformasi.
- Komunikasi hanya lewat kanal dan akun resmi institusi. Jangan pernah menyarankan akun samaran, bot, astroturfing, penyamaran identitas, atau cara menghindari deteksi platform.
- Semua keluaran Anda adalah draf yang akan ditinjau dan disetujui manusia.
TXT;

// ---------------- konfigurasi ----------------
function ai_config_path(): string { return data_dir() . '/ai.json'; }
function ai_config(): array
{
    static $c = null;
    if ($c !== null) return $c;
    $c = ['api_key' => '', 'model' => AI_DEFAULT_MODEL, 'enabled' => true];
    if (is_file(ai_config_path())) $c = array_merge($c, (array)json_decode((string)file_get_contents(ai_config_path()), true));
    if ($c['api_key'] === '' && getenv('ANTHROPIC_API_KEY')) $c['api_key'] = (string)getenv('ANTHROPIC_API_KEY');
    if (!isset(AI_MODELS[$c['model']])) $c['model'] = AI_DEFAULT_MODEL;
    return $c;
}
function ai_save_config(array $patch): void
{
    $cur = is_file(ai_config_path()) ? (array)json_decode((string)file_get_contents(ai_config_path()), true) : [];
    $new = array_merge(['api_key' => '', 'model' => AI_DEFAULT_MODEL, 'enabled' => true], $cur, $patch);
    $tmp = ai_config_path() . '.tmp';
    file_put_contents($tmp, json_encode($new));
    chmod($tmp, 0600);
    rename($tmp, ai_config_path());
}
function ai_live(): bool { $c = ai_config(); return $c['enabled'] && $c['api_key'] !== '' && ai_lib_ready(); }
function ai_status(): array
{
    $c = ai_config();
    return ['mode' => ai_live() ? 'claude' : 'simulasi', 'configured' => $c['api_key'] !== '', 'enabled' => (bool)$c['enabled'], 'library' => ai_lib_ready(),
        'model' => $c['model'], 'model_label' => AI_MODELS[$c['model']], 'models' => array_map(fn($k, $v) => ['id' => $k, 'label' => $v], array_keys(AI_MODELS), AI_MODELS)];
}

function ai_log(?array $u, string $feature, string $mode, ?string $model, int $in, int $out, int $ms, bool $ok): void
{
    insert('ai_logs', ['at' => now_iso(), 'user_id' => $u['id'] ?? null, 'feature' => $feature, 'mode' => $mode, 'model' => $model,
        'input_tokens' => $in, 'output_tokens' => $out, 'ms' => $ms, 'ok' => $ok ? 1 : 0]);
}

/**
 * Panggil Claude. $schema (opsional) = JSON Schema keluaran terstruktur.
 * @return array{text:string,json:mixed,model:string}
 */
function ai_call(?array $u, string $feature, string $system, array $messages, ?array $schema = null, string $effort = 'low', int $maxTokens = 8000): array
{
    $c = ai_config();
    $t0 = microtime(true);
    $client = new Anthropic\Client(apiKey: $c['api_key'], requestOptions: [
        'timeout' => 50.0, 'maxRetries' => 1,
        'transporter' => new GuzzleHttp\Client(['timeout' => 50, 'connect_timeout' => 10]),
    ]);
    $output = ['effort' => $effort];
    if ($schema !== null) $output['format'] = ['type' => 'json_schema', 'schema' => $schema];
    $args = [
        'model' => $c['model'], 'maxTokens' => $maxTokens, 'messages' => $messages, 'outputConfig' => $output,
        'system' => [['type' => 'text', 'text' => AI_SYSTEM_BASE . "\n\n" . $system, 'cacheControl' => ['type' => 'ephemeral']]],
    ];
    // Opus 5.5 & Sonnet 5.5: alihkan otomatis ke model cadangan bila model utama menolak karena kebijakan.
    $useFallback = $c['model'] !== 'claude-haiku-5-5';
    try {
        $msg = $useFallback
            ? $client->beta->messages->create(...$args, betas: ['server-side-fallback-2026-07-01'], fallbacks: 'default')
            : $client->messages->create(...$args);
    } catch (Anthropic\Core\Exceptions\AuthenticationException $e) {
        ai_log($u, $feature, 'claude', $c['model'], 0, 0, (int)((microtime(true) - $t0) * 1000), false);
        throw new HttpError(502, 'Kunci API AI ditolak. Periksa kunci di menu Pengaturan AI.');
    } catch (Anthropic\Core\Exceptions\RateLimitException $e) {
        ai_log($u, $feature, 'claude', $c['model'], 0, 0, (int)((microtime(true) - $t0) * 1000), false);
        throw new HttpError(503, 'Layanan AI sedang sibuk (batas laju). Coba lagi sebentar.');
    } catch (Anthropic\Core\Exceptions\APIStatusException $e) {
        ai_log($u, $feature, 'claude', $c['model'], 0, 0, (int)((microtime(true) - $t0) * 1000), false);
        error_log('SISTRA AI: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 300));
        throw new HttpError(502, 'Layanan AI mengembalikan galat. Coba lagi, atau pakai mode simulasi.');
    } catch (Anthropic\Core\Exceptions\APIException $e) {
        ai_log($u, $feature, 'claude', $c['model'], 0, 0, (int)((microtime(true) - $t0) * 1000), false);
        error_log('SISTRA AI: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 300));
        throw new HttpError(504, 'Tidak dapat menghubungi layanan AI (jaringan/waktu habis).');
    }
    $ms = (int)((microtime(true) - $t0) * 1000);
    $in = (int)($msg->usage->inputTokens ?? 0) + (int)($msg->usage->cacheReadInputTokens ?? 0) + (int)($msg->usage->cacheCreationInputTokens ?? 0);
    $out = (int)($msg->usage->outputTokens ?? 0);
    if ($msg->stopReason === 'refusal') {
        ai_log($u, $feature, 'claude', $msg->model, $in, $out, $ms, false);
        throw new HttpError(422, 'AI menolak permintaan ini karena kebijakan keamanan. Ubah brief atau susun manual.');
    }
    $text = '';
    foreach ($msg->content as $block) { if ($block->type === 'text') $text .= $block->text; }
    ai_log($u, $feature, 'claude', $msg->model, $in, $out, $ms, true);
    if ($msg->stopReason === 'max_tokens') throw new HttpError(502, 'Keluaran AI terpotong (terlalu panjang). Kurangi jumlah variasi atau panjang naskah.');
    $json = null;
    if ($schema !== null) {
        $json = json_decode($text, true);
        if (!is_array($json)) throw new HttpError(502, 'Keluaran AI tidak dapat dibaca. Coba lagi.');
    }
    return ['text' => $text, 'json' => $json, 'model' => $msg->model];
}

// ---------------- skema keluaran ----------------
function schema_obj(array $props, ?array $required = null): array
{
    return ['type' => 'object', 'properties' => $props, 'required' => $required ?? array_keys($props), 'additionalProperties' => false];
}
const STR = ['type' => 'string'];

// ---------------- fitur ----------------
const FORMAT_LABEL = [
    'rilis' => 'Siaran pers', 'artikel' => 'Artikel/berita portal', 'opini' => 'Opini/kolom', 'caption' => 'Unggahan media sosial (caption)',
    'thread' => 'Utas X/Threads', 'carousel' => 'Carousel Instagram (per slide)', 'video' => 'Naskah video pendek 9:16',
    'podcast' => 'Naskah audio/podcast', 'infografis' => 'Teks infografis (judul, poin data, sumber)', 'faq' => 'Tanya-jawab (FAQ) warga',
    'editorial' => 'Editorial/tajuk institusi', 'briefing' => 'Briefing sheet pimpinan (poin ringkas)',
];
const ANGLES = ['Informatif berbasis data', 'Empatik pada warga terdampak', 'Meluruskan hoaks dengan tegas', 'Solutif dan ajakan bertindak', 'Lokal dengan bahasa sehari-hari'];

function ai_generate_content(?array $u, array $brief, int $n): array
{
    $n = max(1, min(5, $n));
    $fmt = FORMAT_LABEL[$brief['format']] ?? $brief['format'];
    $region = $brief['region_name'] ?: 'Nasional';
    if (!ai_live()) {
        $out = [];
        for ($i = 0; $i < $n; $i++) $out[] = sim_variant($brief, ANGLES[$i], $fmt, $region);
        ai_log($u, 'generate', 'simulasi', null, 0, 0, 0, true);
        return ['mode' => 'simulasi', 'variants' => $out, 'notes' => 'Mode simulasi: isi kunci API di Pengaturan AI agar konten disusun oleh Claude.'];
    }
    $facts = !empty($brief['fact_items']) ? kak_fact_lines($brief['fact_items']) : implode("\n", array_map(fn($f) => "- $f", $brief['facts'] ?: []));
    $prompt = "Susun {$n} variasi konten dengan sudut pandang berbeda.\n\nFormat: {$fmt}\nIsu: {$brief['issue_name']}\nJudul kerja: {$brief['title']}\n"
        . "Tujuan komunikasi: {$brief['objective']}\nKhalayak: {$brief['audience']}\nWilayah: {$region}\nNada: {$brief['tone']}\n"
        . "Pesan kunci: {$brief['key_messages']}\nFakta terverifikasi (hanya pakai ini):\n{$facts}\n\n"
        . "Pakai sudut pandang berurutan dari daftar ini: " . implode('; ', array_slice(ANGLES, 0, $n)) . ".\n"
        . "Sesuaikan panjang dengan format (caption ≤ 2.200 karakter, utas 4–7 bagian bernomor, carousel 5–8 slide bertanda 'Slide n:', video bertanda adegan dan durasi ≤ 60 detik, siaran pers 3–5 paragraf).\n"
        . ($region !== 'Nasional' ? "Lokalkan untuk warga {$region}: rujukan tempat, layanan, dan istilah setempat tanpa mengarang nama pejabat.\n" : '')
        . "Panjang maksimal 800 kata per variasi. Setiap kalimat yang memuat angka/data wajib diakhiri penanda sumber fakta seperti [F1].\n"
        . "Sertakan tagar yang relevan untuk format media sosial (kosongkan untuk format lain).";
    $schema = schema_obj(['variants' => ['type' => 'array', 'items' => schema_obj(['angle' => STR, 'title' => STR, 'body' => STR, 'hashtags' => ['type' => 'array', 'items' => STR]])], 'notes' => STR]);
    $r = ai_call($u, 'generate', 'Tugas: menyusun draf konten komunikasi publik multi-format.', [['role' => 'user', 'content' => $prompt]], $schema, 'low', 12000);
    return ['mode' => 'claude', 'model' => $r['model'], 'variants' => array_slice($r['json']['variants'] ?? [], 0, $n), 'notes' => $r['json']['notes'] ?? ''];
}

function ai_localize(?array $u, string $title, string $body, string $province, array $ctx = []): array
{
    if (!ai_live()) {
        ai_log($u, 'pelokalan', 'simulasi', null, 0, 0, 0, true);
        $local = $ctx ? "\n\nKonteks lokal terverifikasi:\n" . implode("\n", array_map(fn($x) => '• ' . $x, array_slice($ctx, 0, 4))) : "\n\n(Belum ada konteks lokal terverifikasi untuk {$province}.)";
        return ['mode' => 'simulasi', 'title' => "$title — $province", 'body' => "[SIMULASI PELOKALAN · {$province}]\nWarga {$province} dapat memperoleh informasi lanjutan melalui kanal resmi pemerintah daerah setempat.\n\n" . $body . $local, 'notes' => 'Mode simulasi. Fakta lokal hanya diambil dari basis data konteks lokal terverifikasi (' . count($ctx) . ' butir).'];
    }
    $schema = schema_obj(['title' => STR, 'body' => STR, 'notes' => STR]);
    $r = ai_call($u, 'pelokalan', 'Tugas: melokalkan naskah nasional untuk satu provinsi.', [['role' => 'user', 'content' =>
        "Lokalkan naskah berikut untuk warga provinsi {$province}. Pertahankan semua fakta, jangan menambah angka atau nama pejabat baru. "
        . "Fakta lokal HANYA boleh diambil dari basis data konteks lokal terverifikasi berikut:\n" . ($ctx ? implode("\n", $ctx) : '(kosong — jangan menambah rujukan lokal spesifik)') . "\n"
        . "Sesuaikan rujukan tempat, istilah, dan ajakan ke kanal resmi daerah. Catat di 'notes' bagian yang perlu diverifikasi tim daerah.\n\nJUDUL: {$title}\n\nISI:\n{$body}"]], $schema, 'low', 8000);
    return ['mode' => 'claude', 'model' => $r['model']] + $r['json'];
}

function ai_editorial_check(?array $u, string $title, string $body, array $facts): array
{
    if (!ai_live()) {
        ai_log($u, 'editorial', 'simulasi', null, 0, 0, 0, true);
        return ['mode' => 'simulasi'] + sim_editorial($title, $body, $facts);
    }
    $sev = ['type' => 'string', 'enum' => ['info', 'peringatan', 'kritis']];
    $cat = ['type' => 'string', 'enum' => ['ejaan', 'sara', 'nada', 'fakta', 'hukum', 'kejelasan']];
    $schema = schema_obj(['score' => ['type' => 'integer'], 'summary' => STR,
        'findings' => ['type' => 'array', 'items' => schema_obj(['category' => $cat, 'severity' => $sev, 'excerpt' => STR, 'suggestion' => STR])]]);
    $factText = $facts ? implode("\n", array_map(fn($f) => "- $f", $facts)) : '(tidak ada daftar fakta)';
    // belajar dari koreksi redaktur: temuan yang ditolak redaksi jangan diulang
    $rej = all("SELECT category, excerpt, note FROM editorial_feedback WHERE decision = 'ditolak' ORDER BY id DESC LIMIT 12");
    if ($rej) $factText .= "\n\nPREFERENSI REDAKSI (temuan serupa pernah ditolak redaktur, jangan diulang kecuali benar-benar salah):\n" . implode("\n", array_map(fn($x) => "- [{$x['category']}] \"{$x['excerpt']}\"" . ($x['note'] ? " — {$x['note']}" : ''), $rej));
    $r = ai_call($u, 'editorial', 'Tugas: agen editorial yang memeriksa naskah sebelum diajukan ke pimpinan.', [['role' => 'user', 'content' =>
        "Periksa naskah ini. Kategori temuan: ejaan (PUEBI/EYD), sara (ujaran kebencian/isu sensitif), nada (terlalu menyerang/defensif), "
        . "fakta (klaim tidak didukung daftar fakta), hukum (potensi melanggar aturan/hak), kejelasan. Beri skor kelayakan 0–100. "
        . "Kutip potongan teks yang bermasalah di 'excerpt' dan beri saran perbaikan konkret.\n\nDAFTAR FAKTA:\n{$factText}\n\nJUDUL: {$title}\n\nISI:\n{$body}"]], $schema, 'low', 6000);
    $j = $r['json'];
    $j['score'] = max(0, min(100, (int)($j['score'] ?? 0)));
    return ['mode' => 'claude', 'model' => $r['model']] + $j;
}

const TRIAGE_CATS = ['pertanyaan', 'keluhan', 'hoaks', 'apresiasi', 'ujaran_kebencian', 'spam'];
/** @param array<int,array{id:int,text:string,platform:string}> $items */
function ai_triage(?array $u, array $items, string $kb): array
{
    if (!ai_live()) {
        ai_log($u, 'triase', 'simulasi', null, 0, 0, 0, true);
        return ['mode' => 'simulasi', 'results' => array_map('sim_triage', $items)];
    }
    $schema = schema_obj(['results' => ['type' => 'array', 'items' => schema_obj([
        'id' => ['type' => 'integer'], 'category' => ['type' => 'string', 'enum' => TRIAGE_CATS],
        'sentiment' => ['type' => 'string', 'enum' => ['positif', 'netral', 'negatif']], 'priority' => ['type' => 'string', 'enum' => ['tinggi', 'sedang', 'rendah']],
        'reason' => STR, 'suggested_reply' => STR])]]);
    $list = implode("\n", array_map(fn($c) => "#{$c['id']} [{$c['platform']}] {$c['text']}", $items));
    $r = ai_call($u, 'triase', 'Tugas: memilah komentar warga di akun resmi dan menyiapkan draf balasan yang akan ditinjau operator.', [['role' => 'user', 'content' =>
        "Pilah setiap komentar. Prioritas tinggi: hoaks yang menyebar, keluhan layanan mendesak, ancaman. Untuk ujaran_kebencian dan spam, suggested_reply kosong (tidak dibalas, cukup dimoderasi). "
        . "Balasan: sopan, singkat (≤ 280 karakter), merujuk kanal/layanan resmi, tanpa janji yang tidak ada di bahan respons.\n\nBAHAN RESPONS RESMI:\n{$kb}\n\nKOMENTAR:\n{$list}"]], $schema, 'low', 8000);
    return ['mode' => 'claude', 'model' => $r['model'], 'results' => $r['json']['results'] ?? []];
}

function ai_reply(?array $u, string $comment, string $kb): array
{
    if (!ai_live()) {
        ai_log($u, 'balasan', 'simulasi', null, 0, 0, 0, true);
        return ['mode' => 'simulasi', 'reply' => sim_triage(['id' => 0, 'text' => $comment, 'platform' => ''])['suggested_reply']];
    }
    $r = ai_call($u, 'balasan', 'Tugas: menyusun satu draf balasan komentar untuk akun resmi.', [['role' => 'user', 'content' =>
        "Tulis satu balasan sopan dan singkat (≤ 280 karakter) untuk komentar warga ini, berdasarkan bahan respons resmi. Bila bahan tidak cukup, arahkan ke kanal pengaduan resmi tanpa berjanji.\n\nBAHAN:\n{$kb}\n\nKOMENTAR:\n{$comment}"]], null, 'low', 2000);
    return ['mode' => 'claude', 'model' => $r['model'], 'reply' => trim($r['text'])];
}

/** Asisten percakapan, diberi ringkasan data aplikasi sebagai konteks. */
function ai_chat(?array $u, array $history, string $page, string $context): array
{
    $history = array_slice(array_values(array_filter($history, fn($m) => in_array($m['role'] ?? '', ['user', 'assistant'], true) && trim((string)($m['content'] ?? '')) !== '')), -12);
    if (!$history || $history[count($history) - 1]['role'] !== 'user') throw new HttpError(422, 'Pesan kosong');
    $q = (string)$history[count($history) - 1]['content'];
    if (!ai_live()) {
        ai_log($u, 'asisten', 'simulasi', null, 0, 0, 0, true);
        return ['mode' => 'simulasi', 'reply' => sim_chat($q, $context)];
    }
    $msgs = array_map(fn($m) => ['role' => $m['role'], 'content' => mb_substr((string)$m['content'], 0, 6000)], $history);
    $sys = "Tugas: Asisten AI SISTRA untuk modul Produksi Narasi, Editorial & Persetujuan, Kanal Media Sosial Resmi, dan Distribusi 39 Kanal.\n"
        . "Pengguna: {$u['full_name']} (peran: " . implode(', ', jdec($u['roles'])) . "). Halaman yang sedang dibuka: {$page}.\n"
        . "Jawab ringkas dan praktis. Gunakan data ringkasan di bawah bila relevan; jangan mengarang angka di luar itu. Bila diminta menyusun konten, beri draf yang siap disunting.\n"
        . "Anda tidak dapat menjalankan aksi di aplikasi (menyetujui, menayangkan, membalas); arahkan pengguna ke menu yang tepat.\n\nRINGKASAN DATA SAAT INI:\n{$context}";
    $r = ai_call($u, 'asisten', $sys, $msgs, null, 'medium', 6000);
    return ['mode' => 'claude', 'model' => $r['model'], 'reply' => trim($r['text'])];
}

// ---------------- mode simulasi (deterministik) ----------------
function sim_variant(array $b, string $angle, string $fmt, string $region): array
{
    $facts = $b['facts'] ?: ['[DATA PERLU DIVERIFIKASI]'];
    $f1 = $facts[0]; $f2 = $facts[1] ?? $facts[0];
    $lead = match ($angle) {
        'Empatik pada warga terdampak' => "Kami memahami kekhawatiran warga terkait {$b['issue_name']}.",
        'Meluruskan hoaks dengan tegas' => "Informasi yang beredar tentang {$b['issue_name']} perlu diluruskan.",
        'Solutif dan ajakan bertindak' => "Berikut langkah yang dapat dilakukan warga terkait {$b['issue_name']}.",
        'Lokal dengan bahasa sehari-hari' => "Buat warga {$region}, ini kabar terbaru soal {$b['issue_name']}.",
        default => "Pemerintah menyampaikan perkembangan terbaru mengenai {$b['issue_name']}.",
    };
    $body = "{$lead} {$f1}. {$f2}.\n\n{$b['key_messages']}\n\nInformasi resmi dan layanan pengaduan tersedia melalui kanal resmi pemerintah.\n\n[SIMULASI · {$fmt} · {$angle}]";
    $tags = in_array($b['format'], ['caption', 'thread', 'carousel', 'video'], true) ? ['#InfoResmi', '#' . preg_replace('/\W+/u', '', ucwords($b['issue_name']))] : [];
    return ['angle' => $angle, 'title' => $b['title'] ?: "Informasi resmi: {$b['issue_name']}", 'body' => $body, 'hashtags' => $tags];
}
function sim_editorial(string $title, string $body, array $facts): array
{
    $f = [];
    $text = "$title\n$body";
    if (preg_match('/\b(\d{2,})\s?%|\bRp\s?[\d.]+/u', $text, $m) && !array_filter($facts, fn($x) => str_contains($x, $m[0]))) {
        $f[] = ['category' => 'fakta', 'severity' => 'peringatan', 'excerpt' => $m[0], 'suggestion' => 'Pastikan angka ini ada di daftar fakta terverifikasi beserta sumbernya.'];
    }
    foreach (['di karenakan' => 'dikarenakan', 'di lakukan' => 'dilakukan', 'mengkoordinasikan' => 'mengoordinasikan', 'resiko' => 'risiko', 'praktek' => 'praktik', 'sekedar' => 'sekadar'] as $bad => $good) {
        if (stripos($text, $bad) !== false) $f[] = ['category' => 'ejaan', 'severity' => 'info', 'excerpt' => $bad, 'suggestion' => "Tulis \"{$good}\" sesuai KBBI/PUEBI."];
    }
    if (preg_match('/\b(bodoh|tolol|provokator|antek)\b/iu', $text, $m)) $f[] = ['category' => 'nada', 'severity' => 'kritis', 'excerpt' => $m[0], 'suggestion' => 'Hapus diksi merendahkan; fokus pada fakta dan layanan.'];
    if (mb_strlen($body) < 200) $f[] = ['category' => 'kejelasan', 'severity' => 'info', 'excerpt' => mb_substr($body, 0, 60) . '…', 'suggestion' => 'Tambahkan informasi apa, siapa, kapan, dan ke mana warga dapat mencari bantuan.'];
    $score = max(40, 95 - array_sum(array_map(fn($x) => ['info' => 4, 'peringatan' => 12, 'kritis' => 30][$x['severity']], $f)));
    return ['score' => $score, 'summary' => '[SIMULASI] Pemeriksaan berbasis aturan sederhana. Isi kunci API agar pemeriksaan dilakukan Claude.', 'findings' => $f];
}
function sim_triage(array $c): array
{
    $t = mb_strtolower($c['text']);
    $cat = 'pertanyaan'; $pri = 'sedang'; $sent = 'netral';
    if (preg_match('/(hoaks|hoax|dihentikan|bohong|palsu|katanya|sebarkan)/u', $t)) { $cat = 'hoaks'; $pri = 'tinggi'; $sent = 'negatif'; }
    elseif (preg_match('/(goblok|bangsat|anjing|kafir|antek)/u', $t)) { $cat = 'ujaran_kebencian'; $pri = 'tinggi'; $sent = 'negatif'; }
    elseif (preg_match('/(promo|klik link|slot|gacor|wa\.me|bit\.ly)/u', $t)) { $cat = 'spam'; $pri = 'rendah'; $sent = 'netral'; }
    elseif (preg_match('/(terima kasih|mantap|bagus|apresiasi|semangat)/u', $t)) { $cat = 'apresiasi'; $pri = 'rendah'; $sent = 'positif'; }
    elseif (preg_match('/(kecewa|lambat|mahal|susah|tidak ada|belum dapat|antre|kenapa)/u', $t)) { $cat = 'keluhan'; $pri = 'sedang'; $sent = 'negatif'; }
    $reply = match ($cat) {
        'hoaks' => 'Terima kasih sudah bertanya. Informasi tersebut tidak benar. Cek informasi resmi di kanal pemerintah dan laporkan hoaks melalui aduankonten.id.',
        'keluhan' => 'Mohon maaf atas kendalanya. Silakan sampaikan detail lokasi dan waktu melalui kanal pengaduan resmi agar dapat ditindaklanjuti petugas.',
        'apresiasi' => 'Terima kasih atas dukungannya. Pantau terus informasi resmi melalui akun ini.',
        'pertanyaan' => 'Terima kasih atas pertanyaannya. Informasi lengkap tersedia di kanal resmi; kami akan memperbarui jadwal terbaru di akun ini.',
        default => '',
    };
    return ['id' => $c['id'], 'category' => $cat, 'sentiment' => $sent, 'priority' => $pri, 'reason' => '[SIMULASI] aturan kata kunci', 'suggested_reply' => $reply];
}
function sim_chat(string $q, string $context): string
{
    return "[MODE SIMULASI] Asisten belum terhubung ke Claude karena kunci API belum diisi (menu Pengaturan AI, peran admin).\n\n"
        . "Ringkasan data saat ini yang bisa membantu menjawab \"" . mb_substr($q, 0, 120) . "\":\n" . $context;
}
