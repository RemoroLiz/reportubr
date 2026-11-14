<?php require_once __DIR__ . '/inc/layout.php'; pageStart('Sales Toko Harian', 'sales_harian'); ?>
<form class="filters" id="filters" onsubmit="return false">
  <label>Bulan <input type="month" id="f-bulan"></label>
  <label>Cabang <select id="f-cabang"><option value="">Semua cabang</option></select></label>
  <label>Hitung s.d. tanggal <select id="f-hb"><option value="0">Otomatis (data terakhir)</option></select></label>
  <span class="range-info" id="range-info"></span>
</form>
<p class="note">Forecast = total sejauh ini ÷ hari berjalan × jumlah hari sebulan. Pcs dan gram tidak termasuk dept KOTAK, KOTAK LM, SERTIFIKAT; diamond direkap terpisah di bawah. Grade, target pcs, dan cabang diatur lewat <a href="master_sales.php">Master Sales</a>.</p>
<section class="kpis" id="kpis"></section>
<div class="panel">
  <div class="row between wrap">
    <h2>Rekap harian per sales</h2>
    <div class="row tampil-bar">
      <label class="inline"><input type="checkbox" id="f-gram" checked> Tampilkan gram</label>
      <label>Tampilkan
        <select id="f-tampil"><option value="semua">Semua tanggal</option><option value="rentang">Rentang tanggal</option><option value="ringkas">Ringkasan saja</option></select>
      </label>
      <label class="f-rentang" hidden>Dari <select id="f-dari"></select></label>
      <label class="f-rentang" hidden>Sampai <select id="f-sampai"></select></label>
    </div>
  </div>
  <div class="tablewrap tall-scroll"><table id="t-harian" class="dense"></table></div>
</div>
<div class="panel"><h2>Perbandingan dengan bulan lalu</h2><section class="kpis" id="kpis-prev"></section></div>
<div class="panel">
  <h2>Penjualan diamond</h2><p class="sub">Pcs dan nominal diamond per tanggal, di luar rekap dan target pcs di atas.</p>
  <div class="tablewrap tall-scroll"><table id="t-diamond" class="dense"></table></div>
</div>
<div class="panel kesimpulan" id="kesimpulan" hidden></div>
<?php pageEnd();