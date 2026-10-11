<?php
require __DIR__ . '/config.php';
$count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $count === 0) {
    check_csrf();
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($name === '' || !preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $username) || strlen($password) < 8) {
        $message = 'Isi nama, username 3–60 karakter, dan password minimal 8 karakter.';
    } else {
        $stmt = $pdo->prepare("INSERT INTO users (name, username, password_hash, role) VALUES (?, ?, ?, 'admin')");
        $stmt->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT)]);
        header('Location: index.php?page=login&setup=done');
        exit;
    }
}
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup Rental Camera</title><link rel="stylesheet" href="assets/style.css"></head><body class="auth-bg"><main class="auth-card"><div class="brand-mark">RC</div><p class="eyebrow">PENGATURAN AWAL</p><h1>Buat akun admin</h1>
<?php if ($count > 0): ?><p>Akun sudah dibuat. Demi keamanan, halaman setup dikunci.</p><a class="button" href="index.php?page=login">Ke halaman login</a>
<?php else: ?><p>Gunakan password minimal 8 karakter.</p><?php if ($message): ?><div class="alert"><?=h($message)?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><label>Nama<input name="name" required></label><label>Username<input name="username" minlength="3" required></label><label>Password<input type="password" name="password" minlength="8" required></label><button class="button" type="submit">Buat admin</button></form><?php endif; ?></main></body></html>
