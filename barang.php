<?php
// Modul Barang: stok fisik per barcode, rekap per kode jenis, dan proses retur.
require_once __DIR__ . '/forecast.php';   // pakai monthLabelID(), sqlIn(), dsb.

// Pengelompokan kode jenis -> kategori (MD / MT / BERLIAN). Di luar daftar ini masuk "OTHER".
if (!defined('BARANG_GROUPS')) define('BARANG_GROUPS', [
    'MD' => ['AN', 'BD', 'CN', 'GA', 'GK', 'GR', 'KL', 'LS'],
    'MT' => ['TA', 'TB', 'TC', 'TGA', 'TGK', 'TGR', 'TK', 'LM'],
    'BERLIAN' => ['BA', 'BB', 'BC', 'BCK', 'BGK', 'BGR', 'BK'],
]);
if (!defined('BARANG_GROUP_LABEL')) define('BARANG_GROUP_LABEL', ['MD' => 'MD', 'MT' => 'MT', 'BERLIAN' => 'Berlian', 'OTHER' => 'Other']);

function barangGroupOf(string $kodeJenis): string {
    $k = strtoupper(trim($kodeJenis));
    foreach (BARANG_GROUPS as $g => $list) if (in_array($k, $list, true)) return $g;
    return 'OTHER';
}

