# Audit dan penyelarasan database EWO legacy

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
