# DATABASE.md

# ReportUBR — Database Documentation

> Dokumentasi database untuk aplikasi ReportUBR / Pantes Sales Report.
>
> File sumber schema: `mytg1554_laporan_sales.sql`
>
> Dokumen ini dibuat sebagai referensi untuk developer dan AI Coding Agent agar memahami database tanpa perlu membaca seluruh SQL dump.

---

# 1. Database Overview

Database menggunakan:

- MySQL / MariaDB
- Engine: InnoDB
- Character Set: `utf8mb4`
- Collation: `utf8mb4_general_ci`

Database menyimpan data utama:

- barang / inventory
- penjualan
- pembelian
- master sales
- target sales
- target bulanan
- grade target
- retur
- user
- login attempt
- log upload

---

# 2. Daftar Tabel

Database saat ini memiliki tabel utama berikut:

| Tabel | Fungsi |
|---|---|
| `barang` | Master dan status inventory barang |
| `penjualan` | Data transaksi penjualan |
| `pembelian` | Data transaksi pembelian |
| `master_sales` | Master sales |
| `grade_target` | Target point berdasarkan grade |
| `target_bulanan` | Target gram bulanan |
| `retur_batch` | Header/batch transaksi retur |
| `retur_item` | Detail barang dalam retur |
| `upload_log` | Riwayat import/upload data |
| `users` | User aplikasi |
| `login_attempts` | Percobaan login |

---

# 3. Relasi Database

Relasi utama secara konseptual:

```text
                    ┌─────────────────┐
                    │  master_sales   │
                    │ kode_sales (PK) │
                    └────────┬────────┘
                             │
                kode_sales   │
                             ▼
                    ┌─────────────────┐
                    │    penjualan    │
                    │ no_faktur (UQ)  │
                    └─────────────────┘


                    ┌─────────────────┐
                    │  master_sales   │
                    │ kode_sales (PK) │
                    └────────┬────────┘
                             │
                kode_sales   │
                             ▼
                    ┌─────────────────┐
                    │   pembelian     │
                    │ no_faktur (UQ)  │
                    └─────────────────┘


                    ┌─────────────────┐
                    │   retur_batch   │
                    │    id (PK)      │
                    └────────┬────────┘
                             │
                     retur_batch_id
                             │
                             ▼
                    ┌─────────────────┐
                    │   retur_item    │
                    │    id (PK)      │
                    └────────┬────────┘
                             │
                       barang_id
                             │
                             ▼
                    ┌─────────────────┐
                    │     barang      │
                    │    id (PK)      │
                    └─────────────────┘


                    ┌─────────────────┐
                    │      users      │
                    │    id (PK)      │
                    └────────┬────────┘
                             │
                          user_id
                             │
                ┌────────────┴────────────┐
                ▼                         ▼
         `upload_log`              `retur_batch`
```

**Catatan penting:** schema dump menggunakan index untuk mendukung hubungan tersebut, tetapi tidak semua hubungan ditetapkan sebagai `FOREIGN KEY` database secara eksplisit. AI harus memperlakukan hubungan di atas sebagai **logical/application relationship**, bukan menganggap semuanya sebagai database-enforced foreign key.

---

# 4. Table: `penjualan`

## Fungsi

Menyimpan detail transaksi penjualan.

Satu transaksi/group nota dapat memiliki beberapa baris barang.

## Struktur

