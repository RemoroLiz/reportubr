<?php require_once __DIR__ . '/inc/layout.php'; pageStart('Dashboard', 'index'); ?>
<p class="lead" id="dash-period">Memuat data bulan ini…</p>
<section class="kpis" id="kpis"></section>
<section class="grid2">
  <div class="panel"><h2>Penjualan bulan ini</h2><p class="sub">Rupiah per hari (batang) dan pcs (garis)</p><div class="chart"><canvas id="c-jual"></canvas></div></div>
  <div class="panel"><h2>Pembelian bulan ini</h2><p class="sub">Pcs per hari</p><div class="chart"><canvas id="c-beli"></canvas></div></div>
</section>
<section class="grid2">
  <div class="panel"><h2>Transaksi customer bulan ini</h2><p class="sub">Jumlah nota dan customer unik per hari</p><div class="chart"><canvas id="c-trx"></canvas></div></div>
  <div class="panel"><h2>Customer paling sering bertransaksi</h2><p class="sub">5 teratas bulan ini</p>
    <div class="tablewrap"><table id="t-top"><thead><tr><th>Customer</th><th class="n">Transaksi</th><th class="n">Rupiah</th></tr></thead><tbody></tbody></table></div>
    <p class="sub"><a href="pareto.php">Lihat analisis pareto lengkap</a></p>
  </div>
</section>
<div class="panel kesimpulan" id="kesimpulan" hidden></div>
<?php pageEnd();