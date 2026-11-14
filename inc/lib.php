<?php
require_once __DIR__ . '/db.php';

/* ---------- Periode ---------- */
function validDate(mixed $s): string {
    if (!is_string($s) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return date('Y-m-d');
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $s : date('Y-m-d');
}

/** Rentang tanggal untuk mode hari|minggu|bulan|triwulan yang memuat $date. Minggu = Senin-Minggu. */
function rangeFor(string $mode, string $date): array {
    $t = strtotime($date);
    switch ($mode) {
        case 'minggu':
            $mon = strtotime('monday this week', $t);
            return [date('Y-m-d', $mon), date('Y-m-d', strtotime('+6 days', $mon))];
        case 'bulan':
            return [date('Y-m-01', $t), date('Y-m-t', $t)];
        case 'triwulan':
            $q = intdiv((int)date('n', $t) - 1, 3);
            $y = date('Y', $t);
            $start = sprintf('%s-%02d-01', $y, $q * 3 + 1);
            return [$start, date('Y-m-t', strtotime($start . ' +2 months'))];
        default:
            return [$date, $date];
    }
}

/** Ambil from/to dari request (dikirim JS). */
function requestRange(): array {
    $from = validDate($_GET['from'] ?? null);
    $to = validDate($_GET['to'] ?? null);
    if ($to < $from) [$from, $to] = [$to, $from];
    return [$from, $to];
}

/* ---------- Ekspresi SQL ---------- */
function pcsExpr(string $tbl): string {
    if ($tbl === 'pembelian') return '1';
    $ex = array_merge(DEPT_NON_PCS, DEPT_DIAMOND);
    return "CASE WHEN p.kode_dept IN " . sqlIn($ex) . " THEN 0 ELSE 1 END";
}

/** Gramasi: dept KOTAK, KOTAK LM, SERTIFIKAT tidak punya bobot emas asli, jadi tidak dihitung. */
function gramExpr(string $tbl): string {
    if ($tbl === 'pembelian') return 'p.berat';
    return "CASE WHEN p.kode_dept IN " . sqlIn(DEPT_NON_PCS) . " THEN 0 ELSE p.berat END";
}

/** Filter sales toko/luar. Sales yang tidak ada di master dianggap luar toko. */
function tipeWhere(string $tipe): string {
    if ($tipe === 'toko') return " AND m.tipe = 'toko'";
    if ($tipe === 'luar') return " AND (m.kode_sales IS NULL OR m.tipe = 'luar')";
    return '';
}

function tableName(string $t): string {
    return $t === 'pembelian' ? 'pembelian' : 'penjualan';
}

/* ---------- Rekap per sales ---------- */
function rekapSales(string $tbl, string $from, string $to, string $tipe, string $diamond = 'exclude'): array {
    $dia = sqlIn(DEPT_DIAMOND);
    $dw = '';
    if ($tbl === 'penjualan') {
        if ($diamond === 'exclude') $dw = " AND p.kode_dept NOT IN $dia";
        if ($diamond === 'only') $dw = " AND p.kode_dept IN $dia";
    }
    // 'all' = emas + berlian: pcs dihitung 1 per baris kecuali KOTAK/KOTAK LM/SERTIFIKAT.
    $pcs = $diamond === 'only' ? '1'
        : ($diamond === 'all' && $tbl === 'penjualan' ? "CASE WHEN p.kode_dept IN " . sqlIn(DEPT_NON_PCS) . " THEN 0 ELSE 1 END" : pcsExpr($tbl));
    $gram = gramExpr($tbl);
    // Sales bukan toko (luar toko / belum terdaftar di master) digabung jadi satu baris "Lainnya" di bagian bawah,
    // supaya daftar sales toko tidak tercampur banyak kode yang tidak terdaftar.
    $grp = "IF(m.tipe = 'toko', p.kode_sales, 'OTHER')";
    $sql = "SELECT $grp AS kode_sales,
              MAX(IF(m.tipe = 'toko', COALESCE(m.nama_sales, CONCAT('Kode ', p.kode_sales)), 'Lainnya')) AS nama_sales,
              MAX(IF(m.tipe = 'toko', 'toko', 'luar')) AS tipe,
              SUM($pcs) AS pcs, SUM($gram) AS gram, SUM(p.harga_total) AS rupiah,
              COUNT(DISTINCT p.no_faktur_group) AS trx
            FROM $tbl p LEFT JOIN master_sales m ON m.kode_sales = p.kode_sales
            WHERE p.tgl BETWEEN ? AND ? AND p.batal = 0 $dw " . tipeWhere($tipe) . "
            GROUP BY $grp
            ORDER BY ($grp = 'OTHER') ASC, rupiah DESC";
    $st = db()->prepare($sql);
    $st->execute([$from, $to]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['pcs'] = (int)$r['pcs']; $r['trx'] = (int)$r['trx'];
        $r['gram'] = round((float)$r['gram'], 2); $r['rupiah'] = (float)$r['rupiah'];
    }
    return $rows;
}

function totals(array $rows): array {
    $t = ['pcs' => 0, 'gram' => 0.0, 'rupiah' => 0.0, 'trx' => 0];
    foreach ($rows as $r) { $t['pcs'] += $r['pcs']; $t['gram'] += $r['gram']; $t['rupiah'] += $r['rupiah']; $t['trx'] += $r['trx']; }
    $t['gram'] = round($t['gram'], 2);
    return $t;
}

/* ---------- Kunci customer: member > no HP > nama ---------- */
function customerKey(string $member, string $hp, string $nama): string {
    $m = strtoupper(trim($member));
    if ($m !== '' && !in_array($m, ['NONMEMBER', 'UNDEFINED', '-', 'NULL'], true)) return 'M:' . $m;
    $h = preg_replace('/\D/', '', $hp);
    if ($h !== '' && str_starts_with($h, '62')) $h = '0' . substr($h, 2);
    if (strlen($h) >= 8) return 'H:' . $h;
    return 'N:' . strtoupper(trim(preg_replace('/\s+/', ' ', $nama)));
}

/* ---------- Import CSV ---------- */
function normHeader(string $h): string {
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
    return preg_replace('/[^a-z0-9]/', '', strtolower($h));
}

function num($v): float {
    $v = cleanText($v);
    if ($v === '') return 0.0;
    if (is_numeric($v)) return (float)$v;
    $t = str_replace(',', '.', str_replace('.', '', $v));   // format Indonesia: 18,67 atau 1.234,56
    return is_numeric($t) ? (float)$t : 0.0;
}

/**
 * Bersihkan isi sel: buang spasi di awal/akhir termasuk spasi "tak terlihat" yang tidak ditangkap trim() biasa
 * (NBSP/spasi Unicode dari Excel/POS, BOM, zero-width), perbaiki encoding Windows-1252, dan kutip pembungkus yang ikut terbawa.
 */
function cleanText($v): string {
    $v = (string)$v;
    if ($v === '') return '';
    if (!mb_check_encoding($v, 'UTF-8')) $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    $v = preg_replace('/[\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u', ' ', $v) ?? $v;
    $v = preg_replace('/[\x{FEFF}\x{200B}-\x{200D}\x{2060}]/u', '', $v) ?? $v;
    $v = trim($v);
    if (strlen($v) >= 2 && $v[0] === '"' && substr($v, -1) === '"') $v = trim(substr($v, 1, -1));
    return $v;
}

/**
 * Ubah berbagai format tanggal jadi YYYY-MM-DD. Didukung: 2026-09-05, 2026-9-5, 2026/09/05, 05/09/2026, 5-9-2026,
 * 05.09.2026, 05/09/26, 20260905, "5 Sep 2026", "05-Sep-26", "5 September 2026", "Sep 5, 2026", serial tanggal Excel (mis. 46270),
 * semuanya boleh disusul jam. Format angka/angka/tahun dibaca hari-dulu (standar Indonesia), kecuali angka pertama jelas bukan hari.
 */
function normDate(string $s): ?string {
    $s = cleanText($s);
    if ($s === '') return null;
    $ok = fn(int $y, int $m, int $d): ?string => checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    $yr = fn(string $y): int => strlen($y) === 2 ? ((int)$y < 70 ? 2000 + (int)$y : 1900 + (int)$y) : (int)$y;
    // serial tanggal Excel
    if (preg_match('/^(\d{5})(\.\d+)?$/', $s, $m) && (int)$m[1] >= 20000 && (int)$m[1] <= 80000) return gmdate('Y-m-d', ((int)$m[1] - 25569) * 86400);
    // tahun dulu: 2026-09-05, 2026/9/5, 2026.09.05
    if (preg_match('#^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})(?!\d)#', $s, $m)) return $ok((int)$m[1], (int)$m[2], (int)$m[3]);
    // 20260905
    if (preg_match('/^(\d{4})(\d{2})(\d{2})(?!\d)/', $s, $m)) return $ok((int)$m[1], (int)$m[2], (int)$m[3]);
    // angka/angka/tahun (4 atau 2 digit)
    if (preg_match('#^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4}|\d{2})(?!\d)#', $s, $m)) {
        $a = (int)$m[1]; $b = (int)$m[2]; $y = $yr($m[3]);
        return ($b > 12 && $a <= 12) ? $ok($y, $a, $b) : $ok($y, $b, $a);   // normalnya hari/bulan; kalau angka kedua >12 berarti bulan/hari
    }
    $bln = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mei' => 5, 'may' => 5, 'jun' => 6, 'jul' => 7, 'agu' => 8, 'agt' => 8, 'aug' => 8,
            'sep' => 9, 'okt' => 10, 'oct' => 10, 'nov' => 11, 'des' => 12, 'dec' => 12];
    // 5 Sep 2026 / 05-Sep-26 / 5 September 2026
    if (preg_match('/^(\d{1,2})[\s\-\/.]*([A-Za-z]{3,})\.?[\s\-\/.,]*(\d{4}|\d{2})(?!\d)/', $s, $m) && isset($bln[strtolower(substr($m[2], 0, 3))]))
        return $ok($yr($m[3]), $bln[strtolower(substr($m[2], 0, 3))], (int)$m[1]);
    // Sep 5, 2026
    if (preg_match('/^([A-Za-z]{3,})\.?\s+(\d{1,2}),?\s+(\d{4}|\d{2})(?!\d)/', $s, $m) && isset($bln[strtolower(substr($m[1], 0, 3))]))
        return $ok($yr($m[3]), $bln[strtolower(substr($m[1], 0, 3))], (int)$m[2]);
    return null;
}

