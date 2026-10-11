<?php
require __DIR__ . '/config.php';

$page = $_GET['page'] ?? 'dashboard';
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'login') {
            $stmt = $pdo->prepare('SELECT id, name, username, password_hash, role FROM users WHERE username = ?');
            $stmt->execute([trim($_POST['username'] ?? '')]);
            $user = $stmt->fetch();
            if ($user && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user'] = ['id' => $user['id'], 'name' => $user['name'], 'role' => $user['role']];
                header('Location: index.php'); exit;
            }
            $error = 'Username atau password salah.';
            $page = 'login';
        } else {
            require_login();
            if ($action === 'logout') {
                $_SESSION = [];
                session_destroy();
                header('Location: index.php?page=login'); exit;
            }
            if ($action === 'add_customer') {
                $name = trim($_POST['name'] ?? ''); $phone = trim($_POST['phone'] ?? '');
                if ($name === '' || $phone === '') throw new RuntimeException('Nama dan nomor telepon wajib diisi.');
                $stmt = $pdo->prepare('INSERT INTO customers (name, phone, email, identity_number, address) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$name, $phone, trim($_POST['email'] ?? '') ?: null, trim($_POST['identity_number'] ?? '') ?: null, trim($_POST['address'] ?? '') ?: null]);
                $notice = 'Pelanggan berhasil ditambahkan.'; $page = 'customers';
            } elseif ($action === 'add_category') {
                require_admin(); $name = trim($_POST['name'] ?? '');
                if ($name === '') throw new RuntimeException('Nama kategori wajib diisi.');
                $stmt = $pdo->prepare('INSERT INTO categories (name, description) VALUES (?, ?)');
                $stmt->execute([$name, trim($_POST['description'] ?? '') ?: null]);
                $notice = 'Kategori berhasil ditambahkan.'; $page = 'equipment';
            } elseif ($action === 'add_equipment') {
                require_admin();
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO equipment (category_id, sku, brand, model, daily_rate, deposit_amount, description) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([(int)$_POST['category_id'], trim($_POST['sku']), trim($_POST['brand']), trim($_POST['model']), (float)$_POST['daily_rate'], (float)($_POST['deposit_amount'] ?? 0), trim($_POST['description'] ?? '') ?: null]);
                $equipmentId = (int)$pdo->lastInsertId();
                $stmt = $pdo->prepare('INSERT INTO equipment_units (equipment_id, serial_number, condition_note) VALUES (?, ?, ?)');
                $stmt->execute([$equipmentId, trim($_POST['serial_number']), trim($_POST['condition_note'] ?? '') ?: null]);
                $pdo->commit(); $notice = 'Peralatan dan unit fisiknya berhasil ditambahkan.'; $page = 'equipment';
            } elseif ($action === 'create_rental') {
                $customerId = (int)($_POST['customer_id'] ?? 0); $unitId = (int)($_POST['unit_id'] ?? 0);
                $start = new DateTime($_POST['start_at'] ?? ''); $due = new DateTime($_POST['due_at'] ?? '');
                if ($customerId < 1 || $unitId < 1 || $due <= $start) throw new RuntimeException('Pelanggan, unit, dan rentang tanggal sewa harus valid.');
                $days = max(1, (int)ceil(($due->getTimestamp() - $start->getTimestamp()) / 86400));
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT u.id, u.status, e.daily_rate, e.deposit_amount FROM equipment_units u JOIN equipment e ON e.id=u.equipment_id WHERE u.id=? FOR UPDATE");
                $stmt->execute([$unitId]); $unit = $stmt->fetch();
                if (!$unit || $unit['status'] !== 'available') throw new RuntimeException('Unit tidak tersedia untuk disewa.');
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM rental_items ri JOIN rentals r ON r.id=ri.rental_id WHERE ri.equipment_unit_id=? AND r.status IN ('booked','active','late') AND r.start_at < ? AND r.due_at > ?");
                $stmt->execute([$unitId, $due->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s')]);
                if ((int)$stmt->fetchColumn() > 0) throw new RuntimeException('Unit sudah memiliki jadwal sewa yang bertabrakan.');
                $code = 'RC-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $total = (float)$unit['daily_rate'] * $days; $deposit = (float)$unit['deposit_amount'];
                $stmt = $pdo->prepare("INSERT INTO rentals (rental_code, customer_id, created_by, start_at, due_at, status, subtotal, deposit_total) VALUES (?, ?, ?, ?, ?, 'active', ?, ?)");
                $stmt->execute([$code, $customerId, $_SESSION['user']['id'], $start->format('Y-m-d H:i:s'), $due->format('Y-m-d H:i:s'), $total, $deposit]);
                $rentalId = (int)$pdo->lastInsertId();
                $stmt = $pdo->prepare('INSERT INTO rental_items (rental_id, equipment_unit_id, daily_rate, rental_days, line_total) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$rentalId, $unitId, $unit['daily_rate'], $days, $total]);
                $pdo->prepare("UPDATE equipment_units SET status='rented' WHERE id=?")->execute([$unitId]);
                $pdo->commit(); $notice = "Sewa {$code} berhasil dicatat."; $page = 'rentals';
            } elseif ($action === 'return_rental') {
                $rentalId = (int)($_POST['rental_id'] ?? 0); $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT id FROM rentals WHERE id=? AND status IN ('active','late') FOR UPDATE"); $stmt->execute([$rentalId]);
                if (!$stmt->fetch()) throw new RuntimeException('Transaksi tidak ditemukan atau sudah selesai.');
                $stmt = $pdo->prepare('SELECT equipment_unit_id FROM rental_items WHERE rental_id=?'); $stmt->execute([$rentalId]);
                $units = $stmt->fetchAll();
                foreach ($units as $u) $pdo->prepare("UPDATE equipment_units SET status='available' WHERE id=? AND status='rented'")->execute([$u['equipment_unit_id']]);
                $pdo->prepare("UPDATE rentals SET status='completed', returned_at=NOW() WHERE id=?")->execute([$rentalId]);
                $pdo->commit(); $notice = 'Pengembalian berhasil dicatat.'; $page = 'rentals';
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e instanceof PDOException && str_contains($e->getMessage(), 'Duplicate') ? 'Kode/SKU/nomor seri sudah digunakan.' : $e->getMessage();
    }
}

if ($page === 'logout') { require_login(); $_SESSION=[]; session_destroy(); header('Location: index.php?page=login'); exit; }
if ($page !== 'login') require_login();
$user = $_SESSION['user'] ?? null;
$isAdmin = ($user['role'] ?? '') === 'admin';
function rows(PDO $pdo, string $sql, array $args = []): array { $s=$pdo->prepare($sql); $s->execute($args); return $s->fetchAll(); }

if ($page === 'login') {
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Masuk · Rental Camera</title><link rel="stylesheet" href="assets/style.css"></head><body class="auth-bg"><main class="auth-card"><div class="brand-mark">RC</div><p class="eyebrow">RENTAL CAMERA</p><h1>Selamat datang</h1><p>Masuk untuk mengelola penyewaan kamera.</p><?php if(isset($_GET['setup'])):?><div class="success">Admin berhasil dibuat. Silakan masuk.</div><?php endif;?><?php if($error):?><div class="alert"><?=h($error)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="login"><label>Username<input name="username" autocomplete="username" required autofocus></label><label>Password<input name="password" type="password" autocomplete="current-password" required></label><button class="button" type="submit">Masuk</button></form><a class="muted setup-link" href="setup.php">Setup admin pertama kali</a></main></body></html><?php exit; }

$customers = rows($pdo, 'SELECT id,name,phone FROM customers ORDER BY name');
$categories = rows($pdo, 'SELECT id,name FROM categories ORDER BY name');
$units = rows($pdo, "SELECT u.id,u.serial_number,u.status,e.brand,e.model,e.daily_rate FROM equipment_units u JOIN equipment e ON e.id=u.equipment_id ORDER BY e.brand,e.model,u.serial_number");
$available = array_values(array_filter($units, fn($u) => $u['status'] === 'available'));
$rentalRows = rows($pdo, 'SELECT r.*,c.name customer_name,u.serial_number,e.brand,e.model FROM rentals r JOIN customers c ON c.id=r.customer_id JOIN rental_items ri ON ri.rental_id=r.id JOIN equipment_units u ON u.id=ri.equipment_unit_id JOIN equipment e ON e.id=u.equipment_id ORDER BY r.created_at DESC LIMIT 100');
$customerRows = rows($pdo, 'SELECT * FROM customers ORDER BY created_at DESC LIMIT 100');
$equipmentRows = rows($pdo, 'SELECT e.*,c.name category_name,u.id unit_id,u.serial_number,u.status unit_status FROM equipment e JOIN categories c ON c.id=e.category_id LEFT JOIN equipment_units u ON u.equipment_id=e.id ORDER BY e.created_at DESC');
$stats = [
    'unit' => (int)$pdo->query('SELECT COUNT(*) FROM equipment_units')->fetchColumn(),
    'available' => (int)$pdo->query("SELECT COUNT(*) FROM equipment_units WHERE status='available'")->fetchColumn(),
    'active' => (int)$pdo->query("SELECT COUNT(*) FROM rentals WHERE status IN ('active','late')")->fetchColumn(),
    'customers' => (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(),
    'low_availability' => (int)$pdo->query("SELECT COUNT(*) FROM equipment e WHERE (SELECT COUNT(*) FROM equipment_units u WHERE u.equipment_id=e.id AND u.status='available') <= 1")->fetchColumn(),
];
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Rental Camera</title><link rel="stylesheet" href="assets/style.css"></head><body><div class="app-shell"><aside class="sidebar"><a class="logo" href="index.php"><span class="brand-mark small">RC</span><span>Rental<span class="logo-light">Camera</span></span></a><div class="side-label">MENU UTAMA</div><a class="nav-item <?= $page==='dashboard'?'active':''?>" href="index.php">▦ <span>Dashboard</span></a><a class="nav-item <?= $page==='rentals'?'active':''?>" href="index.php?page=rentals">▣ <span>Transaksi sewa</span></a><a class="nav-item <?= $page==='equipment'?'active':''?>" href="index.php?page=equipment">◉ <span>Peralatan</span></a><a class="nav-item <?= $page==='customers'?'active':''?>" href="index.php?page=customers">♙ <span>Pelanggan</span></a><a class="nav-item" href="manage.php">⚙ <span>Fitur tugas lainnya</span></a><a class="nav-item" href="manage.php">⚙ <span>Fitur tugas lainnya</span></a><div class="side-bottom"><div class="profile"><div class="avatar"><?=h(strtoupper(substr($user['name'],0,1)))?></div><div><strong><?=h($user['name'])?></strong><small><?=h(ucfirst($user['role']))?></small></div></div><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="logout"><button class="logout" type="submit">Keluar ↗</button></form></div></aside><main class="main"><header class="topbar"><div><span class="muted">Rental Camera</span><span class="crumb"> / <?=h(ucfirst($page))?></span></div><div class="top-user"><span class="online"></span><?=h($user['name'])?></div></header><section class="content">
<?php if($error):?><div class="alert"><?=h($error)?></div><?php endif;?><?php if($notice):?><div class="success"><?=h($notice)?></div><?php endif;?>
<?php if($page==='dashboard'):?><div class="page-heading"><div><p class="eyebrow">RINGKASAN TOKO</p><h1>Dashboard</h1><p>Pantau ketersediaan perlengkapan dan transaksi sewa.</p></div><a class="button" href="index.php?page=rentals">+ Buat penyewaan</a></div><div class="stat-grid"><article class="stat-card"><span class="stat-icon purple">◉</span><span class="stat-title">Total unit</span><strong><?=$stats['unit']?></strong><small>Unit fisik terdaftar</small></article><article class="stat-card"><span class="stat-icon green">✓</span><span class="stat-title">Tersedia</span><strong><?=$stats['available']?></strong><small>Siap untuk disewa</small></article><article class="stat-card"><span class="stat-icon orange">▣</span><span class="stat-title">Sedang disewa</span><strong><?=$stats['active']?></strong><small>Transaksi aktif</small></article><article class="stat-card"><span class="stat-icon blue">♙</span><span class="stat-title">Pelanggan</span><strong><?=$stats['customers']?></strong><small>Total terdaftar</small></article><article class="stat-card"><span class="stat-title">Ketersediaan rendah</span><strong><?=$stats['low_availability']?></strong><small>Model dengan maksimal 1 unit tersedia</small></article></div><div class="section-head"><div><h2>Transaksi terbaru</h2><p>Riwayat penyewaan terakhir</p></div><a class="text-link" href="index.php?page=rentals">Lihat semua →</a></div><?php $showRentals=array_slice($rentalRows,0,6); ?><div class="table-wrap"><table><thead><tr><th>KODE SEWA</th><th>PELANGGAN</th><th>PERALATAN</th><th>TANGGAL KEMBALI</th><th>TOTAL</th><th>STATUS</th></tr></thead><tbody><?php foreach($showRentals as $r):?><tr><td><strong><?=h($r['rental_code'])?></strong></td><td><?=h($r['customer_name'])?></td><td><?=h($r['brand'].' '.$r['model'])?></td><td><?=h(date('d M Y',strtotime($r['due_at'])))?></td><td>Rp <?=number_format((float)$r['subtotal'],0,',','.')?></td><td><span class="badge <?=h($r['status'])?>"><?=h(ucfirst($r['status']))?></span></td></tr><?php endforeach;?><?php if(!$showRentals):?><tr><td colspan="6" class="empty">Belum ada transaksi. Buat penyewaan pertama Anda.</td></tr><?php endif;?></tbody></table></div>
<?php elseif($page==='rentals'):?><div class="page-heading"><div><p class="eyebrow">PENGELOLAAN TRANSAKSI</p><h1>Transaksi sewa</h1><p>Buat penyewaan dan catat pengembalian peralatan.</p></div></div><div class="panel"><h2>Buat penyewaan</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="create_rental"><label>Pelanggan<select name="customer_id" required><option value="">Pilih pelanggan</option><?php foreach($customers as $c):?><option value="<?=$c['id']?>"><?=h($c['name'].' · '.$c['phone'])?></option><?php endforeach;?></select></label><label>Unit kamera<select name="unit_id" required><option value="">Pilih unit tersedia</option><?php foreach($available as $u):?><option value="<?=$u['id']?>"><?=h($u['brand'].' '.$u['model'].' · '.$u['serial_number'].' · Rp '.number_format((float)$u['daily_rate'],0,',','.').'/hari')?></option><?php endforeach;?></select></label><label>Mulai sewa<input type="datetime-local" name="start_at" required></label><label>Rencana kembali<input type="datetime-local" name="due_at" required></label><div class="form-actions"><button class="button" type="submit">Simpan penyewaan</button></div></form><?php if(!$customers || !$available):?><p class="hint">Tambahkan pelanggan dan unit kamera terlebih dahulu sebelum membuat penyewaan.</p><?php endif;?></div><div class="section-head"><div><h2>Daftar transaksi</h2><p>Menampilkan 100 transaksi terbaru</p></div></div><div class="table-wrap"><table><thead><tr><th>KODE</th><th>PELANGGAN</th><th>UNIT</th><th>MULAI</th><th>KEMBALI</th><th>TOTAL</th><th>STATUS / AKSI</th></tr></thead><tbody><?php foreach($rentalRows as $r):?><tr><td><strong><?=h($r['rental_code'])?></strong></td><td><?=h($r['customer_name'])?></td><td><?=h($r['brand'].' '.$r['model'].' · '.$r['serial_number'])?></td><td><?=h(date('d/m/Y H:i',strtotime($r['start_at'])))?></td><td><?=h(date('d/m/Y H:i',strtotime($r['due_at'])))?></td><td>Rp <?=number_format((float)$r['subtotal'],0,',','.')?></td><td><span class="badge <?=h($r['status'])?>"><?=h(ucfirst($r['status']))?></span><?php if(in_array($r['status'],['active','late'],true)):?><form method="post" class="inline-form" onsubmit="return confirm('Catat unit ini sudah dikembalikan?')"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="return_rental"><input type="hidden" name="rental_id" value="<?=$r['id']?>"><button class="small-button" type="submit">Kembalikan</button></form><?php endif;?></td></tr><?php endforeach;?><?php if(!$rentalRows):?><tr><td colspan="7" class="empty">Belum ada transaksi sewa.</td></tr><?php endif;?></tbody></table></div>
<?php elseif($page==='customers'):?><div class="page-heading"><div><p class="eyebrow">DATA PELANGGAN</p><h1>Pelanggan</h1><p>Simpan informasi pelanggan untuk transaksi penyewaan.</p></div></div><div class="panel"><h2>Tambah pelanggan</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="add_customer"><label>Nama lengkap<input name="name" required></label><label>Nomor telepon<input name="phone" required></label><label>Email<input type="email" name="email"></label><label>Nomor identitas<input name="identity_number"></label><label class="full">Alamat<textarea name="address" rows="2"></textarea></label><div class="form-actions"><button class="button" type="submit">Simpan pelanggan</button></div></form></div><div class="section-head"><div><h2>Daftar pelanggan</h2><p><?=$stats['customers']?> pelanggan terdaftar</p></div></div><div class="table-wrap"><table><thead><tr><th>NAMA</th><th>TELEPON</th><th>EMAIL</th><th>NOMOR IDENTITAS</th><th>TERDAFTAR</th></tr></thead><tbody><?php foreach($customerRows as $c):?><tr><td><strong><?=h($c['name'])?></strong></td><td><?=h($c['phone'])?></td><td><?=h($c['email'])?></td><td><?=h($c['identity_number'])?></td><td><?=h(date('d M Y',strtotime($c['created_at'])))?></td></tr><?php endforeach;?><?php if(!$customerRows):?><tr><td colspan="5" class="empty">Belum ada pelanggan.</td></tr><?php endif;?></tbody></table></div>
<?php elseif($page==='equipment'):?><div class="page-heading"><div><p class="eyebrow">KATALOG & UNIT</p><h1>Peralatan</h1><p>Kelola kategori kamera dan setiap unit fisik berdasarkan nomor seri.</p></div></div><?php if($isAdmin):?><div class="panel"><h2>Tambah kategori</h2><form method="post" class="form-grid compact"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="add_category"><label>Nama kategori<input name="name" placeholder="Kamera, Lensa, Aksesori" required></label><label>Deskripsi<input name="description"></label><div class="form-actions"><button class="button secondary" type="submit">Tambah kategori</button></div></form></div><div class="panel"><h2>Tambah peralatan dan unit</h2><?php if(!$categories):?><p class="hint">Tambahkan kategori terlebih dahulu.</p><?php else:?><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="add_equipment"><label>Kategori<select name="category_id" required><?php foreach($categories as $c):?><option value="<?=$c['id']?>"><?=h($c['name'])?></option><?php endforeach;?></select></label><label>SKU unik<input name="sku" required></label><label>Merek<input name="brand" placeholder="Canon, Sony, Fujifilm" required></label><label>Model<input name="model" required></label><label>Tarif per hari (Rp)<input type="number" min="0" step="1000" name="daily_rate" required></label><label>Deposit (Rp)<input type="number" min="0" step="1000" name="deposit_amount" value="0"></label><label>Nomor seri unit<input name="serial_number" required></label><label>Kondisi unit<input name="condition_note" placeholder="Baik, termasuk baterai"></label><label class="full">Deskripsi<input name="description"></label><div class="form-actions"><button class="button" type="submit">Simpan peralatan</button></div></form><?php endif;?></div><?php else:?><div class="hint">Hanya admin yang dapat menambahkan atau mengubah peralatan.</div><?php endif;?><div class="section-head"><div><h2>Daftar unit</h2><p><?=count($units)?> unit fisik tercatat</p></div></div><div class="table-wrap"><table><thead><tr><th>SKU</th><th>PERALATAN</th><th>KATEGORI</th><th>NOMOR SERI</th><th>TARIF / HARI</th><th>STATUS</th></tr></thead><tbody><?php foreach($equipmentRows as $e):?><tr><td><?=h($e['sku'])?></td><td><strong><?=h($e['brand'].' '.$e['model'])?></strong></td><td><?=h($e['category_name'])?></td><td><?=h($e['serial_number'] ?? '—')?></td><td>Rp <?=number_format((float)$e['daily_rate'],0,',','.')?></td><td><span class="badge <?=h($e['unit_status'] ?? 'retired')?>"><?=h(ucfirst($e['unit_status'] ?? 'Belum ada unit'))?></span></td></tr><?php endforeach;?><?php if(!$equipmentRows):?><tr><td colspan="6" class="empty">Belum ada peralatan.</td></tr><?php endif;?></tbody></table></div>
<?php else:?><div class="panel"><h1>Halaman tidak ditemukan</h1><a class="text-link" href="index.php">Kembali ke dashboard</a></div><?php endif;?>
</section></main></div></body></html>



