<?php require_once __DIR__ . '/inc/layout.php'; pageStart('Detail Barang', 'barang_detail'); ?>
<form class="filters" id="filters" onsubmit="return false">
  <label>Dari tanggal input <input type="date" id="f-from"></label>
  <label>Sampai <input type="date" id="f-to"></label>
  <label>Kode jenis <select id="f-jenis"><option value="">Semua</option></select></label>
  <label>Kode baki <select id="f-baki"><option value="">Semua</option></select></label>
  <label>Status <select id="f-status"><option value="stok" selected>Stok</option><option value="">Semua</option><option value="terjual">Sudah terjual</option><option value="retur">Sudah retur</option></select></label>
  <label>Cari <input type="search" id="f-q" placeholder="Barcode / warna"></label>
  <label class="inline"><input type="checkbox" id="f-tglkosong"> Tanggal input kosong</label>
  <button class="btn" id="btn-sync" type="button" title="Cocokkan barcode stok dengan data penjualan">⟳ Sinkronkan dengan penjualan</button>
</form>
<p class="note">Barang keluar dari stok jika sudah <b>diretur</b> atau <b>terjual</b> (kode barcode-nya ditemukan di data penjualan yang tidak dibatalkan). Datanya tidak dihapus permanen, hanya diberi status — jadi upload ulang file barang lama tidak memunculkannya kembali sebagai stok. Daftar secara bawaan hanya menampilkan stok; ubah filter Status untuk melihat yang terjual/retur. Klik header kolom untuk mengurutkan, termasuk tanggal input.</p>
<section class="kpis" id="kpis"></section>
<div class="panel">
  <h2>Rekap stok per kode jenis</h2>
  <div class="stock-groups" id="stock-groups"></div>
</div>
<div class="panel">
  <div class="row between"><h2>Daftar barang</h2><span class="sub" id="page-info"></span></div>
  <div class="tablewrap tall-scroll"><table id="t-barang" class="dense"></table></div>
  <div class="row" id="pager"></div>
</div>
<div class="panel kesimpulan" id="kesimpulan" hidden></div>
<?php pageEnd();