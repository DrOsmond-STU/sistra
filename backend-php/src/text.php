<?php
/**
 * Analitik teks MVP: sentimen leksikon, geotag 38 provinsi, klasifikasi isu kata kunci,
 * deduplikasi (SHA-256 + SimHash + Jaccard), dan parser feed RSS/Atom/JSON.
 */
declare(strict_types=1);

// ---------------- Sentimen ----------------
const POSITIVE_WORDS = 'adil akurat aman apresiasi bagus baik bangga bantuan berhasil berkembang bermanfaat bersatu bersih bijak
damai dukung dukungan efektif efisien gembira harmonis hebat inklusif inovatif jujur keberhasilan kolaborasi
kompeten kondusif kuat lancar layak maju makmur membaik memuaskan mendukung menguat optimis optimistis
pemulihan percaya positif prestasi puas pulih rukun sehat sejahtera selamat senang setuju sinergi solid stabil
sukses tangguh terbantu terjangkau terkendali terlindungi toleran toleransi transparan unggul untung
keuntungan menyambut menggembirakan diapresiasi berprestasi kesejahteraan perdamaian';
const NEGATIVE_WORDS = 'anarkis ancaman anjlok beban bentrok bohong buruk curang darurat defisit diskriminasi gagal gaduh hoaks hoax
inflasi intimidasi intoleran intoleransi kacau kecewa kecurangan kegagalan kekecewaan kekerasan kelangkaan
keluhan kemarahan kemiskinan kerugian kerusakan kerusuhan kericuhan kesulitan ketakutan korban korupsi krisis
kritik lambat langka lonjakan mahal marah melanggar melonjak membebani memburuk mencekik menderita mengancam
mengeluh mengeluhkan mengkritik menolak menyerang merugikan miskin mogok panik parah pelanggaran pemerasan
penderitaan pengangguran penipuan penolakan phk protes provokasi provokatif radikal resah keresahan ricuh rugi
rusak rusuh sengketa serangan sulit takut terancam teror terorisme tewas tragedi kecam mengecam dikecam
memprihatinkan meresahkan menyesatkan fitnah kebencian kisruh polemik';
const NEGATORS = ['tidak', 'tak', 'bukan', 'belum', 'tanpa', 'jangan', 'kurang', 'enggak', 'nggak', 'gak'];
const INTENSIFIERS = ['sangat', 'amat', 'sekali', 'paling', 'terlalu', 'begitu', 'benar-benar'];

function word_set(string $s): array { return array_fill_keys(preg_split('/\s+/', trim($s)), true); }

/** @return array{label:string,score:float} */
function sentiment(string $text): array
{
    static $pos = null, $neg = null, $negators = null, $intens = null;
    if ($pos === null) {
        $pos = word_set(POSITIVE_WORDS); $neg = word_set(NEGATIVE_WORDS);
        $negators = array_fill_keys(NEGATORS, true); $intens = array_fill_keys(INTENSIFIERS, true);
    }
    preg_match_all('/[a-zA-Z\x{00C0}-\x{024F}]+(?:-[a-zA-Z]+)?/u', $text, $m);
    $tokens = array_map(fn($t) => mb_strtolower($t), $m[0]);
    $p = 0.0; $n = 0.0;
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $tok = $tokens[$i];
        $pol = isset($pos[$tok]) ? 1 : (isset($neg[$tok]) ? -1 : 0);
        if ($pol === 0) continue;
        $window = array_slice($tokens, max(0, $i - 2), $i - max(0, $i - 2));
        foreach ($window as $w) { if (isset($negators[$w])) { $pol = -$pol; break; } }
        $weight = 1.0;
        foreach ($window as $w) { if (isset($intens[$w])) { $weight = 1.5; break; } }
        if ($i + 1 < $count && $tokens[$i + 1] === 'sekali') $weight = 1.5;
        if ($pol > 0) $p += $weight; else $n += $weight;
    }
    $total = $p + $n;
    $score = $total == 0 ? 0.0 : round(($p - $n) / $total, 4);
    if ($total == 0 || abs($score) < 0.15) $label = 'netral';
    else $label = $score > 0 ? 'positif' : 'negatif';
    return ['label' => $label, 'score' => $score];
}

