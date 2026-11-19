# EWO database diagnostics

Lihat [audit legacy-first](LEGACY_DATABASE_ALIGNMENT.md) untuk perbandingan metadata aktual dan prosedur penyalinan. Bagian fixture di bawah merupakan catatan teknis historis, dengan penerimaan sekarang memakai salinan database legacy yang disetujui.

Run from the project root with PHP 8.5 and CI_ENVIRONMENT set to development.

```powershell
C:\xampp8512\php\php.exe spark ewo:diagnose
C:\xampp8512\php\php.exe spark ewo:diagnose --inventory
```

The development CLI command checks the effective CI4 connection group and session key format without printing their values. MySQL probes are restricted to loopback and query column metadata in a read-only transaction. SQLite fixture files are opened read-only. The inventory option separately inspects known schemas via the local default group. Authentication continues to use only database.ewo. Exit success requires a configured key and compatible, accessible EWO group. [CI4 Spark commands](https://codeigniter4.github.io/userguide/cli/cli_commands.html)

| Evidence on 19 November 2026 WIB | Result |
| --- | --- |
| CLI | Root .env readable. The effective database.ewo fields are populated, the configured schema is eworeb, and the key matches the expected 64-hex format. The inventory default group is also populated. |
| Apache | The login form and assets are served under /eworeb/. A CSRF-valid empty form advances past the configuration guard to field validation, confirming required settings are present in the web runtime. |
| MySQL TCP 3310 | Both the configured group and the inventory group connected. The configured schema eworeb lacks users.dept_id, posts.corp_id, posts.dept_id and posts.is_scheduled. The command exits 1 for incomplete schema compatibility. |
| ewohotel_tentrem | All seven table and column checks passed. The 29 required columns shared with eworeb match in type, nullability, and collation. Suitability for write-capable tests is pending owner confirmation. |

Set the dedicated group in the ignored local .env when a compatible, isolated database is approved. A missing group gives the existing configuration 503. A database failure during login now gives a separate generic 503. Fixture tests verify CLI substitution, authentication and dashboard access. The section below records isolated MySQL results. Login and dashboard parity on port 3310 remain unverified. Browser URLs under localhost/eworeb/ resolved to the localhost origin with the configured alternate hostname.

## MySQL 8.0 fixture terisolasi

Port 3310 menjalankan MySQL Community Server 8.0.45. Audit baca-saja membandingkan signature lengkap untuk kolom, indeks, constraint, dan key usage pada tabel yang diperiksa. Selisih kolom tampak pada categories, departments, locations, posts, posts_logs, roles_permissions, users dan visual_settings. Tabel departments hanya terlihat pada schema referensi. Pemeriksaan juga mencakup engine, collation, indeks, constraint dan key usage. Ini belum membuktikan bahwa schema referensi aman untuk penulisan. CI3 menggunakan hubungan logis antara ID pengguna, role, corporation, department, kategori, lokasi, dan WO. Pemeriksaan kolom minimum tidak mencakup integritas seluruh relasi tersebut.

Runner membuat data directory baru dan port loopback tinggi. Ia memakai tipe kolom dari audit untuk subset autentikasi, dashboard, daftar WO, laporan dan direktori. Generator sintetis mengisi fixture autentikasi dan 134 Work Order, lalu menambahkan taxonomy dan struktur organisasi sintetis, tanpa menyalin record dari server 3310. Struktur fixture memproyeksikan kolom yang digunakan pengujian dan tidak meniru semua constraint legacy. Akun aplikasi fixture hanya dapat membaca tabel, memperbarui kolom aktivitas pengguna melalui aplikasi, serta menulis users_logs.

Dari project root, setelah build frontend:

~~~powershell
$env:EWO_TEST_SUBFOLDER = '1'
$env:EWO_LEGACY_SOURCE = 'C:\xampp8212\htdocs\ewo'
$env:EWO_TEST_BROWSER = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
node tests/integration/mysql-workspace.cjs 'C:\xampp8512\php\php.exe' 'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqld.exe'
~~~

Fixture php memeriksa @@datadir terhadap direktori sementaranya sebelum membuat schema ewo_fixture. Runner menghentikan proses MySQL yang dibuatnya. Gunakan PHP 8.5.12 dengan extension DLL mysqli, sqlite3, intl dan mbstring tersedia. Set EWO_LEGACY_SOURCE untuk membandingkan dashboard dengan CI3 dan EWO_TEST_BROWSER untuk Chrome. Mode tambahan untuk KB telah dicabut. Gunakan salinan database legacy yang disetujui untuk penerimaan operasional.

Login dan parity dashboard lulus pada MySQL 8.0.45 sintetis. Login dengan database existing di port 3310 belum diuji karena keberhasilannya akan menulis aktivitas autentikasi.
