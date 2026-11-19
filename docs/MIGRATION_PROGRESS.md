# Progres migrasi EWO

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