// ---------------- Geotag ----------------
const PROVINCES = [
    '11' => ['Aceh', ['Aceh', 'Banda Aceh', 'Lhokseumawe', 'Nanggroe Aceh Darussalam']],
    '12' => ['Sumatera Utara', ['Sumatera Utara', 'Sumut', 'Medan', 'Pematangsiantar', 'Binjai']],
    '13' => ['Sumatera Barat', ['Sumatera Barat', 'Sumbar', 'Padang', 'Bukittinggi']],
    '14' => ['Riau', ['Riau', 'Pekanbaru', 'Dumai']],
    '15' => ['Jambi', ['Jambi']],
    '16' => ['Sumatera Selatan', ['Sumatera Selatan', 'Sumsel', 'Palembang']],
    '17' => ['Bengkulu', ['Bengkulu']],
    '18' => ['Lampung', ['Lampung', 'Bandar Lampung']],
    '19' => ['Kepulauan Bangka Belitung', ['Kepulauan Bangka Belitung', 'Bangka Belitung', 'Babel', 'Pangkalpinang']],
    '21' => ['Kepulauan Riau', ['Kepulauan Riau', 'Kepri', 'Batam', 'Tanjungpinang']],
    '31' => ['DKI Jakarta', ['DKI Jakarta', 'Jakarta', 'DKI']],
    '32' => ['Jawa Barat', ['Jawa Barat', 'Jabar', 'Bandung', 'Bogor', 'Bekasi', 'Depok', 'Cirebon']],
    '33' => ['Jawa Tengah', ['Jawa Tengah', 'Jateng', 'Semarang', 'Surakarta', 'Kota Solo']],
    '34' => ['DI Yogyakarta', ['DI Yogyakarta', 'Daerah Istimewa Yogyakarta', 'Yogyakarta', 'DIY', 'Jogja']],
    '35' => ['Jawa Timur', ['Jawa Timur', 'Jatim', 'Surabaya', 'Kota Malang', 'Sidoarjo']],
    '36' => ['Banten', ['Banten', 'Serang', 'Tangerang', 'Cilegon']],
    '51' => ['Bali', ['Bali', 'Denpasar']],
    '52' => ['Nusa Tenggara Barat', ['Nusa Tenggara Barat', 'NTB', 'Mataram', 'Lombok']],
    '53' => ['Nusa Tenggara Timur', ['Nusa Tenggara Timur', 'NTT', 'Kupang']],
    '61' => ['Kalimantan Barat', ['Kalimantan Barat', 'Kalbar', 'Pontianak']],
    '62' => ['Kalimantan Tengah', ['Kalimantan Tengah', 'Kalteng', 'Palangka Raya', 'Palangkaraya']],
    '63' => ['Kalimantan Selatan', ['Kalimantan Selatan', 'Kalsel', 'Banjarmasin', 'Banjarbaru']],
    '64' => ['Kalimantan Timur', ['Kalimantan Timur', 'Kaltim', 'Samarinda', 'Balikpapan']],
    '65' => ['Kalimantan Utara', ['Kalimantan Utara', 'Kaltara', 'Tanjung Selor', 'Tarakan']],
    '71' => ['Sulawesi Utara', ['Sulawesi Utara', 'Sulut', 'Manado']],
    '72' => ['Sulawesi Tengah', ['Sulawesi Tengah', 'Sulteng', 'Palu']],
    '73' => ['Sulawesi Selatan', ['Sulawesi Selatan', 'Sulsel', 'Makassar']],
    '74' => ['Sulawesi Tenggara', ['Sulawesi Tenggara', 'Sultra', 'Kendari']],
    '75' => ['Gorontalo', ['Gorontalo']],
    '76' => ['Sulawesi Barat', ['Sulawesi Barat', 'Sulbar', 'Mamuju']],
    '81' => ['Maluku', ['Maluku', 'Ambon']],
    '82' => ['Maluku Utara', ['Maluku Utara', 'Malut', 'Ternate', 'Sofifi']],
    '91' => ['Papua', ['Papua', 'Jayapura']],
    '92' => ['Papua Barat', ['Papua Barat', 'Manokwari']],
    '93' => ['Papua Selatan', ['Papua Selatan', 'Merauke']],
    '94' => ['Papua Tengah', ['Papua Tengah', 'Nabire', 'Timika']],
    '95' => ['Papua Pegunungan', ['Papua Pegunungan', 'Wamena', 'Jayawijaya']],
    '96' => ['Papua Barat Daya', ['Papua Barat Daya', 'Sorong']],
];