| Column | Type | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint(20) | NO | - | Primary key |
| `no_faktur` | varchar(60) | NO | - | Nomor faktur unik |
| `no_faktur_group` | varchar(60) | NO | - | Group/nota transaksi |
| `tgl` | date | NO | - | Tanggal transaksi |
| `jam` | time | YES | NULL | Jam transaksi |
| `kode_sales` | varchar(20) | NO | - | Kode sales |
| `kode_member` | varchar(30) | NO | `''` | Kode member |
| `no_hp` | varchar(30) | NO | `''` | Nomor HP |
| `nama_customer` | varchar(150) | NO | `''` | Nama customer |
| `customer_key` | varchar(190) | NO | - | Key identifikasi customer |
| `kode_group` | varchar(20) | NO | `''` | Group barang |
| `kode_dept` | varchar(30) | NO | `''` | Department |
| `nama_barang` | varchar(200) | NO | `''` | Nama barang |
| `berat` | decimal(12,3) | NO | `0.000` | Berat/gramasi |
| `harga_total` | decimal(16,0) | NO | `0` | Nilai transaksi |
| `batal` | tinyint(4) | NO | `0` | Status batal |
| `created_at` | timestamp | NO | current_timestamp() | Waktu data dibuat |
| `kode_barcode` | varchar(50) | NO | `''` | Barcode barang |

Schema ini dikonfirmasi langsung dari SQL dump.

## Primary Key

```text
id
```

## Unique Key

```text
no_faktur
```

## Index

```text
idx_tgl
idx_sales (kode_sales, tgl)
idx_cust (customer_key)
idx_group (no_faktur_group)
idx_barcode (kode_barcode)
```

## AUTO_INCREMENT

```text
id
```

---

# 5. Aturan Penting `penjualan`

## Nomor faktur

`no_faktur` harus dianggap sebagai identifier transaksi yang unik.

Jangan menghapus unique constraint tanpa alasan kuat.

## Group transaksi

`no_faktur_group` dapat digunakan untuk mengelompokkan beberapa item dalam satu transaksi/nota.

Contoh:

```text
no_faktur_group
    │
    ├── barang A
    ├── barang B
    └── barang C
```

Karena itu:

```text
jumlah baris penjualan
```

tidak selalu sama dengan:

```text
jumlah transaksi
```

---

# 6. Customer pada `penjualan`

Identitas customer menggunakan beberapa field:

```text
kode_member
no_hp
nama_customer
customer_key
```

`customer_key` merupakan field penting untuk pengelompokan customer.

Contoh pola yang terdapat pada data:

```text
M:UBR0000019
H:08127602058
N:REGULER
```

Interpretasi prefix:

```text
M: → member
H: → nomor HP
N: → customer non-member / nama
```

AI tidak boleh mengubah format `customer_key` tanpa memeriksa seluruh kode yang menggunakan field tersebut.

---

# 7. Status Batal Penjualan

Field:

```text
batal
```

digunakan sebagai penanda transaksi batal.

Default:

```text
0
```

Untuk laporan transaksi aktif, query harus memperhatikan nilai `batal`.

AI tidak boleh mengubah definisi transaksi batal hanya berdasarkan asumsi.

---

# 8. Perhitungan Penjualan

Field utama untuk agregasi:

```text
berat
harga_total
kode_sales
tgl
customer_key
kode_dept
kode_group
```

Contoh:

```sql
SUM(berat)
```

untuk total gramasi.

Dan:

```sql
SUM(harga_total)
```

untuk total rupiah.

Tetapi query laporan harus selalu memperhatikan:

- tanggal
- sales
- department
- status batal
- group transaksi
- aturan bisnis laporan yang bersangkutan.

---

# 9. Table: `pembelian`

## Fungsi

Menyimpan detail transaksi pembelian.

Struktur dasarnya mengikuti pola transaksi pembelian yang terdapat pada SQL dump:

| Column | Fungsi |
|---|---|
| `id` | Primary key |
| `no_faktur` | Nomor faktur pembelian |
| `no_faktur_group` | Group transaksi |
| `tgl` | Tanggal |
| `jam` | Jam |
| `kode_sales` | Sales |
| `kode_member` | Member |
| `no_hp` | Nomor HP |
| `nama_customer` | Customer |
| `customer_key` | Identitas customer |
| `kode_group` | Group |
| `kode_dept` | Department |
| `nama_barang` | Barang |
| `berat` | Berat |
| `harga_total` | Nilai transaksi |
| `batal` | Status batal |
| `created_at` | Waktu import/data |

Contoh data aktual pada dump menggunakan pola:

```text
UBR-FB-...
```

untuk nomor faktur pembelian.

## Primary Key

