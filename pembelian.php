<?php require_once __DIR__ . '/inc/layout.php'; pageStart('Rekap Pembelian', 'pembelian'); ?>
<?php filterBar(); ?>
<section class="kpis" id="kpis"></section>
<div class="panel"><div class="tablewrap"><table id="t-rekap"></table></div></div>
<div class="panel kesimpulan" id="kesimpulan" hidden></div>
<?php pageEnd();