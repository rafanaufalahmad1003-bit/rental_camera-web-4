<?php
declare(strict_types=1);

// XAMPP defaults: MySQL user root with an empty password.
// Change these values if your local MySQL credentials differ.
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbName = getenv('DB_NAME') ?: 'rental_camera';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database belum dapat dihubungi. Pastikan MySQL aktif dan schema.sql sudah diimpor.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function check_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('Form kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    }
}

function require_login(): void
{
    if (empty($_SESSION['user'])) {
        header('Location: index.php?page=login');
        exit;
    }
}

function require_admin(): void
{
    require_login();
    if (($_SESSION['user']['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Akses ditolak: hanya admin yang boleh melakukan tindakan ini.');
    }
}
