# Audit dan penyelarasan database EWO legacy

## Kompatibilitas Scheduled WO pada pengembangan

Pada 20 November 2026 WIB, hanya dua kolom berikut ditambahkan ke `eworeb` TCP 3310 atas persetujuan pemilik.

```sql
ALTER TABLE posts ADD COLUMN intervale INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE posts_logs ADD COLUMN intervale INT UNSIGNED NULL DEFAULT NULL;
```

CI3 menulis interval pada template dan histori serta menghitung pengulangan dalam hari lewat `DATE_ADD`. CI3 memakai nol pada occurrence. Definisi MySQL asli dan nilai jadwal historis belum ditemukan pada tiga dump atau riwayat Git yang tersedia. Kolom kompatibilitas dapat menyimpan interval hari positif, nol untuk occurrence, dan NULL untuk nilai yang tidak diketahui.

- Backup privat `eworeb-3310-scheduled-20261120-141159/eworeb.sql` berukuran 45.434.484 byte, SHA-256 `490669c998289e852458bbdae29b287aa603457bb106fabaed5b1b20cc7e7ee7`. Restore pada MySQL loopback TCP 53521 cocok pada 19 tabel, seluruh jumlah dan fingerprint. MySQL menuliskan charset bawaan secara eksplisit pada dua ENUM KB setelah restore. Pemeriksaan restore menyetarakan hanya representasi tersebut setelah memastikan collations sama. Dua ALTER dan fingerprint sesudah perubahan kemudian lulus pada restore yang sama.
- Sesi DDL memeriksa tujuan loopback, transaksi aktif dan metadata lock, memakai timeout lima detik, serta memegang lock penghalang tulis pada 19 tabel sepanjang verifikasi dan kedua ALTER. Binlogging dinonaktifkan pada sesi DDL. Semua definisi lama, jumlah dan SHA-256 isi kolom lama identik sebelum dan sesudah. Totalnya **444.040 record**, termasuk **19.813 WO** dan **43.610 histori**. Kedua kolom baru berisi NULL pada seluruh record sebelumnya. Lima template dan 43 histori Scheduled tetap memiliki tanggal publikasi dan interval NULL.
- Jika perlu pemulihan, hentikan authoring dan worker terlebih dahulu. MySQL melakukan commit DDL per ALTER. Tinjau definisi kedua tabel, arsipkan setiap jadwal baru dan cocokkan WO serta histori yang tersimpan sejak backup sebelum menyetujui tindakan pemulihan. Gunakan cadangan dan rehearsal di instance terpisah untuk menyiapkan pemulihan yang mempertahankan transaksi tersebut.

## Scheduled, media Create, dan subkategori

