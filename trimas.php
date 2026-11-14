<?php require_once __DIR__ . '/inc/layout.php'; pageStart('Trimas', 'trimas'); ?>
<?php filterBar(false); ?>
<p class="note">Trimas menampilkan pembelian dengan kondisi <b>LUAR JUAL SAJA</b> dan <b>DOLMAS</b> — dipisah dari rekap pembelian biasa karena sifatnya berbeda (bukan buyback standar toko).</p>
<section class="kpis" id="kpis"></section>
<section class="grid2">
  <div class="panel"><h2>Per kondisi</h2><div class="tablewrap"><table id="t-kondisi"></table></div></div>
  <div class="panel"><h2>Komposisi</h2><div class="chart"><canvas id="c-kondisi"></canvas></div></div>
</section>
<div class="panel"><h2>Per sales</h2><div class="tablewrap"><table id="t-sales"></table></div></div>
<div class="panel"><h2>Tren harian</h2><div class="chart"><canvas id="c-harian"></canvas></div></div>
<div class="panel"><h2>Rincian transaksi</h2><p class="sub" id="detail-info"></p><div class="tablewrap"><table id="t-detail"></table></div></div>
<div class="panel kesimpulan" id="kesimpulan" hidden></div>
<?php pageEnd();