/** Import 1 file CSV. Jenis dideteksi dari header. */
function importCsv(string $path, string $origName, ?int $userId): array {
    $fh = fopen($path, 'r');
    if (!$fh) throw new RuntimeException('File tidak bisa dibuka.');
    $head = fgetcsv($fh, 0, ',', '"', '');
    if (!$head) throw new RuntimeException('File kosong.');
    $idx = [];
    foreach ($head as $i => $h) { $k = normHeader((string)$h); if ($k !== '' && !isset($idx[$k])) $idx[$k] = $i; }

    if (isset($idx['nofakturjual'])) $jenis = 'penjualan';
    elseif (isset($idx['nofakturbeli'])) $jenis = 'pembelian';
    elseif (isset($idx['kodesales'], $idx['namasales']) && !isset($idx['tglsystem'])) $jenis = 'master';
    else throw new RuntimeException('Format CSV tidak dikenali (kolom no faktur jual / no_fakturbeli / Kode Sales tidak ditemukan).');

    $get = function (array $row, string $key) use ($idx): string {
        return isset($idx[$key], $row[$idx[$key]]) ? cleanText($row[$idx[$key]]) : '';
    };

    $pdo = db();
    $total = $baru = $dup = $invalid = 0;
    $pdo->beginTransaction();
    try {
        if ($jenis === 'master') {
            $st = $pdo->prepare("INSERT INTO master_sales (kode_sales, nama_sales, fee) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE nama_sales = VALUES(nama_sales), fee = VALUES(fee)");
            while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
                if (count($row) === 1 && trim((string)$row[0]) === '') continue;
                $total++;
                $kode = $get($row, 'kodesales'); $nama = $get($row, 'namasales');
                if ($kode === '' || $nama === '') { $invalid++; continue; }
                $st->execute([$kode, $nama, num($get($row, 'fee'))]);
                $rc = $st->rowCount();
                if ($rc === 1) $baru++; else $dup++;   // 2 = diperbarui, 0 = sama persis
            }
        } else {
            $fk = $jenis === 'penjualan' ? 'nofakturjual' : 'nofakturbeli';
            $gk = $jenis === 'penjualan' ? 'nofakturgroupuser' : 'nofakturgroup';
            $hk = $jenis === 'penjualan' ? 'hargatotal' : 'harga';
            $cols = 'no_faktur, no_faktur_group, tgl, jam, kode_sales, kode_member, no_hp, nama_customer, customer_key,
                 kode_group, kode_dept, nama_barang, berat, harga_total, batal';
            $ph = '?,?,?,?,?,?,?,?,?,?,?,?,?,?,?';
            if ($jenis === 'pembelian') {
                // Kolom kondisi (LUAR JUAL SAJA/DOLMAS/dst, dipakai halaman Trimas). Upload ulang CSV yang sama
                // akan memperbarui kondisi pada baris lama sekalipun fakturnya sudah ada (backfill), tanpa menduplikat data.
                $st = $pdo->prepare("INSERT INTO pembelian ($cols, kondisi) VALUES ($ph,?)
                    ON DUPLICATE KEY UPDATE kondisi = VALUES(kondisi)");
            } else {
                // Penjualan menyimpan kode barcode (dipakai mencocokkan dengan stok barang). Upload ulang file lama akan
                // melengkapi barcode pada baris yang sudah ada tanpa menduplikat data.
                $st = $pdo->prepare("INSERT IGNORE INTO penjualan ($cols, kode_barcode) VALUES ($ph,?)
                    ON DUPLICATE KEY UPDATE kode_barcode = IF(VALUES(kode_barcode) <> '', VALUES(kode_barcode), kode_barcode)");
            }
            while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
                if (count($row) === 1 && trim((string)$row[0]) === '') continue;
                $total++;
                $faktur = $get($row, $fk);
                $tgl = normDate($get($row, 'tglsystem'));
                if ($faktur === '' || !$tgl) { $invalid++; continue; }
                $member = $get($row, 'kodemember'); $hp = $get($row, 'nohp'); $nama = $get($row, 'namacustomer');
                $jam = preg_match('/^\d{2}:\d{2}:\d{2}$/', $get($row, 'jam')) ? $get($row, 'jam') : null;
                $group = $get($row, $gk) ?: $faktur;
                $params = [
                    $faktur, $group, $tgl, $jam, $get($row, 'kodesales'), $member, $hp, $nama,
                    customerKey($member, $hp, $nama),
                    $get($row, 'kodegroup'), $get($row, 'kodedept'), $get($row, 'namabarang'),
                    num($get($row, 'berat')), num($get($row, $hk)),
                    $jenis === 'penjualan' ? (int)(num($get($row, 'batalpenjualan')) != 0) : 0,
                ];
                $params[] = $jenis === 'pembelian' ? $get($row, 'kondisi') : $get($row, 'kodebarcode');
                $st->execute($params);
                // rowCount: 1 = baris baru, 0 = duplikat identik, 2 = duplikat tapi kondisi ter-backfill/berubah
                if ($st->rowCount() === 1) $baru++; else $dup++;
            }
        }
        $pdo->prepare("INSERT INTO upload_log (jenis, filename, total_baris, baru, duplikat, user_id) VALUES (?,?,?,?,?,?)")
            ->execute([$jenis, mb_substr($origName, 0, 200), $total, $baru, $dup, $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    } finally {
        fclose($fh);
    }
    return ['file' => $origName, 'jenis' => $jenis, 'total' => $total, 'baru' => $baru, 'duplikat' => $dup, 'invalid' => $invalid];
}

/* ---------- Pengelompokan kode jenis (dipakai modul Barang & statistik Penjualan) ---------- */
// Kode dept pada penjualan = kode jenis pada barang. Di luar tiga daftar ini masuk "OTHER".
if (!defined('JENIS_GROUPS')) define('JENIS_GROUPS', [
    'MD' => ['AN', 'BD', 'CN', 'GA', 'GK', 'GR', 'KL', 'LS'],
    'MT' => ['TA', 'TB', 'TC', 'TGA', 'TGK', 'TGR', 'TK', 'LM'],
    'BERLIAN' => ['BA', 'BB', 'BC', 'BCK', 'BGK', 'BGR', 'BK'],
]);
function jenisGroupOf(string $kode): string {
    $k = strtoupper(trim($kode));
    foreach (JENIS_GROUPS as $g => $list) if (in_array($k, $list, true)) return $g;
    return 'OTHER';
}

/**
 * Statistik kode jenis yang terjual (pcs, gram, rupiah) per kelompok MD / MT / Diamond / Other.
 * Pcs & gram mengikuti aturan rekap (KOTAK, KOTAK LM, SERTIFIKAT tidak dihitung pcs & gram, rupiahnya tetap).
 */
function jenisTerjualStat(string $from, string $to, string $tipe): array {
    $non = sqlIn(DEPT_NON_PCS);
    $st = db()->prepare("SELECT UPPER(TRIM(p.kode_dept)) kode,
            SUM(CASE WHEN p.kode_dept IN $non THEN 0 ELSE 1 END) pcs, SUM(" . gramExpr('penjualan') . ") gram, SUM(p.harga_total) rupiah
        FROM penjualan p LEFT JOIN master_sales m ON m.kode_sales = p.kode_sales
        WHERE p.tgl BETWEEN ? AND ? AND p.batal = 0 " . tipeWhere($tipe) . " GROUP BY kode");
    $st->execute([$from, $to]);
    $by = [];
    foreach ($st->fetchAll() as $r) $by[$r['kode']] = ['pcs' => (int)$r['pcs'], 'gram' => round((float)$r['gram'], 2), 'rupiah' => (float)$r['rupiah']];

    $groups = []; $grand = ['pcs' => 0, 'gram' => 0.0, 'rupiah' => 0.0];
    $mk = function (string $g, array $rows) use (&$grand) {
        $t = ['pcs' => 0, 'gram' => 0.0, 'rupiah' => 0.0];
        foreach ($rows as $r) { $t['pcs'] += $r['pcs']; $t['gram'] += $r['gram']; $t['rupiah'] += $r['rupiah']; }
        $t['gram'] = round($t['gram'], 2);
        foreach (['pcs', 'gram', 'rupiah'] as $k) $grand[$k] += $t[$k];
        return ['group' => $g, 'rows' => $rows, 'total' => $t];
    };
    foreach (JENIS_GROUPS as $g => $list) {
        $rows = [];
        foreach ($list as $kj) { $v = $by[$kj] ?? ['pcs' => 0, 'gram' => 0, 'rupiah' => 0]; $rows[] = ['kode_jenis' => $kj] + $v; }
        $groups[] = $mk($g, $rows);
    }
    $known = array_merge(...array_values(JENIS_GROUPS));
    $other = [];
    foreach ($by as $kj => $v) if (!in_array($kj, $known, true)) $other[] = ['kode_jenis' => $kj !== '' ? $kj : '(kosong)'] + $v;
    usort($other, fn($a, $b) => $b['rupiah'] <=> $a['rupiah']);
    $groups[] = $mk('OTHER', $other);
    $grand['gram'] = round($grand['gram'], 2);
    foreach ($groups as &$g) $g['pct_rupiah'] = $grand['rupiah'] > 0 ? round($g['total']['rupiah'] / $grand['rupiah'] * 100, 1) : 0;
    return ['groups' => $groups, 'total' => $grand];
}


/* ---------- Perbandingan antar periode: tabel SALES x periode (pilihan bebas) ---------- */
if (!defined('BLN_PENDEK')) define('BLN_PENDEK', [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
    7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des']);
const MAX_PERIODE_BANDING = 8;

function tglID(string $ymd): string {
    [$y, $m, $d] = array_map('intval', explode('-', $ymd));
    return $d . ' ' . BLN_PENDEK[$m] . ' ' . $y;
}

/** "2025-10,2026-10" -> daftar bulan valid, unik, urut naik. Item yang bukan YYYY-MM diabaikan. */
function parseMonthList(string $s): array {
    $out = [];
    foreach (explode(',', $s) as $x) {
        $x = trim($x);
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $x)) $out[$x] = true;
    }
    $out = array_keys($out);
    sort($out);
    return array_slice($out, 0, MAX_PERIODE_BANDING);
}

/** "2026-11-01..2026-11-14|2026-10-01..2026-10-31" -> [[from,to],...] valid (from>to ditukar), unik, urut dari tanggal awal. */
function parseRangeList(string $s): array {
    $out = [];
    foreach (explode('|', $s) as $x) {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})\.\.(\d{4})-(\d{2})-(\d{2})$/', trim($x), $m)) continue;
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || !checkdate((int)$m[5], (int)$m[6], (int)$m[4])) continue;
        $a = "$m[1]-$m[2]-$m[3]"; $b = "$m[4]-$m[5]-$m[6]";
        if ($b < $a) [$a, $b] = [$b, $a];
        $out["$a..$b"] = [$a, $b];
    }
    $out = array_values($out);
    usort($out, fn($x, $y) => [$x[0], $x[1]] <=> [$y[0], $y[1]]);
    return array_slice($out, 0, MAX_PERIODE_BANDING);
}

