<?php require_once __DIR__ . '/inc/layout.php'; pageStart('Rekap Penjualan', 'penjualan'); ?>
<?php filterBar(); ?>
<p class="note">Pcs dihitung per barang terjual, tidak termasuk dept KOTAK, KOTAK LM, SERTIFIKAT, dan diamond. Penjualan diamond ada di <a href="diamond.php">halaman terpisah</a>.</p>
<section class="kpis" id="kpis"></section>
<div class="panel"><div class="tablewrap"><table id="t-rekap"></table></div></div>
<div class="panel" id="jenis-panel">
  <h2>Statistik kode jenis terjual</h2>
  <p class="sub">Dipisah menurut kelompok MD, MT, dan Diamond (kode jenis = kode dept). Mengikuti filter periode dan jenis sales di atas. Diamond tetap tampil di sini walau rekap per sales di atas tidak memasukkannya.</p>
  <section class="kpis" id="jenis-kpis"></section>
  <div class="stock-groups" id="jenis-groups"></div>
</div>
<div class="panel kesimpulan" id="kesimpulan" hidden></div>
<?php pageEnd();