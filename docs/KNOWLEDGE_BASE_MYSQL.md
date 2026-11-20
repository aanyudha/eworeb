# Knowledge Base MySQL

Adapter MySQL memakai satu tabel tersendiri, **kb_articles**. [DDL](KB_MYSQL_PROPOSAL.sql) menggunakan InnoDB. ID unik, slug unik, status dan visibilitas berada di kolom tersendiri. JSON menyimpan konten artikel, waktu UTC sebagaimana direkam oleh editor dan array terurut untuk keyword, gejala, penyebab, langkah dan slug terkait. Desain ini menjaga identitas dan urutan tanpa tabel relasi tambahan. Empat kategori, pencarian dan URL publik tetap sama.

## Hasil verifikasi

MySQL disposable lulus migrasi tiga artikel berisi Unicode, draft publik, publikasi internal, dan publikasi publik. ID, slug, waktu/revisi, urutan konten dan relasi, visibilitas, serta fingerprint cocok. Pengulangan impor dengan snapshot sama lulus dan konflik ditolak. API HTTP dan Chrome lulus untuk admin, CSRF, publikasi, penarikan, pencarian, filter dan URL publik dengan adapter MySQL aktif di fixture.

Backup penuh eworeb TCP 3310 berukuran 45.433.371 byte dipulihkan pada MySQL terisolasi TCP 61547. Seluruh definisi, jumlah dan SHA-256 dari 18 tabel cocok. Pemeriksaan konfigurasi JSON pengembangan mendapati direktori dan articles.json absen. Pertahankan bukti ketiadaan sebagai manifest sumber saat cutover. Auto-review menolak eksekusi CREATE, impor dan pemilihan adapter di eworeb karena memerlukan persetujuan eksplisit atas aksi tersebut. Backend pengembangan tetap JSON hingga operasi itu disetujui dan parity akhir lulus.

Adapter menyeleksi published/public sebelum artikel masuk pencarian dan resolusi tautan. Editor memakai validasi, admin_panel, peran administrator, CSRF dan konflik revisi yang sudah ada. Penulisan MySQL memakai transaksi dan advisory lock per katalog. Adapter JSON memakai flock dan penggantian file atomik. Tidak ada dual-write.

## Prosedur cutover dan pemulihan

1. Buat backup penuh database dengan akses privat dan verifikasi restore pada server MySQL terpisah. Catat count dan fingerprint tabel legacy.
2. Tahan penulisan JSON dengan lock artikel. Simpan file asli dan SHA-256 di lokasi privat. Jika file tidak ada, catat bukti ketiadaan dan validasi bahwa katalog efektif kosong.
3. Buat hanya kb_articles pada target yang diverifikasi. Impor dari snapshot dengan aturan repository yang sama. Bandingkan seluruh artikel dan daftar publik sesuai urutan. Target yang sudah berisi data hanya diterima jika fingerprint identik. Periksa fingerprint seluruh tabel legacy sebelum dan sesudah.
4. Tahan penulisan lama dengan file .mysql-cutover di direktori JSON, lalu pilih knowledgebase.backend = mysql pada .env lokal. Verifikasi API publik, preview dan hak editor. Simpan backup beserta manifest. Jika adapter gagal, API menampilkan galat agar katalog tidak terlihat kosong akibat fallback.
5. Untuk rollback, tahan penulisan admin pada kedua adapter. Jika MySQL telah menerima perubahan, ekspor semua artikel dengan status, visibilitas, revisi dan urutan aktif dan bandingkan fingerprint sebelum memulihkan file JSON. Jika katalog masih identik dengan snapshot cutover, gunakan backup asli atau status file tidak ada. Ubah pengaturan lokal ke json dan lepaskan .mysql-cutover hanya setelah sumber JSON parity. Simpan tabel dan backup MySQL untuk rekonsiliasi.

[Audit tabel legacy](LEGACY_DATABASE_ALIGNMENT.md)
