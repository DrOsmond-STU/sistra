<?php
/**
 * Gerbang login purwarupa UI/UX SISTRA.
 * - Berkas purwarupa disimpan DI LUAR root dokumen; hanya dikirim setelah login berhasil.
 * - Kredensial diverifikasi ke API SISTRA (akun yang sama dengan aplikasi), sehingga tercatat di jejak audit.
 */
const UPSTREAM = 'https://sistra.ramasetyamandiri.com';
const PROTOTYPE_FILE = __DIR__ . '/../../sistra-prototipe/prototipe.html';
const SESSION_TTL = 8 * 3600;

session_name('SISTRAPROTO');
session_set_cookie_params(['lifetime' => 0, 'path' => '/prototipe/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Strict']);
session_start();
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Accel-Expires: 0'); // jangan disimpan oleh cache tepi (CDN/nginx)

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $error = 'Sesi formulir kedaluwarsa, silakan coba lagi.';
    } else {
        $username = substr(trim((string)($_POST['username'] ?? '')), 0, 64);
        $password = (string)($_POST['password'] ?? '');
        $ch = curl_init(UPSTREAM . '/api/auth/login');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['username' => $username, 'password' => $password]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Forwarded-For: ' . ($_SERVER['REMOTE_ADDR'] ?? ''), 'X-Forwarded-Proto: https'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code === 200) {
            $data = json_decode($body, true);
            session_regenerate_id(true);
            $_SESSION['ok'] = time();
            $_SESSION['name'] = $data['user']['full_name'] ?? $username;
            header('Location: ./');
            exit;
        }
        $error = $code === 401 ? 'Nama pengguna atau kata sandi salah.' : 'Layanan login sedang tidak tersedia.';
    }
}

if (!empty($_SESSION['ok']) && time() - $_SESSION['ok'] < SESSION_TTL) {
    $html = file_get_contents(PROTOTYPE_FILE);
    if ($html === false) { http_response_code(500); exit('Berkas purwarupa tidak ditemukan.'); }
    echo str_replace('Pengguna Demo', htmlspecialchars($_SESSION['name'], ENT_QUOTES), $html);
    exit;
}

$_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>SISTRA — Purwarupa UI/UX</title>
<style>
:root{color-scheme:light dark}*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:16px;font:14px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;background:linear-gradient(160deg,#0e2138,#13304f);color:#0b0b0b}
.card{background:#fcfcfb;border-radius:14px;padding:26px;width:100%;max-width:380px;box-shadow:0 20px 60px rgba(0,0,0,.35)}
h1{margin:0;font-size:22px;letter-spacing:.04em}p{margin:4px 0 18px;color:#52514e;font-size:13px}
label{display:flex;flex-direction:column;gap:4px;font-size:13px;color:#52514e;margin-bottom:12px}
input{font:inherit;padding:9px 11px;border:1px solid #c3c2b7;border-radius:8px;background:#fff;color:#0b0b0b}
button{width:100%;font:inherit;font-weight:600;padding:10px;border:0;border-radius:8px;background:#1c5cab;color:#fff;cursor:pointer}
.err{border:1px solid #d03b3b;color:#b42318;background:#fbeaea;padding:8px 10px;border-radius:8px;margin-bottom:12px;font-size:13px}
.note{font-size:12px;color:#6f6e69;margin-top:14px}
</style></head><body>
<form class="card" method="post" autocomplete="on">
  <h1>SISTRA</h1><p>Purwarupa UI/UX — masuk dengan akun SISTRA Anda</p>
  <?php if ($error): ?><div class="err" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= $csrf ?>">
  <label>Nama pengguna<input name="username" autocomplete="username" required autofocus></label>
  <label>Kata sandi<input name="password" type="password" autocomplete="current-password" required></label>
  <button type="submit">Masuk</button>
  <div class="note">Seluruh data dalam purwarupa bersifat sintetis. Login tercatat di jejak audit SISTRA.</div>
</form>
</body></html>
