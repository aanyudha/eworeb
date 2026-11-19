# Proposal MySQL dan migrasi Knowledge Base

[DDL proposal](KB_MYSQL_PROPOSAL.sql) ditujukan untuk MySQL 8.0.16+ dengan InnoDB. Kompatibilitas MariaDB, CHECK constraint, JSON, collation, dan izin view perlu diperiksa pada versi hosting yang dipilih. Proposal belum dijalankan. Runtime saat ini tetap memakai articles.json.

## Model dan batas akses

- Tempatkan tabel KB pada schema dan grup koneksi khusus yang disetujui. Pertahankan tabel EWO, data pengguna dan role existing. Tabel KB tidak memiliki foreign key ke EWO. Taksonomi memakai empat key kategori existing.
- ID heksadesimal, slug, timestamp UTC presisi mikrodetik, status, visibility, dan urutan langkah dipertahankan. Relasi disimpan dengan urutan dan slug agar referensi ke target yang belum ada tetap dapat diselesaikan sewaktu-waktu. Public related links hanya merujuk ke target yang saat itu juga berstatus published dan bervisibilitas public.
- Public projections wajib menerapkan kondisi status dan visibility sebelum search, hitung kategori, pagination, detil, dan related links. Gunakan query terparameterisasi dan allowlist kolom serialisasi API. View dapat mempersempit akses akun publik jika host mendukungnya. Jika view tidak tersedia, terapkan predikat yang sama pada semua query publik dan verifikasi lewat test isolasi.
- Sesi, peran admin, admin_panel, dan CSRF CI4 tetap mengontrol penulisan. Gunakan transaksi untuk artikel serta relasi. Perubahan memakai optimistic concurrency dengan updated_at dan respon konflik ketika revisi berbeda. Simpan draft secara default dan minta aksi terpisah untuk publish atau withdraw. Pertahankan validasi slug, teks, cardinality, dan limit search. Dilarang menghasilkan artikel dari WO, menautkan lampiran internal, atau menaikkan visibility saat migrasi.

## Rencana migrasi untuk persetujuan terpisah

1. Inventaris dan cadangkan articles.json serta batasi penulisan selama pengambilan snapshot final. Simpan salinan bersama checksum di luar document root. Catat jumlah artikel menurut kategori, status, dan visibility.
2. Di lingkungan terisolasi dan kosong, validasi setiap dokumen menggunakan aturan repository yang sama. Tolak ID/slug duplikat, status tidak valid, waktu rusak, dan data yang belum boleh diterbitkan. Pertahankan ID, status, visibility, timestamp dan urutan setiap list. Artikel bermasalah perlu ditinjau sebelum perpindahan.
3. Setelah persetujuan terpisah untuk menyiapkan schema, uji pemetaan JSON ke tabel KB dalam transaksi pada salinan staging. Bandingkan round trip setiap artikel dan urutan related links. Marker migrasi tidak boleh menjadi publikasi baru. Pada pengulangan impor, verifikasi kecocokan record atau berhenti bila konflik.
4. Uji parity pencarian title, summary, keywords, case folding, karakter aksen, slug, pagination, hitung kategori dan urutan. Uji bahwa draft/internal dan related links tersembunyi, serta CSRF/permission dan penyimpanan gagal menghasilkan status yang sesuai. Query SQL Unicode harus dibandingkan dengan pencarian mb_stripos saat ini. Urutkan berdasarkan updated_at DESC, id DESC, dan serialisasikan kembali timestamp sebagai ISO 8601 UTC dengan enam digit pecahan detik.
5. Pada cutover yang disetujui, hentikan penulisan pada backend lama, ulangi snapshot dan parity, kemudian aktifkan satu repository penulis. Simpan JSON sebagai cadangan baca-saja. Bila rollback setelah ada penulisan, tahan penulisan dan ekspor perubahan kembali dengan status/visibility asli sebelum beralih. Hentikan cutover bila hasil parity berbeda. Hindari dual-write.

Tugas ini hanya membuat rancangan. Tidak ada tabel KB baru, adapter MySQL aktif, atau perpindahan artikel.
