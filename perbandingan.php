<?php require_once __DIR__ . '/inc/layout.php'; pageStart('Perbandingan Penjualan', 'perbandingan'); ?>
<form class="filters" id="filters" onsubmit="return false">
  <label>Bandingkan
    <select id="c-mode"><option value="bulan">Antar bulan (bebas pilih)</option><option value="rentang">Rentang tanggal (bebas pilih)</option></select>
  </label>
  <label class="c-bulan">Cara membandingkan bulan
    <select id="c-cara">
      <option value="auto">Otomatis (bulan berjalan = H2H, selesai = full)</option>
      <option value="full">Full bulan</option>
      <option value="h2h">H2H sampai tanggal tertentu</option>
    </select>
  </label>
  <label class="c-bulan c-hari" hidden>Sampai tanggal <input type="number" id="c-hari" min="1" max="31" value="1"></label>
  <label>Kategori
    <select id="c-kat">
      <option value="emas">Emas (tanpa berlian, sama dengan halaman Penjualan)</option>
      <option value="semua">Emas + berlian</option>
      <option value="berlian">Berlian saja</option>
    </select>
  </label>
  <label>Sales
    <select id="c-tipe"><option value="semua">Semua sales</option><option value="toko">Sales toko</option><option value="luar">Sales luar toko</option></select>
  </label>
  <label>Diagram
    <select id="c-metric"><option value="rupiah">Rupiah</option><option value="gram">Gram</option><option value="pcs">Pcs</option></select>
  </label>
</form>
<div class="panel">
  <h2>Periode yang dibandingkan</h2>
  <div id="periods" class="periods"></div>
  <div class="prow-actions"><button type="button" class="btn sm" id="p-add">+ Tambah periode</button></div>
  <p class="sub" id="range-info"></p>
</div>
<div class="panel"><h2>Perbandingan penjualan</h2>
  <div class="kes-pick" id="kes-pick"></div>
  <div class="tablewrap"><table class="cmp" id="t-cmp"></table></div>
</div>
<div class="panel"><h2>Diagram per sales</h2><p class="sub" id="chart-sub"></p><div class="chart tall"><canvas id="c-cmp"></canvas></div></div>
<div class="panel kesimpulan" id="kesimpulan" hidden></div>
<?php pageEnd();