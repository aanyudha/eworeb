# EWO Reborn di shared hosting

EWO membutuhkan hosting PHP dan MySQL/MariaDB yang kompatibel, akses konfigurasi document root atau direktori privat, dan Apache rewrite untuk URL tanpa index.php. Gunakan PHP 8.5 seperti runtime yang diuji, dengan ctype, intl, mbstring, MySQLi/mysqlnd, JSON dan dukungan sesi. Node.js digunakan saat build lokal. Hosting menjalankan PHP dan file statis, sehingga proses Node.js dan VPS tidak diperlukan untuk aplikasi ini. [Persyaratan CI4](https://codeigniter4.github.io/userguide/intro/requirements.html)

## 1. Siapkan artefak secara lokal

Jalankan dari root checkout dengan Node.js LTS yang kompatibel dengan Vite 7.

```powershell
npm.cmd --prefix frontend ci
npm.cmd --prefix frontend run lint
npm.cmd --prefix frontend run build
```

Build menghasilkan public/workspace-assets/manifest.json dan assets/, termasuk entry src/main.tsx untuk workspace, src/knowledge.tsx untuk Knowledge Center, dan chunk bersama. CI4 menghasilkan URL entry dari baseURL dan manifest. Pertahankan seluruh direktori workspace-assets sebagai satu paket build, termasuk manifest dan semua CSS/JS; direktori ini diabaikan Git. Tidak perlu mengunggah node_modules, frontend source, Vite dev server, cache lokal, fixture, atau file sesi. [Vite backend integration](https://vite.dev/guide/backend-integration)

Dari salinan yang disiapkan untuk rilis dengan PHP dan ekstensi yang setara dengan server, jalankan:

```text
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
```

Jika shared hosting tidak menyediakan Composer melalui SSH atau panel, siapkan vendor/ dari composer.lock di salinan lokal yang sesuai, kemudian unggah vendor/ ke direktori privat. Gunakan versi dari lockfile dan periksa persyaratan platform server. [Panduan deployment CI4](https://codeigniter4.github.io/userguide/installation/deployment.html)

## 2. Atur document root

Pilihan utama memakai project privat dan document root langsung ke folder public/. Gunakan subdomain dengan document root ke /home/ACCOUNT/eworeb/public atau mount /eworeb/ jika penyedia menawarkan Alias. Jangan mengekspos app, vendor, writable, .env, file SQL, dan repo Git. Contoh Alias Windows hanya untuk XAMPP lokal.

Jika panel mengunci document root ke public_html, pisahkan file privat dan konten public/:

```text
/home/ACCOUNT/
  eworeb-private/
    .env
    app/
    vendor/
    writable/
    composer.json
    composer.lock
    spark
  public_html/
    eworeb/
      .htaccess
      index.php
      assets/
      workspace-assets/
      favicon.ico
      robots.txt
```

Salin isi public/ ke public_html/eworeb/ termasuk public/.htaccess. Edit hanya file index.php pada salinan upload agar memuat Paths dari private root. Untuk struktur di atas, ganti baris require Paths dengan:

```php
require dirname(__DIR__, 2) . '/eworeb-private/app/Config/Paths.php';
```

Jika isi public/ berada langsung di public_html/, sesuaikan menjadi dirname(__DIR__) . '/eworeb-private/app/Config/Paths.php'. File Paths di direktori privat tetap merujuk app/, vendor/, writable/, dan .env di sana. Pastikan open_basedir atau aturan hosting memperbolehkan PHP membaca direktori privat serta menulis ke writable/. Minta mod_rewrite dan izin AllowOverride yang diperlukan oleh public/.htaccess. Ikuti kebijakan provider mengenai Options FollowSymLinks/Indexes. Bila diperlukan oleh pemetaan provider, sesuaikan RewriteBase dengan /eworeb/ atau /. URL refresh langsung harus mencapai index.php. [CI4 dengan dua direktori](https://codeigniter4.github.io/userguide/installation/deployment.html#using-two-directories)

## 3. Konfigurasi environment, database dan sesi

Buat .env baru di private root. Pada hosting gunakan CI_ENVIRONMENT = production. Set app.baseURL ke URL HTTPS persis yang dipakai browser termasuk trailing slash, misalnya https://example.com/eworeb/ untuk subfolder atau https://help.example.com/ untuk root. Pilih satu origin agar URL aset, API, cookie, dan CSRF konsisten. Jika masuk melalui domain alternatif, gunakan pemetaan hostname yang disetujui dan verifikasi URL yang benar.

Isi database.ewo.hostname, database.ewo.port, database.ewo.database, database.ewo.username, database.ewo.password dan database.ewo.DBDriver = MySQLi dari pengaturan host. Port 3310 adalah konfigurasi lokal, jadi ikuti port yang disediakan hosting. Gunakan schema yang telah disetujui dan kompatibel. Jangan memakai database.default sebagai pengganti; lihat [diagnosis schema](DATABASE_DIAGNOSTICS.md). Untuk mengecek koneksi hosted, gunakan fasilitas panel atau salinan CLI development terisolasi. Jalur diagnostic ini sengaja dibatasi ke loopback.

Buat ewo.sessionKey terpisah untuk environment hosting mengikuti [README](../README.md). Simpan dan jaga kestabilannya. Aktifkan cookie.secure = true pada HTTPS dan gunakan cookie HttpOnly dan SameSite Lax yang sudah ditetapkan aplikasi. Izinkan PHP menulis pada direktori app writable/cache, writable/logs, writable/session, dan writable/knowledgebase bila dipakai. Jangan memakai izin terbuka global. Simpan Knowledge Base di luar document root. Bagian ini memakai penyimpanan JSON existing dan tidak memerlukan tabel KB. Salin artikel hanya dari katalog yang disetujui, sambil menjaga status dan visibility. [Panduan Knowledge Base](KNOWLEDGE_BASE.md)

## 4. Uji setelah upload

1. Buka /eworeb/, /eworeb/kb dan URL artikel. Pastikan aset CSS/JS beserta chunk bersama berstatus 200 dan origin sama. URL artikel yang tidak ada harus 404. Katalog kosong menampilkan empty state yang sah.
2. Verifikasi GET /eworeb/api/v1/kb/categories dan GET /eworeb/api/v1/kb/articles. Pastikan draft/internal tersembunyi. Pastikan /eworeb/.env, /eworeb/app/, /eworeb/vendor/, /eworeb/writable/ dan dump SQL tidak dapat dibaca dari browser.
3. Guest yang membuka /eworeb/workspace/incoming harus diarahkan ke login dan dashboard API harus 401. Pada database staging terisolasi, login, buka dashboard klasik serta workspace, refresh /eworeb/workspace/incoming, uji navigasi/back/forward, role tanpa akses, scope dan logout. Login yang sukses mencatat aktivitas, sehingga gunakan akun uji dan database yang berhak untuk pengujian.
4. Uji penyimpanan dan publikasi artikel hanya dengan katalog staging. Pastikan CSRF, cookie dan marker publikasi berfungsi. Gunakan console/network browser untuk memeriksa JS error, CORS, MIME dan 404 aset.

Dokumen ini adalah panduan packaging dan verifikasi lokal. Target hosting tertentu baru dapat dinyatakan siap setelah document root, dependensi PHP, database, akses tulis dan smoke test staging di sana lulus.
