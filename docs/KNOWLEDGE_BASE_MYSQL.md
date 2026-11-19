# Penyimpanan Knowledge Center

Knowledge Center memakai katalog JSON di writable/knowledgebase. UI admin, izin publikasi, visibilitas publik dan API yang ada dipertahankan.

Pada metadata ewo di port 3310, eworeb, ewohotel_tentrem dan tiga dump yang diperiksa, tidak ditemukan tabel artikel Knowledge Base. Posts dan posts_logs menyimpan Work Order dan riwayat. Images dan post_images menyimpan referensi media. Page_model ada di sumber, sementara tabel pages tidak terlihat dan route pengelolaan pages lokal dikomentari. Temuan ini tidak menutup kemungkinan bahwa sumber CI3 otoritatif memiliki modul lain. Koneksi metadata ke ewo pada TCP 3306 masih ditolak.

Pengujian adapter MySQL pada fixture sebelumnya bersifat historis. Opsi runner yang membuat tabel KB dan aktivasi adapter itu dicabut setelah instruksi legacy-first. Proposal SQL disimpan sebagai catatan, dengan pembuatan tabel dan cutover dihentikan. Jika akses ke sumber otoritatif tersedia, verifikasi semantik, izin dan hubungan tabel sebelum menilai penggunaan ulang.

[Laporan database legacy](LEGACY_DATABASE_ALIGNMENT.md)
