# Progres migrasi EWO

## Cutover Knowledge Base MySQL pengembangan, 20 November 2026 WIB

- Backup `eworeb` terbaru lulus restore pada MySQL terisolasi. Setelah jumlah dan fingerprint katalog sumber cocok, `kb_articles` menjadi satu-satunya tabel baru dan `.env` lokal beralih ke `knowledgebase.backend = mysql`. Katalog JSON efektif berisi nol artikel. Importer menghasilkan nol baris; manifest sumber merekam file yang absen.
- Fingerprint dan definisi seluruh 18 tabel legacy tetap identik, termasuk 19.813 WO, 43.610 histori, dan 444.040 record legacy total. Apache pengembangan membaca tabel MySQL. Chrome lulus untuk homepage, pencarian kosong dan pemeriksaan desktop/mobile. [Bukti cutover dan pemulihan](KNOWLEDGE_BASE_MYSQL.md#cutover-pengembangan-20-november-2026-wib).
- Regresi editor, login/logout, CSRF, konflik revisi, draft/publish/withdraw, artikel publik, internal dan related articles lulus pada Apache, Chrome, dan MySQL disposable. Penyimpanan MySQL dikonfirmasi tanpa fallback JSON. Pengujian tulis tetap di fixture. PHP lint, PHPUnit 9 tes/35 assertions, TypeScript dan Vite lulus.

## Media Create, jadwal terisolasi dan Knowledge Base MySQL, 20 November 2026 WIB

- Form Create existing menyediakan pemilih gambar utama/tambahan dan unggahan katalog ketika penyimpanan privat tersedia. Referensi legacy ikut pada WO, histori awal dan post_images. Chrome menguji unggahan, pemilihan, Draft, Publish, Outgoing dan detail/histori. Fixture menguji penolakan lintas corporation, MIME, CSRF dan rollback metadata/file. Pengembangan belum memiliki root unggahan privat. Kontrol subscriber CI3 ada di blok komentar dan dependensi newsletter tidak tersedia.
- Worker Scheduled menyalin status dan assignment, memakai menit jatuh tempo CI3, menyimpan waktu eksekusi pada occurrence dan waktu template pada histori, lalu memajukan tanggal sejumlah hari. Fixture menguji dua worker serentak, recurrence, draft/publish, nilai historis kosong dan rollback. `intervale` dan konfigurasi cron tetap menunggu penerimaan pemilik. [Audit sumber dan DDL fixture](LEGACY_DATABASE_ALIGNMENT.md#scheduled-media-create-dan-subkategori).
- Adapter KB memakai satu tabel terpisah dan lulus regresi impor, parity konten, visibilitas, CSRF, admin, revisi, publikasi dan URL publik. Backup penuh 18 tabel lulus restore. Auto-review menahan DDL, impor dan perubahan backend pengembangan sampai ada persetujuan eksplisit atas operasi itu. Lokasi sumber JSON yang dikonfigurasi kosong. [Cutover dan pemulihan](KNOWLEDGE_BASE_MYSQL.md).
- Reports tetap menggunakan scope, filter, total, PDF dan XLSX existing. Audit dump tidak menemukan nilai faktual subkategori per WO. Prosedur UAT per sakelar ada di [Kesiapan operasi](OPERATIONAL_READINESS.md#uat-terbatas-per-sakelar).

## Aktivasi Create biasa dan sidebar Workspace, 20 November 2026 WIB

- Backup lengkap `eworeb` dipulihkan di MySQL loopback terpisah. Dua ALTER yang disetujui kemudian lulus pada TCP 3310 dengan lock yang memblokir penulis. Semua fingerprint kolom lama cocok. Jumlah 19.812 WO, 43.609 histori dan 444.035 record lintas 18 tabel sama sebelum dan sesudah. Seluruh waktu publikasi historis tetap `NULL`. [Bukti backup, restore dan DDL](LEGACY_DATABASE_ALIGNMENT.md#keputusan-pemilik-untuk-create-work-order-biasa).
- `.env` pengembangan mengaktifkan hanya `ewo.workOrderCreateWrites`. Smoke baca-saja pada TCP 3310 membuktikan guard skema, izin akun aktif dan preview serta Workspace yang mengiklankan Create. Draft dan Publish diuji melalui Chrome pada fixture. Uji tanpa `add_post`, CSRF, scope, rollback dan referensi gambar lulus di MySQL terisolasi. Pengujian tidak menambah WO sintetis ke TCP 3310.
- Description mengikuti validasi server CI3 yang opsional dengan maksimum 5.000 karakter. Atribut `required` pada HTML CI3 lama bertentangan dengan kontrak server. Form fixture menyimpan Draft dengan description kosong dan API juga menerima field yang absen. Destination role tetap `author`, `author1`, `author2`. Kategori New, visibilitas, status, assignment awal dan scope mempertahankan aturan biasa.
- Sidebar tunggal menempatkan menu WO dan laporan langsung, sedangkan Master Data dan Configuration dapat dilipat dan terbuka ketika route anak aktif. My Profile dan Change Password berpindah ke menu akun. Chrome memverifikasi keyboard, deep link, compact, tablet dan mobile. Route ringkasan lama tetap tersedia. Scheduled, notifikasi subscriber dan pemilih lampiran Create tetap tertahan.
- PHP lint, PHPUnit 9 tes dan 35 assertion, TypeScript, Vite, serta tiga suite MySQL dan Chrome lulus. Suite `--wo-create`, `--wo-replies` dan standar mencakup Dashboard tunggal, sesi dan CSRF, penolakan lintas corporation, rollback, lampiran, Reports PDF A3/XLSX dengan fallback subkategori, dan Knowledge Center.

## Create Work Order biasa, 20 November 2026 WIB

- Audit membaca tiga dump CI3, seluruh ref Git lokal, serta metadata TCP 3310. CI3 menulis `published_at` saat Create biasa maupun terjadwal. Definisi dan nilai historis tidak ditemukan. Kontrak kompatibilitas `DATETIME NULL DEFAULT NULL` pada `posts` dan `posts_logs` diuji di MySQL sementara. [Paket keputusan](LEGACY_DATABASE_ALIGNMENT.md#keputusan-pemilik-untuk-create-work-order-biasa) memuat dua ALTER untuk review, dampak, backup dan rollback.
- API Create biasa memeriksa CSRF, `add_post`, akun aktif, scope, role tujuan, relasi lokasi, dan status awal. Publikasi yang diizinkan tampil di Outgoing dengan histori awal. Draft tampil di Drafts. MySQL terisolasi membuktikan rollback saat INSERT histori maupun relasi gambar ditolak, dan menolak definisi kolom yang berbeda dari kontrak. React memakai form existing dan hanya menawarkan submit ketika gate serta skema memenuhi syarat.
- Pada tahap ini, TCP 3310 masih tanpa DDL atau DML Create dan `ewo.workOrderCreateWrites` masih false. Scheduled tetap menunggu `intervale`, histori dan verifikasi cron.

## Kompatibilitas akhir dan kesiapan operasi, 19 November 2026 WIB

- Reports In/Out kembali tersedia bagi pemegang add_post. Query tetap menggunakan filter, total dan scope WO biasa. Kolom subkategori bersifat opsional berdasarkan metadata schema. UI, PDF A3 dan XLSX berbagi isi sel. Probe read-only di TCP 3310 membandingkan 24 skenario pada enam role dengan daftar WO yang sama.
- Pada tahap ini Create Work Order tampil sebagai entri nonaktif, dengan POST belum tersedia. Scheduled tetap terpisah.
- Arsip CI3 lokal dipasang baca-saja melalui .env. Resolver memverifikasi 1.924 gambar utama dan 302 gambar tambahan. Profil mendukung perubahan email sendiri pada kondisi email verification nonaktif, serta avatar JPEG dari penyimpanan privat. Visual Settings mengelola logo raster dan favicon PNG untuk login dan Workspace. Penulisan hanya diuji pada fixture, dengan sakelar di TCP 3310 tetap false.
- Peta endpoint, cakupan izin, risiko, keputusan pemilik dan checklist penerimaan tersedia di [Kesiapan operasi](OPERATIONAL_READINESS.md). Knowledge Center tetap memakai JSON.
- PHP lint, PHPUnit, TypeScript, Vite dan dua suite MySQL terisolasi lulus. Chrome menguji PDF A3, isi XLSX, mobile, favicon dan refresh. Apache guest menguji aset, branding dan penolakan lampiran. Salinan TCP 3310 tetap memiliki 19.812 posts dan 43.609 posts_logs dengan checksum awal dan akhir identik.
- Perubahan file mencakup route, controller dan view Workspace/login, WorkOrderList untuk Reports, UserManagement dan AccountController untuk email, BrandingController dan AccountImages untuk media, komponen React, templat env dan fixture regresi. .env lokal hanya menambah root arsip baca-saja.

## Lampiran, Draft, Master Data dan pengelolaan akun, 19 November 2026 WIB

- Detail Incoming menyediakan unggahan privat gambar tambahan dan audio sesuai jenis WO serta akses balasan. File disimpan sebelum commit relasi, memakai nama acak dan namespace terpisah dari arsip. Validasi mencakup ekstensi, MIME aktual, ukuran, batas dimensi gambar serta flag unduh audio. Kegagalan relasi audio dibuktikan membatalkan metadata dan meninggalkan jumlah file yang sama. Kegagalan setelah file dipindahkan dibersihkan setelah rollback yang dikonfirmasi. File dengan hasil commit yang tidak diketahui dipertahankan untuk rekonsiliasi.
- Daftar Draft menerbitkan WO biasa dengan konfirmasi di React. Endpoint memakai scope daftar, izin add_post, CSRF dan pemeriksaan status pada UPDATE. Fixture memeriksa perubahan satu field, pengiriman ulang, corporation dan department yang berbeda. Jalur jadwal tetap diblokir.
- Enam Master Data aktif memakai tabel legacy. Validasi parent dan corporation dilakukan sebelum penulisan. Status WO kategori 1–6 dan relasi record existing dilindungi. Admin mengelola penciptaan akun, nama, email dan status; staff yang diberi izin users tetap memiliki daftar scoped. Pengubahan izin terbatas pada tujuh flag yang ditulis CI3 dan mengecualikan role admin. Profil adalah baca-saja. Perubahan kata sandi memverifikasi hash lama dan transaksi perubahan pengaturan aktif mencakup rollback pada kegagalan timezone.
- PHP 8.5.12 lint pada 15 file PHP dan PHPUnit 7 tes/32 assertion lulus. TypeScript, Vite, manifest, aset CSS/JS pada Apache serta akses guest lulus. Vite masih memberi peringatan ukuran chunk ekspor PDF/font. Chrome terisolasi memeriksa dashboard, form balasan, penyimpanan corporation, profil, penyimpanan settings pada viewport 390 px, direct URL/refresh dengan role tanpa izin, logout dan Knowledge Center. Suite MySQL standar menguji filter dan paginasi serta ekspor XLSX dan PDF A3 pada fixture yang memiliki kolom tambahan.
- Uji baca pada TCP 3310 cocok dengan CI3 pada 12 skenario dashboard dan 24 skenario daftar. Probe juga membuktikan 55 detail scoped dan 10 penolakan lintas corporation. SHA-256 atas 19.812 posts dan 43.609 posts_logs sama sebelum dan sesudah. Tidak ada perubahan pada schema, akun, WO, konfigurasi .env, atau arsip CI3. Tiga sakelar tulis tetap default false pada salinan terpelihara.
- Create, Scheduled, dan Reports masih menunggu data historis dan definisi schema yang terbukti. Gallery dan ordered list membutuhkan tabel relasi legacy. Edit metadata audio/video, email atau avatar profil, delegasi mutasi user non-admin, penggantian role akun, dan bagian lanjutan Settings belum diterapkan. Knowledge Center tetap memakai JSON. [Matriks parity](FEATURE_PARITY_MATRIX.md) dan [audit schema](LEGACY_DATABASE_ALIGNMENT.md) mencatat batas setiap fitur.

### File tahap ini

| Bagian | File |
| --- | --- |
| Route dan halaman | `app/Config/Routes.php`, `app/Controllers/WorkspaceController.php`, `app/Views/admin/workspace.php` |
| Work Order | `app/Controllers/WorkOrderAttachmentController.php`, `app/Controllers/WorkOrderController.php`, `app/Models/WorkOrderAttachments.php`, `app/Models/WorkOrderList.php` |
| Master, akun, role dan pengaturan | `app/Controllers/AdminDirectoryController.php`, `app/Controllers/AccountController.php`, `app/Controllers/ApplicationSettingsController.php`, `app/Models/AdminDirectory.php`, `app/Models/AuthRepository.php`, `app/Models/MasterData.php`, `app/Models/UserManagement.php` |
| React | `frontend/src/main.tsx`, `WorkOrderDetail.tsx`, `WorkOrders.tsx`, `AdminDirectory.tsx`, `AccountPassword.tsx`, `WorkspaceSettings.tsx` |
| Fixture dan browser | `tests/integration/auth-http.cjs`, `browser.cjs`, `mysql-fixture.php`, `wo-reply-assertions.cjs`, `master-and-account-assertions.cjs` |
| Dokumen dan templat | `README.md`, `env`, `docs/MIGRATION_PROGRESS.md`, `docs/FEATURE_PARITY_MATRIX.md`, `docs/LEGACY_DATABASE_ALIGNMENT.md` |

## Workspace tunggal dan balasan WO, 19 November 2026 WIB

- AuthController mengarahkan login sukses langsung ke Workspace. AdminController menjaga izin dan scope sebelum mengalihkan /admin. Navigasi React sudah tanpa tautan dashboard klasik. View klasik tetap disimpan. Chrome menguji guest, login, redirect, refresh, deep link, back/forward, logout, serta desktop, tablet dan mobile pada fixture. Apache aktif melayani bundel dan Knowledge Center pada jalur guest. Login ke salinan existing tidak diulang agar aktivitasnya tidak ditulis.
- WorkOrderReply dan route GET/POST reply menangani balasan tipe post. Form React memuat status dan assignee yang diizinkan. Update kategori, assignee, editor dan waktu edit bersifat atomik dengan INSERT history. ewo.workOrderWrites tetap nonaktif pada eworeb port 3310.
- Mode --wo-replies menyalin definisi tabel yang diperlukan dari eworeb melalui koneksi read-only. Seluruh penulisan menggunakan instance MySQL loopback sementara terpisah yang disetujui. HTTP lulus untuk CSRF, input ditolak, scope, assignment, Closed, Reopen, stale revision, histori, dan rollback saat hak INSERT riwayat dicabut di fixture. Chrome membuktikan formulir menyimpan dan memuat riwayat. Uji mencakup revisi usang dan row lock pada implementasi. Skenario dua request paralel tidak dijalankan.
- Endpoint attachments menggunakan scope WO dan root media privat. Tes fixture mengunduh gambar yang valid dan menolak traversal serta akses lintas corporation. Arsip asli belum terpasang; sampel lokal 250 referensi tidak menemukan file. Upload dan perubahan referensi belum tersedia.
- MySQL eworeb lulus 12 skenario Dashboard dan 24 skenario daftar terhadap CI3, 55 pemeriksaan detail dan 10 penolakan lintas corporation. Row count dan SHA-256 posts serta posts_logs sama antara awal dan akhir. Tidak ada DDL, mutasi WO, atau perubahan .env pada port 3310.
- PHP lint, PHPUnit 7 tes/32 assertion, TypeScript dan Vite lulus. Vite memperingatkan ukuran chunk PDF/font yang dimuat sesuai kebutuhan. Knowledge Center JSON dan editor lulus di fixture. Status Create, Scheduled, Reports dan pekerjaan sisa dicatat dalam matriks. [Matriks parity](FEATURE_PARITY_MATRIX.md) · [Kontrak CI3](LEGACY_DATABASE_ALIGNMENT.md)

## Login MySQL asli dan menu baca-saja, 19 November 2026 WIB

- Akun legacy login manual melalui Apache ke database eworeb TCP 3310. Kata sandi, role dan hash tidak diubah. Chrome menguji API dashboard 200, penolakan POST tanpa CSRF 403, sesi saat navigasi, logout dan penolakan API 401 setelah logout. Terdapat 14 aktivitas baru untuk satu akun selama pengujian dan last_seen cocok dengan semuanya.
- WorkOrderList serta WorkOrderController menambah detail dan riwayat paginasi dalam scope daftar. AdminDirectory menambah daftar corporation dan role/permission. WorkspaceController membatasi menu Scheduled dan Reports berdasarkan ketersediaan kolom aktual. React menampilkan tautan detail dari daftar dan memakai route SPA.
- Probe read-only pada MySQL membandingkan model CI3 dengan CI4 pada 12 skenario dashboard dan 24 skenario daftar. Probe kedua memeriksa 55 detail dan menolak 10 akses lintas corporation. Chrome desktop merender Dashboard, daftar Incoming/Outgoing, detail Outgoing dan refresh, Corporations dan Roles. Dashboard lolos pada viewport 390 px. Jumlah baris dan checksum posts serta posts_logs identik sebelum dan sesudah regresi.
- PHP lint, TypeScript dan Vite lulus. Vite memberi peringatan ukuran chunk PDF/font yang dimuat saat ekspor. Tidak ada DDL, data dummy, perubahan WO atau pengujian mutasi pada salinan pengembangan. [Status setiap menu](FEATURE_PARITY_MATRIX.md#status-penerimaan-mysql-legacy-19-november-2026-wib)

### Analisis Scheduled dan Reports

- Scheduled memiliki route dan menu aktif pada CI3. Post_admin_model::add_post dan add_post_logs menulis published_at, serta intervale untuk jadwal. scheduled_posts_update, query pemilihan berdasarkan menit, INSERT riwayat jadwal, dan Cron_controller::check_scheduled_posts membaca atau memperbarui kedua nilai. Tampilan scheduled_posts.php memakai interval, tanggal berikutnya dan sisa hari. Kedua nama field absen dari tiga dump serta tabel posts dan posts_logs di salinan legacy. Tanggal dan interval asli perlu ditelusuri pada versi schema lain atau backup yang sesuai. Penambahan kolom dan backfill memerlukan bukti tipe, nullability, nilai asli, serta persetujuan.
- Reports In/Out memakai kategori utama dan membaca subcategory_id untuk label kedua. Partial formulir legacy juga mereferensikan field itu, sedangkan filter_posts membaca parameter subcategory tanpa menambah predikat SQL. final_db_deploy.sql menyimpan definisi subcategory_id INT(11) DEFAULT NULL pada posts dan posts_logs. Definisi itu absen dari ewohotel_tntrm.sql dan salinan aktif. user.sql hanya berisi users. Konfirmasi sumber nilai subkategori yang historis serta hasil panel legacy sebelum memilih perubahan. Hindari menjalankan dump dengan DROP TABLE atau menebak isi subkategori. Query dan hasil ekspor dipertahankan.
- Jalur tulis ditahan. Create tidak dapat mengikuti CI3 selama published_at absen. Update, assignment, completion, pengelolaan master dan akun memerlukan uji pada database terisolasi yang disetujui. Knowledge Center tetap memakai JSON. Katalog MySQL memerlukan pilihan penyimpanan terpisah dari tabel Work Order.

### File tahap ini

Suite fixture MySQL standar juga lulus untuk direktori, draft, Scheduled, PDF/XLSX dan navigasi. Temuan ekspor itu terbatas pada fixture yang memiliki kolom laporan.

- `app/Config/Routes.php`
- `app/Controllers/AdminController.php`
- `app/Controllers/AuthController.php`
- `app/Controllers/WorkOrderAttachmentController.php`
- `app/Controllers/WorkOrderReplyController.php`
- `app/Controllers/WorkspaceController.php`
- `app/Models/WorkOrderAttachments.php`
- `app/Models/WorkOrderList.php`
- `app/Services/WorkOrderReply.php`
- `app/Views/admin/workspace.php`
- `docs/FEATURE_PARITY_MATRIX.md`
- `docs/LEGACY_DATABASE_ALIGNMENT.md`
- `docs/MIGRATION_PROGRESS.md`
- `env`
- `frontend/src/components.tsx`
- `frontend/src/main.tsx`
- `frontend/src/WorkOrderDetail.tsx`
- `frontend/src/WorkOrderReply.tsx`
- `README.md`
- `tests/integration/auth-http.cjs`
- `tests/integration/browser.cjs`
- `tests/integration/mysql-fixture.php`
- `tests/integration/mysql-workspace.cjs`
- `tests/integration/wo-reply-assertions.cjs`
- `tests/integration/work-orders-assertions.cjs`

## Penyalinan legacy ke eworeb, 19 November 2026 WIB

- Setelah izin eksplisit, 18 tabel dan 443.998 record asli disalin dari ewohotel_tentrem ke eworeb pada TCP 3310. Backup, metadata dan checksum seluruh tabel diverifikasi.
- Diagnosis Spark serta pembacaan Dashboard, Incoming, Outgoing, Drafts dan direktori berhasil pada database pengembangan. Scheduled dan Reports masih membutuhkan kolom yang absen dari schema sumber. Login akun asli belum diuji. [Bukti per tabel](LEGACY_DATABASE_ALIGNMENT.md#hasil-salin-19-november-2026-wib)

## Koreksi legacy-first, sebelum penyalinan

- Konfigurasi CI3 menunjuk ewo di port default 3306. Akses metadata dengan konfigurasi itu ditolak 1045. ewohotel_tentrem di port 3310 menjadi kandidat pembanding, belum menjadi sumber otoritatif.
- Probe read-only memakai data existing. Dashboard, Incoming, Outgoing, Drafts, dan direktori CI4 dapat membaca kandidat. Scheduled terhalang intervale/published_at, Reports terhalang subcategory_id. Lihat [audit dan prosedur salin](LEGACY_DATABASE_ALIGNMENT.md).
- Opsi pembuatan tabel fixture KB dan adapter MySQL eksperimental dicabut. Knowledge Center tetap JSON. Hasil fixture terdahulu di bawah adalah historis. Tidak ada penulisan database, penggantian koneksi atau login pada schema existing.

## Integrasi database dan kesiapan shared hosting, 19 November 2026 WIB

- Grup EWO dan session key efektif tersedia pada CLI. Apache melewati guard konfigurasi saat form kosong dengan CSRF valid diserahkan. Browser memakai URL dan aset same-origin di localhost/eworeb/.
- spark ewo:diagnose --inventory berhasil terhubung ke MySQL port 3310 dan keluar dengan status 1 karena skema eworeb belum kompatibel. users membutuhkan dept_id dan posts membutuhkan corp_id, dept_id serta is_scheduled. ewohotel_tentrem lulus metadata tujuh tabel wajib. Pemeriksaan dilakukan baca-saja. Login dan parity MySQL masih menunggu database uji terisolasi yang disetujui.
- npm ci, lint dan build lulus. Manifest dan semua CSS/JS hasil build tersedia di public/workspace-assets. Dependensi Composer produksi lulus pemeriksaan platform PHP 8.5.12. PHPUnit lulus 7 tes dan 32 assertion.
- Apache sementara dengan PHP 8.5.12, document root terpisah, subfolder dan SQLite sintetis lulus regresi HTTP dan Chrome untuk login, CSRF, sesi, izin, logout, dashboard klasik, Workspace, Knowledge Center, aset, API, direct link dan refresh. Enam skenario dashboard cocok dengan CI3. Aktivitas login tercatat pada fixture dan snapshot WO tetap identik. URL yang mengarah ke file privat ditolak.
- Panduan [shared hosting](SHARED_HOSTING_DEPLOYMENT.md) mencakup paket aset, Composer, PHP, pemisahan file publik, konfigurasi dan uji setelah upload. [Rancangan KB MySQL](KNOWLEDGE_BASE_MYSQL.md) dan proposal SQL tersedia untuk review. Runtime KB tetap menggunakan JSON. Tidak ada perubahan runtime PHP atau skema database pada tahap ini.

## Knowledge Center dan diagnosis database, 19 November 2026 WIB

- Root / sekarang membuka Knowledge Center publik. /kb dan /kb/{slug} memakai entry React/Vite tersendiri. Admin klasik, workspace, dashboard API dan autentikasi existing dipertahankan. Workspace admin mendapat editor artikel dengan draft, review, publikasi manual, penarikan, dan daftar kategori.
- File baru utama. app/Commands/EwoDiagnose.php, app/Config/KnowledgeBase.php, KnowledgeController dan KnowledgeAdminController, KnowledgeBaseRepository, LocalDebugToolbar, view knowledge/index.php, entry knowledge.tsx, KnowledgeAdmin.tsx, CSS dan pengujian Knowledge Base.
- Perubahan integrasi. Routes, filter AdminAccess, pengaturan toolbar lokal, allowedHostnames localhost, respons 503 login, konfigurasi build multi-entry, workspace navigation, .gitignore, README dan dokumen migrasi. Tidak ada penggantian autentikasi atau perubahan query WO.
- spark ewo:diagnose --inventory mengonfirmasi endpoint MySQL default loopback TCP 3310 dapat diakses baca-saja. Grup EWO efektif belum memiliki database dan username, walaupun key 64-hex sudah tersedia. Skema eworeb kekurangan dept_id pada users dan corp_id, dept_id, is_scheduled pada posts. ewohotel_tentrem cocok pada metadata tujuh tabel wajib. Persetujuan sebagai salinan uji masih diperlukan, sehingga database aplikasi tidak dialihkan.
- Apache 8.5.12 menunjukkan 503 missing configuration pada POST CSRF-valid dengan kredensial kosong dan menyajikan portal publik tanpa sesi. Login dan logout sukses pada SQLite fixture dengan grup EWO lengkap. Pemeriksaan metadata MySQL bukan pengujian login MySQL.
- Regresi fixture mencakup validasi query dan slug, 404 draft/internal/unknown, izin guest dan role non-admin, CSRF, draft/edit/publish/withdraw, konflik revisi, proyeksi related links, teks aman, pencarian, kategori, detail, back/forward, refresh, dan 1280/820/390 px. Enam skenario dashboard tetap cocok dengan CI3 dan isi 134 baris WO fixture tetap identik. Chrome lulus pengujian portal serta editor.
- Validasi akhir lulus pada PHP 8.5.12 untuk lint 14 file dan PHPUnit 7 tes dengan 32 assertion. TypeScript dan build Vite lulus. Chrome guest pada Apache aktif merender katalog kosong dan aset dari origin localhost. Route detail yang tidak ditemukan menghasilkan 404 tanpa toolbar.
- Penulisan fixture terisolasi di folder sementara. Katalog live tetap kosong. Pemeriksaan Git hanya status, diff --check, dan diff --stat. Tidak ada perubahan skema, import SQL, deployment, atau perubahan state Git.

Panduan operasi lokal ada di [Knowledge Base](KNOWLEDGE_BASE.md) dan [Database Diagnostics](DATABASE_DIAGNOSTICS.md).

## Login database dan workspace React, 19 November 2026 WIB

### Pemeriksaan database dan status login

- Apache lokal sudah menyajikan `/eworeb/admin/login`. GET `/eworeb/workspace/incoming` sebagai guest diarahkan ke login. GET API tanpa sesi mengembalikan 401.
- `.env` sebelumnya hanya berisi grup `database.default`. Auth memakai `database.ewo` dan `ewo.sessionKey`. Saya menambahkan kunci 256-bit acak pada `.env` yang diabaikan Git. Tidak ada kredensial yang dipindahkan dari source atau template.
- Dump yang ditemukan pada source read-only adalah `ewohotel_tntrm.sql`, `final_db_deploy.sql`, dan `user.sql`. Metadata SQL diverifikasi tanpa mengeksekusi dump. `ewohotel_tntrm.sql` memiliki `departments`, sedangkan dump `final_db_deploy.sql` dan `user.sql` memiliki cakupan lebih sempit.
- Probe metadata SELECT-only di MySQL loopback port 3310 menemukan database `eworeb` dengan admin aktif. Tabel `roles_permissions` dan `users_logs` sesuai kolom yang diperiksa. `users.dept_id`, `posts.corp_id`, `posts.dept_id`, `posts.is_scheduled` dan tabel `departments` tidak tersedia. Keberadaan data membuktikan skema telah diisi, tanpa memastikan dump mana yang dipakai.
- Skema `ewohotel_tentrem` lolos pemeriksaan kolom dasar dashboard. Statusnya sebagai salinan uji atau data operasional belum dikonfirmasi. Grup `database.ewo` lokal belum diaktifkan. Login Apache dengan database existing masih tertahan sampai pemilik menetapkan salinan yang kompatibel dan terisolasi. Import, perubahan skema, dan test login pada MySQL existing tidak dilakukan.

### Perubahan dan status modul

| Bagian | File dan hasil |
| --- | --- |
| Workspace | `frontend/package.json`, lockfile, `vite.config.ts`, `tsconfig.json`, `.gitignore`, dan `src/`. React menyediakan shell responsif, navigasi, filter, KPI, tabel, drawer, dan konfirmasi logout. |
| CI4 | `app/Config/Routes.php`, `app/Controllers/WorkspaceController.php`, `app/Controllers/AdminController.php`, `app/Filters/AdminAccess.php`, dan `app/Views/admin/{dashboard,workspace}.php`. Dashboard klasik dan SPA memakai query yang sama. |
| Aset dan config | Build lokal di `public/workspace-assets` diabaikan lewat `.gitignore`. Secret sesi hanya ada pada `.env` lokal. |
| Pengujian | `tests/integration/{auth-http.cjs,auth-http-fixture.php,browser.cjs,subfolder-router.php}` menguji halaman klasik, API, workspace dan mount subfolder dengan database sintetis. |
| Lanjutan | Transaksi WO, Reports, ekspor, dan master data belum diimplementasikan di React. |

### Build dan pengujian ulang

```powershell
npm.cmd --prefix frontend ci
npm.cmd --prefix frontend run lint
npm.cmd --prefix frontend run build
$env:EWO_LEGACY_SOURCE = 'C:/xampp8212/htdocs/ewo'
$env:EWO_TEST_BROWSER = 'C:/Program Files/Google/Chrome/Application/chrome.exe'
node tests/integration/auth-http.cjs C:/xampp8512/php/php.exe
$env:EWO_TEST_SUBFOLDER = '1'
node tests/integration/auth-http.cjs C:/xampp8512/php/php.exe
```

Jalankan dari root target. `build:watch` dapat membangun ulang aset di origin CI4 yang sama. Mode subfolder menjalankan router fixture loopback dengan prefiks `/eworeb`. Database fixture SQLite, pengguna sintetis, dan konfigurasi proses anak terpisah dari MySQL aplikasi. Untuk operasional, gunakan `http://localhost/eworeb/workspace` setelah grup koneksi yang berhak disiapkan. Periksa nilai grup `database.ewo.*` menggunakan contoh `env`.

### Hasil verifikasi

- PHP 8.5.12 lint pada file PHP yang berubah dan PHPUnit 10.5.67. Unit/session existing lulus 7 tes dengan 32 assertion.
- Frontend TypeScript lint dan Vite build lulus. Bundler mencatat peringatan direktif use client dari React Router dan TanStack Query saat bundling SPA.
- Spark di root dan server fixture dengan mount /eworeb lulus integrasi HTTP dan Chrome. Login/logout, CSRF, guest, role tanpa permission, corporation/department scope, navigasi tanpa reload, back/forward, URL langsung, refresh, tampilan 1280/820/390 px, filter, pencarian, sort dan drawer diuji tanpa error JS atau aset.
- Enam skenario dashboard dari CI3 cocok dengan API CI4. Fingerprint isi 134 baris WO fixture tetap sama, dan GET API tidak menambah users_logs.
- Git status, diff --check, dan diff --stat dipakai dalam mode baca-saja. Tidak ada perubahan branch, commit, push, SQL import, migration, atau deployment.

## Runtime dan dashboard, 19 November 2026 WIB

Target lokal kini `C:/xampp8512/htdocs/eworeb`. Catatan 18 November di bawah adalah riwayat checkout sebelumnya. Login admin sekarang membuka dashboard yang menghitung data setelah pemeriksaan `admin_panel`. Apache utama belum diberi Alias. Semua verifikasi berjalan pada fixture terisolasi.

| Pemeriksaan | Hasil |
| --- | --- |
| Startup | Spark menggunakan public/index.php dan vendor CI4 framework 4.7.6. Checkout sebelumnya menunjuk vendor dev-develop yang tidak ada sehingga bootstrap CLI dan web gagal. Manifest diselaraskan dan Composer validate --strict lulus. Composer PHAR lama mencatat deprecation saat dijalankan dengan PHP 8.5. |
| Apache | Konfigurasi XAMPP menunjuk DocumentRoot htdocs dan mengaktifkan Indexes. Karena root project tidak berisi index.php, Apache sebelumnya menampilkan direktori. Root project sekarang menolak akses langsung. Contoh Alias lulus parser konfigurasi instalasi dan smoke Apache loopback. Login dan CSS dapat diakses melalui Alias. app, vendor, writable, env, .env dan .git mengembalikan 403/404. |
| PHP 8.5.12 | Lint lulus pada tujuh file PHP yang berubah dan public/index.php. Unit/session existing lulus 7 tes dan 32 assertion. Suite migration/seed bawaan starter tidak dijalankan. |
| HTTP dan browser | Spark menggunakan .env sintetis serta SQLite dengan kolom legacy. Login, guest, akun diblokir, password salah, CSRF, permission dan logout lulus. Role tanpa admin_panel mendapat 403 agar redirect ke root yang juga dilindungi tidak berulang. Scope role ID tidak dikenal juga ditolak. Chrome mengirim form login, merender dashboard, memuat CSS/JS lokal dan membuka sidebar pada lebar 390 px. |
| Parity dashboard | Enam skenario CI3 dibandingkan terhadap dashboard HTTP CI4 dengan baris yang sama. Skenario mencakup admin, filter corporation/role/department, employee, Engineering, admin corporation, dan GM. Aktivitas login tercatat dan jumlah dan SHA-256 isi 134 baris WO fixture tetap sama. Tidak ada akses database produksi. |

### File tahap ini

- Runtime dan kemasan. `.htaccess`, `app/Config/Paths.php`, `composer.json`, `phpunit.dist.xml`, dan `docs/eworeb-apache.conf.example`. Versi dan hash paket di composer.lock tetap sama.
- Environment. `.env` lokal diabaikan Git. Perubahan hanya pada baseURL. `env` yang dilacak sebelumnya berisi isian koneksi database dan password. Isian aktif dihapus dari template dan diganti contoh kosong untuk `database.ewo.*` dan `ewo.sessionKey`. Riwayat Git tidak diubah. Pemilik perlu menilai apakah nilai yang pernah tercatat masih berlaku.
- Dashboard. `app/Controllers/AdminController.php`, `app/Filters/AdminAccess.php`, `app/Models/DashboardRepository.php`, `app/Views/admin/dashboard.php`, serta `public/assets/admin/css/dashboard.css` dan aset jQuery, Bootstrap, AdminLTE dari baseline CI3.
- Pengujian. `tests/integration/auth-http.cjs`, `auth-http-fixture.php`, `browser.cjs` dan `legacy-dashboard.php`. Fixture mengaktifkan ekstensi dari direktori PHP 8.5.12 dan memakai sesi/DB sintetis. Oracle CI3 memuat model read-only, tanpa bootstrap CI3.
- Dokumentasi. `docs/MIGRATION_PLAN.md` dan laporan ini.

### Konfigurasi Apache yang perlu diterapkan pemilik

Instalasi memuat modul PHP Apache dari folder XAMPP 8.5.12 dan memiliki modul Alias. Pada `C:/xampp8512/php/php.ini`, `extension=intl` masih dikomentari. Aktifkan intl untuk PHP Apache, lalu masukkan Include berikut pada konfigurasi server atau VirtualHost localhost yang berlaku.

```apache
Include "C:/xampp8512/htdocs/eworeb/docs/eworeb-apache.conf.example"
```

Validasi dengan `C:/xampp8512/apache/bin/httpd.exe -t -f C:/xampp8512/apache/conf/httpd.conf` sebelum memuat ulang Apache milik pemilik. `.env` lokal menggunakan `app.baseURL = "http://localhost/eworeb/"`. Setelah Alias aktif, root proyek tetap diblokir dan login berada di `/eworeb/admin/login`. Alias tidak diterapkan otomatis. Proses Apache utama tidak dinyalakan atau di-restart dalam tugas ini.

POST login memakai config `database.ewo.*` dan `ewo.sessionKey` yang dibaca dari .env. Grup tersebut perlu diisi pemilik dengan koneksi yang disetujui dan kunci sesi privat. Login belum bisa digunakan dengan database nyata selama grup belum dikonfigurasi. `database.default.*` dipertahankan di .env lokal dan tidak digunakan oleh auth/dashboard. Untuk Spark gunakan URL loopback beserta portnya sebagai baseURL.

### Perbedaan dan batas penerimaan

- Dashboard admin menampilkan Incoming dan Outgoing agregat dari query CI3. Tiga kartu departemen admin CI3 memakai satu hitungan Outgoing yang sama. Atribusi department admin belum ditetapkan. Rekap role 7/8 mempertahankan IT Waiting yang menghitung role Engineering 3.
- Statistik role tak dikenal atau scope akun yang tidak lengkap ditahan. CI3 memiliki jalur fallthrough yang dapat menghitung secara global. Role tanpa permission tidak melihat dashboard dan menerima 403.
- Fixture SQLite tidak membuktikan parity MySQL/MariaDB atau konfigurasi produksi. Work Order CRUD, Reports, ekspor, dan master data di luar perubahan ini. Halaman tersebut tidak diberi tautan sidebar yang belum berfungsi.
- Pemeriksaan Git hanya `git status --short --branch`, `git diff --check`, dan `git diff --stat`, dengan safe.directory sementara lewat opsi -c. Source CI3 dan konfigurasi Apache/PHP di luar target tetap dibaca saja. Tidak ada commit, push, deployment, atau perubahan database produksi.

### Ulangi pengujian

```powershell
$env:EWO_LEGACY_SOURCE = 'C:/xampp8212/htdocs/ewo'
$env:EWO_TEST_BROWSER = 'C:/Program Files/Google/Chrome/Application/chrome.exe'
node tests/integration/auth-http.cjs C:/xampp8512/php/php.exe
```

Runner membuka Spark loopback dengan ekstensi fixture, URL port aktual, dan SQLite sintetis. Server yang dipakai fixture ditutup setelah pengujian.

## Riwayat 18 November 2026 WIB

Status 18 November 2026 WIB. Tahap A terverifikasi lokal dan tahap B berjalan sebagian. Target memakai CodeIgniter 4.7.6. Dashboard CI4 yang terlindungi masih mengembalikan HTTP 501 setelah login yang valid. Status ini menandai bahwa dashboard belum tersedia.

| Tahap atau modul | Status | Perbandingan dan hasil uji |
| --- | --- | --- |
| A. Fondasi | Lulus lokal | Spark memuat route native. Composer strict validate lulus dan paket yang terkunci tetap 4.7.6. PHP 8.4.21 dan 8.5.12 menjalankan autentikasi dengan extensi distribusinya masing-masing. Tidak memakai system CI3. |
| B. Login admin, sesi, logout | Implementasi awal, lulus fixture | Form memakai email dan password serta jalur POST legacy. Verifikasi bcrypt dan phpass P/H, password salah, akun diblokir, user hilang, CSRF, sesi dan logout lulus. Smoke Chrome dengan PHP 8.5 merender heading login dan form. |
| B. Permission dan aktivitas | Implementasi awal, lulus fixture | Role ditemukan lewat role key, admin override hanya jika baris role ada, role biasa perlu flag aktif. HTTP menguji admin_panel, unit menguji add_post. last_seen, IP dan users_logs diperiksa pada SQLite sintetis. |
| B. User management, akun, remember-me | Belum dimigrasikan | Role/dept/corp management, user CRUD, reset, verifikasi, profil dan integrasi sosial memerlukan parity tambahan. Jalur pergantian role dan password lama meminta permission users, sementara daftar non-admin scoped per corporation. Perubahan akun lintas corporation dan role yang boleh diberikan perlu kebijakan yang disetujui. Cookie remember-me lama tidak menjadi dasar autentikasi target. |
| C. Incoming, Outgoing, status, assignment, history | Belum dimigrasikan | Menunggu penutupan tahap B serta fixture parity transaksi WO. |
| D. Dashboard dan master data | Belum dimigrasikan | Route dashboard hanya entry point yang dilindungi. Perhitungan, tabel dan CRUD belum dibangun. |
| E. Reports In/Out, filter, pagination dan ekspor | Belum dimigrasikan | Guard add_post diuji sebagai predikat. Route, menu, query dan PDF/Excel belum tersedia di target. Jasper perlu kebijakan akses dan pembandingan template. |
| F. UI/UX | Baseline login saja | CSS dan font baseline admin login dipakai. Logo dari penyimpanan legacy, terjemahan dinamis, sidebar, dan responsivitas seluruh modul belum diverifikasi. |

## File yang ditambahkan atau diubah

- Audit dan laporan ada di docs/MIGRATION_PLAN.md dan docs/MIGRATION_PROGRESS.md.
- Bootstrap dan dependensi ada di composer.json, composer.lock, app/Config/Paths.php, dan phpunit.dist.xml.
- app/Config/App.php, Routes.php, Filters.php, Security.php, Session.php, Database.php, dan Services.php mengatur route, CSRF, sesi dan adapter autentikasi. Pada .env target, hanya skema URL dari baseURL lokal yang dilengkapi.
- app/Controllers/AuthController.php dan AdminController.php, app/Filters/AdminAccess.php, app/Models/AuthRepository.php, app/Libraries/AdminIdentity.php dan LegacyPassword.php memegang implementasi awal auth.
- app/Views/admin/login.php, app/Views/admin/pending.php, app/Language/en/Auth.php, serta CSS/ikon yang dipakai login di public/assets.
- tests/unit/AdminIdentityTest.php, tests/integration/auth-http.cjs dan tests/integration/auth-http-fixture.php menjalankan fixture sintetis.

## Hasil pengujian

- PHP 8.4.21 dan PHP 8.5.12, masing-masing lulus lint 19 file PHP serta 4 unit test dengan 28 assertion. Suite terarah tidak menjalankan test migration bawaan starter.
- Kedua runtime juga lulus HTTP pada listener lokal terisolasi. Kasus mencakup guest, CSRF, login, kesalahan password, akun diblokir, izin dashboard, catatan aktivitas, dan logout. CI3 Auth_model dan Bcrypt asli dibandingkan dengan baris user/role SQLite sintetis yang identik. Wrapper CI3 hanya memuat model dan library dengan adapter fixture, tanpa bootstrap aplikasi.
- PHP 8.5.12 berasal dari arsip Windows NTS resmi yang diperiksa SHA256. Browser Chrome pada runtime ini merender heading login dan CSRF form. [Distribusi Windows PHP](https://www.php.net/downloads.php?os=windows)
- Belum diuji pada MySQL/MariaDB, dataset deployment, storage media asli, provider email/sosial, Jasper atau keseluruhan WO. Pemeriksaan SQLite belum membuktikan perilaku collation/MySQL.

## Menjalankan kembali pengujian

Jalankan dari eworeb dengan PHP 8.4 atau 8.5 dan extension intl, mbstring, dan sqlite3. Untuk distribusi PHP portabel di Windows, pakai extension directory distribusi tersebut.

```powershell
php vendor/phpunit/phpunit/phpunit --no-coverage --no-logging --do-not-cache-result tests/unit/AdminIdentityTest.php
node tests/integration/auth-http.cjs C:/path/to/php.exe
```

Pengujian HTTP memakai file SQLite sintetis di temp, loopback dan cookie sesi fixture yang berbeda. EWO_TEST_BROWSER dapat berisi path Chrome untuk smoke render. Runtime aplikasi hanya memakai grup database.ewo dan meminta ewo.sessionKey terpisah. Grup itu masih kosong, sehingga POST login biasa merespons 503 sampai instance yang disetujui dikonfigurasi. Pengujian memberikan konfigurasi fixture hanya kepada proses anak.

## Selisih dan keputusan yang masih terbuka

- Target memakai nama cookie sesi eworeb_session dan meregenerasi ID saat login agar sesi dua aplikasi tidak tercampur. Login gagal dan logout kembali ke admin/login. CI3 memakai referrer. Validasi awal menegakkan batas panjang, sedangkan parity transformasi xss_clean dan kasus input nonstandar belum dibuktikan. Periksa sebelum menerima kompatibilitas penuh.
- Redirect tanpa admin_panel mengikuti arah root legacy, yang bisa berulang karena root juga dilindungi. Perlu tujuan penolakan akses yang disetujui.
- CI4 FileHandler pada PHP 8.5 Windows gagal untuk path sesi yang diberikan melalui path temp dengan prefix extended-length. Path writable/session bawaan target lulus. Runner mengisolasi cookie fixture dan memakai path bawaan tersebut.
- Sebelum menutup tahap B, putuskan kebijakan remember-me serta batas pergantian role/password, akses lintas corporation, dan penanganan redirect legacy. Sebelum tahap E, putuskan akses dan kompatibilitas Jasper serta maksud route reportsbyfinishdate.
- Sumber CI3 tetap dibaca saja. Tidak ada dump operasional yang dijalankan, migration, seeder, koneksi produksi, commit, push, atau deployment.

## Regresi MySQL dan daftar Work Order

- spark ewo:diagnose --inventory di port 3310 dapat membaca metadata dan tetap keluar 1. Skema eworeb belum memiliki users.dept_id serta posts.corp_id, posts.dept_id dan posts.is_scheduled. Pemeriksaan kolom, indeks dan constraint dilakukan baca-saja pada dua skema existing.
- MySQL Community Server 8.0.45 pada proses loopback terpisah dan data directory sementara menjalankan fixture sintetis. Login, password salah, banned user, sesi, CSRF, permission, logout, aktivitas, dan enam skenario dashboard terhadap CI3 lulus. Pengujian mengunci perubahan WO di luar scope dengan membandingkan hash 134 baris fixture.
- Daftar Incoming dan Outgoing menampilkan baris WO dari CI4 API di React. Data dibatasi oleh role, corporation dan department seperti query daftar CI3. Drafts dan Scheduled memiliki daftar tersendiri. Aksi publish, cron dan mutasi WO belum tersedia. Lihat [matriks parity](FEATURE_PARITY_MATRIX.md) untuk status menu dan perbedaan.
- Daftar Categories, Subcategories, Locations, Sublocations, Departments, Users, dan Administrators sudah membaca MySQL fixture dengan izin server-side. HTTP menguji hierarki, scope corporation, search dan allowlist respons.
- Reports In/Out mempertahankan add_post dan memakai cakupan yang sama dengan daftar WO. Unduhan halaman aktif diverifikasi pada Chrome untuk tabel XLSX dan PDF A3 landscape. Image menampilkan ketersediaan tanpa menerbitkan path file. Jasper lama dipisahkan karena didapati konfigurasi internal dan kontrol akses lama yang tidak memadai.
- Knowledge Base memiliki adapter MySQL yang memakai koneksi kb terpisah, transaksi, penyaringan published/public dan revisi optimistis. Backend lokal tetap JSON hingga cutover disetujui. Katalog default belum memiliki articles.json saat diperiksa.
- Dengan izin khusus fixture sementara, dua CREATE TABLE KB dan pengujian API serta Chrome lulus pada MySQL 8.0.45 terisolasi. Uji mencakup duplikasi slug, konflik revisi, status publikasi, pencarian dan relasi privat. Pemeriksaan langsung mengonfirmasi status dan urutan relasi tersimpan. Mode MySQL tidak menulis articles.json di fixture. .env lokal dan server existing tetap memakai konfigurasi sebelumnya.
- Pemeriksaan akhir lulus pada PHP 8.5.12 untuk 12 file PHP dan PHPUnit 7 tes dengan 32 assertion. npm ci, TypeScript dan Vite lulus. Chrome lulus pada MySQL 8.0.45 sintetis untuk login/logout, daftar WO, Drafts, Scheduled, navigasi direktori, isi worksheet XLSX dan PDF A3, serta Knowledge Center dengan backend JSON fixture. Vite memperingatkan ukuran chunk PDF dan font yang dimuat saat ekspor.
- Apache aktif lulus pada GET portal KB, aset hasil build same-origin, API guest 401 dan redirect workspace ke login. Penerimaan autentikasi dan modul terhadap database existing maupun hosting tujuan masih terbuka. Git status dan diff hanya dibaca. git diff --check lulus.
