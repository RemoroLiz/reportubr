<?php
// Tambah / reset user lewat terminal:  php create_user.php username password "Nama Lengkap"
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/inc/db.php';
[$self, $u, $p] = $argv + [null, null, null];
$nama = $argv[3] ?? '';
if (!$u || !$p) exit("Pemakaian: php create_user.php username password \"Nama\"\n");
if (strlen($p) < 8) exit("Password minimal 8 karakter.\n");
db()->prepare("INSERT INTO users (username, password_hash, nama) VALUES (?,?,?)
  ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), nama = VALUES(nama)")
  ->execute([$u, password_hash($p, PASSWORD_DEFAULT), $nama]);
echo "User '$u' disimpan.\n";