```text
id
```

## Unique Key

```text
no_faktur
```

## Index

```text
idx_tgl
idx_sales (kode_sales, tgl)
idx_cust (customer_key)
idx_group (no_faktur_group)
```

## AUTO_INCREMENT

```text
id
```

---

# 10. Table: `barang`

## Fungsi

Menyimpan inventory/master barang dan status barang.

Field yang terlihat pada schema:

| Column | Fungsi |
|---|---|
| `id` | Primary key |
| `kode_barcode` | Barcode unik |
| `kode_jenis` | Jenis barang |
| `kode_baki` | Lokasi/baki |
| `warna` | Warna |
| `berat` | Berat barang |
| `tanggal_input` | Tanggal input |
| `status` | Status barang |
| `retur_batch_id` | Referensi logical ke batch retur |
| `created_at` | Waktu data dibuat |
| `terjual_tgl` | Tanggal terjual |
| `terjual_faktur` | Faktur penjualan |

Data aktual menunjukkan status seperti:

```text
stok
terjual
retur
```

dan barang yang diretur memiliki `retur_batch_id`.

## Primary Key

```text
id
```

## Unique Key

```text
kode_barcode
```

## Index

```text
idx_jenis
idx_status
idx_tgl
```

## AUTO_INCREMENT

```text
id
```

---

# 11. Status Barang

Status yang terlihat pada data:

```text
stok
terjual
retur
```

### `stok`

Barang masih tersedia.

### `terjual`

Barang sudah terjual.

Field pendukung:

```text
terjual_tgl
terjual_faktur
```

### `retur`

Barang masuk proses/record retur.

Field pendukung:

```text
retur_batch_id
```

AI harus mempertahankan konsistensi antara:

```text
status
terjual_tgl
terjual_faktur
retur_batch_id
```

ketika mengubah logic inventory.

---

# 12. Table: `master_sales`

## Fungsi

Master data sales.

Schema:

| Column | Type | Default | Fungsi |
|---|---|---|---|
| `kode_sales` | varchar(20) | - | Primary key |
| `nama_sales` | varchar(100) | - | Nama sales |
| `fee` | decimal(12,2) | `0.00` | Fee |
| `tipe` | enum('toko','luar') | `toko` | Jenis sales |
| `grade` | varchar(30) | `''` | Grade sales |
| `target_pcs` | int(11) | `0` | Target PCS |
| `cabang` | varchar(20) | `''` | Cabang |
| `nama_laporan` | varchar(60) | `''` | Nama untuk laporan |

Schema dan enum `tipe` berasal langsung dari SQL dump.

## Primary Key

```text
kode_sales
```

Tidak menggunakan auto increment.

---

# 13. Tipe Sales

Field:

```text
tipe
```

memiliki nilai:

```text
toko
luar
```

Ini merupakan business rule penting.

AI **tidak boleh mengganti enum atau definisi `toko` / `luar` tanpa persetujuan**.

---

# 14. Grade Sales

Field:

```text
grade
```

digunakan bersama `grade_target`.

Contoh grade yang terdapat pada database:

```text
ENTREE
TRAINING
JUNIOR
SENIOR
EXECUTIVE
```

---

# 15. Table: `grade_target`

## Fungsi

Menyimpan target point berdasarkan grade sales.

Schema:

| Column | Type | Default |
|---|---|---|
| `grade` | varchar(30) | - |
| `target_point` | int(11) | `0` |

## Primary Key

```text
grade
```

Contoh data:

| Grade | Target Point |
|---|---:|
| ENTREE | 200 |
| EXECUTIVE | 500 |
| JUNIOR | 300 |
| SENIOR | 400 |
| TRAINING | 200 |



---

# 16. Table: `target_bulanan`

## Fungsi

Menyimpan target gram berdasarkan bulan.

Schema:

| Column | Type | Default | Fungsi |
|---|---|---|---|
| `bulan` | char(7) | - | Format YYYY-MM |
| `target_gram` | decimal(12,2) | `0.00` | Target gram |

## Primary Key