/**
 * Periode dari daftar bulan.
 *  cara 'auto': jika salah satu bulan yang dipilih adalah bulan berjalan, SEMUA bulan dibandingkan H2H sampai tanggal
 *               hari ini (tgl 1 s/d tgl hari ini; bulan yang lebih pendek dipotong di akhir bulannya). Jika tidak ada
 *               bulan berjalan, semua dibandingkan FULL bulan.
 *  cara 'full': selalu full bulan.   cara 'h2h': tgl 1 s/d tanggal $hari pada setiap bulan.
 */
function periodsFromMonths(array $months, string $cara, int $hari, string $today): array {
    $cut = 0;
    if ($cara === 'h2h') $cut = max(1, min(31, $hari));
    elseif ($cara === 'auto' && in_array(substr($today, 0, 7), $months, true)) $cut = (int)substr($today, 8, 2);
    $res = [];
    foreach ($months as $ym) {
        $first = $ym . '-01';
        $lastDay = (int)date('t', strtotime($first));
        $endDay = $cut > 0 ? min($cut, $lastDay) : $lastDay;
        $trunc = $endDay < $lastDay;
        [$y, $m] = array_map('intval', explode('-', $ym));
        $res[] = [
            'type' => 'bulan', 'from' => $first, 'to' => sprintf('%s-%02d', $ym, $endDay),
            'label' => BLN_PENDEK[$m] . ' ' . $y,
            'note' => $trunc ? ($endDay === 1 ? 'tgl 1' : 'tgl 1–' . $endDay) : 'full bulan',
            'truncated' => $trunc,
        ];
    }
    return $res;
}