function province_name(?string $code): ?string { return $code === null ? null : (PROVINCES[$code][0] ?? null); }

/** @return array{primary:?string,provinces:string[]} */
function geotag(string $text): array
{
    static $re = null, $lookup = null;
    if ($re === null) {
        $aliases = [];
        foreach (PROVINCES as $code => [, $als]) { foreach ($als as $a) $aliases[] = [$a, (string)$code]; }
        usort($aliases, fn($x, $y) => mb_strlen($y[0]) <=> mb_strlen($x[0]));
        $lookup = [];
        foreach ($aliases as [$a, $c]) $lookup[$a] = $c;
        $re = '/(?<![\p{L}\p{N}_-])(' . implode('|', array_map(fn($a) => preg_quote($a[0], '/'), $aliases)) . ')(?![\p{L}\p{N}_-])/u';
    }
    preg_match_all($re, $text, $m);
    $counts = [];
    foreach ($m[1] as $alias) { $c = $lookup[$alias]; $counts[$c] = ($counts[$c] ?? 0) + 1; }
    $order = array_keys($counts);
    $pos = array_flip($order);
    usort($order, fn($a, $b) => ($counts[$b] <=> $counts[$a]) ?: ($pos[$a] <=> $pos[$b]));
    $order = array_map('strval', $order);
    return ['primary' => $order[0] ?? null, 'provinces' => $order];
}

// ---------------- Klasifikasi isu ----------------
const DEFAULT_ISSUES = [
    ['code' => 'EKO-PANGAN', 'name' => 'Harga Pangan & Inflasi', 'risk_level' => 'tinggi', 'taxonomy_path' => 'Ekonomi/Pangan',
     'keywords' => ['harga beras', 'beras', 'minyak goreng', 'harga cabai', 'sembako', 'inflasi', 'harga pangan', 'bahan pokok', 'kelangkaan', 'operasi pasar', 'bulog', 'gula pasir']],
    ['code' => 'POL-PEMILU', 'name' => 'Pemilu & Pilkada', 'risk_level' => 'tinggi', 'taxonomy_path' => 'Politik/Pemilu',
     'keywords' => ['pemilu', 'pilkada', 'kpu', 'bawaslu', 'kampanye', 'surat suara', 'politik uang', 'rekapitulasi', 'tps', 'calon kepala daerah']],
    ['code' => 'SOS-SARA', 'name' => 'Kerukunan & Isu SARA', 'risk_level' => 'tinggi', 'taxonomy_path' => 'Sosial/Kerukunan',
     'keywords' => ['sara', 'intoleransi', 'intoleran', 'rumah ibadah', 'antarumat', 'kerukunan', 'ujaran kebencian', 'penistaan', 'antaretnis']],
    ['code' => 'BEN-ALAM', 'name' => 'Bencana Alam', 'risk_level' => 'sedang', 'taxonomy_path' => 'Keselamatan/Bencana',
     'keywords' => ['banjir', 'gempa', 'tanah longsor', 'longsor', 'erupsi', 'tsunami', 'bnpb', 'bpbd', 'pengungsi', 'cuaca ekstrem', 'kebakaran hutan']],
    ['code' => 'DIS-HOAKS', 'name' => 'Disinformasi & Hoaks', 'risk_level' => 'tinggi', 'taxonomy_path' => 'Informasi/Disinformasi',
     'keywords' => ['hoaks', 'hoax', 'disinformasi', 'berita bohong', 'deepfake', 'misinformasi', 'cek fakta', 'akun palsu', 'narasi menyesatkan']],
    ['code' => 'KES-PUBLIK', 'name' => 'Kesehatan Publik', 'risk_level' => 'sedang', 'taxonomy_path' => 'Sosial/Kesehatan',
     'keywords' => ['wabah', 'demam berdarah', 'dbd', 'vaksinasi', 'vaksin', 'bpjs kesehatan', 'puskesmas', 'stunting', 'rumah sakit', 'kasus penyakit']],
    ['code' => 'LIN-AGRARIA', 'name' => 'Lingkungan & Agraria', 'risk_level' => 'sedang', 'taxonomy_path' => 'Sumber Daya/Agraria',
     'keywords' => ['sengketa lahan', 'konflik agraria', 'deforestasi', 'tambang', 'pencemaran', 'limbah', 'masyarakat adat', 'reklamasi', 'izin tambang']],
    ['code' => 'EKO-KERJA', 'name' => 'Ketenagakerjaan', 'risk_level' => 'sedang', 'taxonomy_path' => 'Ekonomi/Ketenagakerjaan',
     'keywords' => ['phk', 'pemutusan hubungan kerja', 'upah minimum', 'ump', 'buruh', 'pengangguran', 'mogok kerja', 'serikat pekerja', 'lowongan kerja']],
];

