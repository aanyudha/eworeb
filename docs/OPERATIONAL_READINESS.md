# Kesiapan operasi EWO Reborn

## UAT terbatas per sakelar

Gunakan corpus sintetis dan instance MySQL terisolasi untuk setiap langkah tulis berikut. Untuk eksekusi pengembangan, pemilik menetapkan akun, corporation, record, jadwal, dan cakupan transaksi secara terpisah. Perubahan konfigurasi modul KB memakai backend tersendiri.

| Kemampuan | UAT terkontrol |
| --- | --- |
| `ewo.workOrderCreateWrites` | Satu akun terdaftar berizin dan satu akun tanpa izin. Pilih gambar utama/tambahan dari corporation yang benar, simpan Draft, lalu buat Publish tersendiri. Periksa histori, daftar, galat lintas corporation, dan file privat. Upload memerlukan root privat yang dikonfigurasi dengan GD dan fileinfo. |
| `ewo.workOrderWrites` | Uji atas WO fixture yang ditetapkan. Verifikasi status awal, assignment, perubahan histori yang diizinkan, reopen, publish Draft existing, rollback, dan lampiran. |
| `ewo.masterDataWrites` | Verifikasi satu kasus tiap editor pada data fixture, parent valid, penolakan lintas corporation, dan perlindungan kategori status WO. |
| `ewo.accountWrites` | Gunakan pengguna fixture. Uji akun sendiri, administrator, role, kata sandi, settings, avatar, dan branding menurut batas yang telah tercatat. |
| Scheduled | Fixture saja. Terima kebijakan atas jadwal historis kosong, menit terlewat, zona waktu dan cara pemanggilan cron terlebih dahulu. |

## Siap untuk penerimaan pengguna

