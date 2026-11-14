<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/layout.php';
startSession();
if (currentUser()) { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0';
    $st = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > (NOW() - INTERVAL 15 MINUTE)");
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= 5) {
        $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam 15 menit.';
    } else {
        $st = db()->prepare("SELECT * FROM users WHERE username = ?");
        $st->execute([trim($_POST['username'] ?? '')]);
        $u = $st->fetch();
        if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$u['id'], 'username' => $u['username'], 'nama' => $u['nama']];
            db()->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
            header('Location: index.php'); exit;
        }
        db()->prepare("INSERT INTO login_attempts (ip) VALUES (?)")->execute([$ip]);
        $error = 'Username atau password salah.';
    }
}
?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk · <?= h(APP_NAME) ?></title><link rel="stylesheet" href="assets/css/style.css"></head>
<body class="login-body">
<form method="post" class="login-card" autocomplete="on">
  <div class="brand dark"><span class="mark">Au</span><span><?= h(APP_NAME) ?></span></div>
  <h1>Masuk</h1>
  <p class="muted">Login diperlukan untuk melihat laporan dan mengunggah data.</p>
  <?php if ($error): ?><div class="alert err"><?= h($error) ?></div><?php endif; ?>
  <label>Username <input name="username" required autofocus autocomplete="username"></label>
  <label>Password <input name="password" type="password" required autocomplete="current-password"></label>
  <button class="btn primary" type="submit">Masuk</button>
</form>
</body></html>