/** @param array<string,string[]> $taxonomy  @return array<int,array{code:string,confidence:float}> */
function classify_issues(string $text, array $taxonomy): array
{
    $low = mb_strtolower($text);
    $out = [];
    foreach ($taxonomy as $code => $kws) {
        $hits = 0;
        foreach ($kws as $kw) {
            $kw = trim((string)$kw);
            if ($kw === '') continue;
            if (preg_match('/(?<![\p{L}\p{N}_])' . preg_quote(mb_strtolower($kw), '/') . '(?![\p{L}\p{N}_])/u', $low)) $hits++;
        }
        if ($hits) $out[] = ['code' => (string)$code, 'confidence' => round(min(0.95, 0.5 + 0.15 * ($hits - 1)), 2)];
    }
    usort($out, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
    return $out;
}

// ---------------- Dedup ----------------
const SIMHASH_PREFILTER = 22;
const JACCARD_THRESHOLD = 0.8;

function words_of(string $s): array { preg_match_all('/[\p{L}\p{N}_]+/u', mb_strtolower($s), $m); return $m[0]; }
function normalize_text(string $title, string $body): string { return implode(' ', words_of("$title $body")); }
function content_hash_norm(string $title, string $body): string { return hash('sha256', normalize_text($title, $body)); }

function simhash(string $text): int
{
    $w = words_of($text);
    if (!$w) return 0;
    $n = max(1, count($w) - 2);
    $vec = array_fill(0, 64, 0);
    for ($i = 0; $i < $n; $i++) {
        $g = implode(' ', array_slice($w, $i, 3));
        $h = unpack('J', substr(hash('sha256', $g, true), 0, 8))[1];
        for ($b = 0; $b < 64; $b++) $vec[$b] += (($h >> $b) & 1) ? 1 : -1;
    }
    $v = 0;
    for ($b = 0; $b < 64; $b++) if ($vec[$b] > 0) $v |= (1 << $b);
    return $v;
}
function hamming(int $a, int $b): int { return substr_count(decbin($a ^ $b), '1'); }
/** @return array<string,bool> */
function shingles(string $text): array
{
    $w = words_of($text);
    if (count($w) < 3) return $w ? [implode(' ', $w) => true] : [];
    $out = [];
    for ($i = 0, $n = count($w) - 2; $i < $n; $i++) $out[$w[$i] . ' ' . $w[$i + 1] . ' ' . $w[$i + 2]] = true;
    return $out;
}
function jaccard(array $a, array $b): float
{
    if (!$a || !$b) return 0.0;
    $inter = count(array_intersect_key($a, $b));
    return $inter / (count($a) + count($b) - $inter);
}

// ---------------- Parser feed ----------------
function clean_text(?string $v): string
{
    if ($v === null || $v === '') return '';
    $t = preg_replace('/<[^>]+>/', ' ', $v);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $t));
}
function parse_dt(?string $v): ?string
{
    if ($v === null || trim($v) === '') return null;
    try { $d = new DateTimeImmutable(trim($v), new DateTimeZone('UTC')); } catch (Exception $e) { return null; }
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
}
function child_text(DOMElement $el, string ...$names): string
{
    foreach ($el->childNodes as $c) {
        if ($c instanceof DOMElement && in_array(strtolower($c->localName), $names, true)) return trim($c->textContent);
    }
    return '';
}
/** @return array<int,array{title:string,body:string,url:string,external_id:string,author:string,published_at:?string}> */
function parse_feed(string $data): array
{
    if (stripos(substr($data, 0, 2048), '<!doctype') !== false || stripos($data, '<!entity') !== false) {
        throw new InvalidArgumentException('Feed dengan DOCTYPE/ENTITY ditolak (pencegahan XXE/entity expansion)');
    }
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadXML($data, LIBXML_NONET | LIBXML_NOCDATA);
    $err = libxml_get_last_error();
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) throw new InvalidArgumentException('XML tidak valid: ' . ($err ? trim($err->message) : 'tidak dapat diurai'));
    $items = [];
    foreach ($doc->getElementsByTagName('*') as $el) {
        $name = strtolower($el->localName);
        if ($name === 'item') {
            $link = child_text($el, 'link');
            $items[] = [
                'title' => clean_text(child_text($el, 'title')),
                'body' => clean_text(child_text($el, 'encoded') ?: child_text($el, 'description')),
                'url' => $link,
                'external_id' => child_text($el, 'guid') ?: $link,
                'author' => clean_text(child_text($el, 'creator', 'author')),
                'published_at' => parse_dt(child_text($el, 'pubdate', 'date')),
            ];
        } elseif ($name === 'entry') {
            $link = ''; $author = '';
            foreach ($el->childNodes as $c) {
                if ($c instanceof DOMElement && strtolower($c->localName) === 'link' && ($c->getAttribute('rel') ?: 'alternate') === 'alternate') { $link = $c->getAttribute('href'); break; }
            }
            foreach ($el->childNodes as $c) {
                if ($c instanceof DOMElement && strtolower($c->localName) === 'author') $author = child_text($c, 'name');
            }
            $items[] = [
                'title' => clean_text(child_text($el, 'title')),
                'body' => clean_text(child_text($el, 'content') ?: child_text($el, 'summary')),
                'url' => $link,
                'external_id' => child_text($el, 'id') ?: $link,
                'author' => clean_text($author),
                'published_at' => parse_dt(child_text($el, 'published') ?: child_text($el, 'updated')),
            ];
        }
    }
    return $items;
}
function parse_json_items(string $data): array
{
    $p = json_decode($data, true);
    if ($p === null && json_last_error() !== JSON_ERROR_NONE) throw new InvalidArgumentException('JSON tidak valid: ' . json_last_error_msg());
    if (is_array($p) && !array_is_list($p)) $p = $p['items'] ?? [];
    if (!is_array($p) || !array_is_list($p)) throw new InvalidArgumentException('JSON harus berupa array objek atau {"items": [...]}');
    $out = [];
    foreach ($p as $o) {
        if (!is_array($o) || array_is_list($o)) continue;
        $s = fn($k) => isset($o[$k]) && !is_array($o[$k]) ? (string)$o[$k] : '';
        $out[] = [
            'title' => clean_text($s('title')),
            'body' => clean_text($s('body') ?: ($s('content') ?: $s('text'))),
            'url' => $s('url'),
            'external_id' => $s('external_id') ?: ($s('id') ?: $s('url')),
            'author' => clean_text($s('author')),
            'published_at' => $s('published_at') !== '' ? parse_dt($s('published_at')) : null,
        ];
    }
    return $out;
}
function parse_any(string $data): array
{
    $t = ltrim($data);
    if ($t !== '' && ($t[0] === '[' || $t[0] === '{')) return parse_json_items($data);
    return parse_feed($data);
}
