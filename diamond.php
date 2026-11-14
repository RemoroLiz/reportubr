<?php require_once __DIR__ . '/inc/layout.php'; pageStart('Penjualan Diamond', 'diamond'); ?>
<?php filterBar(); ?>
<p class="note">Dept diamond: <?= h(implode(', ', DEPT_DIAMOND)) ?>.</p>
<section class="kpis" id="kpis"></section>
<div class="panel"><h2>Rekap per sales</h2><div class="tablewrap"><table id="t-rekap"></table></div></div>
<div class="panel"><h2>Rincian transaksi</h2><p class="sub" id="detail-info"></p><div class="tablewrap"><table id="t-detail"></table></div></div>
<div class="panel kesimpulan" id="kesimpulan" hidden></div>
<?php pageEnd();