function periodsFromRanges(array $ranges): array {
    $res = [];
    foreach ($ranges as [$a, $b]) {
        $days = (int)round((strtotime($b) - strtotime($a)) / 86400) + 1;
        $res[] = ['type' => 'rentang', 'from' => $a, 'to' => $b,
            'label' => $a === $b ? tglID($a) : tglID($a) . ' – ' . tglID($b), 'note' => $days . ' hari', 'truncated' => false];
    }
    return $res;
}

/** $diamond: exclude (emas saja, sama dengan halaman Penjualan) | all (emas + berlian) | only (berlian saja). */
function compareMulti(array $periods, string $tipe, string $diamond): array {
    $sales = [];
    foreach ($periods as $i => &$p) {
        $rows = rekapSales('penjualan', $p['from'], $p['to'], $tipe, $diamond);
        $p['total'] = totals($rows);
        foreach ($rows as $r) {
            $k = $r['kode_sales'];
            if (!isset($sales[$k])) {
                $sales[$k] = ['kode' => $k, 'nama' => $r['nama_sales'], 'tipe' => $r['tipe'],
                    'v' => array_fill(0, count($periods), ['pcs' => 0, 'gram' => 0.0, 'rupiah' => 0.0])];
            }
            $sales[$k]['v'][$i] = ['pcs' => $r['pcs'], 'gram' => $r['gram'], 'rupiah' => $r['rupiah']];
        }
    }
    unset($p);
    $list = array_values($sales);
    foreach ($list as &$s) $s['sum_rp'] = array_sum(array_column($s['v'], 'rupiah'));
    unset($s);
    // urut: rupiah gabungan semua periode terbesar dulu; baris "Lainnya" gabungan sales bukan toko selalu paling bawah
    usort($list, fn($a, $b) => [$a['kode'] === 'OTHER', $b['sum_rp']] <=> [$b['kode'] === 'OTHER', $a['sum_rp']]);
    return ['periods' => $periods, 'sales' => $list];
}

