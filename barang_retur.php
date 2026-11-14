<?php require_once __DIR__ . '/inc/layout.php'; pageStart('Retur Barang', 'barang_retur'); ?>
<p class="note">Scan atau ketik kode barcode lalu tekan Enter. Barang yang sudah berstatus retur, atau barcode yang tidak ditemukan, tidak akan ditambahkan. Simpan untuk mengurangi stok dan mencatat riwayat retur.</p>
<div class="panel">
  <div class="row scan-row">
    <label class="grow">Scan barcode <input type="text" id="scan-input" placeholder="Scan atau ketik barcode, lalu Enter" autocomplete="off"></label>
    <span class="sub" id="scan-info"></span>
  </div>
</div>

<div class="panel no-print">
  <h2>Pengaturan form</h2>
  <div class="row">
    <label>Tanggal <input type="date" id="rf-set-tanggal"></label>
    <label class="grow">Cabang <input type="text" id="rf-set-cabang" value="UJUNGBERUNG BANDUNG"></label>
  </div>
  <p class="sub">Isi nama &amp; jabatan langsung di kotak TTD pada pratinjau form di bawah (klik lalu ketik).</p>
</div>

<div class="panel">
  <div class="row between no-print"><h2>Pratinjau form retur</h2>
    <div class="row">
      <button class="btn" id="btn-print" type="button">🖨 Print</button>
      <button class="btn" id="btn-img" type="button">⬇ Download IMG</button>
      <button class="btn primary" id="btn-pdf" type="button">⬇ Download PDF</button>
    </div>
  </div>
  <div class="retur-form" id="print-form">
    <div class="rf-title">DBM BARANG CABANG <span contenteditable="true" id="rf-cabang-title">UJUNGBERUNG BANDUNG</span></div>
    <div class="rf-meta">
      <div><b>TANGGAL</b> : <span id="rf-tanggal-txt"></span></div>
      <div><b>CABANG</b> : <span id="rf-cabang-txt">UJUNGBERUNG BANDUNG</span></div>
    </div>
    <table class="rf-table">
      <thead><tr><th>KODE BAKI</th><th>KODE JENIS</th><th>PCS</th><th>BERAT</th><th>KETERANGAN</th><th>BULAN</th><th class="no-print"></th></tr></thead>
      <tbody id="rf-tbody"><tr><td colspan="7" class="empty">Belum ada barang yang discan.</td></tr></tbody>
      <tfoot><tr><td colspan="2"><b>TOTAL</b></td><td id="rf-total-pcs">0</td><td id="rf-total-gram">0</td><td colspan="2" id="rf-total-ket"></td><td class="no-print"></td></tr></tfoot>
    </table>
    <div class="rf-ttd-label">KOTAK TTD:</div>
    <div class="rf-ttd-row" id="rf-ttd-row"></div>
  </div>
  <div class="row no-print"><button class="btn primary" id="btn-save" type="button">Simpan Retur</button></div>
</div>

<div class="panel no-print">
  <h2>Riwayat retur</h2>
  <div class="tablewrap"><table id="t-retur-log"></table></div>
</div>
<?php pageEnd(['jspdf.umd.min.js', 'html2canvas.min.js']);