# Pantes Sales Report

Laporan penjualan & pembelian berbasis PHP 8+, MySQL/MariaDB, CSS, JavaScript (AJAX/fetch). Tanpa framework. PHP harus mengaktifkan ekstensi `pdo_mysql` dan `mbstring`.

## Pemasangan
1. Salin folder ini ke server (mis. `htdocs/sales-report` di XAMPP, atau `/var/www/html/sales-report`).
2. Atur variabel lingkungan `DB_HOST`, `DB_NAME`, `DB_USER`, dan `DB_PASS` untuk proses web PHP dan CLI. `DB_PASS` boleh kosong jika server database tidak memakai password. Jangan simpan kredensial database di source code.
3. Buka `http://localhost/sales-report/install.php`, isi username & password admin. Tabel dan database dibuat otomatis
   (alternatif manual: import `inc/schema.sql` lewat phpMyAdmin, lalu `php create_user.php admin PASSWORD "Nama"`).
4. **Hapus `install.php`** setelah selesai, lalu login di `login.php`.
5. Menu **Upload CSV**: unggah `master_sales`, `penjualan`, `pembelian` (boleh sekaligus; jenis dikenali otomatis dari header).

Batas aplikasi adalah 20 MB per file. `.user.ini` meminta batas `upload_max_filesize` 20 MB dan `post_max_size` 25 MB; jika server mengabaikannya, atur nilai tersebut lewat panel hosting atau konfigurasi PHP server.

## Aturan perhitungan
- **Kode unik**: penjualan = `no fakturjual`, pembelian = `no_fakturbeli`. Baris yang sudah ada dilewati (tidak duplikat). Master sales = `Kode Sales` (nama & fee diperbarui, jenis toko/luar dipertahankan).
- **Pcs penjualan** = jumlah baris barang, kecuali dept `KOTAK`, `KOTAK LM`, `SERTIFIKAT`, dan diamond. **Gramasi** = kolom `berat`. **Rupiah** = kolom `harga total`.
- **Diamond** = dept `BA, BB, BC, BCK, BGK, BGR, BK` → hanya tampil di halaman Penjualan Diamond, tidak masuk rekap biasa.
- **Pembelian**: laporan hanya pcs (jumlah baris).
- **Sales toko / luar toko**: sales yang ada di Master Sales dengan jenis "toko" = sales toko. Sales yang tidak ada di master, atau ditandai "luar toko", = sales luar toko.
- **Transaksi customer** = jumlah nota (`no fakturgroupuser`; satu nota bisa banyak barang).
- **Identitas customer**: kode member → jika NONMEMBER, nomor HP → jika tidak ada HP, nama. Nama generik seperti REGULER tidak diperingkat (daftar di `config.php`, `GENERIC_CUSTOMER_NAMES`).
- Minggu = Senin–Minggu. Triwulan = Jan–Mar, Apr–Jun, Jul–Sep, Okt–Des.
- Baris penjualan dengan `batal penjualan` ≠ 0 diabaikan.

## Sales Toko Harian & Mingguan (forecast)
Dua halaman baru, formatnya meniru file Excel rekap per hari dan per minggu:
- **Sales Toko Harian**: pcs & gram tiap tanggal (1–31), total pcs/gram, target pcs, dan **forecast** = total sejauh ini ÷ hari berjalan × jumlah hari sebulan. Penjualan diamond direkap terpisah di tabel bawah (tidak masuk target pcs). Ada kartu forecast gramasi emas dibanding target emas bulanan.
- **Sales Toko Mingguan**: rekap per minggu (Senin–Minggu, terpotong di akhir bulan, sama seperti file Excel), dibandingkan dengan bulan sebelumnya (selisih & rasio forecast).
- Filter: bulan, cabang (opsional), dan "hitung s.d. tanggal" untuk mengoreksi forecast manual (mis. data baru sampai tanggal 20, ingin forecast tetap dihitung dari 20 bukan otomatis dari tanggal transaksi terakhir).
- Kedua halaman hanya memakai sales bertipe **toko** (dari Master Sales).

Untuk mengaktifkan, isi lewat menu **Master Sales**:
- **Grade**: KEPALA CABANG / SPV / ENTREE / JUNIOR / TRAINING — menentukan urutan tampil (meniru kolom "GRADE" di file harian).
- **Cabang**: kode cabang bebas (mis. `CWK`, `UBR`) untuk filter multi-cabang. Sales dengan nama "...ONLINE" otomatis muncul di semua cabang (mengikuti pola file Excel, di mana ONLINE digabung satu baris).
- **Target pcs / bulan**: dipakai untuk kolom Target & % Capaian.
- **Nama di laporan forecast**: opsional. Jika kosong, nama otomatis diambil dari Master Sales dengan akhiran cabang dihapus (mis. "MILA CWK" → "MILA").
- **Target emas bulanan** (gram): formulir terpisah di halaman Master Sales, dipakai sebagai pembanding forecast gramasi di halaman Harian.

Rumus forecast: `total_sekarang / hari_berjalan * jumlah_hari_bulan`, sama seperti rumus `=Q14/10*30` pada file Excel Anda.

## Struktur
File PHP di root adalah entrypoint publik agar URL lama tetap kompatibel. `inc/` berisi kode internal dan `schema.sql` (dilindungi `.htaccess`); `assets/js/app.js` berisi aplikasi, sedangkan pustaka pihak ketiga ada di `assets/js/vendor/`. CSS berada di `assets/css/`.

## Keamanan
Semua halaman & API wajib login; aksi ubah data/upload wajib token CSRF; password di-hash; login diblokir 15 menit setelah 5 kali gagal dari satu IP; semua query memakai prepared statement.
Tambah user baru: `php create_user.php username password "Nama"`. Atur variabel lingkungan database juga pada terminal sebelum menjalankan perintah CLI.

Password database yang sebelumnya tersimpan langsung di source code harus segera dirotasi setelah konfigurasi lingkungan baru diterapkan.
