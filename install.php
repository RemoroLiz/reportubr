<?php
// Installer satu kali: membuat tabel + akun admin. HAPUS file ini setelah selesai.
require_once __DIR__ . '/inc/layout.php';
$lock = __DIR__ . '/install.lock';
$msg = ''; $ok = false;
if (file_exists($lock)) { http_response_code(403); exit('Sudah terpasang. Hapus install.php dari server.'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? ''); $p = $_POST['password'] ?? '';
    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $u)) $msg = 'Username 3-50 karakter (huruf, angka, . _ -).';
    elseif (strlen($p) < 8) $msg = 'Password minimal 8 karakter.';
    else {
        try {
            assertDbConfig();
            $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', DB_NAME) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec('USE `' . str_replace('`', '', DB_NAME) . '`');
            $schema = file_get_contents(__DIR__ . '/inc/schema.sql');
            if ($schema === false) throw new RuntimeException('File inc/schema.sql tidak dapat dibaca.');
            foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $schema)))) as $q) $pdo->exec($q);
            $pdo->prepare("INSERT INTO users (username, password_hash, nama) VALUES (?,?,?)")
                ->execute([$u, password_hash($p, PASSWORD_DEFAULT), trim($_POST['nama'] ?? '')]);
            file_put_contents($lock, date('c'));
            $ok = true;
        } catch (Throwable $e) { $msg = 'Gagal: ' . $e->getMessage(); }
    }
}
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalasi</title><link rel="stylesheet" href="assets/css/style.css"></head><body class="login-body">
<form method="post" class="login-card">
  <div class="brand dark"><span class="mark">Au</span><span><?= h(APP_NAME) ?></span></div>
  <h1>Instalasi</h1>
  <?php if ($ok): ?>
    <div class="alert ok">Selesai. Tabel dibuat dan akun admin tersimpan. <b>Hapus file install.php</b>, lalu <a href="login.php">masuk</a>.</div>
  <?php else: ?>
    <p class="muted">Pastikan variabel lingkungan DB_HOST, DB_NAME, DB_USER, dan DB_PASS sudah diatur. Database <b><?= h(DB_NAME) ?></b> dibuat otomatis.</p>
    <?php if ($msg): ?><div class="alert err"><?= h($msg) ?></div><?php endif; ?>
    <label>Nama lengkap <input name="nama"></label>
    <label>Username admin <input name="username" required></label>
    <label>Password (min. 8 karakter) <input name="password" type="password" required minlength="8"></label>
    <button class="btn primary" type="submit">Pasang</button>
  <?php endif; ?>
</form></body></html>
