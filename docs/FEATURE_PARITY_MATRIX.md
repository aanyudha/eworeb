# Matriks parity EWO CI3 → CI4

## Status modul dan parity legacy terbaru, 20 November 2026 WIB

| Modul | Status | Cakupan |
| --- | --- | --- |
| Login dan dashboard tunggal React | Fully working | Login Apache dengan akun legacy sudah dibuktikan pada tahap sebelumnya. Regresi sekarang memakai MySQL dan Chrome terisolasi. |
| Sidebar Workspace dan menu akun | Browser verified | Menu WO langsung, dua grup berdasarkan izin, profil dan kata sandi di menu akun, deep link, keyboard, compact, tablet dan mobile. |
| Incoming, Outgoing, detail dan history | Read-only working | API mengikuti scope WO. Arsip baca-saja terpasang sebagian dan unduhan mengikuti relasi dan pemeriksaan izin. |
| Balasan, assignment, completion dan reopen tipe post | Write verified on isolated MySQL | Perubahan dan histori dalam satu transaksi dengan pemeriksaan revisi. |
| Publikasi Draft biasa | Write verified on isolated MySQL | Daftar React dengan konfirmasi. Hanya `status` berubah. Filter scope, penolakan lintas corporation atau department, CSRF dan pengiriman ulang diuji. |
| Lampiran gambar dan audio | Write verified on isolated MySQL | Gambar tambahan pada tipe post dan audio pada tipe audio untuk Incoming yang memenuhi syarat. Format, izin unduh audio dan rollback diuji. |
| Categories dan Subcategories | Write verified on isolated MySQL | Tambah dan edit di tabel categories. ID status WO 1–6 terkunci. Validasi parent dan bahasa. |
| Locations dan Sublocations | Write verified on isolated MySQL | Tambah dan edit dengan corporation dari sesi untuk non-admin. Parent lintas corporation ditolak. |
| Departments | Write verified on isolated MySQL | Tambah dan edit dengan scope corporation. Form legacy memakai visibility tersembunyi untuk pembuatan. |
| Corporations | Write verified on isolated MySQL | Administrator menambah dan mengedit nama, kode dan urutan. Form aktif CI3 tidak memiliki kontrol status. |
| Users dan Administrators | Write verified on isolated MySQL | Administrator menambah akun, mengedit nama dan email, dan mengaktifkan atau menonaktifkan login baru. Sesi yang sudah aktif mengikuti perilaku legacy. Penggantian role, pemindahan corporation atau department dan delegasi mutasi ke pemegang izin users belum diterapkan. |
| Roles dan Permissions | Write verified on isolated MySQL | Hanya administrator. Tujuh flag yang benar-benar ditulis Auth_model::update_role. Role administrator terlindungi. |
| My profile | Write verified on isolated MySQL | Cakupan akun sendiri. Email memerlukan password saat ini dan hanya dapat berubah saat email verification nonaktif. Avatar memerlukan GD dan penyimpanan privat. |
| Change password | Write verified on isolated MySQL | Current password wajib dan hash baru kompatibel dengan login legacy. |
| Application settings dan Visual Settings | Write verified on isolated MySQL | Timezone, field teks aktif, logo raster dan favicon PNG untuk login serta Workspace. Email Settings dan konfigurasi HTML masih tertunda. |
| Create WO biasa | Enabled on development TCP 3310, writes verified on isolated MySQL | Dua kolom `DATETIME NULL DEFAULT NULL` telah diterapkan dan diverifikasi. Description opsional. Draft dan Publish, histori awal, relasi gambar melalui API, scope, CSRF, dan rollback lulus pada fixture. Pemilih gambar pada form dan notifikasi belum aktif. |
| Scheduled | Blocked by schema | Kontrak `intervale`, histori jadwal dan cron belum terbukti. Create biasa tidak mengaktifkan Scheduled. |
| Reports In dan Reports Out | Read-only working with limitation | Baris dan scope dari data aktual. Penanda subkategori tidak tersedia sama pada tabel, PDF, dan XLSX. Hasil ini tidak menetapkan paritas lengkap dengan Jasper. |
| Edit gallery dan ordered list | Blocked by schema | Tabel relasi item CI3 tidak tersedia pada schema aktif. |
| Edit metadata audio dan video | Not yet migrated | Source CI3 memiliki view. Jalur lokal memakai post_type yang seluruhnya post. Detail audio baru sebatas lampiran. |
| Knowledge Center dan editor | Fully working | JSON existing. Publikasi, CSRF, akses publik dan navigasi lulus di fixture. |

