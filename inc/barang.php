<?php
// Modul Barang: stok fisik per barcode, rekap per kode jenis, dan proses retur.
require_once __DIR__ . '/forecast.php';   // pakai monthLabelID(), sqlIn(), dsb.

// Pengelompokan kode jenis (MD / MT / BERLIAN / OTHER) dibagi bersama dengan statistik Penjualan: lihat JENIS_GROUPS di lib.php.
if (!defined('BARANG_GROUPS')) define('BARANG_GROUPS', JENIS_GROUPS);
if (!defined('BARANG_GROUP_LABEL')) define('BARANG_GROUP_LABEL', ['MD' => 'MD', 'MT' => 'MT', 'BERLIAN' => 'Berlian', 'OTHER' => 'Other']);

function barangGroupOf(string $kodeJenis): string { return jenisGroupOf($kodeJenis); }

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
            status ENUM('stok','retur','terjual') NOT NULL DEFAULT 'stok',
            retur_batch_id BIGINT NULL,
            terjual_tgl DATE NULL,
            terjual_faktur VARCHAR(60) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_barcode (kode_barcode),
            KEY idx_jenis (kode_jenis), KEY idx_status (status), KEY idx_tgl (tanggal_input)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    else {
        // tabel lama: tambah status "terjual" + kolom jejak penjualan
        $st = $pdo->query("SHOW COLUMNS FROM barang LIKE 'status'")->fetch();
        if ($st && stripos((string)$st['Type'], 'terjual') === false)
            $pdo->exec("ALTER TABLE barang MODIFY status ENUM('stok','retur','terjual') NOT NULL DEFAULT 'stok'");
        $bc = array_column($pdo->query("SHOW COLUMNS FROM barang")->fetchAll(), 'Field');
        if (!in_array('terjual_tgl', $bc, true)) $pdo->exec("ALTER TABLE barang ADD COLUMN terjual_tgl DATE NULL, ADD COLUMN terjual_faktur VARCHAR(60) NULL");
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

/** Bersihkan data barang yang sudah ada: spasi di awal/akhir (termasuk NBSP & karakter tak terlihat) dibuang. */
function barangCleanup(): int {
    ensureBarangSchema();
    $nb = "CONVERT(UNHEX('C2A0') USING utf8mb4)"; $zw = "CONVERT(UNHEX('E2808B') USING utf8mb4)"; $bom = "CONVERT(UNHEX('EFBBBF') USING utf8mb4)";
    $n = 0;
    foreach (['kode_barcode', 'kode_jenis', 'kode_baki', 'warna'] as $c) {
        $fix = "TRIM(REPLACE(REPLACE(REPLACE($c, $nb, ' '), $zw, ''), $bom, ''))";
        $n += (int)db()->exec("UPDATE IGNORE barang SET $c = $fix");   // hanya baris yang benar-benar berubah yang dihitung
    }
    return $n;
}

/**
 * Cocokkan stok barang dengan data penjualan lewat kode barcode: barang yang barcode-nya sudah ada di penjualan
 * (dan penjualannya tidak batal) ditandai "terjual" sehingga keluar dari stok. Barang yang sudah retur tidak disentuh.
 */
function barangSync(): array {
    ensureBarangSchema();
    $cleaned = barangCleanup();
    $n = db()->exec("UPDATE barang b JOIN (
            SELECT kode_barcode, MIN(tgl) tgl, MIN(no_faktur) faktur FROM penjualan
            WHERE kode_barcode <> '' AND batal = 0 GROUP BY kode_barcode
        ) p ON p.kode_barcode = b.kode_barcode
        SET b.status = 'terjual', b.terjual_tgl = p.tgl, b.terjual_faktur = p.faktur
        WHERE b.status = 'stok'");
    $pj = db()->query("SELECT COUNT(*) total, SUM(kode_barcode <> '') berbarcode FROM penjualan")->fetch();
    return ['terjual' => (int)$n, 'dibersihkan' => $cleaned, 'penjualan_total' => (int)$pj['total'], 'penjualan_berbarcode' => (int)$pj['berbarcode']];
}

/** Import CSV/Excel barang. Kode barcode = kunci unik; barcode yang sudah ada tidak dobel, hanya data kosongnya dilengkapi. */
function importBarangCsv(string $path, string $origName, ?int $userId): array {
    ensureBarangSchema();
    barangCleanup();   // bersihkan spasi di data lama dulu, supaya barcode " BC1" lama tidak dianggap beda dengan "BC1" baru
    $fh = fopen($path, 'r');
    if (!$fh) throw new RuntimeException('File tidak bisa dibuka.');
    // pemisah kolom otomatis: koma / titik koma / tab (Excel Indonesia sering memakai titik koma)
    $first = (string)fgets($fh); rewind($fh);
    $delim = ','; $best = substr_count($first, ',');
    foreach ([';', "\t"] as $d) if (substr_count($first, $d) > $best) { $best = substr_count($first, $d); $delim = $d; }
    $head = fgetcsv($fh, 0, $delim, '"', '');
    if (!$head) throw new RuntimeException('File kosong.');
    $idx = [];
    foreach ($head as $i => $h) { $k = normHeader((string)$h); if ($k !== '' && !isset($idx[$k])) $idx[$k] = $i; }
    if (!isset($idx['kodebarcode'])) throw new RuntimeException('Kolom KODE BARCODE tidak ditemukan di file ini.');
    $get = function (array $row, string $key) use ($idx): string {
        return isset($idx[$key], $row[$idx[$key]]) ? cleanText($row[$idx[$key]]) : '';
    };

    $pdo = db();
    $total = $baru = $upd = $dup = $invalid = $tglGagal = 0; $contoh = [];
    $st = $pdo->prepare("INSERT IGNORE INTO barang (kode_barcode, kode_jenis, kode_baki, warna, berat, tanggal_input) VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          tanggal_input = IF(tanggal_input IS NULL, VALUES(tanggal_input), tanggal_input),
          kode_jenis = IF(kode_jenis = '', VALUES(kode_jenis), kode_jenis),
          kode_baki = IF(kode_baki = '', VALUES(kode_baki), kode_baki),
          warna = IF(warna = '', VALUES(warna), warna),
          berat = IF(berat = 0, VALUES(berat), berat)");
    $pdo->beginTransaction();
    try {
        while (($row = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            if (count($row) === 1 && trim((string)$row[0]) === '') continue;
            $total++;
            $barcode = $get($row, 'kodebarcode');
            if ($barcode === '') { $invalid++; continue; }
            $rawTgl = $get($row, 'tanggalinput');
            $tgl = normDate($rawTgl);
            if ($rawTgl !== '' && $tgl === null) { $tglGagal++; if (count($contoh) < 5 && !in_array($rawTgl, $contoh, true)) $contoh[] = $rawTgl; }
            $st->execute([mb_substr($barcode, 0, 50), mb_strtoupper($get($row, 'kodejenis')), mb_strtoupper($get($row, 'kodebaki')), $get($row, 'warna'), num($get($row, 'berat')), $tgl]);
            $rc = $st->rowCount();
            if ($rc === 1) $baru++; elseif ($rc === 2) $upd++; else $dup++;
        }
        $pdo->prepare("INSERT INTO upload_log (jenis, filename, total_baris, baru, duplikat, user_id) VALUES ('barang',?,?,?,?,?)")
            ->execute([mb_substr($origName, 0, 200), $total, $baru, $dup + $upd, $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    } finally {
        fclose($fh);
    }
    return ['file' => $origName, 'jenis' => 'barang', 'total' => $total, 'baru' => $baru, 'diperbarui' => $upd, 'duplikat' => $dup,
            'invalid' => $invalid, 'tgl_gagal' => $tglGagal, 'contoh_tgl' => $contoh];
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
    if (!empty($f['q'])) { $where[] = '(kode_barcode LIKE ? OR warna LIKE ?)'; $params[] = '%' . trim($f['q']) . '%'; $params[] = '%' . trim($f['q']) . '%'; }
    if (!empty($f['tgl_kosong'])) { $where[] = 'tanggal_input IS NULL'; }
    $wsql = implode(' AND ', $where);

    $sortCols = ['tanggal_input', 'kode_barcode', 'kode_jenis', 'kode_baki', 'warna', 'berat', 'status', 'created_at'];
    $sort = in_array($f['sort'] ?? '', $sortCols, true) ? $f['sort'] : 'created_at';
    $dir = ($f['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

    $cst = db()->prepare("SELECT COUNT(*) FROM barang WHERE $wsql");
    $cst->execute($params);
    $total = (int)$cst->fetchColumn();

    $perPage = max(10, min(500, $perPage));
    $page = max(1, $page);
    $offset = ($page - 1) * $perPage;
    $st = db()->prepare("SELECT id, kode_barcode, kode_jenis, kode_baki, warna, berat, tanggal_input, status, terjual_tgl, terjual_faktur, created_at
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
    // jumlah barang per status (stok / retur / terjual) pada rentang tanggal input yang sama
    $dw = []; $dp = [];
    if (!empty($f['from'])) { $dw[] = 'tanggal_input >= ?'; $dp[] = $f['from']; }
    if (!empty($f['to'])) { $dw[] = 'tanggal_input <= ?'; $dp[] = $f['to']; }
    $q = db()->prepare("SELECT status, COUNT(*) n FROM barang" . ($dw ? ' WHERE ' . implode(' AND ', $dw) : '') . " GROUP BY status");
    $q->execute($dp);
    $by = ['stok' => 0, 'retur' => 0, 'terjual' => 0];
    foreach ($q->fetchAll() as $r) $by[$r['status']] = (int)$r['n'];
    $kosong = (int)db()->query("SELECT COUNT(*) FROM barang WHERE tanggal_input IS NULL")->fetchColumn();
    return ['groups' => $groups, 'total' => $grand, 'by_status' => $by, 'tgl_kosong' => $kosong];
}

/** Cari satu barang lewat barcode untuk proses scan retur. Hanya barang berstatus stok yang bisa diretur. */
function barangByBarcode(string $barcode): ?array {
    ensureBarangSchema();
    $st = db()->prepare("SELECT * FROM barang WHERE kode_barcode = ?");
    $st->execute([cleanText($barcode)]);
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