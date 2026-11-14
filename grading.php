<?php require_once __DIR__ . '/inc/layout.php'; pageStart('Grading Sales', 'grading'); ?>
<form class="filters" id="filters" onsubmit="return false">
  <label>Periode
    <select id="f-mode"><option value="bulan" selected>Bulanan</option><option value="triwulan">Triwulan (3 bulan)</option></select>
  </label>
  <label>Bulan <input type="month" id="f-bulan"></label>
  <label>Cabang <select id="f-cabang"><option value="">Semua cabang</option></select></label>
  <span class="range-info" id="range-info"></span>
</form>
<p class="note">Poin: perhiasan (selain KOTAK, KOTAK LM, SERTIFIKAT) = 1 poin/pcs. Dept LM (logam mulia) bertingkat sesuai berat: &lt;50g = 1, 50–99g = 2, ≥100g = 3 poin. Pembelian Trimas (Luar Jual Saja/Dolmas) = 1 poin/baris. Mode Triwulan dirinci per bulan. Target per grade diatur di <a href="master_target.php">Master Target</a>.</p>
<section class="kpis" id="kpis"></section>
<div class="panel">
  <div class="row between wrap">
    <h2>Rekap poin per sales</h2>
    <div class="row">
      <button class="btn" id="btn-xlsx" type="button">⬇ Excel</button>
      <button class="btn" id="btn-pdf" type="button">⬇ PDF</button>
    </div>
  </div>
  <div class="tablewrap tall-scroll"><table id="t-grading" class="dense"></table></div>
</div>
<div class="panel"><h2>Poin per sales</h2><div class="chart"><canvas id="c-grading"></canvas></div></div>
<div class="panel kesimpulan" id="kesimpulan" hidden></div>
<?php pageEnd(['xlsx.full.min.js', 'jspdf.umd.min.js', 'jspdf.plugin.autotable.min.js']);