Operasi tulis di atas diuji pada MySQL terisolasi. Hanya sakelar Create aktif pada pengembangan `eworeb` TCP 3310.

## Bukti dashboard tunggal dan alur WO sebelumnya, 19 November 2026 WIB

Status ini menggantikan hasil historis di bawah. Salinan eworeb port 3310 digunakan untuk regresi read-only.

| Modul | Status | Legacy, CI4, React dan bukti |
| --- | --- | --- |
| Login, redirect, dashboard | Fully working | Auth_controller dan dashboard lama menjadi acuan. AuthController menuju /workspace, AdminController menjaga admin_panel sebelum redirect /admin, API v1 dashboard tetap. Chrome fixture menguji login, redirect alias, refresh dan SPA history |
| Incoming, Outgoing dan Drafts | Read-only working | Post_controller dan Post_admin_model, API v1 work-orders, Workspace dengan izin add_post. Parity MySQL real untuk daftar dan dashboard dicatat pada tahap sebelumnya |
| Detail dan history | Read-only working | Post_controller::view_post dan Post_admin_model::get_posts_history. CI4 detail memakai scope daftar dan paginasi posts_logs. Nama editor dari corporation lain disembunyikan bagi non-admin |
| Edit balasan, assignment, status, completion dan reopen untuk tipe post | Write functionality verified on isolated MySQL | Post_controller::update_post_post dan form Incoming/Closed. GET/POST api/v1/work-orders/incoming/{id}/reply, dipasang pada detail React Incoming. admin_panel, add_post, manage_all_posts, cakupan Incoming, layak_check, pilihan kategori dan assignee. Pengubahan empat field serta INSERT history diuji dengan schema legacy terisolasi. Default write gate tetap false |
| Create WO, Scheduled | Blocked by schema | posts dan posts_logs tidak memiliki published_at; Scheduled juga tidak memiliki intervale. Tidak ada default palsu atau DDL |
| Reports In/Out | Blocked by schema | posts.subcategory_id tidak tersedia; query syarat dan hasil ekspor tidak diubah |
| Referensi lampiran dan unduhan privat | Read-only working | Legacy post_images serta post_audios/audios. Scope add_post dan daftar WO pada API attachments. File lokal uji diunduh melalui endpoint yang sama; arsip lampiran asli belum dipasang |
| Upload, edit tipe khusus, draft publish, dan mutasi master/user | Not yet migrated | Belum ada route mutasi baru untuk alur tersebut |
| Categories/Subcategories, Locations/Sublocations, Departments, Corporations, Users dan Administrators, Roles dan Permissions | Read-only working | API direktori dan React existing dipertahankan dengan permission yang sama |
| Knowledge Center dan editor | Fully working | JSON existing; HTTP dan Chrome menguji publikasi admin, filter publik, direct link dan CSRF terisolasi |

## Bukti tahapan sebelumnya

## Status penerimaan MySQL legacy, 19 November 2026 WIB