```text
bulan
```

Contoh:

```text
2026-09
2026-10
```

dengan target gram masing-masing.

## Format bulan

Gunakan:

```text
YYYY-MM
```

Jangan menggunakan:

```text
YYYY-MM-DD
```

untuk field `bulan`.

---

# 17. Table: `retur_batch`

## Fungsi

Menyimpan header/batch retur.

Schema:

| Column | Type | Fungsi |
|---|---|---|
| `id` | bigint(20) | Primary key |
| `tanggal` | date | Tanggal retur |
| `cabang` | varchar(150) | Cabang |
| `ttd1_nama` | varchar(100) | Nama tanda tangan 1 |
| `ttd1_jabatan` | varchar(100) | Jabatan 1 |
| `ttd2_nama` | varchar(100) | Nama tanda tangan 2 |
| `ttd2_jabatan` | varchar(100) | Jabatan 2 |
| `ttd3_nama` | varchar(100) | Nama tanda tangan 3 |
| `ttd3_jabatan` | varchar(100) | Jabatan 3 |
| `ttd4_nama` | varchar(100) | Nama tanda tangan 4 |
| `ttd4_jabatan` | varchar(100) | Jabatan 4 |
| `user_id` | int(11) | User pembuat |
| `created_at` | timestamp | Waktu dibuat |

Schema berasal dari SQL dump.

## Primary Key

```text
id
```

## AUTO_INCREMENT

```text
id
```

---

# 18. Table: `retur_item`

## Fungsi

Menyimpan detail barang yang masuk dalam suatu batch retur.

Schema:

| Column | Type | Fungsi |
|---|---|---|
| `id` | bigint(20) | Primary key |
| `retur_batch_id` | bigint(20) | Batch retur |
| `barang_id` | bigint(20) | ID barang |
| `kode_barcode` | varchar(50) | Barcode |
| `kode_baki` | varchar(20) | Baki |
| `kode_jenis` | varchar(20) | Jenis |
| `berat` | decimal(12,3) | Berat |
| `keterangan` | varchar(255) | Keterangan |
| `bulan` | varchar(20) | Bulan |



## Index

```text
idx_batch (retur_batch_id)
```

## AUTO_INCREMENT

```text
id
```

---

# 19. Hubungan Retur

Logical relationship:

```text
retur_batch.id
       │
       │
       ▼
retur_item.retur_batch_id
```

Dan:

```text
retur_item.barang_id
       │
       ▼
barang.id
```

Perubahan status barang dapat berhubungan dengan proses retur.

AI harus berhati-hati ketika mengubah:

```text
barang.status
barang.retur_batch_id
retur_batch
retur_item
```

---

# 20. Table: `upload_log`

## Fungsi

Menyimpan histori import/upload data.

Schema:

| Column | Type | Default | Fungsi |
|---|---|---|---|
| `id` | int(11) | - | Primary key |
| `jenis` | varchar(20) | - | Jenis import |
| `filename` | varchar(200) | - | Nama file |
| `total_baris` | int(11) | `0` | Total baris |
| `baru` | int(11) | `0` | Data baru |
| `duplikat` | int(11) | `0` | Data duplikat |
| `user_id` | int(11) | NULL | User |
| `created_at` | timestamp | current_timestamp() | Waktu |



## Primary Key

```text
id
```

## AUTO_INCREMENT

```text
id
```

---

# 21. Jenis Upload

Field:

```text
jenis
```

digunakan untuk membedakan jenis data yang diimport.

Contoh pada database:

```text
penjualan
pembelian
master
barang
```

AI jangan membuat jenis baru tanpa memeriksa logic importer yang sudah ada.

---

# 22. Duplicate Import

`upload_log` menyimpan:

```text
total_baris
baru
duplikat
```

Ini penting untuk sistem import CSV.

Konsep:

```text
total_baris
    │
    ├── baru
    │
    └── duplikat
```

Contoh:

```text
total = 12808
baru = 72
duplikat = 12736
```

Data aktual seperti ini terdapat pada dump.

