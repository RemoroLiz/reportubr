-- Laporan Penjualan & Pembelian - skema MySQL/MariaDB
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  nama VARCHAR(100) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- tipe: 'toko' = sales terdaftar toko, 'luar' = sales luar toko
CREATE TABLE IF NOT EXISTS master_sales (
  kode_sales VARCHAR(20) NOT NULL PRIMARY KEY,
  nama_sales VARCHAR(100) NOT NULL,
  fee DECIMAL(12,2) NOT NULL DEFAULT 0,
  tipe ENUM('toko','luar') NOT NULL DEFAULT 'toko',
  grade VARCHAR(30) NOT NULL DEFAULT '',
  target_pcs INT NOT NULL DEFAULT 0,
  cabang VARCHAR(20) NOT NULL DEFAULT '',
  nama_laporan VARCHAR(60) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS penjualan (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  no_faktur VARCHAR(60) NOT NULL,          -- CSV: no fakturjual (kode unik)
  no_faktur_group VARCHAR(60) NOT NULL,    -- CSV: no fakturgroupuser (1 nota = banyak barang)
  tgl DATE NOT NULL,
  jam TIME NULL,
  kode_sales VARCHAR(20) NOT NULL,
  kode_member VARCHAR(30) NOT NULL DEFAULT '',
  no_hp VARCHAR(30) NOT NULL DEFAULT '',
  nama_customer VARCHAR(150) NOT NULL DEFAULT '',
  customer_key VARCHAR(190) NOT NULL,
  kode_group VARCHAR(20) NOT NULL DEFAULT '',
  kode_dept VARCHAR(30) NOT NULL DEFAULT '',
  nama_barang VARCHAR(200) NOT NULL DEFAULT '',
  berat DECIMAL(12,3) NOT NULL DEFAULT 0,
  harga_total DECIMAL(16,0) NOT NULL DEFAULT 0,
  batal TINYINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_faktur (no_faktur),
  KEY idx_tgl (tgl), KEY idx_sales (kode_sales, tgl),
  KEY idx_cust (customer_key), KEY idx_group (no_faktur_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pembelian (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  no_faktur VARCHAR(60) NOT NULL,          -- CSV: no_fakturbeli (kode unik)
  no_faktur_group VARCHAR(60) NOT NULL,    -- CSV: no_fakturgroup
  tgl DATE NOT NULL,
  jam TIME NULL,
  kode_sales VARCHAR(20) NOT NULL,
  kode_member VARCHAR(30) NOT NULL DEFAULT '',
  no_hp VARCHAR(30) NOT NULL DEFAULT '',
  nama_customer VARCHAR(150) NOT NULL DEFAULT '',
  customer_key VARCHAR(190) NOT NULL,
  kode_group VARCHAR(20) NOT NULL DEFAULT '',
  kode_dept VARCHAR(30) NOT NULL DEFAULT '',
  nama_barang VARCHAR(200) NOT NULL DEFAULT '',
  berat DECIMAL(12,3) NOT NULL DEFAULT 0,
  harga_total DECIMAL(16,0) NOT NULL DEFAULT 0,
  batal TINYINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_faktur (no_faktur),
  KEY idx_tgl (tgl), KEY idx_sales (kode_sales, tgl),
  KEY idx_cust (customer_key), KEY idx_group (no_faktur_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS upload_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  jenis VARCHAR(20) NOT NULL,
  filename VARCHAR(200) NOT NULL,
  total_baris INT NOT NULL DEFAULT 0,
  baru INT NOT NULL DEFAULT 0,
  duplikat INT NOT NULL DEFAULT 0,
  user_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS target_bulanan (
  bulan CHAR(7) NOT NULL PRIMARY KEY,
  target_gram DECIMAL(12,2) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
