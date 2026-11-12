<?php
/**
 * Reverse proxy PHP SISTRA — untuk hosting yang tidak mengizinkan proxy Apache ([P]) dan tidak
 * mendukung aplikasi Python. Domain publik dilayani di sini; aplikasi berjalan di server origin.
 *
 * Keamanan: origin hanya menerima koneksi dari IP server ini (SISTRA_PROXY_ONLY + SISTRA_TRUSTED_PROXIES),
 * dan IP asli pengguna diteruskan lewat X-Forwarded-For untuk jejak audit dan watermark.
 */

const UPSTREAM = 'https://sistra.ramasetyamandiri.com';
const TIMEOUT = 120;

$hopByHop = ['host', 'connection', 'keep-alive', 'proxy-connection', 'te', 'trailer', 'transfer-encoding',
    'upgrade', 'content-length', 'accept-encoding', 'x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-host',
    'x-real-ip'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isMultipart = stripos($contentType, 'multipart/form-data') === 0;

$headers = [];
foreach (getallheaders() as $name => $value) {
    $lower = strtolower($name);
    if (in_array($lower, $hopByHop, true)) continue;
    if ($isMultipart && $lower === 'content-type') continue; // curl menyusun boundary baru
    $headers[] = $name . ': ' . $value;
}
$headers[] = 'X-Forwarded-For: ' . ($_SERVER['REMOTE_ADDR'] ?? '');
$headers[] = 'X-Forwarded-Proto: https';
$headers[] = 'X-Forwarded-Host: ' . ($_SERVER['HTTP_HOST'] ?? '');
$headers[] = 'Expect:';

$ch = curl_init(UPSTREAM . $uri);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => TIMEOUT,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

if ($method === 'HEAD') {
    curl_setopt($ch, CURLOPT_NOBODY, true);
} elseif ($isMultipart) {
    // PHP sudah mengurai multipart ke $_POST/$_FILES; susun ulang untuk diteruskan
    $fields = $_POST;
    foreach ($_FILES as $field => $file) {
        if (is_array($file['tmp_name'])) continue; // MVP: satu berkas per field
        if ($file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(413);
            header('Content-Type: application/json');
            echo json_encode(['detail' => 'Unggahan gagal di proxy (kode ' . $file['error'] . '), periksa batas ukuran berkas']);
            exit;
        }
        $fields[$field] = new CURLFile($file['tmp_name'], $file['type'] ?: 'application/octet-stream', $file['name']);
    }
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
} elseif (!in_array($method, ['GET', 'DELETE', 'OPTIONS'], true) || ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $body = file_get_contents('php://input');
    if ($body !== '' && $body !== false) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

$response = curl_exec($ch);
if ($response === false) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['detail' => 'Server aplikasi SISTRA tidak dapat dihubungi']);
    exit;
}
$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

$rawHeaders = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);
$blocks = preg_split("/\r\n\r\n/", trim($rawHeaders));
$last = end($blocks);

http_response_code($status);
header_remove('X-Powered-By');
$skip = ['transfer-encoding', 'connection', 'keep-alive', 'content-length', 'content-encoding', 'server'];
foreach (explode("\r\n", $last) as $i => $line) {
    if ($i === 0 || strpos($line, ':') === false) continue;
    [$name, $value] = explode(':', $line, 2);
    if (in_array(strtolower(trim($name)), $skip, true)) continue;
    header(trim($name) . ': ' . trim($value), false);
}
echo $body;
