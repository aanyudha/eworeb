# Knowledge Base MySQL

Adapter MySQL memakai satu tabel tersendiri, **kb_articles**. [DDL](KB_MYSQL_PROPOSAL.sql) menggunakan InnoDB. ID unik, slug unik, status dan visibilitas berada di kolom tersendiri. JSON menyimpan konten artikel, waktu UTC sebagaimana direkam oleh editor dan array terurut untuk keyword, gejala, penyebab, langkah dan slug terkait. Desain ini menjaga identitas dan urutan tanpa tabel relasi tambahan. Empat kategori, pencarian dan URL publik tetap sama.

## Hasil verifikasi

Regresi penerimaan lokal mengulang Chrome dan HTTP dengan backend MySQL fixture untuk pencarian, detail, editor, draft, publikasi, penarikan, visibilitas, revision conflict dan CSRF. Apache pengembangan melayani portal dengan backend MySQL. Inventaris dan fingerprint setelah regresi mengonfirmasi `kb_articles` tetap berisi nol artikel di pengembangan. [Baseline penerimaan](LEGACY_DATABASE_ALIGNMENT.md#baseline-penerimaan-pengembangan).

MySQL disposable lulus migrasi tiga artikel berisi Unicode, draft publik, publikasi internal, dan publikasi publik. ID, slug, waktu/revisi, urutan konten dan relasi, visibilitas, serta fingerprint cocok. Pengulangan impor dengan snapshot sama lulus dan konflik ditolak. API HTTP dan Chrome lulus untuk admin, CSRF, publikasi, penarikan, pencarian, filter dan URL publik dengan adapter MySQL aktif di fixture.

## Cutover pengembangan, 20 November 2026 WIB

- Target terverifikasi `eworeb` di MySQL TCP 3310. Backup baru 45.433.422 byte dipulihkan di MySQL terisolasi TCP 63603. Definisi, jumlah dan SHA-256 seluruh 18 tabel cocok. Backup, manifest restore, catatan sumber dan bukti parity disimpan di `%LOCALAPPDATA%/EwoRebornBackups/eworeb-3310-kb-cutover-20261120-134433`.
- Konfigurasi efektif memakai `writable/knowledgebase/articles.json`. File absen di lokasi efektif, dan pencarian checkout terkait, Git serta backup yang tersedia tidak menemukan katalog alternatif. Repository JSON efektif dan API Apache masing-masing melaporkan nol artikel. Manifest sumber mencatat kondisi tersebut. Importer memeriksa parity dan hasil impor nol baris. Fingerprint katalog `4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945`.
- `kb_articles` adalah satu-satunya tabel yang ditambahkan. Setelah skema dan parity lulus, `.env` lokal memakai `knowledgebase.backend = mysql`, dan `.mysql-cutover` menahan penulisan JSON lama. Definisi serta fingerprint seluruh 18 tabel tetap identik, mencakup 444.040 record legacy, 19.813 WO, dan 43.610 histori. Sakelar EWO tetap pada nilai semula.
- Apache pengembangan terbukti membaca `kb_articles` melalui penghitung kueri MySQL. Chrome pada desktop dan mobile memeriksa homepage, kategori, pencarian kosong dan halaman login. URL artikel yang tidak ada memberi 404 dan akses admin guest memberi 401.
- Apache terisolasi dengan MySQL disposable dan Chrome lulus login administrator, create/edit, Draft, Publish/Withdraw, visibilitas internal, related articles, konflik revisi, CSRF, logout, detail positif/direct URL, dan layout desktop/tablet/mobile. Baris artikel tersimpan di tabel MySQL fixture, file JSON fixture tetap absen, dan fingerprint WO fixture identik. Katalog pengembangan tetap kosong. PHP lint, PHPUnit (9 tes, 35 assertions), TypeScript, dan Vite lulus.

Adapter menyeleksi published/public sebelum artikel masuk pencarian dan resolusi tautan. Editor memakai validasi, admin_panel, peran administrator, CSRF dan konflik revisi yang sudah ada. Penulisan MySQL memakai transaksi dan advisory lock per katalog. Adapter JSON memakai flock dan penggantian file atomik. Tidak ada dual-write.

## Prosedur cutover dan pemulihan

1. Buat backup penuh database dengan akses privat dan verifikasi restore pada server MySQL terpisah. Catat count dan fingerprint tabel legacy.
2. Tahan penulisan JSON dengan lock artikel. Simpan file asli dan SHA-256 di lokasi privat. Jika file tidak ada, catat bukti ketiadaan dan validasi bahwa katalog efektif kosong.
3. Buat hanya kb_articles pada target yang diverifikasi. Impor dari snapshot dengan aturan repository yang sama. Bandingkan seluruh artikel dan daftar publik sesuai urutan. Target yang sudah berisi data hanya diterima jika fingerprint identik. Periksa fingerprint seluruh tabel legacy sebelum dan sesudah.
4. Tahan penulisan lama dengan file .mysql-cutover di direktori JSON, lalu pilih knowledgebase.backend = mysql pada .env lokal. Verifikasi API publik, preview dan hak editor. Simpan backup beserta manifest. Jika adapter gagal, API menampilkan galat agar katalog tidak terlihat kosong akibat fallback.
5. Untuk rollback, tahan penulisan admin pada kedua adapter. Jika MySQL telah menerima perubahan, ekspor semua artikel dengan status, visibilitas, revisi dan urutan aktif dan bandingkan fingerprint sebelum memulihkan file JSON. Jika katalog masih identik dengan snapshot cutover, gunakan backup asli atau status file tidak ada. Ubah pengaturan lokal ke json dan lepaskan .mysql-cutover hanya setelah sumber JSON parity. Simpan tabel dan backup MySQL untuk rekonsiliasi.

[Audit tabel legacy](LEGACY_DATABASE_ALIGNMENT.md)