function ensureBarangSchema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('barang', $tables, true)) {
        $pdo->exec("CREATE TABLE barang (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            kode_barcode VARCHAR(50) NOT NULL,
            kode_jenis VARCHAR(20) NOT NULL DEFAULT '',
            kode_baki VARCHAR(20) NOT NULL DEFAULT '',
            warna VARCHAR(50) NOT NULL DEFAULT '',
            berat DECIMAL(12,3) NOT NULL DEFAULT 0,
            tanggal_input DATE NULL,
            status ENUM('stok','retur') NOT NULL DEFAULT 'stok',
            retur_batch_id BIGINT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_barcode (kode_barcode),
            KEY idx_jenis (kode_jenis), KEY idx_status (status), KEY idx_tgl (tanggal_input)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!in_array('retur_batch', $tables, true)) {
        $pdo->exec("CREATE TABLE retur_batch (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tanggal DATE NOT NULL,
            cabang VARCHAR(150) NOT NULL DEFAULT '',
            ttd1_nama VARCHAR(100) NOT NULL DEFAULT '', ttd1_jabatan VARCHAR(100) NOT NULL DEFAULT '',
            ttd2_nama VARCHAR(100) NOT NULL DEFAULT '', ttd2_jabatan VARCHAR(100) NOT NULL DEFAULT '',
            ttd3_nama VARCHAR(100) NOT NULL DEFAULT '', ttd3_jabatan VARCHAR(100) NOT NULL DEFAULT '',
            ttd4_nama VARCHAR(100) NOT NULL DEFAULT '', ttd4_jabatan VARCHAR(100) NOT NULL DEFAULT '',
            user_id INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if (!in_array('retur_item', $tables, true)) {
        $pdo->exec("CREATE TABLE retur_item (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            retur_batch_id BIGINT NOT NULL,
            barang_id BIGINT NOT NULL,
            kode_barcode VARCHAR(50) NOT NULL,
            kode_baki VARCHAR(20) NOT NULL DEFAULT '',
            kode_jenis VARCHAR(20) NOT NULL DEFAULT '',
            berat DECIMAL(12,3) NOT NULL DEFAULT 0,
            keterangan VARCHAR(255) NOT NULL DEFAULT '',
            bulan VARCHAR(20) NOT NULL DEFAULT '',
            KEY idx_batch (retur_batch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

/** Import CSV/Excel barang. Kode barcode = kunci unik (duplikat dilewati, tidak menambah data baru). */
function importBarangCsv(string $path, string $origName, ?int $userId): array {
    ensureBarangSchema();
    $fh = fopen($path, 'r');
    if (!$fh) throw new RuntimeException('File tidak bisa dibuka.');
    $head = fgetcsv($fh, 0, ',', '"', '');
    if (!$head) throw new RuntimeException('File kosong.');
    $idx = [];
    foreach ($head as $i => $h) { $k = normHeader((string)$h); if ($k !== '' && !isset($idx[$k])) $idx[$k] = $i; }
    if (!isset($idx['kodebarcode'])) throw new RuntimeException('Kolom KODE BARCODE tidak ditemukan di file ini.');
    $get = function (array $row, string $key) use ($idx): string {
        return isset($idx[$key], $row[$idx[$key]]) ? trim((string)$row[$idx[$key]]) : '';
    };

    $pdo = db();
    $total = $baru = $dup = $invalid = 0;
    $st = $pdo->prepare("INSERT IGNORE INTO barang (kode_barcode, kode_jenis, kode_baki, warna, berat, tanggal_input) VALUES (?,?,?,?,?,?)");
    $pdo->beginTransaction();
    try {
        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if (count($row) === 1 && trim((string)$row[0]) === '') continue;
            $total++;
            $barcode = $get($row, 'kodebarcode');
            if ($barcode === '') { $invalid++; continue; }
            $tgl = normDate($get($row, 'tanggalinput'));
            $st->execute([$barcode, strtoupper($get($row, 'kodejenis')), strtoupper($get($row, 'kodebaki')), $get($row, 'warna'), num($get($row, 'berat')), $tgl]);
            if ($st->rowCount() > 0) $baru++; else $dup++;
        }
        $pdo->prepare("INSERT INTO upload_log (jenis, filename, total_baris, baru, duplikat, user_id) VALUES ('barang',?,?,?,?,?)")
            ->execute([mb_substr($origName, 0, 200), $total, $baru, $dup, $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    } finally {
        fclose($fh);
    }
    return ['file' => $origName, 'jenis' => 'barang', 'total' => $total, 'baru' => $baru, 'duplikat' => $dup, 'invalid' => $invalid];
}

/** Daftar barang (Detail), dengan filter, sortir, dan halaman. */
function barangList(array $f, int $page, int $perPage): array {
    ensureBarangSchema();
    $where = ['1=1']; $params = [];
    if (!empty($f['from'])) { $where[] = 'tanggal_input >= ?'; $params[] = $f['from']; }
    if (!empty($f['to'])) { $where[] = 'tanggal_input <= ?'; $params[] = $f['to']; }
    if (!empty($f['kode_jenis'])) { $where[] = 'kode_jenis = ?'; $params[] = strtoupper($f['kode_jenis']); }
    if (!empty($f['kode_baki'])) { $where[] = 'kode_baki = ?'; $params[] = strtoupper($f['kode_baki']); }
    if (!empty($f['status'])) { $where[] = 'status = ?'; $params[] = $f['status']; }
    if (!empty($f['q'])) { $where[] = '(kode_barcode LIKE ? OR warna LIKE ?)'; $params[] = '%' . $f['q'] . '%'; $params[] = '%' . $f['q'] . '%'; }
    $wsql = implode(' AND ', $where);

    $sortCols = ['tanggal_input', 'kode_barcode', 'kode_jenis', 'kode_baki', 'warna', 'berat', 'created_at'];
    $sort = in_array($f['sort'] ?? '', $sortCols, true) ? $f['sort'] : 'created_at';
    $dir = ($f['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

    $cst = db()->prepare("SELECT COUNT(*) FROM barang WHERE $wsql");
    $cst->execute($params);
    $total = (int)$cst->fetchColumn();

    $perPage = max(10, min(500, $perPage));
    $page = max(1, $page);
    $offset = ($page - 1) * $perPage;
    $st = db()->prepare("SELECT id, kode_barcode, kode_jenis, kode_baki, warna, berat, tanggal_input, status, created_at
        FROM barang WHERE $wsql ORDER BY $sort $dir, id DESC LIMIT $perPage OFFSET $offset");
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) { $r['berat'] = round((float)$r['berat'], 3); $r['group'] = barangGroupOf($r['kode_jenis']); }
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int)ceil($total / $perPage))];
}

/** Rekap stok (hanya status=stok) per kode jenis, dikelompokkan MD/MT/BERLIAN/OTHER. */
function barangStockSummary(array $f): array {
    ensureBarangSchema();
    $where = ["status = 'stok'"]; $params = [];
    if (!empty($f['from'])) { $where[] = 'tanggal_input >= ?'; $params[] = $f['from']; }
    if (!empty($f['to'])) { $where[] = 'tanggal_input <= ?'; $params[] = $f['to']; }
    $wsql = implode(' AND ', $where);
    $st = db()->prepare("SELECT kode_jenis, COUNT(*) pcs, SUM(berat) gram FROM barang WHERE $wsql GROUP BY kode_jenis");
    $st->execute($params);
    $byJenis = [];
    foreach ($st->fetchAll() as $r) $byJenis[strtoupper($r['kode_jenis'])] = ['pcs' => (int)$r['pcs'], 'gram' => round((float)$r['gram'], 3)];

    $groups = [];
    foreach (BARANG_GROUPS as $g => $list) {
        $rows = []; $tp = 0; $tg = 0.0;
        foreach ($list as $kj) {
            $v = $byJenis[$kj] ?? ['pcs' => 0, 'gram' => 0];
            $rows[] = ['kode_jenis' => $kj, 'pcs' => $v['pcs'], 'gram' => $v['gram']];
            $tp += $v['pcs']; $tg += $v['gram'];
        }
        $groups[] = ['group' => $g, 'label' => BARANG_GROUP_LABEL[$g], 'rows' => $rows, 'total' => ['pcs' => $tp, 'gram' => round($tg, 3)]];
    }
    // OTHER: kode jenis apa pun yang tidak ada di 3 daftar di atas
    $known = array_merge(...array_values(BARANG_GROUPS));
    $otherRows = []; $otp = 0; $otg = 0.0;
    foreach ($byJenis as $kj => $v) {
        if (in_array($kj, $known, true)) continue;
        $otherRows[] = ['kode_jenis' => $kj ?: '(kosong)', 'pcs' => $v['pcs'], 'gram' => $v['gram']];
        $otp += $v['pcs']; $otg += $v['gram'];
    }
    usort($otherRows, fn($a, $b) => $b['pcs'] <=> $a['pcs']);
    $groups[] = ['group' => 'OTHER', 'label' => BARANG_GROUP_LABEL['OTHER'], 'rows' => $otherRows, 'total' => ['pcs' => $otp, 'gram' => round($otg, 3)]];

    $grand = ['pcs' => 0, 'gram' => 0.0];
    foreach ($groups as $g) { $grand['pcs'] += $g['total']['pcs']; $grand['gram'] += $g['total']['gram']; }
    $grand['gram'] = round($grand['gram'], 3);
    return ['groups' => $groups, 'total' => $grand];
}

/** Cari satu barang lewat barcode untuk proses scan retur. Hanya barang berstatus stok yang bisa diretur. */
function barangByBarcode(string $barcode): ?array {
    ensureBarangSchema();
    $st = db()->prepare("SELECT * FROM barang WHERE kode_barcode = ?");
    $st->execute([trim($barcode)]);
    $r = $st->fetch();
    if (!$r) return null;
    $r['berat'] = round((float)$r['berat'], 3);
    $r['bulan'] = $r['tanggal_input'] ? monthLabelID(substr($r['tanggal_input'], 0, 7)) : '';
    return $r;
}

/** Simpan batch retur: catat retur_batch + retur_item, dan tandai barang-nya jadi status=retur. */
function returSave(array $header, array $items): int {
    ensureBarangSchema();
    if (!$items) throw new RuntimeException('Belum ada barang yang discan.');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO retur_batch (tanggal, cabang, ttd1_nama, ttd1_jabatan, ttd2_nama, ttd2_jabatan, ttd3_nama, ttd3_jabatan, ttd4_nama, ttd4_jabatan, user_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)")->execute([
            $header['tanggal'], $header['cabang'],
            $header['ttd1_nama'], $header['ttd1_jabatan'], $header['ttd2_nama'], $header['ttd2_jabatan'],
            $header['ttd3_nama'], $header['ttd3_jabatan'], $header['ttd4_nama'], $header['ttd4_jabatan'], $header['user_id'] ?? null,
        ]);
        $batchId = (int)$pdo->lastInsertId();
        $insItem = $pdo->prepare("INSERT INTO retur_item (retur_batch_id, barang_id, kode_barcode, kode_baki, kode_jenis, berat, keterangan, bulan) VALUES (?,?,?,?,?,?,?,?)");
        $markRetur = $pdo->prepare("UPDATE barang SET status = 'retur', retur_batch_id = ? WHERE id = ? AND status = 'stok'");
        foreach ($items as $it) {
            $b = barangByBarcode($it['kode_barcode']);
            if (!$b || $b['status'] !== 'stok') continue;   // lewati yang sudah tidak berstatus stok (mis. diretur di tab lain)
            $insItem->execute([$batchId, $b['id'], $b['kode_barcode'], $b['kode_baki'], $b['kode_jenis'], $b['berat'], $it['keterangan'] ?? '', $b['bulan']]);
            $markRetur->execute([$batchId, $b['id']]);
        }
        $pdo->commit();
        return $batchId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function returList(int $limit = 100): array {
    ensureBarangSchema();
    $st = db()->query("SELECT b.id, b.tanggal, b.cabang, b.created_at, COUNT(i.id) pcs, SUM(i.berat) gram
        FROM retur_batch b LEFT JOIN retur_item i ON i.retur_batch_id = b.id
        GROUP BY b.id ORDER BY b.id DESC LIMIT " . (int)$limit);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) { $r['pcs'] = (int)$r['pcs']; $r['gram'] = round((float)$r['gram'], 3); }
    return $rows;
}

function returDetail(int $id): ?array {
    ensureBarangSchema();
    $st = db()->prepare("SELECT * FROM retur_batch WHERE id = ?");
    $st->execute([$id]);
    $batch = $st->fetch();
    if (!$batch) return null;
    $st = db()->prepare("SELECT * FROM retur_item WHERE retur_batch_id = ? ORDER BY id");
    $st->execute([$id]);
    $items = $st->fetchAll();
    $total = ['pcs' => count($items), 'gram' => 0.0];
    foreach ($items as &$it) { $it['berat'] = round((float)$it['berat'], 3); $total['gram'] += $it['berat']; }
    $total['gram'] = round($total['gram'], 3);
    return ['batch' => $batch, 'items' => $items, 'total' => $total];
}