---

# 23. Table: `users`

## Fungsi

Menyimpan user aplikasi.

Schema:

| Column | Type | Default | Fungsi |
|---|---|---|---|
| `id` | int(11) | - | Primary key |
| `username` | varchar(50) | - | Username |
| `password_hash` | varchar(255) | - | Password hash |
| `nama` | varchar(100) | `''` | Nama |
| `created_at` | timestamp | current_timestamp() | Waktu dibuat |

## Primary Key

```text
id
```

## Unique Key

```text
username
```

## AUTO_INCREMENT

```text
id
```



---

# 24. Security — `users`

Field:

```text
password_hash
```

berisi password yang sudah di-hash.

AI:

- jangan mengubah password menjadi plaintext
- jangan menampilkan password
- jangan menyimpan password plaintext
- jangan menghapus hashing
- jangan mengubah sistem authentication tanpa memahami seluruh flow login

---

# 25. Table: `login_attempts`

## Fungsi

Mencatat percobaan login berdasarkan IP dan waktu.

Schema:

| Column | Type | Default |
|---|---|---|
| `id` | int(11) | - |
| `ip` | varchar(45) | - |
| `created_at` | timestamp | current_timestamp() |

## Primary Key

```text
id
```

## Index

```text
idx_ip (ip, created_at)
```



---

# 26. Login Protection

`login_attempts` digunakan sebagai bagian dari mekanisme proteksi login.

AI tidak boleh menghapus mekanisme pencatatan login attempt hanya karena dianggap tidak diperlukan.

Jika mengubah authentication:

```text
users
+
login_attempts
+
session
+
security logic
```

harus diperiksa bersama.

---

# 27. Index Database

Index penting:

## `barang`

```text
PRIMARY KEY (id)
UNIQUE (kode_barcode)
INDEX (kode_jenis)
INDEX (status)
INDEX (tanggal_input)
```

## `pembelian`

```text
PRIMARY KEY (id)
UNIQUE (no_faktur)
INDEX (tgl)
INDEX (kode_sales, tgl)
INDEX (customer_key)
INDEX (no_faktur_group)
```

## `penjualan`

```text
PRIMARY KEY (id)
UNIQUE (no_faktur)
INDEX (tgl)
INDEX (kode_sales, tgl)
INDEX (customer_key)
INDEX (no_faktur_group)
INDEX (kode_barcode)
```

## `master_sales`

```text
PRIMARY KEY (kode_sales)
```

## `retur_batch`

```text
PRIMARY KEY (id)
```

## `retur_item`

```text
PRIMARY KEY (id)
INDEX (retur_batch_id)
```

## `target_bulanan`

```text
PRIMARY KEY (bulan)
```

## `grade_target`

```text
PRIMARY KEY (grade)
```

## `upload_log`

```text
PRIMARY KEY (id)
```

## `users`

```text
PRIMARY KEY (id)
UNIQUE (username)
```



---

# 28. AUTO_INCREMENT

Current schema menggunakan AUTO_INCREMENT pada:

```text
barang.id
login_attempts.id
pembelian.id
penjualan.id
retur_batch.id
retur_item.id
upload_log.id
users.id
```

Contoh struktur:

```text
barang.id
    AUTO_INCREMENT

penjualan.id
    AUTO_INCREMENT

pembelian.id
    AUTO_INCREMENT
```

AI jangan melakukan insert dengan menentukan `id` secara manual kecuali memang diperlukan untuk proses migrasi/import.

---

# 29. Tabel Transaction vs Master

## Transaction tables

```text
penjualan
pembelian
retur_batch
retur_item
```

## Master/reference tables

```text
barang
master_sales
grade_target
target_bulanan
users
```

## Log tables

```text
upload_log
login_attempts
```

---

# 30. Mapping Business Domain

```text
PENJUALAN
    │
    ├── penjualan
    │      ├── kode_sales
    │      ├── customer_key
    │      ├── kode_dept
    │      ├── berat
    │      └── harga_total
    │
    └── master_sales
           ├── nama_sales
           ├── tipe
           ├── grade
           ├── target_pcs
           └── cabang
```

