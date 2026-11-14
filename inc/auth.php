<?php
require_once __DIR__ . '/db.php';

function startSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS'])]);
    session_name('salesrpt');
    session_start();
}

function currentUser(): ?array {
    startSession();
    return $_SESSION['user'] ?? null;
}

function requireLogin(bool $json = false): array {
    $u = currentUser();
    if (!$u) {
        if ($json) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Sesi habis. Silakan login kembali.']);
        } else {
            header('Location: login.php');
        }
        exit;
    }
    return $u;
}

function csrfToken(): string {
    startSession();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}

function checkCsrf(): void {
    startSession();
    $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (!$t || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Token keamanan tidak valid. Muat ulang halaman.']);
        exit;
    }
}
