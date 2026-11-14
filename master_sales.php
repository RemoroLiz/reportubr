<?php require_once __DIR__ . '/inc/layout.php'; pageStart('Master Sales', 'master_sales'); ?>
<p class="note">Sales toko = sales terdaftar milik toko. Sales luar toko = tidak terdaftar di master, atau ditandai luar toko. Filter "Sales" di semua laporan mengikuti pengaturan ini. Grade dipakai untuk urutan tampil dan sebagai acuan target poin di halaman <a href="grading.php">Grading Sales</a> (target per grade diatur di <a href="master_target.php">Master Target</a>).</p>
<section class="grid2 ms">
  <div class="panel">
    <h2 id="form-title">Tambah / ubah sales</h2>
    <form id="sales-form" class="stack">
      <label>Kode sales <input name="kode" required maxlength="20"></label>
      <label>Nama sales <input name="nama" required maxlength="100"></label>
      <label>Nama di laporan forecast <input name="nama_laporan" maxlength="60" placeholder="Kosongkan = otomatis dari nama"></label>
      <label>Fee <input name="fee" type="number" step="any" value="0"></label>
      <label>Jenis
        <select name="tipe"><option value="toko">Sales toko</option><option value="luar">Sales luar toko</option></select>
      </label>
      <label>Cabang <input name="cabang" maxlength="20" placeholder="cth. CWK, UBR"></label>
      <label>Grade
        <select name="grade"><option value="">– pilih grade –</option><option>EXECUTIVE</option><option>SENIOR</option><option>JUNIOR</option><option>ENTREE</option><option>TRAINING</option></select>
      </label>
      <label>Target pcs / bulan <input name="target_pcs" type="number" min="0" step="1" value="0"></label>
      <div class="row"><button class="btn primary" type="submit">Simpan sales</button><button class="btn" type="reset" id="btn-reset">Kosongkan</button></div>
    </form>
    <div class="panel sub-panel">
      <h2>Target emas bulanan</h2>
      <p class="sub">Dipakai sebagai pembanding di halaman Sales Toko Harian. Jika bulan belum diisi, memakai target bulan terakhir yang tersedia.</p>
      <form id="target-form" class="row">
        <label>Bulan <input type="month" name="bulan" required></label>
        <label>Target gram <input type="number" step="any" min="0" name="target_gram" required></label>
        <button class="btn primary" type="submit">Simpan target</button>
      </form>
    </div>
    <div id="unreg-box" hidden>
      <h2>Kode belum terdaftar</h2>
      <p class="sub">Muncul di transaksi tetapi belum ada di master, sehingga dihitung sebagai sales luar toko.</p>
      <div id="unreg" class="chips"></div>
    </div>
  </div>
  <div class="panel">
    <div class="row between"><h2>Daftar sales</h2><input type="search" id="ms-q" placeholder="Cari kode / nama"></div>
    <div class="tablewrap tall-scroll"><table id="t-master"></table></div>
  </div>
</section>
<?php pageEnd();