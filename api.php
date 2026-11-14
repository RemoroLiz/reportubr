<?php
// Endpoint AJAX tunggal. Semua respons JSON. Wajib login; aksi POST wajib token CSRF.

// Error fatal PHP (file hilang, salah sintaks, dst) selalu dijawab sebagai JSON berisi pesan singkat,
// bukan halaman kosong — supaya di layar muncul penyebabnya, bukan sekadar "respons server tidak valid".
ini_set('display_errors', '0');
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        error_log('[api fatal] ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
        if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
        $msg = preg_replace('~/[^\s\'"]*/~', '', $e['message']);   // buang path folder server dari pesan
        echo json_encode(['ok' => false, 'error' => 'Kesalahan server: ' . mb_substr($msg, 0, 220) . ' (' . basename($e['file']) . ':' . $e['line'] . ')']);
    }
});

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/lib.php';
require_once __DIR__ . '/inc/forecast.php';
// Modul barang bersifat opsional di sini: kalau file-nya belum terupload, halaman lain tetap jalan
// dan hanya fitur Barang yang menampilkan pesan jelas.
$barangFile = __DIR__ . '/inc/barang.php';
if (is_file($barangFile)) require_once $barangFile;

header('Content-Type: application/json; charset=utf-8');
$user = requireLogin(true);
$action = $_GET['action'] ?? '';
if ((str_starts_with($action, 'barang_') || str_starts_with($action, 'retur_') || $action === 'barcode_lookup') && !function_exists('ensureBarangSchema')) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'File inc/barang.php belum ada di server. Upload file barang.php ke dalam folder inc/ (bukan folder utama).']));
}
$postActions = ['upload', 'sales_save', 'sales_delete', 'target_save', 'grade_target_save', 'barang_upload', 'retur_save', 'barang_sync'];
if (in_array($action, $postActions, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok' => false, 'error' => 'Method tidak diizinkan.'])); }
    checkCsrf();
}
session_write_close();
ensureForecastSchema();

function out(array $d): void { echo json_encode(['ok' => true] + $d, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit; }
function fail(string $m, int $code = 400): void { http_response_code($code); echo json_encode(['ok' => false, 'error' => $m]); exit; }
function tipeParam(): string { $t = $_GET['tipe'] ?? 'semua'; return in_array($t, ['toko', 'luar'], true) ? $t : 'semua'; }

/** Pareto customer. urut: trx | rupiah | gram */
function pareto(string $tbl, string $from, string $to, string $urut, int $limit, string $q = ''): array {
    $pcs = pcsExpr($tbl); $gram = gramExpr($tbl);
    $sql = "SELECT p.customer_key, MAX(p.nama_customer) AS nama, MAX(p.no_hp) AS hp, MAX(p.kode_member) AS member,
              COUNT(DISTINCT p.no_faktur_group) AS trx, SUM($pcs) AS pcs, SUM($gram) AS gram, SUM(p.harga_total) AS rupiah,
              MAX(p.tgl) AS terakhir
            FROM $tbl p WHERE p.tgl BETWEEN ? AND ? AND p.batal = 0 GROUP BY p.customer_key";
    $st = db()->prepare($sql); $st->execute([$from, $to]);
    $all = $st->fetchAll();
    $anon = 0; $rows = [];
    foreach ($all as $r) {
        if ($r['customer_key'][0] === 'N' && in_array(substr($r['customer_key'], 2), GENERIC_CUSTOMER_NAMES, true)) { $anon += (int)$r['trx']; continue; }
        $r['trx'] = (int)$r['trx']; $r['pcs'] = (int)$r['pcs']; $r['gram'] = round((float)$r['gram'], 2); $r['rupiah'] = (float)$r['rupiah'];
        $r['jenis'] = $r['customer_key'][0] === 'M' ? 'Member' : ($r['customer_key'][0] === 'H' ? 'No HP' : 'Nama');
        $rows[] = $r;
    }
    $metric = in_array($urut, ['rupiah', 'gram'], true) ? $urut : 'trx';
    usort($rows, fn($a, $b) => [$b[$metric], $b['rupiah']] <=> [$a[$metric], $a['rupiah']]);
    $sum = array_sum(array_column($rows, $metric)) ?: 1;
    $cum = 0; $topA = 0;
    foreach ($rows as $i => &$r) {
        $r['no'] = $i + 1;
        $cum += $r[$metric];
        $r['pct'] = round($r[$metric] / $sum * 100, 2);
        $r['cum'] = round($cum / $sum * 100, 2);
        $r['kelas'] = ($cum - $r[$metric]) / $sum < 0.8 ? 'A' : (($cum - $r[$metric]) / $sum < 0.95 ? 'B' : 'C');
        if ($r['kelas'] === 'A') $topA++;
    }
    unset($r);
    $summary = [
        'customer' => count($rows), 'trx' => array_sum(array_column($rows, 'trx')),
        'repeat' => count(array_filter($rows, fn($r) => $r['trx'] >= 2)), 'kelasA' => $topA,
        'rupiah' => array_sum(array_column($rows, 'rupiah')), 'tanpa_identitas' => $anon,
    ];
    if ($q !== '') $rows = array_values(array_filter($rows, fn($r) => stripos($r['nama'] . ' ' . $r['hp'] . ' ' . $r['member'], $q) !== false));
    if ($limit > 0) $rows = array_slice($rows, 0, $limit);
    return ['rows' => $rows, 'summary' => $summary, 'metric' => $metric];
}

function dailySeries(string $tbl, string $from, string $to): array {
    $pcs = pcsExpr($tbl); $gram = gramExpr($tbl);
    $st = db()->prepare("SELECT p.tgl, SUM($pcs) pcs, SUM($gram) gram, SUM(p.harga_total) rupiah,
        COUNT(DISTINCT p.no_faktur_group) trx, COUNT(DISTINCT p.customer_key) cust
        FROM $tbl p WHERE p.tgl BETWEEN ? AND ? AND p.batal = 0 GROUP BY p.tgl");
    $st->execute([$from, $to]);
    $map = [];
    foreach ($st->fetchAll() as $r) $map[$r['tgl']] = $r;
    return $map;
}

try {
    switch ($action) {
        case 'dashboard': {
            $today = date('Y-m-d');
            [$from, $to] = rangeFor('bulan', $today);
            $last = min($to, $today);
            $jual = dailySeries('penjualan', $from, $to);
            $beli = dailySeries('pembelian', $from, $to);
            $labels = $pcsBeli = $rpJual = $pcsJual = $trx = $cust = $trxBeli = [];
            for ($d = strtotime($from); $d <= strtotime($last); $d += 86400) {
                $k = date('Y-m-d', $d);
                $labels[] = date('j', $d);
                $pcsBeli[] = (int)($beli[$k]['pcs'] ?? 0);
                $rpJual[] = (float)($jual[$k]['rupiah'] ?? 0);
                $pcsJual[] = (int)($jual[$k]['pcs'] ?? 0);
                $trx[] = (int)($jual[$k]['trx'] ?? 0);
                $cust[] = (int)($jual[$k]['cust'] ?? 0);
                $trxBeli[] = (int)($beli[$k]['trx'] ?? 0);
            }
            $rj = totals(rekapSales('penjualan', $from, $to, 'semua', 'exclude'));
            $rd = totals(rekapSales('penjualan', $from, $to, 'semua', 'only'));
            $rb = totals(rekapSales('pembelian', $from, $to, 'semua'));
            $p = pareto('penjualan', $from, $to, 'trx', 5);
            $lastData = db()->query("SELECT (SELECT MAX(tgl) FROM penjualan) jual, (SELECT MAX(tgl) FROM pembelian) beli")->fetch();
            out([
                'periode' => ['from' => $from, 'to' => $to], 'labels' => $labels,
                'series' => ['pcsBeli' => $pcsBeli, 'rpJual' => $rpJual, 'pcsJual' => $pcsJual, 'trx' => $trx, 'cust' => $cust, 'trxBeli' => $trxBeli],
                'kpi' => [
                    'jual_rp' => $rj['rupiah'] + $rd['rupiah'], 'jual_pcs' => $rj['pcs'], 'jual_gram' => $rj['gram'],
                    'diamond_rp' => $rd['rupiah'], 'beli_pcs' => $rb['pcs'],
                    'trx' => array_sum($trx), 'customer' => $p['summary']['customer'], 'repeat' => $p['summary']['repeat'],
                ],
                'top' => $p['rows'], 'lastData' => $lastData,
            ]);
        }

        case 'rekap': {
            [$from, $to] = requestRange();
            $kind = $_GET['kind'] ?? 'penjualan';
            $tipe = tipeParam();
            if ($kind === 'pembelian') $rows = rekapSales('pembelian', $from, $to, $tipe);
            elseif ($kind === 'diamond') $rows = rekapSales('penjualan', $from, $to, $tipe, 'only');
            else $rows = rekapSales('penjualan', $from, $to, $tipe, 'exclude');
            $res = ['rows' => $rows, 'total' => totals($rows), 'from' => $from, 'to' => $to];
            if ($kind === 'diamond') {
                $st = db()->prepare("SELECT p.tgl, p.no_faktur, COALESCE(m.nama_sales, CONCAT('Kode ', p.kode_sales)) sales,
                    p.nama_customer, p.kode_dept, p.nama_barang, p.berat, p.harga_total
                    FROM penjualan p LEFT JOIN master_sales m ON m.kode_sales = p.kode_sales
                    WHERE p.tgl BETWEEN ? AND ? AND p.batal = 0 AND p.kode_dept IN " . sqlIn(DEPT_DIAMOND) . tipeWhere($tipe) . "
                    ORDER BY p.tgl DESC, p.no_faktur DESC LIMIT 500");
                $st->execute([$from, $to]);
                $res['detail'] = $st->fetchAll();
            }
            out($res);
        }

        case 'pareto': {
            [$from, $to] = requestRange();
            $tbl = tableName($_GET['sumber'] ?? 'penjualan');
            out(paretoGrup($tbl, $from, $to, $_GET['urut'] ?? 'trx', max(0, (int)($_GET['limit'] ?? 50)), trim($_GET['q'] ?? '')) + ['from' => $from, 'to' => $to]);
        }

        case 'compare': {
            $mode = in_array($_GET['mode'] ?? '', ['hari', 'minggu', 'bulan'], true) ? $_GET['mode'] : 'hari';
            $date = validDate($_GET['date'] ?? null);
            $tipe = tipeParam();
            $t = strtotime($date);
            $prevDate = $mode === 'hari' ? date('Y-m-d', strtotime('-1 day', $t))
                : ($mode === 'minggu' ? date('Y-m-d', strtotime('-7 days', $t)) : date('Y-m-d', strtotime('first day of last month', $t)));
            $cur = rangeFor($mode, $date); $prv = rangeFor($mode, $prevDate);
            $data = [];
            foreach (['cur' => $cur, 'prev' => $prv] as $k => [$f, $tt]) {
                $data[$k] = [
                    'jual' => rekapSales('penjualan', $f, $tt, $tipe, 'exclude'),
                    'diamond' => rekapSales('penjualan', $f, $tt, $tipe, 'only'),
                    'beli' => rekapSales('pembelian', $f, $tt, $tipe),
                ];
            }
            $sales = [];
            foreach ($data as $k => $sets) foreach (['jual', 'beli'] as $j) foreach ($sets[$j] as $r) {
                $s = &$sales[$r['kode_sales']];
                $s = $s ?? ['kode' => $r['kode_sales'], 'nama' => $r['nama_sales'], 'tipe' => $r['tipe']];
                foreach (['pcs', 'gram', 'rupiah'] as $f) $s["{$j}_{$f}_{$k}"] = $r[$f];
                unset($s);
            }
            out([
                'mode' => $mode, 'cur' => ['from' => $cur[0], 'to' => $cur[1]], 'prev' => ['from' => $prv[0], 'to' => $prv[1]],
                'total' => [
                    'cur' => ['jual' => totals($data['cur']['jual']), 'diamond' => totals($data['cur']['diamond']), 'beli' => totals($data['cur']['beli'])],
                    'prev' => ['jual' => totals($data['prev']['jual']), 'diamond' => totals($data['prev']['diamond']), 'beli' => totals($data['prev']['beli'])],
                ],
                'sales' => array_values($sales),
            ]);
        }

        case 'compare2': {
            // Perbandingan tabel SALES x periode. mode=bulan: months=YYYY-MM,YYYY-MM (cara=auto|full|h2h, hari=1..31) | mode=rentang: ranges=a..b|c..d
            $tipe = tipeParam();
            $kat = $_GET['kat'] ?? 'emas';
            $dm = ['emas' => 'exclude', 'semua' => 'all', 'berlian' => 'only'][$kat] ?? 'exclude';
            $mode = ($_GET['mode'] ?? 'bulan') === 'rentang' ? 'rentang' : 'bulan';
            if ($mode === 'rentang') {
                $periods = periodsFromRanges(parseRangeList((string)($_GET['ranges'] ?? '')));
            } else {
                $cara = in_array($_GET['cara'] ?? 'auto', ['auto', 'full', 'h2h'], true) ? $_GET['cara'] : 'auto';
                $periods = periodsFromMonths(parseMonthList((string)($_GET['months'] ?? '')), $cara, (int)($_GET['hari'] ?? 0), date('Y-m-d'));
            }
            if (!$periods) fail('Pilih minimal satu periode yang valid.');
            out(compareMulti($periods, $tipe, $dm) + ['mode' => $mode, 'today' => date('Y-m-d')]);
        }

        case 'sales_list': {
            $rows = db()->query("SELECT kode_sales, nama_sales, fee, tipe, grade, target_pcs, cabang, nama_laporan FROM master_sales ORDER BY tipe, CAST(kode_sales AS UNSIGNED), kode_sales")->fetchAll();
            $un = db()->query("SELECT kode_sales, SUM(n) baris FROM (
                    SELECT kode_sales, COUNT(*) n FROM penjualan GROUP BY kode_sales
                    UNION ALL SELECT kode_sales, COUNT(*) FROM pembelian GROUP BY kode_sales) x
                WHERE kode_sales NOT IN (SELECT kode_sales FROM master_sales) GROUP BY kode_sales ORDER BY baris DESC")->fetchAll();
            $log = db()->query("SELECT jenis, filename, total_baris, baru, duplikat, created_at FROM upload_log ORDER BY id DESC LIMIT 10")->fetchAll();
            out(['rows' => $rows, 'unregistered' => $un, 'log' => $log]);
        }

        case 'sales_save': {
            $kode = trim($_POST['kode'] ?? ''); $nama = trim($_POST['nama'] ?? '');
            $tipe = ($_POST['tipe'] ?? '') === 'luar' ? 'luar' : 'toko';
            if ($kode === '' || $nama === '' || strlen($kode) > 20) fail('Kode dan nama sales wajib diisi.');
            db()->prepare("INSERT INTO master_sales (kode_sales, nama_sales, fee, tipe, grade, target_pcs, cabang, nama_laporan) VALUES (?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE nama_sales = VALUES(nama_sales), fee = VALUES(fee), tipe = VALUES(tipe), grade = VALUES(grade),
                target_pcs = VALUES(target_pcs), cabang = VALUES(cabang), nama_laporan = VALUES(nama_laporan)")
                ->execute([$kode, mb_substr($nama, 0, 100), num($_POST['fee'] ?? 0), $tipe,
                    mb_substr(strtoupper(trim($_POST['grade'] ?? '')), 0, 30), max(0, (int)($_POST['target_pcs'] ?? 0)),
                    mb_substr(strtoupper(trim($_POST['cabang'] ?? '')), 0, 20), mb_substr(trim($_POST['nama_laporan'] ?? ''), 0, 60)]);
            out(['message' => 'Sales disimpan.']);
        }

        case 'sales_delete': {
            db()->prepare("DELETE FROM master_sales WHERE kode_sales = ?")->execute([trim($_POST['kode'] ?? '')]);
            out(['message' => 'Sales dihapus dari master.']);
        }

        case 'upload': {
            if (empty($_FILES['files'])) fail('Tidak ada file yang dikirim.');
            @set_time_limit(300);
            $f = $_FILES['files']; $results = [];
            $n = is_array($f['name']) ? count($f['name']) : 0;
            for ($i = 0; $i < $n; $i++) {
                $name = basename((string)$f['name'][$i]);
                try {
                    if ($f['error'][$i] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload gagal (kode ' . $f['error'][$i] . '). Cek batas upload_max_filesize di PHP.');
                    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') throw new RuntimeException('Hanya file .csv yang diperbolehkan.');
                    if ($f['size'][$i] > MAX_UPLOAD_MB * 1048576) throw new RuntimeException('Ukuran file melebihi ' . MAX_UPLOAD_MB . ' MB.');
                    $results[] = ['ok' => true] + importCsv($f['tmp_name'][$i], $name, $user['id']);
                } catch (Throwable $e) {
                    $results[] = ['ok' => false, 'file' => $name, 'error' => $e->getMessage()];
                }
            }
            // Penjualan baru masuk -> cocokkan barcode-nya dengan stok barang (yang sudah terjual keluar dari stok)
            $sync = null;
            if (function_exists('barangSync') && array_filter($results, fn($r) => !empty($r['ok']) && ($r['jenis'] ?? '') === 'penjualan')) {
                try { $sync = barangSync(); } catch (Throwable $e) { error_log('[barangSync] ' . $e->getMessage()); }
            }
            out(['results' => $results, 'barang_sync' => $sync]);
        }

        case 'barang_log': {
            out(['log' => db()->query("SELECT jenis, filename, total_baris, baru, duplikat, created_at FROM upload_log WHERE jenis = 'barang' ORDER BY id DESC LIMIT 10")->fetchAll()]);
        }

        case 'barang_upload': {
            if (empty($_FILES['files'])) fail('Tidak ada file yang dikirim.');
            @set_time_limit(300);
            $f = $_FILES['files']; $results = [];
            $n = is_array($f['name']) ? count($f['name']) : 0;
            for ($i = 0; $i < $n; $i++) {
                $name = basename((string)$f['name'][$i]);
                try {
                    if ($f['error'][$i] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload gagal (kode ' . $f['error'][$i] . '). Cek batas upload_max_filesize di PHP.');
                    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') throw new RuntimeException('Hanya file .csv yang diperbolehkan (file Excel dikonversi otomatis di browser sebelum diunggah).');
                    if ($f['size'][$i] > MAX_UPLOAD_MB * 1048576) throw new RuntimeException('Ukuran file melebihi ' . MAX_UPLOAD_MB . ' MB.');
                    $results[] = ['ok' => true] + importBarangCsv($f['tmp_name'][$i], $name, $user['id']);
                } catch (Throwable $e) {
                    $results[] = ['ok' => false, 'file' => $name, 'error' => $e->getMessage()];
                }
            }
            out(['results' => $results, 'sync' => barangSync()]);   // barang yang barcode-nya sudah ada di penjualan langsung ditandai terjual
        }

        case 'barang_sync': {
            out(barangSync());
        }

        case 'jenis_stat': {
            [$from, $to] = requestRange();
            out(jenisTerjualStat($from, $to, tipeParam()) + ['from' => $from, 'to' => $to]);
        }

        case 'barang_list': {
            $dateOk = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
            $f = ['from' => $dateOk($_GET['from'] ?? '') ? $_GET['from'] : '', 'to' => $dateOk($_GET['to'] ?? '') ? $_GET['to'] : '',
                'kode_jenis' => $_GET['kode_jenis'] ?? '', 'kode_baki' => $_GET['kode_baki'] ?? '',
                'status' => in_array($_GET['status'] ?? '', ['stok', 'retur', 'terjual'], true) ? $_GET['status'] : '',
                'tgl_kosong' => !empty($_GET['tgl_kosong']),
                'q' => trim($_GET['q'] ?? ''), 'sort' => $_GET['sort'] ?? '', 'dir' => $_GET['dir'] ?? ''];
            out(barangList($f, max(1, (int)($_GET['page'] ?? 1)), (int)($_GET['per_page'] ?? 50)));
        }

        case 'barang_filter_options': {
            $jenis = db()->query("SELECT DISTINCT kode_jenis FROM barang WHERE kode_jenis <> '' ORDER BY kode_jenis")->fetchAll(PDO::FETCH_COLUMN);
            $baki = db()->query("SELECT DISTINCT kode_baki FROM barang WHERE kode_baki <> '' ORDER BY kode_baki")->fetchAll(PDO::FETCH_COLUMN);
            out(['kode_jenis' => $jenis, 'kode_baki' => $baki]);
        }

        case 'barang_stock': {
            $dateOk = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
            out(barangStockSummary(['from' => $dateOk($_GET['from'] ?? '') ? $_GET['from'] : '', 'to' => $dateOk($_GET['to'] ?? '') ? $_GET['to'] : '']));
        }

        case 'barcode_lookup': {
            $b = barangByBarcode(trim($_GET['barcode'] ?? ''));
            if (!$b) fail('Barcode tidak ditemukan di data barang.', 404);
            if ($b['status'] === 'terjual') fail('Barang ini sudah terjual' . ($b['terjual_faktur'] ? ' (faktur ' . $b['terjual_faktur'] . ')' : '') . ', tidak bisa diretur dari stok.', 409);
            if ($b['status'] !== 'stok') fail('Barang ini sudah pernah diretur.', 409);
            out(['item' => $b]);
        }

        case 'retur_save': {
            $items = json_decode($_POST['items'] ?? '[]', true);
            if (!is_array($items) || !$items) fail('Belum ada barang yang discan.');
            $header = [
                'tanggal' => validDate($_POST['tanggal'] ?? null),
                'cabang' => mb_substr(trim($_POST['cabang'] ?? ''), 0, 150),
                'user_id' => $user['id'],
            ];
            foreach ([1, 2, 3, 4] as $i) {
                $header["ttd{$i}_nama"] = mb_substr(trim($_POST["ttd{$i}_nama"] ?? ''), 0, 100);
                $header["ttd{$i}_jabatan"] = mb_substr(trim($_POST["ttd{$i}_jabatan"] ?? ''), 0, 100);
            }
            $clean = [];
            foreach ($items as $it) {
                if (empty($it['kode_barcode'])) continue;
                $clean[] = ['kode_barcode' => trim((string)$it['kode_barcode']), 'keterangan' => mb_substr(trim((string)($it['keterangan'] ?? '')), 0, 255)];
            }
            $id = returSave($header, $clean);
            out(['id' => $id, 'message' => 'Retur disimpan.']);
        }

        case 'retur_list': {
            out(['rows' => returList()]);
        }

        case 'retur_detail': {
            $d = returDetail((int)($_GET['id'] ?? 0));
            if (!$d) fail('Data retur tidak ditemukan.', 404);
            out($d);
        }

        case 'sales_harian': {
            $t = tipeParam(); $hb = (int)($_GET['hb'] ?? 0) ?: null;
            out(forecastHarian(validMonth($_GET['bulan'] ?? null), $t === 'semua' && !isset($_GET['tipe']) ? 'toko' : $t, strtoupper(trim($_GET['cabang'] ?? '')), $hb));
        }

        case 'sales_mingguan': {
            $t = tipeParam(); $hb = (int)($_GET['hb'] ?? 0) ?: null;
            out(forecastMingguan(validMonth($_GET['bulan'] ?? null), $t === 'semua' && !isset($_GET['tipe']) ? 'toko' : $t, strtoupper(trim($_GET['cabang'] ?? '')), $hb));
        }

        case 'grading': {
            $mode = ($_GET['mode'] ?? 'bulan') === 'triwulan' ? 'triwulan' : 'bulan';
            $bulan = validMonth($_GET['bulan'] ?? null);
            $buckets = gradingBuckets($mode, $bulan);
            $from = $buckets[0]['from']; $to = end($buckets)['to'];
            out(gradingReport($buckets, strtoupper(trim($_GET['cabang'] ?? ''))) + ['from' => $from, 'to' => $to, 'mode' => $mode, 'grades' => GRADES]);
        }

        case 'grade_target_list': {
            out(['targets' => gradeTargets(), 'grades' => GRADES]);
        }

        case 'grade_target_save': {
            $pdo = db();
            $st = $pdo->prepare("INSERT INTO grade_target (grade, target_point) VALUES (?,?) ON DUPLICATE KEY UPDATE target_point = VALUES(target_point)");
            foreach (GRADES as $g) {
                $v = $_POST['target'][$g] ?? null;
                if ($v === null) continue;
                $st->execute([$g, max(0, (int)$v)]);
            }
            out(['message' => 'Target grade disimpan.']);
        }

        case 'trimas': {
            [$from, $to] = requestRange();
            out(trimasReport($from, $to) + ['from' => $from, 'to' => $to]);
        }

        case 'cabang_list': {
            out(['rows' => db()->query("SELECT DISTINCT cabang FROM master_sales WHERE cabang <> '' ORDER BY cabang")->fetchAll(PDO::FETCH_COLUMN)]);
        }

        case 'target_save': {
            $bulan = validMonth($_POST['bulan'] ?? null);
            $g = num($_POST['target_gram'] ?? 0);
            if ($g < 0) fail('Target tidak boleh negatif.');
            db()->prepare("INSERT INTO target_bulanan (bulan, target_gram) VALUES (?,?) ON DUPLICATE KEY UPDATE target_gram = VALUES(target_gram)")->execute([$bulan, $g]);
            out(['message' => 'Target emas disimpan.']);
        }

        default:
            fail('Aksi tidak dikenal.', 404);
    }
} catch (Throwable $e) {
    error_log('[api] ' . $e->getMessage());
    fail('Terjadi kesalahan server. Periksa log PHP.', 500);
}