| Menu atau kemampuan | Status | Bukti dan batas |
| --- | --- | --- |
| Login dan logout | Fully working | Akun legacy login manual pada Apache, dashboard API 200, POST tanpa CSRF 403, sesi tetap berlaku saat navigasi, logout mengarah ke form login dan API kembali 401. users_logs bertambah 14 untuk satu akun selama pengujian dan semua aktivitas cocok dengan last_seen |
| Dashboard | Read-only working | Browser desktop dan 390 px lulus. Dua skenario per role untuk enam role cocok dengan model CI3 pada MySQL eworeb |
| Incoming WO | Read-only working | Browser desktop lulus. Daftar ID dan total cocok dengan model CI3 pada 12 skenario |
| Outgoing WO | Read-only working | Browser desktop lulus. Daftar ID dan total cocok dengan model CI3 pada 12 skenario |
| Work Order detail dan history | Read-only working | Detail memakai cakupan daftar Incoming atau Outgoing. 55 detail diuji dari baris daftar. 10 akses luar corporation ditolak. Chrome menguji detail Outgoing dan refresh. Lampiran dan mutasi belum tersedia |
| Drafts | Read-only working | Query pada data legacy berhasil. Publish draft belum tersedia |
| Categories dan Subcategories | Read-only working | Query CI4 menggunakan categories dan hubungan parent_id |
| Locations dan Sublocations | Read-only working | Query CI4 menggunakan locations dan hubungan parent_id. Corporation dibatasi dari sesi |
| Departments | Read-only working | Query CI4 membaca departments dan membatasi corporation |
| Corporations | Read-only working | Sidebar mengikuti permission corps. Non-admin melihat corporation sendiri sesuai batas tugas. Pencarian dan pagination server-side tersedia. Chrome desktop lulus |
| Users dan Administrators | Read-only working | Query menggunakan users. Daftar non-admin dibatasi pada corporation sesi. Mutasi akun belum tersedia |
| Roles dan Permissions | Read-only working | Menggunakan roles_permissions. Menu dan endpoint hanya untuk administrator. Chrome desktop lulus |
| Scheduled | Blocked by schema | posts.intervale dan posts.published_at absen. Menu disembunyikan dan endpoint memberi 503 pada pengguna yang memiliki izin |
| Reports In dan Reports Out | Blocked by schema | posts.subcategory_id absen. Menu disembunyikan dan endpoint memberi 503. Ekspor fixture terdahulu tidak menetapkan penerimaan laporan legacy |
| Create WO | Blocked by schema | CI3 menulis published_at pada posts dan posts_logs sekalipun WO bukan scheduled. Nilai dan tipe harus diverifikasi sebelum migrasi penulisan |
| Update, assignment, status transition dan completion | Not yet migrated | Pengujian mutasi memerlukan salinan terisolasi dan pembuktian scope serta efek log CI3 |
| Pengelolaan master, akun, role, profile, password dan settings | Not yet migrated | Belum ada endpoint tulis baru |
| Knowledge Center | Fully working | Katalog JSON existing tetap aktif. Portal publik Apache lulus. Bukti MySQL KB terdahulu bersifat referensi dan tidak menetapkan target penyimpanan baru |

Detail memakai ID dari daftar yang sama dan mengembalikan 404 untuk WO di luar cakupan. Riwayat dibatasi pada corporation WO dan mengikuti cabang join legacy berdasarkan ada tidaknya catatan awal. Nama editor dan assignee diambil hanya dari corporation tersebut. File lampiran belum disajikan.

## Inventaris legacy dan bukti historis

Status di bawah mencakup implementasi source dan bukti historis fixture. Penerimaan legacy-first mengikuti [audit aktual](LEGACY_DATABASE_ALIGNMENT.md), termasuk kegagalan Scheduled dan Reports pada kandidat dan status login yang belum diuji pada salinan pengembangan.

Route admin berikut memakai prefix admin dari slug legacy. Izin berasal dari sidebar dan controller CI3. Semua API operasional CI4 juga melewati sesi server. Tanda — berarti halaman atau API CI4 belum tersedia. Bukti ini memakai data sintetis.