Pada audit sebelum cutover KB, MySQL pengembangan TCP 3310 memiliki 18 tabel legacy dengan 444.039 record. SHA-256 dan definisi setiap tabel cocok dengan backup yang dipulihkan: 19.813 `posts` dan 43.610 `posts_logs`. Lima template dan 43 baris histori terjadwal masih memiliki `published_at` NULL. `intervale` masih tertahan, sedangkan tabel terpisah `kb_articles` ditambahkan pada [cutover KB berikutnya](KNOWLEDGE_BASE_MYSQL.md#cutover-pengembangan-20-november-2026-wib).

- CI3 `Cron_controller::check_scheduled_posts` memproses template dengan `is_scheduled=1` pada menit yang sama. `Post_admin_model::input_values2` membawa status, assignment, referensi gambar utama, dan `created_at` dari template. Baris hasil akan memiliki `is_scheduled=0`, `intervale=0`, dan `published_at` dari waktu eksekusi. History memakai `published_at` template dan `post_id` hasil. Template maju dengan `DATE_ADD(..., INTERVAL intervale DAY)`. CI3 tidak menyalin `post_images` dan tidak memanggil notifikasi di cron. Menit yang terlewat, status Draft, dan nilai jadwal kosong mengikuti perilaku ini.
- Pada tahap sebelum cutover Scheduled, kedua ALTER `intervale` diuji pada fixture. Worker CI4 memakai row lock dan satu transaksi untuk hasil, histori, dan kemajuan jadwal.
- `File_model` menyimpan lima rendition JPEG dan GIF tunggal pada katalog `images`. Create menyalin referensi utama ke `posts` dan `posts_logs`, serta gambar tambahan ke `post_images`. CI4 menggunakan file privat, validasi format aktual, izin katalog, batas corporation, dan rollback metadata/file. Upload katalog merupakan aksi tersendiri sebelum Draft/Publish, seperti file manager CI3.
- `final_db_deploy.sql` punya definisi `subcategory_id` pada `posts` dan `posts_logs`, tanpa `INSERT` untuk kedua tabel itu. Dump `ewohotel_tntrm.sql` mempunyai record tanpa kolom tersebut. `user.sql` hanya memuat akun. Git tidak menyediakan versi data lain. Belum ada sumber pasangan nilai subkategori per WO yang dapat dicocokkan. Reports tetap menggunakan `Subcategory unavailable`, filter dan total existing serta PDF/XLSX.

## Pembaruan kompatibilitas Reports dan Create

CI3 menampilkan label subkategori hanya apabila lookup berhasil. Field ini bukan predikat pada filter aktif. Laporan CI4 menggunakan join subkategori bila kolom tersedia, atau mengirim `subcategory_name: null` dan `subcategory_available: false` bila skema tidak memilikinya. Sumber label kategori utama, predikat, total, sorting dan paginasi tetap dipakai. PDF dan XLSX memakai isi sel yang sama dengan tabel. Metadata aktual tidak memerlukan DDL untuk laporan dengan batas ini.

### Keputusan pemilik untuk Create Work Order biasa

**Status TCP 3310: kedua ALTER lulus pada 20 November 2026 WIB.** Sebelum perubahan, `SHOW CREATE TABLE` mengonfirmasi kedua tabel InnoDB tanpa `published_at`. `created_at` mempunyai default waktu pembuatan pada kedua tabel. CI3 `Post_controller::add_post_post` memanggil `Post_admin_model::add_post` dan `add_post_logs`. Keduanya memasukkan `date_published` sebagai `published_at` tanpa syarat. Hanya `is_scheduled` dan `intervale` bergantung pada kotak jadwal. Form mengirim `date_published` kosong untuk Create biasa, dan JavaScript hanya mewajibkannya untuk penjadwalan dengan format `YYYY-MM-DD HH:mm`.

`fda3e7d3` adalah satu-satunya commit dump SQL CI3 yang ditemukan pada seluruh ref lokal. `ewohotel_tntrm.sql` dan `final_db_deploy.sql` mendefinisikan kedua tabel tanpa kolom tersebut, dan `user.sql` hanya berisi tabel pengguna. Pencarian DDL dan perubahan Git untuk `published_at` serta `intervale` tidak menemukan definisi kolom yang pernah diterapkan. `97689d26` masih memajukan `created_at` dengan interval hari. `277a5f98` memindahkan pemilihan per menit dan `DATE_ADD` ke `published_at`, menyalinnya ke histori jadwal, dan memasukkannya pada Create biasa. Ini membuktikan pemisahan waktu pembuatan dari waktu jadwal. Tipe asal, precision, default, nullability, perlakuan string kosong, timezone server lama, dan nilai historis belum terbukti.

**DDL yang disetujui dan dijalankan di `eworeb`.** `DATETIME` menyimpan kalender dan jam dari input legacy tanpa konversi zona waktu otomatis. `NULL DEFAULT NULL` menyatakan waktu publikasi tidak ditetapkan atau tidak diketahui. CI4 mengirim SQL `NULL` secara eksplisit. CI3 yang mengirim string kosong dapat ditolak oleh MySQL strict mode, sehingga writer CI3 memerlukan uji terpisah sebelum diarahkan ke skema ini. Definisi MySQL historis belum dapat dipulihkan dari bukti yang tersedia.

```sql
ALTER TABLE posts ADD COLUMN published_at DATETIME NULL DEFAULT NULL;
ALTER TABLE posts_logs ADD COLUMN published_at DATETIME NULL DEFAULT NULL;
```

- Backup privat berukuran 45.115.800 byte, SHA-256 `cf50cd5e7ec4de58ee2dd484ee7a3c65b02849923a01503f98fdae9ba911de7b`. Restore terpisah pada loopback TCP 54850 cocok dengan definisi, jumlah dan fingerprint 18 tabel. Baseline memiliki 444.035 record. Selisih 37 terhadap inventaris lama seluruhnya berasal dari `users_logs` sebelum operasi.
- Sesi DDL memverifikasi target, nihil penulis atau transaksi aktif, dan lock yang memblokir penulis pada 18 tabel sebelum dan sesudah setiap ALTER. Timeout lock lima detik. Binlogging dinonaktifkan untuk sesi DDL saja. SHA-256 seluruh nilai kolom lama tetap sama sesudah ALTER, termasuk **19.812 WO**, **43.609 histori**, ID, status, assignment, permission dan relasi. Kedua kolom baru `NULL` pada seluruh record lama. Lima WO dan 43 histori scheduled tetap memiliki tanggal publikasi yang belum diketahui. Scheduled masih membutuhkan `intervale`.
- Buat cadangan konsisten database dan media, uji restore pada instance terpisah, lalu catat `SHOW CREATE TABLE`, jumlah dan checksum kolom lama pada kedua tabel. Hentikan penulis sebelum DDL yang disetujui dan konfirmasi ruang serta dampak lock. MySQL meng-commit setiap `ALTER TABLE` secara terpisah. Verifikasi kedua definisi dan fingerprint sebelum memberi izin INSERT minimum dan menguji UAT.
- Untuk rollback, matikan `ewo.workOrderCreateWrites` terlebih dahulu. Jika baru satu ALTER yang berhasil, verifikasi pemakaian kolom sebelum menghapus kolom yang baru ditambah. Sebelum menjatuhkan salah satu kolom setelah operasi, arsipkan nilai yang mungkin sudah ditulis dan rekonsiliasi WO, histori serta referensi gambar yang tersimpan sejak backup. Restore dari cadangan teruji bila diperlukan dengan prosedur pemulihan yang mempertahankan transaksi yang sudah diterima.

Lihat [bukti transaksi dan persetujuan aktivasi](OPERATIONAL_READINESS.md#create-wo-dan-keterbatasan-kompatibilitas).

## Audit ulang schema aktif dan kontrak modul, 19 November 2026 WIB

Metadata baca-saja MySQL 8.0.45 TCP 3310 mengembalikan 18 tabel, 248 kolom, 18 indeks dan 0 foreign key pada `ewohotel_tentrem` dan `eworeb`. Kedua schema tidak memiliki ketiga kolom di posts maupun posts_logs. `ewohotel_tntrm.sql`, `final_db_deploy.sql` dan `user.sql` dibaca sebagai berkas lokal. Riwayat commit CI3 dibaca untuk melacak perubahan terjadwal. Dump lama tidak dieksekusi.

| Field | Penggunaan dan arti | Bukti historis dan perubahan minimum untuk ditinjau |
| --- | --- | --- |
| `published_at` | `Post_admin_model::add_post` dan `add_post_logs` mengisi nilai ini saat membuat WO biasa atau terjadwal. Query `check_scheduled_posts` memilih WO pada menit yang sesuai. `update_sch_wo` maju ke tanggal berikutnya. | Tiga dump tidak memberi definisi atau nilai asli. Usulan `DATETIME NULL DEFAULT NULL` untuk Create biasa dirinci di atas. Scheduled memerlukan investigasi tersendiri. |
| `intervale` | Form dan model menyimpan jumlah hari untuk pengulangan. `Cron_controller::check_scheduled_posts` mengirim nilai ke `DATE_ADD` dan model menulisnya juga pada histori. | Kode historis menunjukkan semantik hari. Nilai dan tipe database tidak ditemukan dalam tiga dump atau metadata aktif. Pulihkan definisi dan nilai terverifikasi dari backup terjadwal sebelum mengusulkan perubahan. |
| `subcategory_id` | `reports_in.php` dan `reports_out.php` mengambil label subkategori dengan `helper_get_category`. Model legacy membaca filter `subcategory` tanpa predikat subkategori. CI4 memakai kolom yang sama dalam join label laporan. | `final_db_deploy.sql` versi awal menyimpan definisi `INT(11) DEFAULT NULL` pada posts dan posts_logs. Nilai per WO historis tidak terverifikasi. Untuk laporan, tinjau perubahan aditif minimum pada posts setelah bukti nilai atau konfirmasi pemilik tentang semantik NULL. Pertahankan label, filter dan hasil ekspor sampai rekonsiliasi selesai. |

Source aktif yang tersedia masih merujuk ketiga field tersebut. Tidak ditemukan implementasi setara berbasis field yang tersedia, atau nilai historis yang layak dipakai untuk backfill. Tanggal pembuatan, kategori status WO dan hubungan child master memiliki arti berbeda dari ketiga field tersebut.

Pemanggilan `Post_admin_model::publish_draft` hanya menulis `posts.status = 1`. CI4 mengikuti scope daftar Draft dan menolak publikasi jadwal dari endpoint Draft. Untuk special types, CI3 memilih view video, audio, gallery, dan ordered list. Model `Post_item_model` memakai tabel `post_gallery_items` dan `post_ordered_list_items` yang absen. Tabel `videos`, `audios` dan `post_audios` tersedia. Salinan MySQL terotorisasi hanya memiliki WO bertipe post. Unggahan audio memakai metadata dan relasi legacy pada fixture dengan tipe audio. Jalur ganti metadata dan edit lengkap audio/video perlu uji parity terpisah, termasuk histori dan lampiran.

Master Data memakai enam menu yang aktif. Form CI3 hanya menampilkan perubahan status eksplisit pada visibility Categories. Pembuatan Departments memakai visibility 1 dari input tersembunyi. Form Corporations hanya mengedit nama, kode dan urutan. CI4 menahan perpindahan corporation dan perubahan parent atau bahasa pada record existing untuk melindungi referensi WO. Enam kategori status WO inti dilindungi.

## Kontrak balasan Work Order dari CI3, 19 November 2026 WIB

- Controller Post_controller::add_post_post memvalidasi title wajib hingga 500, summary hingga 5.000, kategori, lokasi, role tujuan, dan optional_url hingga 1.000. Post_admin_model::add_post memakai corporation/department akun non-admin, mengambil pilihan form untuk administrator, mengisi user_id dari sesi, dan menulis published_at untuk WO biasa maupun scheduled. Form New memilih kategori ID 1. Controller menambah log, referensi gambar/audio sesuai post_type, dan secara kondisional menjalankan pengiriman ke subscriber. Kedua tabel target tidak memiliki published_at. Create dan penjadwalan tidak diaktifkan.
- Post_controller::update_post dan update_post_closed memakai struktur form balasan yang sama. View memeriksa layak_check dan menu Incoming menawarkan balasan dengan manage_all_posts kepada administrator, role 7, atau petugas dengan role yang sama dengan WO. API CI4 juga mewajibkan admin_panel dan add_post, serta memakai seluruh pembatas query Incoming sebelum membolehkan aksi. Assignee mengikuti get_active_users_assign berdasarkan role tujuan dan corporation WO. Fungsi CI3 tersebut tidak memfilter status assignee.
- Pemetaan status kategori dari schema dan model yang dikonfirmasi ialah ID 1 New, 2 Progress, 3 Waiting, 4 Closed, 5 Pending, dan 6 Reopen. Form balasan biasa menggunakan kategori induk berbahasa sama dengan visibility 1. Form reopen untuk Closed memilih visibility 0, yang pada snapshot ini hanya ID 6. Status publikasi pada kolom posts.status dan kategori kerja pada category_id dipertahankan sebagai field terpisah.
- Judul, ringkasan awal, corporation, department, pembuat, role tujuan, lokasi, dan created_at tidak dapat diedit melalui form balasan. CI4 hanya memperbarui kategori, assignee, editor, dan waktu edit. summary_edit yang wajib masuk ke posts_logs sesuai form. CI4 menolak markup dan membatasi panjang catatan menurut kolom VARCHAR(5000). Jalur update tidak memanggil notifikasi di controller CI3. React memperbarui cache hanya setelah API memastikan commit.
- CI3 melakukan UPDATE lalu INSERT log terpisah. CI4 membungkus keduanya dalam satu transaksi dan mengunci row. Revision mencakup snapshot row serta ID riwayat terbaru. Stale reply gagal tanpa menulis. Kegagalan INSERT riwayat membatalkan UPDATE WO.
- Post_file_model mengaitkan gambar tambahan melalui post_images.post_id dan audio melalui post_audios.post_id serta audio_id. Endpoint CI4 mengecek scope WO, relasi, root privat, realpath, jenis file aktual, dan flag download audio. Tidak ada file dari upload root legacy yang ditemukan dalam sampel 250 referensi. Mount arsip yang sah dibutuhkan untuk mengunduh lampiran asli. Legacy Upload_model membatasi temp image ke GIF/JPEG/PNG dan audio ke MP3/WAV. Upload, perubahan referensi, draft publish, dan tipe edit khusus belum dibuka.
- Scheduled masih merujuk intervale dan published_at pada form, query cron dan log. Tidak ada bukti field pengganti atau nilai historis pada dump yang diperiksa. Reports membaca subcategory_id, yang ditemukan pada final_db_deploy.sql lama dan absen pada schema otoritatif. Tidak ada perubahan schema, penggantian nilai, atau modifikasi query laporan.

## Hasil salin 19 November 2026 WIB

Pemilik mengizinkan eksekusi penyalinan dari schema ewohotel_tentrem ke eworeb pada TCP 3310. Preflight mengulang verifikasi backup definisi schema dan mengonfirmasi tujuan kosong tepat sebelum DDL. Delapan belas definisi tabel legacy diverifikasi setelah dibuat. Data dipindahkan melalui parameter dari snapshot sumber read-only. Sebelum commit, jumlah baris dan SHA-256 data terurut cocok untuk setiap tabel. Postflight metadata mengonfirmasi kesetaraan 18 tabel, 248 kolom, 18 indeks, constraint dan relasi. Pengaturan SQL selama salin mempertahankan dua tanggal tahun nol.

| Tabel | Record terverifikasi |
| --- | ---: |
| audios | 0 |
| categories | 6 |
| corps | 3 |
| departments | 22 |
| general_settings | 1 |
| images | 19.680 |
| languages | 1 |
| locations | 659 |
| post_audios | 0 |
| post_images | 3.126 |
| posts | 19.812 |
| posts_logs | 43.609 |
| roles_permissions | 7 |
| settings | 1 |
| users | 95 |
| users_logs | 356.975 |
| videos | 0 |
| visual_settings | 1 |
| Total | 443.998 |

- spark ewo:diagnose --inventory lulus pada grup database.ewo.
- Probe repository read-only pada eworeb lulus untuk pembacaan auth/settings, Dashboard, Incoming, Outgoing, Drafts, dan direktori kategori, lokasi, department, pengguna, serta administrator. Untuk Dashboard dan WO ada enam sampel role.
- Scheduled masih gagal karena posts.intervale dan posts.published_at absen dari definisi legacy. Reports In/Out masih gagal pada posts.subcategory_id. Kolom tersebut belum ditambahkan.
- Portal Knowledge Center dan aset Apache lulus untuk akses guest. API terlindungi mengembalikan 401 dan workspace mengarahkan guest ke login. Login dengan akun asli dan permutasi role dalam browser belum diuji.
- Pekerjaan ini menulis hanya ke skema pengembangan eworeb. Tidak ada perubahan .env, penulisan sumber, tabel KB, data sintetis, atau deployment.

## Konfirmasi sumber dan target

Konfigurasi CI3 terbaru menunjuk localhost TCP 3310 dan schema ewohotel_tentrem. Koneksi melalui konfigurasi CI3 berhasil dan memverifikasi 18 tabel InnoDB, 248 kolom, 18 indeks, serta 0 FK, view, routine, event dan trigger. Pemilik menetapkan eworeb pada port 3310 sebagai tujuan pengembangan. Grup database.ewo di CI4 memilih schema tersebut. Koneksi sumber dan tujuan memverifikasi UUID server yang sama dan schema yang berbeda.

Preflight terbaru melihat eworeb kosong dan memverifikasi backup definisi schema. SHA-256 backup adalah 0715c06061f7a8a30454588693121935418ab97ee89ced1ac2637a4e5098e877. Backup tidak memuat record karena tujuan belum memiliki tabel. Perbandingan eworeb pada bagian historis di bawah berasal dari kondisi sebelum preflight terbaru.

Daftar perubahan yang ditinjau sebelum eksekusi hanya 18 CREATE TABLE sesuai definisi sumber dan penyalinan record asli ke eworeb. Pemeriksaan ulang wajib berhenti jika objek tujuan muncul, backup berbeda, atau definisi sumber berubah. Nama schema sumber dan tujuan dikunci. Data disalin melalui parameter dari snapshot baca-saja dan diverifikasi dengan jumlah baris serta checksum sebelum commit. Dua field tanggal tahun nol terdeteksi dan harus dipertahankan memakai pengaturan SQL yang berlaku hanya selama sesi salin.

## Sumber dan batas bukti historis

Konfigurasi CI3 di application/config/database.php memilih MySQLi, host localhost dan schema ewo. Konfigurasi tidak menentukan port. PHP XAMPP CI3 melaporkan port MySQLi default 3306 dan dua listener lokal ditemukan pada 3306 dan 3310. Percobaan baca-saja dengan konfigurasi CI3 pada TCP 3306 ditolak dengan MySQL 1045 sebelum metadata schema bisa dibaca. Schema ewo di TCP 3310 memang terlihat, tetapi koneksi dan metadata itu tidak menetapkan database yang dipakai runtime CI3.

Kandidat pembanding terbaik saat ini ialah ewohotel_tentrem di TCP 3310 karena 18 tabel serta tipe kolom, nullability, indeks dan FK yang terurai cocok dengan ewohotel_tntrm.sql. Penamaan file dan schema berbeda. Status kandidat sebagai sumber produksi atau salinan pengembangan belum dikonfirmasi. final_db_deploy.sql mengikuti bentuk yang lebih dekat dengan eworeb dan berisi DROP TABLE. user.sql adalah dump satu tabel dari schema lain. Ketiganya diperiksa secara statis tanpa mengeksekusi SQL atau mengimpor data.

Metadata server diperiksa lewat transaksi read-only. Perbandingan live memakai tipe kolom, nullability, default, collation, generated/extra, kolom bersama dan urutannya, indeks, constraint, FK, dan engine. Perbandingan dump menormalisasi alias utf8mb3 dan lebar tampilan integer. Parser dump mencakup nama tabel, tipe, nullability, definisi indeks dan FK yang tercatat. Jumlah DML di bawah adalah jumlah instruksi terdeteksi, bukan jumlah record. Data pribadi, hash akun, nilai default dan kredensial tidak dicantumkan.

## Skema yang teramati

| Sumber | Tabel | Kolom | Indeks | FK | Trigger / routine / event |
| --- | ---: | ---: | ---: | ---: | --- |
| ewo TCP 3310 | 17 | 265 | 17 | 0 | 0 / 0 / 0 |
| eworeb TCP 3310 | 17 | 265 | 17 | 0 | 0 / 0 / 0 |
| ewohotel_tentrem TCP 3310 | 18 | 248 | 18 | 0 | 0 / 0 / 0 |

## Selisih kandidat ewohotel_tentrem terhadap eworeb

Hanya ada di kandidat: `departments`. Hanya ada di eworeb: �.

| Tabel | Kolom hanya di acuan | Kolom hanya di pembanding | Perbedaan kolom bersama | Perbedaan lain |
| --- | --- | --- | --- | --- |
| categories | � | `block_type`, `description`, `keywords`, `show_at_homepage`, `show_on_menu` | � | � |
| locations | � | `block_type`, `description`, `keywords`, `show_at_homepage`, `show_on_menu` | � | � |
| posts | `corp_id`, `dept_id`, `is_scheduled` | `content`, `feed_id`, `hit`, `keywords`, `need_auth`, `optional_url`, `post_url`, `show_item_numbers`, `show_post_url`, `show_right_column`, `subcategory_id`, `title_hash`, `title_slug` | � | � |
| posts_logs | `corp_id`, `dept_id`, `is_scheduled` | `content`, `feed_id`, `hit`, `keywords`, `need_auth`, `optional_url`, `post_url`, `show_item_numbers`, `show_post_url`, `show_right_column`, `subcategory_id`, `title_hash`, `title_slug` | � | � |
| roles_permissions | `departments` | � | � | � |
| users | `dept_id` | � | � | � |
| visual_settings | `site_block_color` | � | � | � |

Skema ewo pada TCP 3310 terhadap eworeb: seluruh signature metadata teramati cocok.

## Dump dibandingkan dengan kandidat lokal

### ewohotel_tntrm.sql

Tabel di dump: 18. DROP TABLE: 0. TRUNCATE: 0. ALTER TABLE: 36. Instruksi DML terdeteksi: 973.

Tabel hanya di dump: �. Tabel kandidat yang tidak ada di dump: �.

Tipe, nullability, indeks dan FK yang diparsing cocok untuk tabel bersama.

### final_db_deploy.sql

Tabel di dump: 17. DROP TABLE: 17. TRUNCATE: 0. ALTER TABLE: 0. Instruksi DML terdeteksi: 10.

Tabel hanya di dump: �. Tabel kandidat yang tidak ada di dump: `departments`.

| Tabel | Kolom hanya di acuan | Kolom hanya di pembanding | Perbedaan kolom bersama | Perbedaan lain |
| --- | --- | --- | --- | --- |
| categories | `block_type`, `description`, `keywords`, `show_at_homepage`, `show_on_menu` | � | � | � |
| locations | `block_type`, `description`, `keywords`, `show_at_homepage`, `show_on_menu` | � | � | � |
| posts | `content`, `feed_id`, `hit`, `keywords`, `need_auth`, `optional_url`, `post_url`, `show_item_numbers`, `show_post_url`, `show_right_column`, `subcategory_id`, `title_hash`, `title_slug` | `corp_id`, `dept_id`, `is_scheduled` | � | � |
| posts_logs | `content`, `feed_id`, `hit`, `keywords`, `need_auth`, `optional_url`, `post_url`, `show_item_numbers`, `show_post_url`, `show_right_column`, `subcategory_id`, `title_hash`, `title_slug` | `corp_id`, `dept_id`, `is_scheduled` | � | � |
| roles_permissions | � | `departments` | � | � |
| users | � | `dept_id` | � | � |
| visual_settings | � | `site_block_color` | � | � |

### user.sql

Tabel di dump: 1. DROP TABLE: 1. TRUNCATE: 0. ALTER TABLE: 0. Instruksi DML terdeteksi: 1.

Tabel hanya di dump: �. Tabel kandidat yang tidak ada di dump: `audios`, `categories`, `corps`, `departments`, `general_settings`, `images`, `languages`, `locations`, `post_audios`, `post_images`, `posts`, `posts_logs`, `roles_permissions`, `settings`, `users_logs`, `videos`, `visual_settings`.

| Tabel | Kolom hanya di acuan | Kolom hanya di pembanding | Perbedaan kolom bersama | Perbedaan lain |
| --- | --- | --- | --- | --- |
| users | `facebook_id`, `facebook_url`, `google_id`, `instagram_url`, `linkedin_url`, `pinterest_url`, `show_email_on_profile`, `show_rss_feeds`, `telegram_url`, `twitter_url`, `vk_id`, `vk_url`, `youtube_url` | `corp_id`, `dept_id` | � | � |

## Referensi tabel pada kode CI3

Referensi literal yang cocok dengan kandidat: `audios`, `categories`, `corps`, `departments`, `general_settings`, `images`, `languages`, `locations`, `post_audios`, `post_images`, `posts`, `posts_logs`, `roles_permissions`, `settings`, `users`, `users_logs`, `videos`, `visual_settings`.

Referensi literal tanpa tabel yang terlihat pada kandidat: `followers`, `pages`, `post_gallery_items`, `post_hits`, `post_ordered_list_items`, `tags`, `widgets`. Daftar ini mencakup kode warisan dan kemungkinan komentar. Ia tidak membuktikan bahwa route aktif memakai tabel tersebut.

Seluruh tabel kandidat: `audios`, `categories`, `corps`, `departments`, `general_settings`, `images`, `languages`, `locations`, `post_audios`, `post_images`, `posts`, `posts_logs`, `roles_permissions`, `settings`, `users`, `users_logs`, `videos`, `visual_settings`.

## Data yang terlihat pada TCP 3310

| Schema pembanding | Accounts | WO | Riwayat WO | Departments |
| --- | ---: | ---: | ---: | ---: |
| ewo | 9 | 0 | 0 | Tabel belum ada |
| eworeb | 9 | 0 | 0 | Tabel belum ada |
| ewohotel_tentrem | 95 | 19.812 | 43.609 | 22 |

Tabel tidak mendeklarasikan FK. CI3 menghubungkan data di model dan view. SELECT agregat pada kandidat menemukan 3 WO dengan author ID positif tanpa baris users dan 117 baris posts_logs dengan post ID positif tanpa baris posts. Pemeriksaan WO ke corporation, department, assignee positif dan role akun tidak menemukan referensi tak cocok. Pertahankan record tersebut saat menyalin. Makna riwayat orphan perlu dikonfirmasi pada sumber otoritatif sebelum memperbaiki data.

## Bukti query CI4 pada data yang ada

Probe menjalankan repository CI4 dalam transaksi read-only pada masing-masing schema. Ia mengambil maksimal satu akun aktif per role yang memiliki akses panel untuk menentukan cakupan query. Probe tidak mengautentikasi akun, menyimpan sesi, mengubah .env atau menampilkan record. Hasil ini menguji keberhasilan pembacaan, belum membandingkan hasil bisnis CI3 baris demi baris.

| Modul | eworeb TCP 3310 | ewohotel_tentrem TCP 3310 |
| --- | --- | --- |
| Authentication dan settings | Pembacaan users, roles_permissions, general_settings dan settings lulus. Login dan aktivitas belum diuji | Pembacaan yang sama lulus. Login dan aktivitas belum diuji |
| Dashboard, Incoming, Outgoing | Kolom corp_id, dept_id dan is_scheduled tidak lengkap. Role yang membutuhkan departemen ditolak | Query dashboard, Incoming dan Outgoing lulus pada enam sampel role, belum diterima sebagai parity CI3 |
| Drafts | Query gagal pada corp_id | Query lulus pada enam sampel role |
| Scheduled | Query gagal pada is_scheduled. intervale dan published_at juga tidak ada | Gagal pada intervale. published_at juga tidak ada. Post_admin_model dan tampilan Scheduled aktif membutuhkan kedua kolom |
| Reports In/Out | Gagal pada is_scheduled sebelum join laporan | Gagal pada posts.subcategory_id. Kedua view Reports CI3 membaca field itu. Kolom ada di eworeb, tetapi tipe dan sumber nilai untuk salinan yang disetujui tetap perlu divalidasi |
| Category, Subcategory, Location, Sublocation | Query baca lulus | Query baca lulus |
| Departments, Users, Administrators | departments tidak ada. Users dan Administrators gagal pada users.dept_id | Query baca lulus menggunakan tabel legacy yang sudah tersedia |
| WO detail/status/assignment/history dan lampiran | Belum tersedia di CI4 | Belum tersedia di CI4. CI3 memakai posts, posts_logs, post_images/images dan post_audios/audios. Lookup detail mengambil ID langsung. Source lokal juga memanggil tag_model pada detail/edit, tetapi model tersebut tidak termasuk daftar autoload aktif. Verifikasi cakupan detail dan jalur ini pada sumber yang otoritatif sebelum membuka endpoint |
| Mutasi master/user/role, profile, settings dan Visual Settings | Belum tersedia | Belum tersedia. Model legacy menjadi acuan validasi. Kandidat memuat departments permission dan visual_settings.site_block_color yang absen dari eworeb |
| Knowledge Center | JSON existing | JSON existing. Tidak ada tabel kandidat yang sesuai secara semantik |

Kolom posts.corp_id, posts.dept_id dan posts.is_scheduled juga tidak ada di posts_logs pada eworeb. CI3 menulis log perubahan melalui Post_admin_model. Jangan meniru status dari fixture atau mengisi ulang field ini dengan nilai asumsi.

## Prosedur salin pada tujuan pengembangan yang disetujui

1. Catat endpoint, nama schema, versi, scope akses baca-saja sumber dan pemilik tujuan. Selesaikan penolakan akses CI3 pada port 3306 atau minta konfirmasi tertulis mengenai sumber pengganti yang otoritatif. Hentikan jika tujuan belum dinyatakan terpisah atau jika identitasnya sama dengan sumber.
2. Inventaris sumber yang disetujui melalui information_schema dan SHOW CREATE TABLE. Validasi nama kolom pada menu CI3 yang aktif, khususnya Scheduled, Reports, role dan WO history. Bedakan struktur yang diperlukan dari data yang perlu dilestarikan. Jangan memasukkan tabel KB atau tabel fixture.
3. Simpan backup eworeb dan tujuan pengembangan bila sudah ada. Gunakan lokasi privat dan verifikasi kemampuan restore pada ruang yang disetujui. Catat jumlah tabel, row count, checksum backup dan snapshot consistency. Backup berisi akun dan riwayat sehingga jangan letakkan di repository atau document root.
4. Tampilkan daftar perubahan dari sumber terverifikasi dan minta persetujuan sebelum penulisan. Siapkan CREATE TABLE dari definisi legacy yang direview untuk tujuan kosong. Hilangkan switch schema, DROP, TRUNCATE, REPLACE dan instruksi instalasi dari paket salin. Pertahankan PK, index, tipe, charset, collation, AUTO_INCREMENT dan relasi. Gagal dengan aman jika objek tujuan sudah ada.
5. Bila penyalinan data disetujui, salin record asli dalam snapshot konsisten melalui transfer terparameterisasi atau dump data yang sudah direview, tanpa perubahan ID, hash, role, status, assignment atau history. Hentikan saat konflik ID. Bandingkan row count, integritas relasi dan checksum tanpa menampilkan record. Jangan memperbaiki orphan atau menggabungkan akun secara otomatis.
6. Sesudah verifikasi, ubah hanya .env EWO Reborn untuk menunjuk tujuan pengembangan yang disetujui. Uji login dengan akun legacy yang sah pada salinan karena jalur normal menulis users.last_seen, users.ip_address dan users_logs. Uji role, Dashboard, Incoming/Outgoing, Draft, Scheduled, detail/assignment/history, master dan Reports dengan data riil salinan. Bandingkan hasil dan izin dengan CI3 sebelum menyatakan parity. Jalankan rollback konfigurasi jika tahap penerimaan gagal.

Prosedur di atas disiapkan sebelum eksekusi. Hasil untuk tujuan eworeb dicatat di bagian teratas. Login pada salinan pengembangan belum diuji.
