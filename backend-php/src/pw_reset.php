<?php
declare(strict_types=1);

/**
 * Atur ulang kata sandi akun bawaan dari sisi server, tanpa kata sandi melewati jaringan/obrolan.
 *
 * Pemicu: berkas biasa bernama RESET-KATA-SANDI di folder data (di luar root dokumen; hanya pemegang
 * akses hosting yang dapat membuatnya). Permintaan berikutnya membuat kata sandi acak baru untuk semua
 * akun bawaan yang ada, menulisnya ke kredensial.txt (0600), mengakhiri sesi lama, mencatat jejak audit,
 * lalu menghapus pemicu.
 */
const PW_RESET_TRIGGER = 'RESET-KATA-SANDI';

function ensure_password_reset(): void
{
    $trig = data_dir() . '/' . PW_RESET_TRIGGER;
    clearstatcache(true, $trig);
    if (!is_link($trig) && !file_exists($trig)) return;
    $lock = fopen(data_dir() . '/.seed.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        clearstatcache(true, $trig);
        if (is_link($trig) || !is_file($trig)) { if (is_link($trig)) @unlink($trig); return; } // hanya berkas biasa
        $names = array_column(SEED_USERS, 0);
        $pw = [];
        tx(function () use ($names, &$pw) {
            add_col('users', 'pw_reset_at', 'TEXT');
            $now = now_iso();
            foreach (all('SELECT id, username FROM users WHERE username IN (' . implode(',', array_fill(0, count($names), '?')) . ') ORDER BY id', $names) as $u) {
                $p = random_password();
                q('UPDATE users SET password_hash = ?, pw_reset_at = ? WHERE id = ?', [password_hash($p, PASSWORD_DEFAULT), $now, $u['id']]);
                $pw[$u['username']] = $p;
            }
            audit_record(null, 'auth.reset_sandi_server', 'user', 'akun-bawaan', ['akun' => array_keys($pw)], 'server');
        });
        $path = data_dir() . '/kredensial.txt';
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        $old = umask(0077);
        $fh = fopen($tmp, 'x');
        umask($old);
        if ($fh === false) throw new RuntimeException('Tidak dapat menulis berkas kredensial');
        chmod($tmp, 0600);
        fwrite($fh, "# Kredensial akun SISTRA (diatur ulang " . gmdate('Y-m-d H:i') . " UTC) — simpan di tempat aman lalu hapus berkas ini.\n");
        foreach ($pw as $un => $p) fwrite($fh, "$un\t$p\n");
        fclose($fh);
        rename($tmp, $path);
        @unlink($trig);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Token yang terbit sebelum kata sandi diatur ulang tidak berlaku lagi. */
function token_before_reset(array $user, array $payload): bool
{
    return !empty($user['pw_reset_at']) && (int)($payload['iat'] ?? 0) < (int)strtotime((string)$user['pw_reset_at']);
}
