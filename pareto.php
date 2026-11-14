<?php require_once __DIR__ . '/inc/layout.php'; pageStart('Pareto Customer', 'pareto');
filterBar(false, '
  <label>Data <select id="f-sumber"><option value="penjualan">Penjualan</option><option value="pembelian">Pembelian</option></select></label>
  <label>Urutkan <select id="f-urut"><option value="trx">Transaksi terbanyak</option><option value="rupiah">Rupiah terbesar</option><option value="gram">Gramasi terbesar</option><option value="pcs">Pcs terbanyak</option></select></label>
  <label>Tampilkan <select id="f-limit"><option value="10">10 teratas</option><option value="20">20 teratas</option><option value="50" selected>50 teratas</option><option value="100">100 teratas</option><option value="0">Semua</option></select></label>
  <label>Cari <input type="search" id="f-q" placeholder="Nama / no HP / member"></label>'); ?>
<p class="note">Customer dibedakan dari kode member; jika non-member, dari nomor HP; jika tidak ada HP, dari nama. Satu transaksi = satu nota. Kelas A = customer yang bersama-sama membentuk 80% pertama.<br>
Kelompok berdasarkan kode dept &mdash; <b>MD</b>: AN, BD, CN, GA, GK, GR, KL &middot; <b>MT</b>: TA, TB, TC, TGA, TGK, TGR, TK &middot; <b>LM</b>: LM &middot; <b>LS</b>: LS &middot; <b>BERLIAN</b>: BA, BB, BC, BCK, BGK, BGR, BK &middot; <b>OTHERS</b>: semua kode dept lainnya.
Pcs = jumlah barang (baris; KOTAK, KOTAK LM dan SERTIFIKAT tidak dihitung pcs &amp; gram pada penjualan), Total = jumlah semua kelompok.</p>
<p class="note warn" id="excl-note" hidden></p>
<section class="kpis" id="kpis"></section>
<div class="panel"><h2>Kurva pareto</h2><p class="sub" id="chart-sub"></p><div class="chart tall"><canvas id="c-pareto"></canvas></div></div>
<div class="panel"><div class="tablewrap"><table class="cmp" id="t-pareto"></table></div></div>
<div class="panel kesimpulan" id="kesimpulan" hidden></div>
<?php pageEnd();