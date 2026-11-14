<?php require_once __DIR__ . '/inc/layout.php'; pageStart('Upload CSV', 'upload'); ?>
<div class="panel">
  <label class="drop" id="drop">
    <input type="file" id="files" accept=".csv,text/csv" multiple hidden>
    <strong>Pilih atau seret file CSV ke sini</strong>
    <span class="sub">Penjualan, pembelian, dan master sales bisa diunggah sekaligus. Jenis file dikenali otomatis dari kolomnya.</span>
  </label>
  <ul class="filelist" id="filelist"></ul>
  <div class="row"><button class="btn primary" id="btn-upload" disabled>Unggah dan proses</button><progress id="prog" max="100" value="0" hidden></progress></div>
</div>
<div class="panel" id="result-box" hidden><h2>Hasil</h2><div class="tablewrap"><table id="t-result"></table></div></div>
<div class="panel">
  <h2>Aturan duplikat</h2>
  <p class="sub">Penjualan dicek dengan kolom <b>no fakturjual</b>, pembelian dengan <b>no_fakturbeli</b>. Baris yang nomor fakturnya sudah ada di database dilewati (tidak menambah data baru), jadi mengunggah file yang sama dua kali aman. Khusus pembelian, kolom <b>kondisi</b> (dipakai halaman Trimas) tetap diperbarui walau fakturnya sudah ada — jadi bisa upload ulang file lama untuk mengisi kondisi tanpa menduplikat data. Master sales dicek dengan kode sales: nama dan fee diperbarui, jenis toko/luar tetap.</p>
  <h2>Riwayat unggahan</h2>
  <div class="tablewrap"><table id="t-log"></table></div>
</div>
<?php pageEnd();