```text
PEMBELIAN
    │
    └── pembelian
           ├── kode_sales
           ├── customer_key
           ├── kode_dept
           ├── berat
           └── harga_total
```

```text
INVENTORY
    │
    ├── barang
    │
    └── retur
         ├── retur_batch
         └── retur_item
```

```text
TARGET
    │
    ├── master_sales.target_pcs
    ├── grade_target.target_point
    └── target_bulanan.target_gram
```

---

# 31. Query Guidelines

## Gunakan index

Untuk laporan berdasarkan tanggal, gunakan:

```sql
WHERE tgl BETWEEN ? AND ?
```

atau kondisi tanggal yang sesuai.

Jangan menggunakan fungsi pada kolom tanggal jika menyebabkan index tidak dapat digunakan tanpa alasan.

Contoh yang perlu dihindari jika tidak diperlukan:

```sql
WHERE DATE(tgl) = ?
```

lebih baik:

```sql
WHERE tgl >= ?
  AND tgl < ?
```

---

# 32. Query Sales

Jika laporan berdasarkan sales:

```sql
WHERE kode_sales = ?
```

dan database memiliki index:

```text
idx_sales (kode_sales, tgl)
```

Untuk laporan berdasarkan sales + tanggal, gunakan kedua kondisi tersebut.

---

# 33. Query Customer

Field yang digunakan untuk pengelompokan customer:

```text
customer_key
```

Database memiliki:

```text
idx_cust (customer_key)
```

Jangan mengganti `customer_key` dengan `nama_customer` sebagai identifier utama tanpa alasan.

Nama customer dapat berubah atau memiliki variasi penulisan.

---

# 34. Query Group Transaction

Field:

```text
no_faktur_group
```

digunakan untuk mengelompokkan item dalam transaksi.

Database memiliki:

```text
idx_group (no_faktur_group)
```

Gunakan field tersebut ketika kebutuhan laporan adalah:

```text
per nota
per transaksi
per group transaksi
```

dan bukan sekadar per item.

---

# 35. Import Data

Import data harus memperhatikan unique key.

## Penjualan

```text
no_faktur
```

adalah unique.

## Pembelian

```text
no_faktur
```

adalah unique.

Karena itu sistem import harus menangani duplicate record.

Jangan menghilangkan:

```text
UNIQUE KEY uq_faktur
```

untuk mengatasi error duplicate tanpa memahami logic import.

---

# 36. Data Barang dan Barcode

`barang.kode_barcode` adalah unique.

```text
UNIQUE KEY uq_barcode
```

Barcode merupakan identifier penting inventory.

AI tidak boleh:

- menghapus unique constraint
- mengganti barcode secara massal
- menggunakan `nama_barang` sebagai pengganti barcode

tanpa persetujuan.

---

# 37. Retur dan Inventory

Hubungan logical:

```text
barang
   │
   │ barang_id
   ▼
retur_item
   │
   │ retur_batch_id
   ▼
retur_batch
```

Saat membuat atau mengubah fitur retur, AI harus memeriksa minimal:

```text
barang
retur_item
retur_batch
```

---

# 38. Target Sales

Target sales memiliki beberapa sumber.

### Target PCS

```text
master_sales.target_pcs
```

### Target berdasarkan grade

```text
grade_target.target_point
```

### Target gram bulanan

```text
target_bulanan.target_gram
```

Jangan mencampurkan ketiga konsep tersebut.

---

# 39. Business Rule Protection

AI **TIDAK BOLEH** mengubah struktur atau arti field berikut tanpa persetujuan:

```text
penjualan.no_faktur
penjualan.no_faktur_group
penjualan.customer_key
penjualan.kode_sales
penjualan.berat
penjualan.harga_total
penjualan.batal

pembelian.no_faktur
pembelian.no_faktur_group
pembelian.customer_key
pembelian.kode_sales

barang.kode_barcode
barang.status
barang.terjual_tgl
barang.terjual_faktur
barang.retur_batch_id

master_sales.kode_sales
master_sales.tipe
master_sales.grade

target_bulanan.bulan
grade_target.grade

retur_batch.id
retur_item.retur_batch_id
retur_item.barang_id

users.username
users.password_hash
```