/* ---------- Pareto customer per kelompok kode dept ---------- */
if (!defined('PARETO_GROUPS')) define('PARETO_GROUPS', [
    'MD' => ['AN', 'BD', 'CN', 'GA', 'GK', 'GR', 'KL'],
    'MT' => ['TA', 'TB', 'TC', 'TGA', 'TGK', 'TGR', 'TK'],
    'LM' => ['LM'],
    'LS' => ['LS'],
    'BERLIAN' => ['BA', 'BB', 'BC', 'BCK', 'BGK', 'BGR', 'BK'],
]);

/**
 * Pareto customer dengan kolom per kelompok MD / MT / LM / LS / BERLIAN / OTHERS (pcs, gram, rupiah) + total.
 * OTHERS = semua kode dept di luar lima kelompok (mis. KOTAK, SERTIFIKAT, BKL, TBKL); rinciannya ada di 'others_detail'.
 * Pcs = jumlah baris barang (penjualan: KOTAK, KOTAK LM, SERTIFIKAT tidak dihitung pcs & gram, sama dengan halaman Penjualan),
 * gram = kolom berat, rupiah = kolom harga total. urut: trx | rupiah | gram | pcs.
 */
function paretoGrup(string $tbl, string $from, string $to, string $urut, int $limit, string $q = ''): array {
    $keys = array_merge(array_keys(PARETO_GROUPS), ['OTHERS']);
    $case = 'CASE';
    foreach (PARETO_GROUPS as $g => $list) $case .= " WHEN UPPER(TRIM(t.kode_dept)) IN " . sqlIn($list) . " THEN '$g'";
    $case .= " ELSE 'OTHERS' END";
    // penjualan: dept KOTAK / KOTAK LM / SERTIFIKAT bukan barang emas, jadi pcs & gramnya 0 (rupiah tetap dihitung)
    $cnt = $tbl === 'penjualan' ? "p.grp = '%s' AND p.dept NOT IN " . sqlIn(DEPT_NON_PCS) : "p.grp = '%s'";
    $sel = [];
    foreach ($keys as $g) {
        $k = strtolower($g);
        $c = sprintf($cnt, $g);
        $sel[] = "SUM(IF($c, 1, 0)) AS {$k}_pcs, SUM(IF($c, p.berat, 0)) AS {$k}_gram, SUM(IF(p.grp = '$g', p.harga_total, 0)) AS {$k}_rp";
    }
    $sub = "SELECT t.customer_key, t.nama_customer, t.no_hp, t.kode_member, t.no_faktur_group, t.tgl, t.berat, t.harga_total,
                   UPPER(TRIM(t.kode_dept)) AS dept, $case AS grp
            FROM $tbl t WHERE t.tgl BETWEEN ? AND ? AND t.batal = 0";
    $sql = "SELECT p.customer_key, MAX(p.nama_customer) AS nama, MAX(p.no_hp) AS hp, MAX(p.kode_member) AS member,
              COUNT(DISTINCT p.no_faktur_group) AS trx, MAX(p.tgl) AS terakhir, " . implode(', ', $sel) . "
            FROM ($sub) p GROUP BY p.customer_key";
    $st = db()->prepare($sql); $st->execute([$from, $to]);
    $anon = 0; $rows = [];
    foreach ($st->fetchAll() as $r) {
        if ($r['customer_key'][0] === 'N' && in_array(substr($r['customer_key'], 2), GENERIC_CUSTOMER_NAMES, true)) { $anon += (int)$r['trx']; continue; }
        $row = ['customer_key' => $r['customer_key'], 'nama' => $r['nama'], 'hp' => $r['hp'], 'member' => $r['member'],
                'trx' => (int)$r['trx'], 'terakhir' => $r['terakhir'], 'g' => [], 'pcs' => 0, 'gram' => 0.0, 'rupiah' => 0.0];
        foreach ($keys as $g) {
            $k = strtolower($g);
            // gram dibulatkan 2 desimal per kelompok; total = jumlah angka yang tampil, supaya penjumlahan di layar selalu pas
            $v = ['pcs' => (int)$r[$k . '_pcs'], 'gram' => round((float)$r[$k . '_gram'], 2), 'rupiah' => (float)$r[$k . '_rp']];
            $row['g'][$g] = $v;
            $row['pcs'] += $v['pcs']; $row['gram'] += $v['gram']; $row['rupiah'] += $v['rupiah'];
        }
        $row['gram'] = round($row['gram'], 2);
        $row['jenis'] = $row['customer_key'][0] === 'M' ? 'Member' : ($row['customer_key'][0] === 'H' ? 'No HP' : 'Nama');
        $rows[] = $row;
    }
    $metric = in_array($urut, ['rupiah', 'gram', 'pcs'], true) ? $urut : 'trx';
    usort($rows, fn($a, $b) => [$b[$metric], $b['rupiah']] <=> [$a[$metric], $a['rupiah']]);
    $sum = array_sum(array_column($rows, $metric)) ?: 1;
    $cum = 0; $topA = 0;
    foreach ($rows as $i => &$r) {
        $before = $cum / $sum;
        $cum += $r[$metric];
        $r['no'] = $i + 1;
        $r['pct'] = round($r[$metric] / $sum * 100, 2);
        $r['cum'] = round($cum / $sum * 100, 2);
        $r['kelas'] = $before < 0.8 ? 'A' : ($before < 0.95 ? 'B' : 'C');
        if ($r['kelas'] === 'A') $topA++;
    }
    unset($r);
    // total semua customer (sebelum pencarian / batas tampil)
    $grand = ['g' => [], 'pcs' => 0, 'gram' => 0.0, 'rupiah' => 0.0];
    foreach ($keys as $g) $grand['g'][$g] = ['pcs' => 0, 'gram' => 0.0, 'rupiah' => 0.0];
    foreach ($rows as $r) {
        foreach ($keys as $g) foreach (['pcs', 'gram', 'rupiah'] as $f) $grand['g'][$g][$f] += $r['g'][$g][$f];
        $grand['pcs'] += $r['pcs']; $grand['gram'] += $r['gram']; $grand['rupiah'] += $r['rupiah'];
    }
    foreach ($keys as $g) $grand['g'][$g]['gram'] = round($grand['g'][$g]['gram'], 2);
    $grand['gram'] = round($grand['gram'], 2);
    $summary = [
        'customer' => count($rows), 'trx' => array_sum(array_column($rows, 'trx')),
        'repeat' => count(array_filter($rows, fn($r) => $r['trx'] >= 2)), 'kelasA' => $topA,
        'rupiah' => $grand['rupiah'], 'tanpa_identitas' => $anon,
    ];
    // rincian isi kolom OTHERS (kode dept di luar lima kelompok)
    $ex = db()->prepare("SELECT UPPER(TRIM(t.kode_dept)) AS dept, COUNT(*) AS baris, SUM(t.harga_total) AS rupiah
        FROM $tbl t WHERE t.tgl BETWEEN ? AND ? AND t.batal = 0 AND ($case) = 'OTHERS'
        GROUP BY UPPER(TRIM(t.kode_dept)) ORDER BY rupiah DESC");
    $ex->execute([$from, $to]);
    $others = [];
    foreach ($ex->fetchAll() as $e) $others[] = ['dept' => $e['dept'] !== '' ? $e['dept'] : '(kosong)', 'baris' => (int)$e['baris'], 'rupiah' => (float)$e['rupiah']];
    if ($q !== '') $rows = array_values(array_filter($rows, fn($r) => stripos($r['nama'] . ' ' . $r['hp'] . ' ' . $r['member'], $q) !== false));
    $shown = ['pcs' => 0, 'gram' => 0.0, 'rupiah' => 0.0, 'g' => []];
    $matched = count($rows);
    if ($limit > 0) $rows = array_slice($rows, 0, $limit);
    foreach ($keys as $g) $shown['g'][$g] = ['pcs' => 0, 'gram' => 0.0, 'rupiah' => 0.0];
    foreach ($rows as $r) {
        foreach ($keys as $g) foreach (['pcs', 'gram', 'rupiah'] as $f) $shown['g'][$g][$f] += $r['g'][$g][$f];
        $shown['pcs'] += $r['pcs']; $shown['gram'] += $r['gram']; $shown['rupiah'] += $r['rupiah'];
    }
    foreach ($keys as $g) $shown['g'][$g]['gram'] = round($shown['g'][$g]['gram'], 2);
    $shown['gram'] = round($shown['gram'], 2);
    return ['rows' => $rows, 'summary' => $summary, 'metric' => $metric, 'groups' => $keys,
            'total' => $grand, 'shown' => $shown, 'matched' => $matched, 'others_detail' => $others];
}
