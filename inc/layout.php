<?php
require_once __DIR__ . '/auth.php';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function pageStart(string $title, string $key): array {
    $user = requireLogin();
    // Menu dikelompokkan; grup yang berisi halaman aktif otomatis terbuka. Grup tanpa judul tampil sebagai tautan biasa.
    $groups = [
        ['', [['index', 'Dashboard', 'index.php']]],
        ['Laporan', [['penjualan', 'Penjualan', 'penjualan.php'], ['diamond', 'Penjualan Diamond', 'diamond.php'], ['pembelian', 'Pembelian', 'pembelian.php'],
                     ['trimas', 'Trimas', 'trimas.php'], ['perbandingan', 'Perbandingan', 'perbandingan.php'], ['pareto', 'Pareto Customer', 'pareto.php']]],
        ['Sales', [['sales_harian', 'Sales Toko Harian', 'sales_harian.php'], ['sales_mingguan', 'Sales Toko Mingguan', 'sales_mingguan.php'],
                   ['grading', 'Grading Sales', 'grading.php']]],
        ['Barang', [['barang_detail', 'Detail Barang', 'barang_detail.php'], ['barang_retur', 'Retur', 'barang_retur.php'],
                    ['barang_tambah', 'Tambah Barang', 'barang_tambah.php']]],
        ['Pengaturan &amp; Data', [['master_sales', 'Master Sales', 'master_sales.php'], ['master_target', 'Master Target', 'master_target.php'],
                                   ['upload', 'Upload CSV', 'upload.php']]],
    ];
    ?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= h(csrfToken()) ?>">
<title><?= h($title) ?> · <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body data-page="<?= h($key) ?>">
<input type="checkbox" id="navtoggle" class="navtoggle" hidden>
<aside class="side">
  <label for="navtoggle" class="closenav" aria-label="Tutup menu">✕ Tutup</label>
  <div class="brand"><span class="mark">Au</span><span><?= h(APP_NAME) ?></span></div>
  <nav>
    <?php foreach ($groups as $gi => [$gname, $items]):
        $active = false; foreach ($items as $it) if ($it[0] === $key) $active = true;
        if ($gname === ''): foreach ($items as [$k, $label, $href]): ?>
      <a href="<?= $href ?>" class="<?= $k === $key ? 'on' : '' ?>"><?= h($label) ?></a>
    <?php endforeach; else: ?>
      <details class="navgrp" data-g="<?= $gi ?>" <?= $active ? 'open' : '' ?>>
        <summary><?= $gname ?></summary>
        <?php foreach ($items as [$k, $label, $href]): ?>
          <a href="<?= $href ?>" class="<?= $k === $key ? 'on' : '' ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
      </details>
    <?php endif; endforeach; ?>
  </nav>
  <script>
  // ingat grup menu yang dibuka/ditutup pengguna (grup halaman aktif selalu terbuka)
  (function(){ try {
    var saved = JSON.parse(localStorage.getItem('navopen') || '[]');
    document.querySelectorAll('.navgrp').forEach(function(d){
      if (saved.indexOf(d.dataset.g) > -1) d.open = true;
      d.addEventListener('toggle', function(){
        var cur = JSON.parse(localStorage.getItem('navopen') || '[]').filter(function(x){ return x !== d.dataset.g; });
        if (d.open) cur.push(d.dataset.g);
        localStorage.setItem('navopen', JSON.stringify(cur));
      });
    });
  } catch(e){} })();
  </script>
  <div class="who"><span><?= h($user['nama'] ?: $user['username']) ?></span><a href="logout.php">Keluar</a></div>
</aside>
<label for="navtoggle" class="backdrop" aria-hidden="true"></label>
<main class="main">
  <header class="top">
    <label for="navtoggle" class="burger" aria-label="Buka menu">☰ Menu</label>
    <h1><?= h($title) ?></h1>
  </header>
<?php
    return $user;
}

/** $extra: nama file tambahan di assets/js/vendor/ yang dimuat sebelum app.js (mis. untuk export PDF/Excel di satu halaman saja). */
function pageEnd(array $extra = []): void { ?>
</main>
<div id="toast" role="status" aria-live="polite"></div>
<script src="assets/js/vendor/chart.umd.js"></script>
<?php foreach ($extra as $f): ?><script src="assets/js/vendor/<?= h($f) ?>"></script><?php endforeach; ?>
<script src="assets/js/app.js"></script>
</body>
</html>
<?php }

/** Bar filter periode + tipe sales (dipakai lewat JS). */
function filterBar(bool $showTipe = true, string $extra = ''): void { ?>
<form class="filters" id="filters" onsubmit="return false">
  <label>Periode
    <select id="f-mode">
      <option value="hari">Harian</option>
      <option value="minggu">Mingguan</option>
      <option value="bulan" selected>Bulanan</option>
      <option value="triwulan">Triwulan</option>
      <option value="custom">Rentang bebas</option>
    </select>
  </label>
  <label class="f-single">Tanggal acuan <input type="date" id="f-date"></label>
  <label class="f-range">Dari <input type="date" id="f-from"></label>
  <label class="f-range">Sampai <input type="date" id="f-to"></label>
  <?php if ($showTipe): ?>
  <label>Sales
    <select id="f-tipe">
      <option value="semua">Semua sales</option>
      <option value="toko">Sales toko</option>
      <option value="luar">Sales luar toko</option>
    </select>
  </label>
  <?php endif; ?>
  <?= $extra ?>
  <span class="range-info" id="range-info"></span>
</form>
<?php }