---

# 40. AI Development Rules

Ketika AI mengerjakan task yang berhubungan dengan database:

### Rule 1

Jangan membaca seluruh:

```text
mytg1554_laporan_sales.sql
```

untuk task biasa.

Gunakan `DATABASE.md` terlebih dahulu.

---

### Rule 2

Jika membutuhkan detail schema tertentu:

```text
DATABASE.md
    ↓
identifikasi tabel
    ↓
baca source/schema SQL yang relevan
```

Jangan membaca seluruh SQL dump hanya untuk mengetahui satu tabel.

---

### Rule 3

Jika perubahan hanya menyangkut:

```text
penjualan
```

prioritaskan:

```text
penjualan
master_sales
customer_key
target/report logic
```

dan dependency yang benar-benar diperlukan.

---

### Rule 4

Jika perubahan menyangkut inventory:

```text
barang
retur_item
retur_batch
```

harus diperiksa sebagai satu domain.

---

### Rule 5

Jika perubahan menyangkut import:

```text
upload_log
penjualan
pembelian
barang
```

periksa bersama sesuai jenis import.

---

### Rule 6

Jangan mengubah schema database hanya untuk memperbaiki masalah PHP/JavaScript sederhana.

---

### Rule 7

Jangan menghapus index hanya karena query sedang bermasalah.

Cari masalah query terlebih dahulu.

---

# 41. Database Migration Policy

Perubahan schema harus dianggap sebagai perubahan besar.

Contoh:

```text
ADD COLUMN
ALTER COLUMN
DROP COLUMN
DROP INDEX
ADD INDEX
CHANGE TYPE
RENAME COLUMN
DROP TABLE
```

AI harus:

1. menjelaskan alasan perubahan
2. menjelaskan dampaknya
3. memastikan compatibility dengan source code
4. memeriksa query yang menggunakan field tersebut
5. tidak melakukan destructive migration tanpa persetujuan

---

# 42. Destructive Operations

Tanpa instruksi eksplisit, AI dilarang melakukan:

```text
DROP TABLE
DROP COLUMN
TRUNCATE TABLE
DELETE seluruh data
menghapus PRIMARY KEY
menghapus UNIQUE KEY
menghapus INDEX penting
```

---

# 43. Perubahan Aman

Perubahan yang relatif aman:

```text
SELECT query
penambahan filter
perbaikan WHERE
perbaikan JOIN
optimasi query
penambahan index setelah analisis
penambahan kolom non-destructive
```

Tetap harus diperiksa terhadap aplikasi.

---

# 44. Sensitive Data

Database dump mengandung data operasional nyata.

Contohnya:

```text
nama customer
nomor HP
kode member
data transaksi
username
password hash
IP login
```

AI tidak boleh:

- mempublikasikan data tersebut
- memasukkan data produksi ke dokumentasi
- menyalin password hash ke source code
- membuat contoh dokumentasi menggunakan data customer nyata

Gunakan data contoh/fiktif ketika diperlukan.

---

# 45. Password

Table:

```text
users
```

menggunakan:

```text
password_hash
```

Jangan pernah mengubah menjadi:

```text
password
```

plaintext.

Password harus tetap diproses menggunakan mekanisme hashing aplikasi yang sudah ada.

---

# 46. Performance

Tabel transaction:

```text
penjualan
pembelian
```

dapat memiliki data dalam jumlah besar.

Karena itu:

- hindari `SELECT *` jika tidak diperlukan
- gunakan filter tanggal
- gunakan index yang tersedia
- gunakan pagination untuk tampilan detail
- hindari query berulang dalam loop
- gunakan aggregation SQL jika sesuai
- jangan mengambil seluruh transaksi ke PHP jika hanya membutuhkan SUM/COUNT

---