| Menu atau aksi | Route CI3 dan controller | Izin dan cakupan CI3 | Query, model dan tabel | Route CI4 dan React | Status dan pengujian |
| --- | --- | --- | --- | --- | --- |
| Login dan logout | admin/login, auth_controller/admin_login_post, logout → Auth_controller | Form memakai email, status akun, hash legacy dan sesi | Auth_model pada users, roles_permissions, users_logs, general_settings, settings | Route login/logout existing dan dashboard setelah login | Login benar/salah, akun nonaktif, sesi, izin, CSRF, aktivitas dan logout lulus pada MySQL 8.0.45 terisolasi |
| Dashboard | admin → Admin_controller::index | admin_panel, role 1/3/4/5/6/7/8 dengan cakupan CI3 | Post_admin_model menghitung posts per kategori; corps untuk filter | GET api/v1/dashboard, admin dan workspace | Enam skenario cocok dengan CI3 pada MySQL sintetis. IT Waiting tetap memakai angka Engineering sesuai sumber |
| Add WO | admin/add-post, post_controller/add_post_post → Post_controller | add_post, formulir lang/lokasi/role/corp sesuai akun | Post_admin_model::add_post/add_post_logs, categories, locations, departments, corps, users, images, posts, posts_logs | — | Belum dimigrasikan. Catat pembuatan baris dan riwayat bersama, validasi status awal, assignment, upload dan side effect notifikasi |
| Incoming WO | admin/posts_in → Post_controller::posts_in | add_post, admin role 1 dapat filter corp, role 7/8 corp sendiri, role 3/5/6 corp dan role sendiri, role 4 corp/role/department dan creator sendiri | Post_admin_model::get_paginated_posts_in dan count, posts yang visible/active/non-scheduled, filter form | GET api/v1/work-orders/incoming, workspace/work-orders/incoming | Daftar baca-saja, filter dan pagination. Daftar utama menampilkan ID untuk corporation, department, dan role. Detail dan lampiran belum tersedia. Tie pada created_at diurutkan dengan id agar pagination stabil |
| Outgoing WO | admin/posts_out → Post_controller::posts_out | add_post, admin role 1 dapat filter corp, role 7/8 corp sendiri, role 3/4/5/6 corp/department dengan role tujuan berbeda | Post_admin_model::get_paginated_posts_out dan count, posts yang visible/active/non-scheduled | GET api/v1/work-orders/outgoing, workspace/work-orders/outgoing | Daftar baca-saja, filter dan pagination. Tie pada created_at diurutkan dengan id. Role di luar matriks ditolak di kedua daftar |
| Detail WO, lampiran dan history | admin/view-post/{id} → Post_controller::view_post | add_post. Query detail legacy mengambil id langsung dan kebijakan view tidak cukup untuk memastikan cakupan lintas corp | Post_admin_model::get_post/get_posts_history pada posts/posts_logs, Post_file_model, users, categories, images | — | Ditahan sampai cakupan akses detail, riwayat dan file disepakati |
| Update, assignment, status, completion | admin/update-post/{id}, admin/update-post-closed/{id}, post_controller/update_post_post | add_post, pemeriksaan role/action pada view dan controller harus dipetakan per aksi | Post_admin_model::update_post/update_post_logs, posts dan posts_logs, Auth_model::get_active_users_assign, images | — | Belum dimigrasikan. Perlu matriks transisi, cakupan per aksi, dan transaksi history |
| Scheduled | admin/scheduled-posts → Post_controller::scheduled_posts | Sidebar add_post, aksi controller manage_all_posts | Post_admin_model::get_paginated_scheduled_posts/count pada posts dan filter legacy | GET api/v1/work-orders/scheduled, workspace/work-orders/scheduled | Daftar baca-saja. Izin manage_all_posts ditambahkan pada syarat sidebar add_post. Scheduler, edit dan eksekusi publikasi belum tersedia |
| Drafts | admin/drafts → Post_controller::drafts | add_post, cakupan query draft berbeda dari daftar published | Post_admin_model::get_paginated_drafts/count pada posts, publish_draft_post | GET api/v1/work-orders/drafts, workspace/work-orders/drafts | Daftar baca-saja dengan scope Outgoing serta status berbeda dari daftar aktif. Aksi publish draft belum tersedia |
| Categories dan subcategories | admin/categories, admin/subcategories, update-category/{id}, update-subcategory/{id} → Category_controller | categories | Category_model, categories parent_id dan category_order, referensi status/WO pada posts | GET api/v1/admin-directory/{categories,subcategories}, workspace/directory/{categories,subcategories} | Daftar baca-saja. HTTP menguji induk/anak dan pencarian |
| Locations dan sublocations | admin/locations, admin/sublocations, update-location/{id}, update-sublocation/{id} → Location_controller | locations, variasi is_admin dan corporation pada operasi | Location_model, locations parent_id/corp_id/location_order; posts | GET api/v1/admin-directory/{locations,sublocations}, workspace/directory/{locations,sublocations} | Daftar baca-saja. Cakupan corporation diuji. Sublocation lintas corp dengan parent yang tidak konsisten dikecualikan |
| Departments | admin/departments, update-department/{id} → Department_controller | departments dan filter scoped legacy | Department_model, departments, corps dan referensi WO/user | GET api/v1/admin-directory/departments, workspace/directory/departments | Daftar baca-saja, cakupan corp lulus fixture. Tabel departments tidak ada pada schema eworeb aktif |
| Corporations | admin/corp-settings, admin/corps, update-corp/{id} → Corp_controller | Sidebar corps, guard corps pada edit tetapi guard daftar dikomentari | Corp_model::get_all_corps/get_corps pada corps, corp_order | — | Admin master ditahan. Filter corporation pada Dashboard tersedia sesuai cakupannya |
| Users, add dan edit | admin/users, admin/add-user, admin/edit-user/{id} → Admin_controller | users, daftar corp pengguna, akses edit target perlu aturan eksplisit | Auth_model::get_users pada users dan profile/auth models untuk perubahan; roles_permissions, corps, departments | GET api/v1/admin-directory/users, workspace/directory/users | Daftar baca-saja, scope corp lulus fixture. Buat/edit/status dan perubahan role belum tersedia |
| Administrators, roles, permissions | admin/administrators, admin/roles-permissions, admin/edit-role/{id} → Admin_controller | is_admin; submenu administrators berada di bawah users | Auth_model::get_administrators/get_roles/get_role, users dan roles_permissions | GET api/v1/admin-directory/administrators, workspace/directory/administrators | Daftar administrators diuji. Role dan permission management belum tersedia |
| Profile dan change password | settings, settings/change-password → Profile_controller | Sesi pengguna sendiri | Profile_model, users; perubahan email dapat memicu verifikasi, avatar, dan department mengubah cakupan | — | Belum dimigrasikan. Aturan pergantian department dan validasi kata sandi lama perlu parity |
| Reports In | admin/reports_in → Report_controller::reports_in | add_post, cakupan list incoming | Post_admin_model::get_paginated_posts_in/count, posts serta lookups categories, locations, departments, users, roles_permissions | GET api/v1/reports/incoming, workspace/reports/incoming | HTTP lulus untuk izin, scope, filter, urutan dan jumlah baris. Chrome mengunduh XLSX dan tes membuka worksheet. Image memakai indikator ketersediaan |
| Reports Out | admin/reports_out → Report_controller::reports_out | add_post, cakupan list outgoing | Post_admin_model::get_paginated_posts_out/count, tabel/lookup seperti Reports In | GET api/v1/reports/outgoing, workspace/reports/outgoing | HTTP lulus dan Chrome mengunduh PDF A3 landscape. Image memakai indikator ketersediaan. Endpoint Jasper tidak diaktifkan |
| Visual settings | admin/visual-settings → Admin_controller::visual_settings | Sidebar is_admin, controller settings | Visual_settings_model, visual_settings dan file visual | — | Belum dimigrasikan |
| Settings | admin/settings → Admin_controller::settings | Sidebar is_admin, controller settings | Settings_model, general_settings, settings dan bahasa yang aktif | — | Belum dimigrasikan. Email Settings dan Navigation tidak ada di sidebar aktif |
| Knowledge Center dan admin KB | Fitur baru CI4 | Publik published + public. Tulis perlu sesi, admin_panel, administrator dan CSRF | articles.json pada runtime lokal | kb, api/v1/kb/*, api/v1/admin/kb/*, workspace/knowledge | JSON dipertahankan. Rencana adapter MySQL dihentikan. Tabel legacy yang cocok belum teridentifikasi |

Daftar WO menggunakan nama parameter filter legacy. Batas tanggal akhir tetap pukul 00:00 sesuai query BETWEEN legacy. Parameter subcategory dibaca oleh CI3 tanpa menjadi predikat SQL. Input tanggal tidak valid ditolak dengan 422. Untuk detail WO dan mutasi, audit aturan akses di controller, model, view, dan penyimpanan file harus selesai sebelum membuka endpoint.

## Catatan parity Reports

Reports In/Out berjalan dari baris halaman yang sudah difilter dan dipaginasi oleh CI4. PDF A3 landscape dan Excel XLSX mempertahankan 12 kolom laporan. Teks Excel ditulis sebagai inline string. File tidak menerima formula dari judul atau ringkasan. Media ditampilkan sebagai indikator karena lingkungan ini belum memiliki pemetaan penyimpanan lampiran yang dapat diverifikasi. Join ke pengguna, departemen dan lokasi juga mencocokkan corp WO. Referensi yang silang-corporation dikosongkan.

Report_controller legacy memiliki jalur Jasper terpisah dari DataTables. Audit menemukan nilai koneksi statis dan guard yang tidak diterapkan pada method ekspor lama. Endpoint dan template Jasper tidak dibawa ke CI4. Pengujian browser memeriksa header PDF, ukuran A3, workbook ZIP dan konten worksheet pada fixture. Penerimaan pada data pelaporan yang disetujui masih terbuka.
