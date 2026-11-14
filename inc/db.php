<?php
require_once __DIR__ . '/../config.php';

function assertDbConfig(): void {
    if (DB_NAME === '' || DB_USER === '') {
        throw new RuntimeException('Konfigurasi database belum lengkap. Atur DB_NAME dan DB_USER; DB_PASS boleh kosong.');
    }
}

function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        assertDbConfig();
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+07:00'");
    }
    return $pdo;
}

function sqlIn(array $list): string {
    return "('" . implode("','", array_map('addslashes', $list)) . "')";
}
