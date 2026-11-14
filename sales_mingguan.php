<?php require_once __DIR__ . '/inc/layout.php'; pageStart('Sales Toko Mingguan', 'sales_mingguan'); ?>
<form class="filters" id="filters" onsubmit="return false">
  <label>Bulan <input type="month" id="f-bulan"></label>
  <label>Cabang <select id="f-cabang"><option value="">Semua cabang</option></select></label>
  <label>Hitung s.d. tanggal <select id="f-hb"><option value="0">Otomatis (data terakhir)</option></select></label>
  <span class="range-info" id="range-info"></span>
</form>
<p class="note">Minggu 1 mulai tanggal 1; setiap minggu Senin–Minggu, terpotong di akhir bulan. Forecast dan perbandingan memakai bulan sebelumnya penuh.</p>
<section class="kpis" id="kpis"></section>
<div class="panel">
  <div class="row between wrap">
    <h2>Rekap per minggu</h2>
    <div class="row tampil-bar">
      <label>Tampilkan
        <select id="f-tampil"><option value="semua">Semua minggu</option><option value="rentang">Rentang minggu</option><option value="ringkas">Ringkasan saja</option></select>
      </label>
      <label class="f-rentang" hidden>Dari <select id="f-dari"></select></label>
      <label class="f-rentang" hidden>Sampai <select id="f-sampai"></select></label>
    </div>
  </div>
  <div class="tablewrap tall-scroll"><table id="t-mingguan" class="dense"></table></div>
</div>
<section class="grid2">
  <div class="panel"><h2>Forecast vs bulan lalu</h2><p class="sub">Pcs, gramasi, dan rupiah per minggu</p><div class="chart"><canvas id="c-forecast"></canvas></div></div>
  <div class="panel"><h2>Rupiah per sales</h2><p class="sub">Bulan berjalan, diurutkan dari yang tertinggi</p><div class="chart"><canvas id="c-sales"></canvas></div></div>
</section>
<div class="panel kesimpulan" id="kesimpulan" hidden></div>
<?php pageEnd();