- Login Apache dengan akun legacy sudah diverifikasi pada tahap sebelumnya. Workspace React adalah dashboard operasional. Alias /admin mengarah ke Workspace melalui filter sesi dan admin_panel.
- Dashboard, Incoming, Outgoing, Drafts, detail dan histori, direktori, serta profil sendiri tersedia untuk penerimaan baca-saja sesuai izin.
- Reports In dan Reports Out memuat data aktual dengan izin admin_panel dan add_post. Total serta paginasi mengikuti query arah dan scope yang sama. Scope non-admin berasal dari sesi. Pada skema tanpa posts.subcategory_id, kolom kategori memuat kategori utama dan label “Subcategory unavailable”. Layar, PDF A3 dan XLSX memakai array sel yang sama untuk halaman hasil saat ini. Jasper CI3 dan paritas laporan penuh berada di luar hasil ini.
- Root arsip CI3 dipasang sebagai sumber baca-saja melalui .env lokal. Resolver menemukan 1.924 dari 17.469 referensi gambar utama dan 302 dari 3.126 referensi gambar tambahan. Detail WO hanya mengunduh file yang terkait pada WO dalam scope. Lima referensi avatar dan kedua referensi branding memiliki file lokal dalam audit. MIME, root dan path tetap diperiksa sebelum penyajian. Lengkapi arsip di lokasi privat yang disetujui untuk memenuhi referensi sisanya.
- Knowledge Center dan editor memakai `kb_articles` di MySQL pengembangan TCP 3310 setelah parity, backup dan restore lulus. Katalog awal kosong. Pengujian penulisan admin memakai fixture dan smoke pengembangan bersifat baca-saja. [Status cutover dan rollback KB](KNOWLEDGE_BASE_MYSQL.md#cutover-pengembangan-20-november-2026-wib).

## Sakelar penulisan

Hanya `ewo.workOrderCreateWrites` aktif pada `.env` lokal pengembangan TCP 3310. Prefix endpoint di tabel adalah `/api/v1`. Semua POST melewati CSRF global dan filter sesi. Endpoint yang menyimpan data memeriksa akun aktif dan meminta izin sesuai operasi.

| Sakelar | POST yang dikendalikan | Cakupan, transaksi, histori dan kegagalan |
| --- | --- | --- |
| ewo.workOrderWrites | work-orders/incoming/{id}/reply, work-orders/incoming/{id}/attachments/{image atau audio}, work-orders/drafts/{id}/publish | add_post dan manage_all_posts untuk balasan dan lampiran. Scope Incoming, role tujuan dan corporation dilindungi. Balasan mengunci WO, memeriksa revisi dan memasukkan posts_logs dalam satu transaksi. Lampiran menulis metadata/referensi dan menyimpan file sebelum commit serta menangani rollback; arsip CI3 dipasang terpisah. Publikasi membutuhkan add_post dan memakai scope daftar Draft pada UPDATE atomik; mengikuti CI3 dengan menulis status saja. Fixture menolak lintas department dan corporation. |
| ewo.workOrderCreateWrites | work-orders/create | Default false dan terpisah dari sakelar WO yang ada. Memerlukan MySQL dengan tabel InnoDB dan `published_at DATETIME NULL DEFAULT NULL` pada kedua tabel. Izin `admin_panel` dan `add_post`, CSRF, akun aktif, serta aturan verifikasi email dan scope berlaku. |
| ewo.masterDataWrites | admin-directory/{categories,subcategories,locations,sublocations,departments,corporations}/new dan /{id}/edit | Izin menu yang sama dengan daftar. Corporation dan parent divalidasi, aksi corporations dibatasi administrator. Transaksi mencakup perubahan record, slug dan pewarisan warna bila berlaku. Kategori status inti dan relasi existing dilindungi. CI3 tidak menulis log WO untuk perubahan master. Fixture menguji keenam jalur, CSRF dan scope; gagalnya UPDATE turunan belum diuji dengan injeksi kegagalan. |
| ewo.accountWrites | admin-directory/{users,administrators}/new dan /{id}/edit, admin-directory/roles/{id}/edit, account/password, account/profile/email, account/profile/avatar, settings/application, settings/visual/{logo atau favicon} | Mutasi akun dan role oleh administrator, password dan profil pada akun sesi sendiri, settings dengan izin settings. Akun tetap memakai hash bcrypt kompatibel, perubahan email meminta password saat ini dan memerlukan email verification legacy nonaktif. Settings menyimpan timezone dan field teks dalam transaksi; logo, favicon dan avatar mempunyai transaksi file/referensi. Tidak ada INSERT users_logs untuk mutasi profil/settings; users_logs mencatat aktivitas autentikasi seperti sebelumnya. Deaktivasi mengikuti CI3 untuk login baru; sesi lama tidak dicabut otomatis. |

GET work-orders/incoming/{id}/reply juga mengikuti sakelar WO. GET daftar Draft dan attachments hanya mengiklankan aksi sesuai sakelar dan cakupan. GET editor /new dan /{id}/edit mengikuti sakelar Master Data atau akun. GET account/password mengikuti sakelar akun. GET account/profile, settings/application dan settings/visual tetap tersedia sesuai izin dan mengembalikan ketersediaan edit. GET media hanya membaca referensi tersimpan.

Pembuktian mutasi memakai instance MySQL loopback sementara terpisah dengan definisi tabel aktual dan record uji terkontrol. Uji kegagalan mencakup rollback WO saat INSERT history ditolak, rollback audio saat relasi ditolak, kegagalan update avatar tanpa file tambahan, dan rollback settings saat penulisan timezone ditolak. Git status dan diff dipakai untuk membaca working tree.

## Create WO dan keterbatasan kompatibilitas

Kedua kolom publikasi tersedia pada TCP 3310. [Catatan eksekusi skema](LEGACY_DATABASE_ALIGNMENT.md#keputusan-pemilik-untuk-create-work-order-biasa) memuat bukti CI3, dua ALTER, hasil verifikasi 19.812 WO dan 43.609 histori, backup serta rollback. Definisi asli dan tanggal publikasi historis belum terbukti. Scheduled memerlukan kontrak `intervale`, validasi zona waktu dan pengujian cron tersendiri.

Workspace menampilkan entri nonaktif dan pratinjau tanpa aksi simpan ketika gate tertutup. `GET api/v1/work-orders/create/preview` mengembalikan opsi yang scoped. `POST api/v1/work-orders/create` memvalidasi pilihan admin atau scope sesi non-admin, lokasi induk dan sublokasi, role tujuan `author`, `author1`, `author2`, dan status Draft atau Publish. Kategori New, WO biasa dan visibilitas berasal dari server. `posts`, satu `posts_logs` awal, serta relasi gambar yang berlaku ditulis atomik. API menerima referensi gambar katalog legacy. Opsi gambar milik pengguna lain memerlukan pengaturan file manager bersama serta corporation yang sama. Pemilih gambar pada form, upload baru dan pemberitahuan subscriber berada di luar aktivasi Create biasa. Work Order yang dipublikasikan dapat tampil di Outgoing sesuai filter role dan corporation/department yang sudah ada.

Suite `tests/integration/mysql-workspace.cjs <php.exe> <mysqld.exe> --wo-create` lulus dengan datadir sementara, definisi tabel dari `SHOW CREATE TABLE` baca-saja, data sintetis dan DDL usulan pada fixture. DDL mempertahankan setiap field lama dan memberi `NULL` pada kolom baru, termasuk pada histori sintetis yang bertanda scheduled. HTTP memeriksa penolakan guest dan akun tanpa `add_post`, CSRF, payload invalid dan scope palsu, Publish dan Draft, detail/histori serta relasi gambar. Pencabutan INSERT histori dan INSERT gambar masing-masing membuktikan rollback tanpa tambahan baris. Skema yang tidak cocok menutup akses tulis meskipun gate fixture aktif. Chrome memublikasikan WO dari form React, membaca konfirmasi dengan ID hasil commit, lalu membuka Outgoing dan histori awal.

Regresi 20 November menguji Draft dengan Description kosong dan Publish melalui form React. PHP lint, PHPUnit 9 tes dan 35 assertion, TypeScript dan Vite lulus. Smoke CI4 terhadap TCP 3310 berjalan dalam transaksi baca-saja dengan sesi uji CLI in-memory. Akun aktif yang berizin mendapat preview dan Workspace dengan Create aktif. Guest ditolak. Penolakan akun tanpa `add_post` dan aksi simpan autentik diuji dengan sesi HTTP pada fixture.

Edit umum audio/video belum terbukti setara. File_controller menyediakan upload, select, list, search dan delete untuk katalog audio/video, sementara jalur penyuntingan metadata in-place tidak ditemukan. Field video_embed_code dan video_path pada set_data CI3 dikomentari. Audio pada WO masih didukung melalui unggah dan relasi yang sudah diuji. Gallery dan ordered list tetap diblokir oleh skema tabel relasi yang hilang. Visual Settings aktif hanya logo dan favicon. Upaya mengedit warna/format lama tidak ditambahkan; field branding mentah SVG ditahan dan favicon baru dibatasi PNG sesuai input form legacy. Email Settings, kontak rich HTML, dan pengiriman email aktivasi masih memerlukan alur aman yang diverifikasi.

## Bukti regresi

- PHP 8.5.12 lint, PHPUnit 7 tes dan 32 assertion, TypeScript serta Vite lulus. Chunk PDF/font dimuat sesuai kebutuhan.
- MySQL TCP 3310, transaksi baca-saja. Dua arah laporan, dua kondisi filter dan enam role menghasilkan 24 skenario yang cocok untuk total dan urutan ID. Angka awal dan akhir tetap 19.812 posts dan 43.609 posts_logs dengan SHA-256 identik. Apache guest mengembalikan 200 untuk logo dan favicon, 401 untuk API lampiran dan 404 untuk akses langsung lokasi upload melalui subfolder.
- Chrome fixture menguji login, sesi, logout, redirect /admin, dashboard tunggal, mobile, direct refresh dan akses role terbatas. Chrome menguji ekspor XLSX berisi penanda subkategori serta PDF A3 pada skema aktual, dan unggah favicon yang tetap tampil setelah refresh. Suite standar menguji jalur alternatif untuk skema yang masih mempunyai subcategory_id. Sesi admin dengan CSRF asli ditolak pada endpoint mutasi ketika ketiga sakelar nonaktif.
- Fixture profil menguji password salah, email duplikat, cakupan akun, guard verifikasi, login dengan alamat baru, JPEG avatar, kegagalan tanpa file tambahan, serta pembacaan logo publik. Aset avatar hanya disajikan dari referensi akun sesi. File lama dibiarkan privat untuk pemulihan sesuai kebijakan penyimpanan.

## Checklist pemilik sebelum menyalakan penulisan

- [ ] Konfirmasi instance, schema, akun MySQL dengan GRANT minimum per sakelar, cadangan database dan media serta uji restore pada salinan terisolasi.
- [ ] Setujui operasi mana yang boleh dibuka. Buat role matrix UAT untuk admin, admin corporation, GM, petugas dan employee. Verifikasi akun aktif, lokasi, department dan assignee dalam cakupan yang benar.
- [ ] Siapkan direktori privat, writable oleh PHP sesuai kebutuhan. Mount arsip sebagai baca-saja. Pisahkan ewo.workOrderUploadRoot dan ewo.accountMediaRoot. Verifikasi fileinfo dan GD untuk validasi unggahan profil dan branding, izin filesystem, batas PHP dan larangan akses HTTP langsung. Sertakan media pada backup. Verifikasi bentuk dan keberhasilan restore dan rekonsiliasi reference jika hasil commit tidak diketahui.
- [ ] Jalankan suite fixture schema aktual dan uji ulang browser di instance penerimaan. Validasi kegagalan parsial, pengiriman ulang dan perubahan bersamaan secara terpisah bila menjadi kebutuhan operasional.
- [ ] Putuskan kebijakan histori administrasi. CI3 tidak memberi log mutasi admin khusus. Tentukan apakah status akun juga perlu mencabut sesi yang sudah aktif.
- [x] Untuk Create biasa di pengembangan, pemilik menyetujui DDL dan aktivasi. Backup/restore, kedua kolom, fingerprint, fixture Create dan smoke baca-saja lulus. Untuk pemulihan operasional, matikan `ewo.workOrderCreateWrites` sebelum tindakan lanjutan. Scheduled memerlukan investigasi `intervale`, histori asli dan cron.
- [ ] Putuskan kelengkapan arsip, layanan email dan aktivasi, atau tetap gunakan email verification nonaktif bila sesuai kebijakan pemilik. Tentukan penyimpanan KB semantik hanya jika JSON perlu diganti.
- [ ] Aktifkan sakelar satu per satu hanya setelah persetujuan pemilik. Ulangi smoke test, pemantauan galat dan pengecekan integritas sesuai cakupan penulisan yang diizinkan.

