<?php require_once __DIR__ . '/inc/layout.php'; pageStart('Tambah Barang', 'barang_tambah'); ?>
<div class="panel">
  <label class="drop" id="drop">
    <input type="file" id="files" accept=".csv,.xlsx,.xls,text/csv" multiple hidden>
    <strong>Pilih atau seret file CSV / Excel barang ke sini</strong>
    <span class="sub">File Excel (.xlsx/.xls) otomatis dikonversi di browser sebelum diunggah. Kode barcode jadi kunci unik — barang yang sudah ada tidak akan dobel. Spasi di depan data dibuang otomatis, dan pemisah kolom (koma / titik koma / tab) dikenali sendiri.</span>
  </label>
  <ul class="filelist" id="filelist"></ul>
  <div class="row"><button class="btn primary" id="btn-upload" disabled>Unggah dan proses</button><progress id="prog" max="100" value="0" hidden></progress></div>
</div>
<div class="panel" id="result-box" hidden><h2>Hasil</h2><div class="tablewrap"><table id="t-result"></table></div><div id="result-note"></div></div>
<div class="panel">
  <h2>Kolom yang dipakai</h2>
  <p class="sub">Dari file sumber Anda, kolom yang disimpan: <b>KODE BARCODE</b> (wajib, kunci unik), <b>KODE JENIS</b>, <b>KODE BAKI</b>, <b>WARNA</b>, <b>BERAT</b>, <b>TANGGAL INPUT</b>. Kolom lain (no seri, kode group, supplier, dsb.) diabaikan — tidak perlu dihapus dari file, sistem otomatis memilih kolom yang relevan berdasarkan nama header.</p>
  <h2>Aturan upload</h2>
  <p class="sub"><b>Tidak ada barang yang dihapus</b> saat upload: barang lama yang tidak ada di file baru tetap ada di database. Barang hanya keluar dari stok lewat <b>retur</b> atau saat barcode-nya <b>terjual</b> (ditemukan di data penjualan — dicek otomatis setiap upload barang maupun upload penjualan). Jika barang lama punya data yang masih kosong (mis. tanggal input) dan file baru mengisinya, data itu dilengkapi — data yang sudah terisi tidak ditimpa.</p>
  <h2>Aturan duplikat</h2>
  <p class="sub">Barang dicek dengan kolom <b>kode barcode</b>. Barcode yang sudah ada di database dilewati (tidak menambah data baru dan tidak menimpa), jadi mengunggah file yang sama dua kali aman.</p>
  <h2>Riwayat unggahan</h2>
  <div class="tablewrap"><table id="t-log"></table></div>
</div>
<?php pageEnd(['xlsx.full.min.js']);