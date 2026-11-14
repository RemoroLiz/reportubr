<?php
// Laporan sales toko (harian & mingguan) + forecast. Konstanta di bawah bisa diubah tanpa menyentuh config.php.
require_once __DIR__ . '/lib.php';

if (!defined('GRADES')) define('GRADES', ['EXECUTIVE', 'SENIOR', 'JUNIOR', 'ENTREE', 'TRAINING']);   // urutan tampil, tertinggi dulu
if (!defined('CABANG_LIST')) define('CABANG_LIST', ['CWK', 'UBR']);                                     // akhiran nama sales = cabang

/** Menambah kolom/tabel baru bila belum ada (aman dijalankan berulang, kompatibel MySQL & MariaDB). */
function ensureForecastSchema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $cols = array_column($pdo->query("SHOW COLUMNS FROM master_sales")->fetchAll(), 'Field');
    $defs = ['grade' => "VARCHAR(30) NOT NULL DEFAULT ''", 'target_pcs' => 'INT NOT NULL DEFAULT 0',
             'cabang' => "VARCHAR(20) NOT NULL DEFAULT ''", 'nama_laporan' => "VARCHAR(60) NOT NULL DEFAULT ''"];
    $add = [];
    foreach ($defs as $c => $d) if (!in_array($c, $cols, true)) $add[] = "ADD COLUMN $c $d";
    if ($add) {
        $pdo->exec('ALTER TABLE master_sales ' . implode(', ', $add));
        if (!in_array('cabang', $cols, true)) {   // isi cabang otomatis dari akhiran nama (mis. "MILA CWK")
            $st = $pdo->prepare("UPDATE master_sales SET cabang = ? WHERE cabang = '' AND UPPER(TRIM(nama_sales)) LIKE ?");
            foreach (CABANG_LIST as $c) $st->execute([$c, '% ' . $c]);
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS target_bulanan (
        bulan CHAR(7) NOT NULL PRIMARY KEY, target_gram DECIMAL(12,2) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pcols = array_column($pdo->query("SHOW COLUMNS FROM pembelian")->fetchAll(), 'Field');
    if (!in_array('kondisi', $pcols, true)) {
        $pdo->exec("ALTER TABLE pembelian ADD COLUMN kondisi VARCHAR(50) NOT NULL DEFAULT ''");
    }
    $jcols = array_column($pdo->query("SHOW COLUMNS FROM penjualan")->fetchAll(), 'Field');
    if (!in_array('kode_barcode', $jcols, true)) {
        // dipakai mencocokkan penjualan dengan stok barang (barang yang barcode-nya sudah terjual ditandai terjual)
        $pdo->exec("ALTER TABLE penjualan ADD COLUMN kode_barcode VARCHAR(50) NOT NULL DEFAULT '', ADD KEY idx_barcode (kode_barcode)");
    }
    $isNew = !in_array('grade_target', $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN), true);
    $pdo->exec("CREATE TABLE IF NOT EXISTS grade_target (
        grade VARCHAR(30) NOT NULL PRIMARY KEY, target_point INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($isNew) {
        $def = ['TRAINING' => 100, 'ENTREE' => 200, 'JUNIOR' => 300, 'SENIOR' => 400, 'EXECUTIVE' => 500];
        $ins = $pdo->prepare("INSERT IGNORE INTO grade_target (grade, target_point) VALUES (?,?)");
        foreach ($def as $g => $t) $ins->execute([$g, $t]);
    }
}

// Kondisi pembelian yang masuk halaman Trimas (lihat trimas() di bawah).
if (!defined('TRIMAS_KONDISI')) define('TRIMAS_KONDISI', ['LUAR JUAL SAJA', 'DOLMAS']);

/** Nama yang tampil di laporan: override > "ONLINE" (semua sales online digabung) > nama tanpa akhiran cabang. */
function laporanName(string $nama, string $override): string {
    if (trim($override) !== '') return strtoupper(trim($override));
    $n = strtoupper(trim($nama));
    if (preg_match('/\bONLINE\b/', $n)) return 'ONLINE';
    foreach (CABANG_LIST as $c) if (preg_match('/\s' . preg_quote($c, '/') . '$/', $n)) return trim(substr($n, 0, -strlen($c) - 1));
    return $n;
}

function gradeRank(string $g, string $nama): int {
    if ($nama === 'Lainnya') return 100;   // sales bukan toko, digabung, selalu paling bawah
    if ($nama === 'ONLINE') return 99;
    $i = array_search(strtoupper(trim($g)), GRADES, true);
    return $i !== false ? $i : ($g === '' ? 60 : 50);
}

/** Minggu Senin-Minggu yang dipotong batas bulan (Minggu 1 = tgl 1 sampai Minggu pertama). */
function monthWeeks(string $first, string $last): array {
    $w = [];
    $s = strtotime($first); $end = strtotime($last);
    while ($s <= $end) {
        $sun = date('N', $s) == 7 ? $s : strtotime('next sunday', $s);
        $e = min($sun, $end);
        $w[] = ['no' => count($w) + 1, 'from' => date('Y-m-d', $s), 'to' => date('Y-m-d', $e)];
        $s = strtotime('+1 day', $e);
    }
    return $w;
}

function validMonth(?string $m): string {
    return ($m && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) ? $m : date('Y-m');
}

/**
 * Kumpulkan data per sales (nama laporan) untuk rentang tanggal.
 * $bucket: 'day' => indeks 1..31 (tanggal) | 'week' => indeks 1..n (sesuai $weeks)
 * Tiap bucket: [pcs, gram, rupiah, diamond_pcs, diamond_nominal]. Diamond tidak masuk pcs/gram/rupiah biasa.
 */
function loadSales(string $from, string $to, string $tipe, string $cabang, string $bucket, array $weeks = []): array {
    ensureForecastSchema();
    $master = [];
    foreach (db()->query("SELECT * FROM master_sales")->fetchAll() as $m) $master[$m['kode_sales']] = $m;

    $cabangOk = function (?array $m) use ($cabang): bool {
        if ($m && $cabang !== '' && $m['cabang'] !== '' && strcasecmp($m['cabang'], $cabang) !== 0
            && laporanName($m['nama_sales'], $m['nama_laporan']) !== 'ONLINE') return false;
        return true;
    };
    $ok = function (?array $m) use ($tipe, $cabangOk): bool {
        if ($tipe === 'toko' && !($m && $m['tipe'] === 'toko')) return false;
        if ($tipe === 'luar' && $m && $m['tipe'] === 'toko') return false;
        return $cabangOk($m);
    };

    $ents = [];
    $targetDihitung = [];   // kode sales yang targetnya sudah dijumlahkan (target hanya boleh dihitung SEKALI per sales)
    $get = function (string $name, ?array $m) use (&$ents, &$targetDihitung, $bucket, $weeks) {
        if (!isset($ents[$name])) {
            $n = $bucket === 'day' ? 31 : count($weeks);
            $ents[$name] = ['nama' => $name, 'grade' => '', 'target_pcs' => 0, 'cabang' => '', 'b' => array_fill(1, max($n, 1), [0, 0.0, 0.0, 0, 0.0])];
        }
        if ($m) {
            if ($ents[$name]['grade'] === '' && $m['grade'] !== '') $ents[$name]['grade'] = strtoupper($m['grade']);
            // Sebelumnya baris ini ikut dijumlahkan setiap kali ada transaksi di satu hari -> target membengkak.
            // Sekarang tiap sales (kode) hanya menyumbang targetnya sekali; sales yang digabung (mis. ONLINE) tetap dijumlah.
            if (!isset($targetDihitung[$m['kode_sales']])) { $targetDihitung[$m['kode_sales']] = true; $ents[$name]['target_pcs'] += (int)$m['target_pcs']; }
            if ($ents[$name]['cabang'] === '') $ents[$name]['cabang'] = $m['cabang'];
        }
        return $name;
    };
    foreach ($master as $m) if ($ok($m)) $get(laporanName($m['nama_sales'], $m['nama_laporan']), $m);

    $dia = sqlIn(DEPT_DIAMOND);
    $nonpcs = sqlIn(DEPT_NON_PCS);
    $st = db()->prepare("SELECT p.kode_sales, p.tgl,
        SUM(" . pcsExpr('penjualan') . ") pcs,
        SUM(CASE WHEN p.kode_dept IN $dia THEN 0 WHEN p.kode_dept IN $nonpcs THEN 0 ELSE p.berat END) gram,
        SUM(CASE WHEN p.kode_dept IN $dia THEN 0 ELSE p.harga_total END) rp,
        SUM(CASE WHEN p.kode_dept IN $dia THEN 1 ELSE 0 END) dpcs,
        SUM(CASE WHEN p.kode_dept IN $dia THEN p.harga_total ELSE 0 END) drp
        FROM penjualan p WHERE p.tgl BETWEEN ? AND ? AND p.batal = 0 GROUP BY p.kode_sales, p.tgl");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $r) {
        $m = $master[$r['kode_sales']] ?? null;
        if ($ok($m)) {
            $name = $m ? laporanName($m['nama_sales'], $m['nama_laporan']) : 'KODE ' . $r['kode_sales'];
            $get($name, $m);
        } elseif ($tipe === 'toko' && $cabangOk($m)) {
            // Bukan sales toko: tidak disembunyikan, tapi digabung jadi satu baris "Lainnya" (tanpa target/grade).
            $name = 'Lainnya';
            $get($name, null);
        } else {
            continue;
        }
        if ($bucket === 'day') $i = (int)substr($r['tgl'], 8, 2);
        else { $i = 0; foreach ($weeks as $w) if ($r['tgl'] >= $w['from'] && $r['tgl'] <= $w['to']) { $i = $w['no']; break; } }
        if (!$i) continue;
        $b = &$ents[$name]['b'][$i];
        $b[0] += (int)$r['pcs']; $b[1] += (float)$r['gram']; $b[2] += (float)$r['rp']; $b[3] += (int)$r['dpcs']; $b[4] += (float)$r['drp'];
        unset($b);
    }

    // tampilkan hanya sales yang punya transaksi bulan ini atau sudah diberi grade di master
    $out = [];
    foreach ($ents as $e) {
        $sum = 0; foreach ($e['b'] as $b) $sum += $b[0] + $b[1] + $b[2] + $b[3] + $b[4];
        if ($sum > 0 || $e['grade'] !== '') $out[] = $e;
    }
    usort($out, fn($a, $b) => [gradeRank($a['grade'], $a['nama']), $a['nama']] <=> [gradeRank($b['grade'], $b['nama']), $b['nama']]);
    return $out;
}

function sumBuckets(array $e): array {
    $t = [0, 0.0, 0.0, 0, 0.0];
    foreach ($e['b'] as $b) foreach ($b as $k => $v) $t[$k] += $v;
    return $t;
}

function monthInfo(string $bulan): array {
    $first = $bulan . '-01';
    return ['first' => $first, 'last' => date('Y-m-t', strtotime($first)), 'hari' => (int)date('t', strtotime($first))];
}

/** Hari berjalan: override user, atau tanggal transaksi terakhir pada bulan tsb (seperti pembagi 11 / 10 di file Excel). */
function hariBerjalan(array $mi, ?int $override): array {
    $st = db()->prepare("SELECT MAX(tgl) FROM penjualan WHERE tgl BETWEEN ? AND ?");
    $st->execute([$mi['first'], $mi['last']]);
    $last = $st->fetchColumn();
    $auto = $last ? (int)substr($last, 8, 2) : 0;
    $hb = ($override && $override >= 1 && $override <= $mi['hari']) ? $override : $auto;
    return ['hb' => $hb, 'auto' => $auto, 'manual' => $hb !== $auto, 'last' => $last ?: null];
}

function fc(float $total, int $hb, int $hari): ?float {
    return $hb > 0 ? $total / $hb * $hari : null;
}

/** Total & rincian per-sales bulan sebelumnya, untuk perbandingan/selisih. */
function prevMonthAgg(string $bulan, string $tipe, string $cabang): array {
    $prevFirst = date('Y-m-01', strtotime($bulan . '-01 -1 month'));
    $pm = monthInfo(substr($prevFirst, 0, 7));
    $tot = [0, 0.0, 0.0]; $per = [];
    foreach (loadSales($pm['first'], $pm['last'], $tipe, $cabang, 'day') as $e) {
        $s = sumBuckets($e);
        $per[$e['nama']] = [(int)$s[0], round($s[1], 2), (float)$s[2]];
        $tot[0] += $s[0]; $tot[1] += $s[1]; $tot[2] += $s[2];
    }
    return ['bulan' => substr($prevFirst, 0, 7), 'total' => [(int)$tot[0], round($tot[1], 2), round($tot[2], 2)], 'perSales' => $per];
}

/** Persentase selisih $cur terhadap $prev (null jika bulan lalu 0, supaya tidak dibagi nol / "baru"). */
function pctVs(?float $cur, float $prev): ?float {
    return ($cur !== null && $prev > 0) ? round(($cur - $prev) / $prev * 100, 1) : null;
}

function targetEmas(string $bulan): array {
    $st = db()->prepare("SELECT target_gram FROM target_bulanan WHERE bulan = ?");
    $st->execute([$bulan]);
    $v = $st->fetchColumn();
    if ($v !== false) return ['gram' => (float)$v, 'inherited' => false];
    $st = db()->prepare("SELECT target_gram FROM target_bulanan WHERE bulan < ? ORDER BY bulan DESC LIMIT 1");
    $st->execute([$bulan]);
    $v = $st->fetchColumn();
    return ['gram' => $v !== false ? (float)$v : 0.0, 'inherited' => $v !== false];
}

/* ---------- Halaman harian ---------- */
function forecastHarian(string $bulan, string $tipe, string $cabang, ?int $hbOverride): array {
    $mi = monthInfo($bulan); $n = $mi['hari'];
    $hb = hariBerjalan($mi, $hbOverride);
    $ents = loadSales($mi['first'], $mi['last'], $tipe, $cabang, 'day');
    $rows = []; $diam = [];
    $tot = ['d' => array_fill(0, $n, [0, 0.0]), 'tp' => 0, 'tg' => 0.0, 'tr' => 0.0, 'target' => 0, 'fp' => 0.0];
    $dtot = ['d' => array_fill(0, $n, [0, 0.0]), 'tp' => 0, 'tn' => 0.0];
    foreach ($ents as $e) {
        $d = []; $dd = [];
        for ($i = 1; $i <= $n; $i++) {
            $b = $e['b'][$i];
            $d[] = [$b[0], round($b[1], 3)]; $dd[] = [$b[3], $b[4]];
            $tot['d'][$i - 1][0] += $b[0]; $tot['d'][$i - 1][1] += $b[1];
            $dtot['d'][$i - 1][0] += $b[3]; $dtot['d'][$i - 1][1] += $b[4];
        }
        $s = sumBuckets($e);
        $fp = fc($s[0], $hb['hb'], $n);
        $rows[] = ['nama' => $e['nama'], 'grade' => $e['grade'], 'cabang' => $e['cabang'], 'd' => $d,
                   'tp' => $s[0], 'tg' => round($s[1], 2), 'tr' => round($s[2], 2),
                   'target' => $e['target_pcs'], 'fp' => $fp === null ? null : round($fp, 1),
                   'pct' => ($fp !== null && $e['target_pcs'] > 0) ? round($fp / $e['target_pcs'] * 100, 1) : null];
        $diam[] = ['nama' => $e['nama'], 'd' => $dd, 'tp' => $s[3], 'tn' => $s[4]];
        $tot['tp'] += $s[0]; $tot['tg'] += $s[1]; $tot['tr'] += $s[2]; $tot['target'] += $e['target_pcs']; $dtot['tp'] += $s[3]; $dtot['tn'] += $s[4];
    }
    foreach ($tot['d'] as &$x) $x[1] = round($x[1], 3); unset($x);
    $tot['tg'] = round($tot['tg'], 2); $tot['tr'] = round($tot['tr'], 2);
    $fpTot = fc($tot['tp'], $hb['hb'], $n);
    $tot['fp'] = $fpTot === null ? null : round($fpTot, 1);
    $tot['pct'] = ($fpTot !== null && $tot['target'] > 0) ? round($fpTot / $tot['target'] * 100, 1) : null;
    $tg = targetEmas($bulan);
    $fg = fc($tot['tg'], $hb['hb'], $n);
    $fr = fc($tot['tr'], $hb['hb'], $n);

    // Perbandingan & selisih dengan bulan lalu (pcs, gram, rupiah — total keseluruhan)
    $prevAgg = prevMonthAgg($bulan, $tipe, $cabang);
    $fcTotal = [$fpTot, $fg, $fr];
    $ringkas = ['prev_bulan' => $prevAgg['bulan'], 'prev' => $prevAgg['total'], 'forecast' => [], 'selisih' => [], 'selisih_pct' => []];
    foreach ([0, 1, 2] as $k) {
        $fv = $fcTotal[$k]; $pv = $prevAgg['total'][$k];
        $ringkas['forecast'][$k] = $fv === null ? null : round($fv, 2);
        $ringkas['selisih'][$k] = $fv === null ? null : round($fv - $pv, 2);
        $ringkas['selisih_pct'][$k] = pctVs($fv, $pv);
    }

    $days = [];
    for ($i = 1; $i <= $n; $i++) $days[] = ['n' => $i, 'dow' => (int)date('N', strtotime(sprintf('%s-%02d', $bulan, $i)))];
    return ['meta' => ['bulan' => $bulan, 'hari' => $n, 'hb' => $hb['hb'], 'hb_auto' => $hb['auto'], 'hb_manual' => $hb['manual'], 'last' => $hb['last']],
        'days' => $days, 'rows' => $rows, 'total' => $tot,
        'emas' => ['target' => $tg['gram'], 'inherited' => $tg['inherited'], 'selisih' => round($tot['tg'] - $tg['gram'], 2),
                   'forecast' => $fg === null ? null : round($fg, 2),
                   'capaian' => ($fg !== null && $tg['gram'] > 0) ? round($fg / $tg['gram'] * 100, 1) : null,
                   'prev_gram' => $prevAgg['total'][1],
                   'selisih_prev' => $fg === null ? null : round($fg - $prevAgg['total'][1], 2),
                   'selisih_prev_pct' => pctVs($fg, $prevAgg['total'][1])],
        'ringkas' => $ringkas,
        'diamond' => ['rows' => $diam, 'total' => $dtot]];
}

/* ---------- Halaman mingguan ---------- */
function forecastMingguan(string $bulan, string $tipe, string $cabang, ?int $hbOverride): array {
    $mi = monthInfo($bulan); $n = $mi['hari'];
    $hb = hariBerjalan($mi, $hbOverride);
    $weeks = monthWeeks($mi['first'], $mi['last']);
    $ents = loadSales($mi['first'], $mi['last'], $tipe, $cabang, 'week', $weeks);
    $prevAgg = prevMonthAgg($bulan, $tipe, $cabang);
    $rows = [];
    $tot = ['w' => array_fill(0, count($weeks), [0, 0.0, 0.0]), 'tp' => 0, 'tg' => 0.0, 'tr' => 0.0];
    foreach ($ents as $e) {
        $w = [];
        foreach ($weeks as $k => $wk) {
            $b = $e['b'][$wk['no']];
            $w[] = [$b[0], round($b[1], 3), $b[2]];
            $tot['w'][$k][0] += $b[0]; $tot['w'][$k][1] += $b[1]; $tot['w'][$k][2] += $b[2];
        }
        $s = sumBuckets($e);
        $tg2 = round($s[1], 2);
        $pv = $prevAgg['perSales'][$e['nama']] ?? [0, 0.0, 0.0];
        $rows[] = ['nama' => $e['nama'], 'grade' => $e['grade'], 'w' => $w, 'tp' => $s[0], 'tg' => $tg2, 'tr' => $s[2],
                   'prev' => $pv, 'sel_pct' => [pctVs((float)$s[0], $pv[0]), pctVs($tg2, $pv[1]), pctVs($s[2], $pv[2])]];
        $tot['tp'] += $s[0]; $tot['tg'] += $s[1]; $tot['tr'] += $s[2];
    }
    foreach ($tot['w'] as &$x) $x[1] = round($x[1], 3); unset($x);
    $tot['tg'] = round($tot['tg'], 2);

    $cur = [$tot['tp'], $tot['tg'], $tot['tr']];
    $f = $sel = $selF = $rasio = $selPctTot = [];
    foreach ([0, 1, 2] as $k) {
        $fv = fc((float)$cur[$k], $hb['hb'], $n);
        $f[$k] = $fv === null ? null : round($fv, 2);
        $sel[$k] = round($cur[$k] - $prevAgg['total'][$k], 2);
        $selF[$k] = $fv === null ? null : round($fv - $prevAgg['total'][$k], 2);
        $rasio[$k] = ($fv !== null && $prevAgg['total'][$k] > 0) ? round($fv / $prevAgg['total'][$k], 4) : null;
        $selPctTot[$k] = pctVs($fv, $prevAgg['total'][$k]);
    }
    return ['meta' => ['bulan' => $bulan, 'hari' => $n, 'hb' => $hb['hb'], 'hb_auto' => $hb['auto'], 'hb_manual' => $hb['manual'], 'last' => $hb['last'], 'prev_bulan' => $prevAgg['bulan']],
        'weeks' => $weeks, 'rows' => $rows, 'total' => $tot,
        'ringkas' => ['prev' => $prevAgg['total'], 'forecast' => $f, 'selisih' => $sel, 'selisih_forecast' => $selF, 'rasio' => $rasio, 'selisih_pct' => $selPctTot]];
}

/* ---------- Halaman Trimas (pembelian kondisi LUAR JUAL SAJA / DOLMAS) ---------- */
function trimasReport(string $from, string $to): array {
    $in = sqlIn(TRIMAS_KONDISI);
    $master = [];
    foreach (db()->query("SELECT kode_sales, nama_sales, tipe FROM master_sales")->fetchAll() as $m) $master[$m['kode_sales']] = $m;

    // Rekap per kondisi (LUAR JUAL SAJA vs DOLMAS)
    $st = db()->prepare("SELECT p.kondisi, COUNT(*) pcs, SUM(p.berat) gram, SUM(p.harga_total) rupiah
        FROM pembelian p WHERE p.tgl BETWEEN ? AND ? AND p.batal = 0 AND p.kondisi IN $in
        GROUP BY p.kondisi ORDER BY p.kondisi");
    $st->execute([$from, $to]);
    $perKondisi = [];
    foreach (TRIMAS_KONDISI as $k) $perKondisi[$k] = ['kondisi' => $k, 'pcs' => 0, 'gram' => 0.0, 'rupiah' => 0.0];
    foreach ($st->fetchAll() as $r) $perKondisi[$r['kondisi']] = ['kondisi' => $r['kondisi'], 'pcs' => (int)$r['pcs'], 'gram' => round((float)$r['gram'], 2), 'rupiah' => (float)$r['rupiah']];

    // Rekap per sales (semua kondisi trimas digabung), sales bukan toko digabung "Lainnya"
    $st = db()->prepare("SELECT p.kode_sales, p.kondisi, p.tgl,
            COUNT(*) pcs, SUM(p.berat) gram, SUM(p.harga_total) rupiah
        FROM pembelian p WHERE p.tgl BETWEEN ? AND ? AND p.batal = 0 AND p.kondisi IN $in
        GROUP BY p.kode_sales, p.kondisi, p.tgl");
    $st->execute([$from, $to]);
    $bySales = []; $byDate = [];
    foreach ($st->fetchAll() as $r) {
        $m = $master[$r['kode_sales']] ?? null;
        $toko = $m && $m['tipe'] === 'toko';
        $name = $toko ? $m['nama_sales'] : 'Lainnya';
        if (!isset($bySales[$name])) $bySales[$name] = ['nama' => $name, 'tipe' => $toko ? 'toko' : 'luar', 'pcs' => 0, 'gram' => 0.0, 'rupiah' => 0.0];
        $bySales[$name]['pcs'] += (int)$r['pcs']; $bySales[$name]['gram'] += (float)$r['gram']; $bySales[$name]['rupiah'] += (float)$r['rupiah'];
        if (!isset($byDate[$r['tgl']])) $byDate[$r['tgl']] = ['tgl' => $r['tgl'], 'pcs' => 0, 'gram' => 0.0, 'rupiah' => 0.0];
        $byDate[$r['tgl']]['pcs'] += (int)$r['pcs']; $byDate[$r['tgl']]['gram'] += (float)$r['gram']; $byDate[$r['tgl']]['rupiah'] += (float)$r['rupiah'];
    }
    foreach ($bySales as &$s) { $s['gram'] = round($s['gram'], 2); } unset($s);
    usort($bySales, fn($a, $b) => ($a['nama'] === 'Lainnya') <=> ($b['nama'] === 'Lainnya') ?: $b['rupiah'] <=> $a['rupiah']);
    ksort($byDate);
    foreach ($byDate as &$d) { $d['gram'] = round($d['gram'], 2); } unset($d);

    $total = ['pcs' => 0, 'gram' => 0.0, 'rupiah' => 0.0];
    foreach ($perKondisi as $k) { $total['pcs'] += $k['pcs']; $total['gram'] += $k['gram']; $total['rupiah'] += $k['rupiah']; }
    $total['gram'] = round($total['gram'], 2);

    // Rincian transaksi (terbaru dulu, dibatasi 500 baris)
    $st = db()->prepare("SELECT p.tgl, p.no_faktur, COALESCE(m.nama_sales, CONCAT('Kode ', p.kode_sales)) sales,
            p.nama_customer, p.kondisi, p.nama_barang, p.berat, p.harga_total
        FROM pembelian p LEFT JOIN master_sales m ON m.kode_sales = p.kode_sales
        WHERE p.tgl BETWEEN ? AND ? AND p.batal = 0 AND p.kondisi IN $in
        ORDER BY p.tgl DESC, p.no_faktur DESC LIMIT 500");
    $st->execute([$from, $to]);
    $detail = $st->fetchAll();

    return ['kondisi' => array_values($perKondisi), 'sales' => array_values($bySales), 'harian' => array_values($byDate),
        'total' => $total, 'detail' => $detail];
}

/* ---------- Grading Sales (sistem poin) ---------- */
/**
 * Poin per baris penjualan: dept KOTAK/KOTAK LM/SERTIFIKAT = 0 (bukan perhiasan).
 * Dept LM (logam mulia) bertingkat sesuai berat: <50g = 1, 50-99g = 2, >=100g = 3.
 * Dept lain (perhiasan, termasuk diamond) = 1 poin per pcs.
 */
/** Poin perhiasan biasa: dept KOTAK/KOTAK LM/SERTIFIKAT/LM = 0 di sini (LM punya poin sendiri lewat pointLmExpr). */
function pointPcsExpr(): string {
    $non = sqlIn(DEPT_NON_PCS);
    return "CASE WHEN p.kode_dept IN $non THEN 0 WHEN p.kode_dept = 'LM' THEN 0 ELSE 1 END";
}
/** Poin logam mulia (dept LM) per tingkatan berat — dipisah jadi 3 kolom sendiri (bukan digabung). */
function pointLmUnder50Expr(): string { return "CASE WHEN p.kode_dept = 'LM' AND p.berat < 50 THEN 1 ELSE 0 END"; }
function pointLm50Expr(): string { return "CASE WHEN p.kode_dept = 'LM' AND p.berat >= 50 AND p.berat < 100 THEN 2 ELSE 0 END"; }
function pointLm100Expr(): string { return "CASE WHEN p.kode_dept = 'LM' AND p.berat >= 100 THEN 3 ELSE 0 END"; }

function gradeTargets(): array {
    ensureForecastSchema();
    $out = [];
    foreach (db()->query("SELECT grade, target_point FROM grade_target")->fetchAll() as $r) $out[$r['grade']] = (int)$r['target_point'];
    foreach (GRADES as $g) if (!isset($out[$g])) $out[$g] = 0;   // grade baru yang belum sempat di-seed
    return $out;
}

const BULAN_ID = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
function monthLabelID(string $bulan): string {
    [$y, $m] = explode('-', $bulan);
    return BULAN_ID[(int)$m] . ' ' . $y;
}

/** Daftar bucket periode: bulanan = 1 bucket (bulan itu sendiri), triwulan = 3 bucket (tiap bulan kalender dalam triwulan). */
function gradingBuckets(string $mode, string $bulan): array {
    if ($mode !== 'triwulan') {
        $mi = monthInfo($bulan);
        return [['label' => monthLabelID($bulan), 'bulan' => $bulan, 'from' => $mi['first'], 'to' => $mi['last']]];
    }
    $y = (int)substr($bulan, 0, 4); $m = (int)substr($bulan, 5, 2);
    $q = intdiv($m - 1, 3);
    $buckets = [];
    for ($i = 0; $i < 3; $i++) {
        $bm = sprintf('%04d-%02d', $y, $q * 3 + 1 + $i);
        $mi = monthInfo($bm);
        $buckets[] = ['label' => monthLabelID($bm), 'bulan' => $bm, 'from' => $mi['first'], 'to' => $mi['last']];
    }
    return $buckets;
}

/**
 * Tangga level berdasarkan poin. Urutan dari grade terendah ke tertinggi (GRADES dibalik); target tiap level
 * dikali jumlah bulan periode (1 = bulanan, 3 = triwulan).
 *  - tercapai : level tertinggi yang targetnya sudah dilewati, persen = poin / target level itu (mis. 102 poin -> 102% TRAINING)
 *  - belum    : level berikutnya (upgrade) beserta progres & kekurangan poin; null jika sudah di level tertinggi
 *  - puncak   : true jika sudah mencapai level tertinggi
 */
function gradeLadder(int $points, array $targets, int $months): array {
    $ladder = [];
    foreach (array_reverse(GRADES) as $g) {
        $t = (int)($targets[$g] ?? 0) * $months;
        if ($t > 0) $ladder[] = ['grade' => $g, 'target' => $t];
    }
    $ach = -1;
    foreach ($ladder as $i => $L) if ($points >= $L['target']) $ach = $i;
    $tercapai = $ach >= 0 ? ['grade' => $ladder[$ach]['grade'], 'target' => $ladder[$ach]['target'], 'pct' => round($points / $ladder[$ach]['target'] * 100, 1)] : null;
    $nx = $ladder[$ach + 1] ?? null;
    $belum = $nx ? ['grade' => $nx['grade'], 'target' => $nx['target'], 'pct' => round($points / $nx['target'] * 100, 1), 'kurang' => $nx['target'] - $points] : null;
    return ['tercapai' => $tercapai, 'belum' => $belum, 'puncak' => $ladder && $ach === count($ladder) - 1];
}

/** Rekap poin per sales toko, dirinci per bucket (per bulan) lalu dijumlah jadi total periode. */
function gradingReport(array $buckets, string $cabang): array {
    $targets = gradeTargets();
    $n = count($buckets);
    $master = [];
    foreach (db()->query("SELECT kode_sales, nama_sales, nama_laporan, tipe, grade, cabang FROM master_sales")->fetchAll() as $m) $master[$m['kode_sales']] = $m;
    $cabangOk = function (?array $m) use ($cabang): bool {
        if ($m && $cabang !== '' && $m['cabang'] !== '' && strcasecmp($m['cabang'], $cabang) !== 0
            && laporanName($m['nama_sales'], $m['nama_laporan']) !== 'ONLINE') return false;
        return true;
    };

    $ents = [];
    $get = function (string $name, ?array $m) use (&$ents, $n) {
        if (!isset($ents[$name])) $ents[$name] = ['nama' => $name, 'grade' => '', 'cabang' => '',
            'bulanan' => array_fill(0, $n, ['pc' => 0, 'l1' => 0, 'l2' => 0, 'l3' => 0, 'tr' => 0]), 'pcs' => 0, 'rupiah' => 0.0];
        if ($m) {
            if ($ents[$name]['grade'] === '' && $m['grade'] !== '') $ents[$name]['grade'] = strtoupper($m['grade']);
            if ($ents[$name]['cabang'] === '') $ents[$name]['cabang'] = $m['cabang'];
        }
    };
    foreach ($master as $m) if ($m['tipe'] === 'toko' && $cabangOk($m)) $get(laporanName($m['nama_sales'], $m['nama_laporan']), $m);

    $in = sqlIn(TRIMAS_KONDISI);
    foreach ($buckets as $bi => $bk) {
        // Poin dari penjualan bulan ini: pcs (perhiasan biasa) dan LM (logam mulia, 3 tingkatan berat) dipisah
        $st = db()->prepare("SELECT p.kode_sales, SUM(" . pointPcsExpr() . ") pts_pc,
            SUM(" . pointLmUnder50Expr() . ") pts_l1, SUM(" . pointLm50Expr() . ") pts_l2, SUM(" . pointLm100Expr() . ") pts_l3,
            SUM(" . pcsExpr('penjualan') . ") pcs, SUM(p.harga_total) rp
            FROM penjualan p WHERE p.tgl BETWEEN ? AND ? AND p.batal = 0 GROUP BY p.kode_sales");
        $st->execute([$bk['from'], $bk['to']]);
        foreach ($st->fetchAll() as $r) {
            $m = $master[$r['kode_sales']] ?? null;
            $toko = $m && $m['tipe'] === 'toko';
            $name = $toko ? laporanName($m['nama_sales'], $m['nama_laporan']) : 'Lainnya';
            if ($toko && !$cabangOk($m)) continue;
            $get($name, $toko ? $m : null);
            $b = &$ents[$name]['bulanan'][$bi];
            $b['pc'] += (int)$r['pts_pc']; $b['l1'] += (int)$r['pts_l1']; $b['l2'] += (int)$r['pts_l2']; $b['l3'] += (int)$r['pts_l3'];
            unset($b);
            $ents[$name]['pcs'] += (int)$r['pcs']; $ents[$name]['rupiah'] += (float)$r['rp'];
        }
        // Poin dari pembelian Trimas bulan ini (1 poin per baris)
        $st = db()->prepare("SELECT p.kode_sales, COUNT(*) pts FROM pembelian p
            WHERE p.tgl BETWEEN ? AND ? AND p.batal = 0 AND p.kondisi IN $in GROUP BY p.kode_sales");
        $st->execute([$bk['from'], $bk['to']]);
        foreach ($st->fetchAll() as $r) {
            $m = $master[$r['kode_sales']] ?? null;
            $toko = $m && $m['tipe'] === 'toko';
            $name = $toko ? laporanName($m['nama_sales'], $m['nama_laporan']) : 'Lainnya';
            if ($toko && !$cabangOk($m)) continue;
            $get($name, $toko ? $m : null);
            $ents[$name]['bulanan'][$bi]['tr'] += (int)$r['pts'];
        }
    }

    $bulanRow = fn($b) => ['point_pcs' => $b['pc'], 'point_lm1' => $b['l1'], 'point_lm2' => $b['l2'], 'point_lm3' => $b['l3'], 'point_trimas' => $b['tr'],
        'point' => $b['pc'] + $b['l1'] + $b['l2'] + $b['l3'] + $b['tr']];
    $rows = [];
    foreach ($ents as $e) {
        $pc = array_sum(array_column($e['bulanan'], 'pc')); $l1 = array_sum(array_column($e['bulanan'], 'l1'));
        $l2 = array_sum(array_column($e['bulanan'], 'l2')); $l3 = array_sum(array_column($e['bulanan'], 'l3'));
        $tr = array_sum(array_column($e['bulanan'], 'tr'));
        $total = $pc + $l1 + $l2 + $l3 + $tr;
        if ($total === 0 && $e['pcs'] === 0 && $e['grade'] === '' && $e['nama'] !== 'Lainnya') continue; // sales toko tanpa aktivitas & grade: skip baris kosong
        $isOther = $e['nama'] === 'Lainnya';
        $target = (!$isOther && $e['grade'] !== '' && isset($targets[$e['grade']])) ? $targets[$e['grade']] * $n : null;
        $rows[] = ['nama' => $e['nama'], 'grade' => $e['grade'], 'cabang' => $e['cabang'],
            'bulan' => array_map($bulanRow, $e['bulanan']),
            'point_pcs' => $pc, 'point_lm1' => $l1, 'point_lm2' => $l2, 'point_lm3' => $l3, 'point_trimas' => $tr, 'point' => $total,
            'pcs' => $e['pcs'], 'rupiah' => round($e['rupiah'], 2),
            'target' => $target, 'pct' => ($target && $target > 0) ? round($total / $target * 100, 1) : null,
            'status' => $target === null ? null : ($total >= $target ? 'tercapai' : 'belum'),
            'level' => $isOther ? null : gradeLadder($total, $targets, $n)];
    }
    usort($rows, fn($a, $b) => [gradeRank($a['grade'], $a['nama']), -$a['point']] <=> [gradeRank($b['grade'], $b['nama']), -$b['point']]);

    $total = ['point' => 0, 'point_pcs' => 0, 'point_lm1' => 0, 'point_lm2' => 0, 'point_lm3' => 0, 'point_trimas' => 0,
        'pcs' => 0, 'rupiah' => 0.0, 'target' => 0, 'tercapai' => 0, 'belum' => 0,
        'bulan' => array_fill(0, $n, ['point_pcs' => 0, 'point_lm1' => 0, 'point_lm2' => 0, 'point_lm3' => 0, 'point_trimas' => 0, 'point' => 0])];
    $total['level_count'] = ['_none' => 0];
    foreach (array_reverse(GRADES) as $g) $total['level_count'][$g] = 0;
    foreach ($rows as $r) {
        if ($r['level'] !== null) $total['level_count'][$r['level']['tercapai']['grade'] ?? '_none']++;
        foreach (['point', 'point_pcs', 'point_lm1', 'point_lm2', 'point_lm3', 'point_trimas'] as $k) $total[$k] += $r[$k];
        $total['pcs'] += $r['pcs']; $total['rupiah'] += $r['rupiah'];
        if ($r['target'] !== null) { $total['target'] += $r['target']; if ($r['status'] === 'tercapai') $total['tercapai']++; else $total['belum']++; }
        foreach ($r['bulan'] as $bi => $b) foreach (['point_pcs', 'point_lm1', 'point_lm2', 'point_lm3', 'point_trimas', 'point'] as $k) $total['bulan'][$bi][$k] += $b[$k];
    }
    $total['rupiah'] = round($total['rupiah'], 2);
    return ['rows' => $rows, 'total' => $total, 'targets' => $targets, 'buckets' => $buckets];
}