# 47. Recommended Query Pattern

Untuk laporan:

```text
Tanggal
↓
Sales
↓
Department
↓
Customer / Group
↓
Aggregation
```

Contoh konsep:

```sql
SELECT
    kode_sales,
    SUM(berat) AS total_gram,
    SUM(harga_total) AS total_rupiah
FROM penjualan
WHERE tgl >= ?
  AND tgl < ?
  AND batal = 0
GROUP BY kode_sales;
```

Query aktual harus disesuaikan dengan business rule laporan yang digunakan.

---

# 48. Data Flow

## Penjualan

```text
CSV / Input
      ↓
penjualan
      ↓
filter tanggal
      ↓
filter batal
      ↓
filter department
      ↓
group sales/customer/nota
      ↓
laporan
```

## Pembelian

```text
CSV / Input
      ↓
pembelian
      ↓
filter tanggal
      ↓
filter batal
      ↓
laporan pembelian
```

## Inventory

```text
Import barang
      ↓
barang
      │
      ├── stok
      ├── terjual
      └── retur
             ↓
        retur_batch
             ↓
        retur_item
```

---

# 49. Important Field Dictionary

## Transaction identifiers

```text
penjualan.no_faktur
pembelian.no_faktur
penjualan.no_faktur_group
pembelian.no_faktur_group
```

## Sales

```text
kode_sales
nama_sales
tipe
grade
cabang
target_pcs
```

## Customer

```text
kode_member
no_hp
nama_customer
customer_key
```

## Product

```text
kode_barcode
kode_jenis
kode_baki
warna
berat
status
```

## Financial

```text
harga_total
fee
target_gram
```

## Date

```text
tgl
jam
tanggal_input
terjual_tgl
created_at
bulan
tanggal
```

---

# 50. AI Quick Reference

Jika AI perlu mengetahui tabel berdasarkan kebutuhan:

| Kebutuhan | Tabel utama |
|---|---|
| Penjualan | `penjualan` |
| Pembelian | `pembelian` |
| Inventory | `barang` |
| Sales | `master_sales` |
| Grade sales | `grade_target` |
| Target bulanan | `target_bulanan` |
| Retur | `retur_batch`, `retur_item` |
| User | `users` |
| Login security | `login_attempts` |
| Import history | `upload_log` |

---

# 51. Source of Truth

Untuk struktur database:

```text
mytg1554_laporan_sales.sql
```

adalah source schema utama.

Untuk pemahaman cepat:

```text
DATABASE.md
```

digunakan terlebih dahulu.

Jika `DATABASE.md` berbeda dengan schema SQL aktual:

```text
SQL schema = source of truth
```

AI harus memperbarui `DATABASE.md` setelah perubahan schema.

---

# 52. Rule Setelah Database Berubah

Jika AI melakukan perubahan schema:

1. Update database.
2. Update `DATABASE.md`.
3. Update `PROJECT_MAP.md` jika dependency berubah.
4. Update README jika perubahan mempengaruhi instalasi/penggunaan.
5. Buat commit terpisah untuk perubahan database jika memungkinkan.

Contoh:

```text
feat(db): add monthly sales target
```

---

# 53. AI Context Priority

Untuk task database, gunakan urutan:

```text
1. README.md
       ↓
2. DATABASE.md
       ↓
3. PROJECT_MAP.md
       ↓
4. source code terkait
       ↓
5. schema SQL jika detail diperlukan
```

**Jangan langsung membaca SQL dump 7 MB.**

---

# 54. Final Database Safety Rule

Sebelum mengubah database, AI harus menjawab secara internal:

```text
Tabel apa yang terlibat?
Kolom apa yang terlibat?
Apakah ada unique key?
Apakah ada index?
Apakah ada logical relationship?
Apakah ada source code yang bergantung pada kolom ini?
Apakah perubahan bersifat destructive?
Apakah DATABASE.md harus diperbarui?
```

Jika perubahan berpotensi mengubah data produksi atau struktur database secara destructive, AI harus meminta konfirmasi sebelum melakukannya.
