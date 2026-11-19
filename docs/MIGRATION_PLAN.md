# Rencana migrasi EWO ke CodeIgniter 4

Audit lokal 18 November 2026 WIB, CI3 3.1.10 commit e4543a70. Snapshot SQL dibaca sebagai referensi DDL. Snapshot berbeda usia dan cakupan, sehingga parity skema live belum terbukti. DDL target akan mengikuti kontrak yang dipakai kode dan fixture yang disetujui.

## Arsitektur CI3 dan CI4

CI3 dimulai dari index.php dan rewrite Apache. Routes memuat slug admin, route bahasa dinamis dari database, dan fallback URI. View/JavaScript juga mengakses controller secara langsung. Core_Controller memuat konfigurasi, bahasa, timezone, aset, sesi dan aktivitas user. Admin_Core_Controller memerlukan sesi dan setiap handler memeriksa permission sesuai modul.

CI4 memakai public/ sebagai document root, namespace App, route dengan verb eksplisit, filter sesi dan permission, Query Builder, dan data view eksplisit. Jangan salin system/ CI3. Settings runtime dan timezone property diambil dari general_settings saat modul memerlukannya. Pertahankan perbedaan antara waktu PHP, waktu server database dan tanggal tampil.

Target awal memakai starter CI4 dan vendor framework v4.7.6. Manifest merujuk dev-develop dan Paths menunjuk paket vendor yang tidak terpasang, sehingga Spark gagal bootstrap. PHP 8.4.21 tersedia dengan extension directory di distribusi yang sama, PHP 8.5 perlu runtime tambahan. [Rilis resmi](https://github.com/codeigniter4/CodeIgniter4/releases/tag/v4.7.6) · [Persyaratan](https://codeigniter.com/user_guide/intro/requirements.html) · [Panduan upgrade](https://codeigniter.com/user_guide/installation/upgrade_4xx.html)

## Pemetaan modul

| Controller CI3 → CI4 | Model dan kontrak query | View |
| --- | --- | --- |

| Auth_controller → AuthController, Profile_controller → ProfileController | Auth/Profile. users, roles_permissions, users_logs. Email/password, status, session, profile, reset, konfirmasi dan provider sosial. | admin/login, auth, settings, modal/pesan |
| Post_controller → WorkOrderController | PostAdmin, Post, PostFile, PostItem. posts, posts_logs, post_images. Create/edit, assignment, status, visibilitas, jadwal, list/count, audit. | admin/post, form kategori/lokasi, lampiran, riwayat |
| Admin_controller → AdminController | PostAdmin, Auth, Settings, VisualSettings, Email, File, Language. Hitung status, users, role dan konfigurasi. | admin/index, admin/users, admin/navigation, settings |
| Category_controller, Location_controller, Department_controller, Corp_controller → controller native per entitas | Category, Location, Department, Corp. Parent/subtype, lang, department per corporation dan role tujuan. | admin/category, location, department, corp |
| Report_controller → ReportController | PostAdmin/Report. List dan count per scope dengan GET filter, default 15, kategori ASC, tanggal dibuat DESC. | admin/report, _filter_posts, pagination, sidebar, DataTables Buttons |
| Cron_controller → CronController | PostAdmin. Interval, publikasi, duplikasi WO, riwayat. | Endpoint scheduler |
| File_controller → FileController | File, Upload, PostFile, VisualSettings. File, validasi dan referensi media. | File manager, preview dan upload |
| Home_controller, Page_controller, Language_controller → controller native per modul | Post, Page, Category, Language, Settings, Video. Slug, lokalisasi, fallback dan konten. | Frontend, admin/page, admin/language, partials |


posts.user_id adalah pembuat, role_id adalah role tujuan, dan assign_to merujuk penerima assignment. dept_id dan corp_id membatasi scope pada cabang yang berlaku. Kategori dan lokasi memakai parent/subtype. Pisahkan kode status WO, flag publikasi, visibilitas, dan jadwal. Daftar Incoming memakai role tujuan pada cabang terkait, sementara Outgoing memakai department asal dan pengecualian role tujuan. Audit query list dan count sebagai pasangan.

## Matriks permission dan scope

| Kondisi CI3 | Perilaku yang dibawa |
| --- | --- |
| Belum login | Admin route mengalihkan ke login. |
| Role key admin dengan baris role | Pemeriksa permission memberi izin admin. Dashboard memakai admin_panel. |
| Role dengan add_post = 1 | Sidebar dan handler report aktif. Scope baris mengikuti model. |
| add_post = 0, role tidak dikenal | Report tersembunyi dan handler menolak akses. |
| ID role 1 | Cabang admin. Filter corporation, role target untuk Incoming, department untuk Outgoing. |
| ID role 7/8 | Admin corporation/GM menurut kode. Scope corporation sesi dan filter role/department sesuai arah. |
| ID role 3/5/6 | IT/engineering/housekeeping. Incoming memakai role tujuan dan corporation. Outgoing memakai department, corporation dan pengecualian role sendiri. |
| ID role 4 | Incoming memakai ID user, role, department, corporation. Outgoing memakai department dan corporation serta kondisi cabangnya. |
| ID role lain | Cabang query report lama tidak menambahkan scope khusus. Mapping dan perubahan kontrol akses perlu persetujuan. |


Flag master data dan administrasi berasal dari roles_permissions. Periksa matriks fixture sebelum menyimpulkan hak akses deployment. Inventaris di bawah menunjukkan guard yang ada di source.

## Dependensi, risiko dan persetujuan

- Bcrypt legacy mengenali phpass dan crypt(). Uji kedua keluarga yang didukung dan bandingkan aturan input xss_clean, panjang, flash serta redirect. Jangan rehash pada login. Remember-me lama memakai identitas dari cookie tanpa bukti per-user yang memadai pada jalur tersebut. Parity kebijakan remember-me dan perubahan kontrak sesi persisten perlu keputusan pemilik.
- Bootstrap CI3 memperbarui users.last_seen serta menulis users_logs untuk request dengan sesi aktif. Uji waktu dan IP dengan fixture. Jangan bootstrap CI3 terhadap database produksi meskipun request GET.
- DataTables Buttons, JSZip dan pdfmake mengekspor baris yang sedang dimuat. Jasper adalah jalur terpisah dengan JRXML, PHPJasperXML serta PDF/spreadsheet lama. Endpoint Jasper memakai input SQL dan kredensial dari source serta belum memiliki pemeriksaan permission khusus. Penanganan akses dan penggantian engine perlu persetujuan. Route reportsbyfinishdate menunjuk method reports2 yang nonaktif.
- Audit PHPMailer, SDK Facebook/Google/VK, reCAPTCHA, RSS, GD dan media sebelum menyalin library. Domain-parser lama memakai nama Mixed yang gagal lint pada PHP modern. Pakai data dan rahasia sintetis untuk uji integrasi.
- Inventaris controller dan route hasil kode tidak menetapkan bahwa semua jalur lama aktif atau disetujui. Hindari mengaktifkan auto-routing CI3 di target. Tentukan metode HTTP berdasarkan pemanggil yang sebenarnya.

## Urutan dan pengujian


1. A, buktikan bootstrap, Composer, route, filter, session, CSRF dan database test yang terisolasi. Uji PHP 8.4 dan 8.5.
2. B, bandingkan admin login, user diblokir, password salah, input invalid, flash, logout, sesi dan aktivitas. Lengkapi user management dan jalur akun tersisa sebelum memulai transaksi WO.
3. C–D, bandingkan field, validasi, transisi, assignment, history, list/count dan dashboard. Gunakan fixture sintetis identik untuk CI3 dan CI4, termasuk scope corporation dan role.
4. E–F, bandingkan hasil filter, pagination, tabel, ekspor PDF/XLSX, XLS Jasper, UI dan responsivitas menggunakan browser. Jalankan perbandingan MySQL/MariaDB, timezone dan collation sebelum penerimaan akhir.
5. Fixture SQLite dalam memori hanya memberi bukti lokal terbatas. Data fixture tidak berasal dari dump operasional. Tidak ada migrations, seeders, atau pengujian pada database produksi.

## Inventaris kode




### Route eksplisit CI3

Route bahasa dinamis memakai tabel languages dan general_settings. Daftar ini belum menyatakan verb HTTP untuk jalur CI3 yang tidak mendeklarasikannya.

| URI atau key | Controller/method |
| --- | --- |
| `default_controller` | `admin_controller/index` |
| `404_override` | `home_controller/error_404` |
| `index` | `home_controller/index` |
| `error-404` | `home_controller/error_404` |
| `profile/(:any)` | `profile_controller/profile/$1` |
| `settings` | `profile_controller/update_profile` |
| `settings/change-password` | `profile_controller/change_password` |
| `confirm` | `auth_controller/confirm_email` |
| `logout` | `auth_controller/logout` |
| `cron/check-scheduled-posts` | `cron_controller/check_scheduled_posts` |
| `admin` | `admin_controller/index` |
| `admin/login` | `auth_controller/admin_login` |
| `admin/navigation` | `admin_controller/navigation` |
| `admin/apk` | `auth_controller/download_apk` |
| `admin/add-post` | `post_controller/add_post` |
| `admin/posts_in` | `post_controller/posts_in` |
| `admin/posts_out` | `post_controller/posts_out` |
| `admin/scheduled-posts` | `post_controller/scheduled_posts` |
| `admin/drafts` | `post_controller/drafts` |
| `admin/update-post/(:num)` | `post_controller/update_post/$1` |
| `admin/update-post-closed/(:num)` | `post_controller/update_post_closed/$1` |
| `admin/view-post/(:num)` | `post_controller/view_post/$1` |
| `admin/categories` | `category_controller/categories` |
| `admin/subcategories` | `category_controller/subcategories` |
| `admin/update-category/(:num)` | `category_controller/update_category/$1` |
| `admin/update-subcategory/(:num)` | `category_controller/update_subcategory/$1` |
| `admin/locations` | `location_controller/locations` |
| `admin/sublocations` | `location_controller/sublocations` |
| `admin/update-location/(:num)` | `location_controller/update_location/$1` |
| `admin/update-sublocation/(:num)` | `location_controller/update_sublocation/$1` |
| `admin/visual-settings` | `admin_controller/visual_settings` |
| `admin/email-settings` | `admin_controller/email_settings` |
| `admin/settings` | `admin_controller/settings` |
| `admin/users` | `admin_controller/users` |
| `admin/administrators` | `admin_controller/administrators` |
| `admin/add-user` | `admin_controller/add_user` |
| `admin/edit-user/(:num)` | `admin_controller/edit_user/$1` |
| `admin/roles-permissions` | `admin_controller/roles_permissions` |
| `admin/edit-role/(:num)` | `admin_controller/edit_role/$1` |
| `admin/email-preview` | `admin_controller/email_preview` |
| `admin/corps` | `corp_controller/corps` |
| `admin/corp-settings` | `corp_controller/corps` |
| `admin/update-corp/(:num)` | `corp_controller/update_corp/$1` |
| `admin/departments` | `department_controller/departments` |
| `admin/update-department/(:num)` | `department_controller/update_department/$1` |
| `admin/reports_in` | `report_controller/reports_in` |
| `admin/reports_out` | `report_controller/reports_out` |
| `admin/reportsbyfinishdate` | `report_controller/reports2` |
| `(:any)` | `home_controller/any/$1` |

### Controller dan permission

- Admin_controller.php — `index`, `navigation`, `add_menu_link_post`, `update_menu_link`, `update_menu_link_post`, `delete_navigation_post`, `menu_limit_post`, `get_menu_links_by_lang`, `comments`, `delete_comment_post`, `contact_messages`, `delete_contact_message_post`, `ad_spaces`, `ad_spaces_post`, `settings`, `settings_post`, `recaptcha_settings_post`, `cache_system`, `cache_system_post`, `preferences`, `preferences_post`, `visual_settings`, `visual_settings_post`, `email_settings`, `email_settings_post`, `contact_email_settings_post`, `email_verification_settings_post`, `subscribers`, `send_email_subscribers`, `send_email_subscribers_post`, `send_email_subscriber`, `send_email_subscriber_post`, `delete_newsletter_post`, `email_preview`, `seo_tools`, `seo_tools_post`, `social_login_configuration`, `social_login_facebook_post`, `social_login_google_post`, `social_login_vk_post`, `font_options`, `font_options_post`, `users`, `administrators`, `add_user`, `add_user_post`, `change_user_password_post`, `change_user_role_post`, `confirm_user_email`, `ban_user_post`, `edit_user`, `edit_user_post`, `delete_user_post`, `roles_permissions`, `edit_role`, `edit_role_post`, `control_panel_language_post`, `delete_selected_comments`, `delete_selected_contact_messages`, `file_manager` · Permission `admin_panel`, `navigation`, `comments_contact`, `ad_spaces`, `settings`, `newsletter`, `seo_tools`, `users`, `comments`.
- Auth_controller.php — `login_post`, `connect_with_facebook`, `facebook_callback`, `connect_with_google`, `connect_with_vk`, `admin_login`, `download_apk`, `admin_login_post`, `register`, `register_post`, `logout`, `forgot_password`, `forgot_password_post`, `reset_password`, `reset_password_post`, `confirm_email`, `unsubscribe`, `reset_flash_data`, `is_registration_active`, `get_users_by_corp`.
- Category_controller.php — `categories`, `add_category_post`, `update_category`, `update_category_post`, `delete_category_post`, `subcategories`, `add_subcategory_post`, `update_subcategory`, `update_subcategory_post`, `get_top_categories_by_lang`, `get_sub_categories`, `gallery_categories`, `add_gallery_category_post`, `update_gallery_category`, `update_gallery_category_post`, `delete_gallery_category_post`, `gallery_categories_by_lang`, `gallery_categories_by_album` · Permission `categories`, `gallery`.
- Corp_controller.php — `corps`, `add_corp_post`, `update_corp`, `update_corp_post`, `delete_corp_post`, `subcorps`, `add_subcorp_post`, `update_subcorp`, `update_subcorp_post`, `get_top_corps_by_lang`, `get_sub_corps`, `gallery_corps`, `add_gallery_corp_post`, `update_gallery_corp`, `update_gallery_corp_post`, `delete_gallery_corp_post`, `gallery_corps_by_lang`, `gallery_corps_by_album` · Permission `corps`, `gallery`.
- Cron_controller.php — `check_scheduled_posts`.
- Department_controller.php — `departments`, `add_department_post`, `update_department`, `update_department_post`, `delete_department_post`, `subdepartments`, `add_subdepartment_post`, `update_subdepartment`, `update_subdepartment_post`, `get_top_departments_by_lang`, `get_sub_departments`, `gallery_departments`, `add_gallery_department_post`, `update_gallery_department`, `update_gallery_department_post`, `delete_gallery_department_post`, `gallery_departments_by_lang`, `gallery_departments_by_album`, `get_department_by_corp` · Permission `departments`, `gallery`.
- File_controller.php — `upload_multiple_images`, `get_images`, `select_image_file`, `load_more_images`, `delete_image_file`, `upload_ck_multiple_images`, `get_ck_images`, `load_more_ckimages`, `delete_ckimage_file`, `upload_audio_file`, `select_audio_file`, `load_more_audios`, `delete_audio_file`, `search_audio_file`, `select_video_file`, `upload_video_file`, `load_more_videos`, `delete_video_file`, `search_video_file` · Permission `add_post`.
- Home_controller.php — `index`, `posts`, `gallery`, `gallery_album`, `category`, `tag`, `redirect_post_to_any`, `any`, `post`, `gallery_post`, `contact`, `contact_post`, `search`, `rss_feeds`, `rss_latest_posts`, `rss_by_category`, `rss_by_user`, `add_comment_post`, `delete_comment_post`, `load_subcomment_box`, `load_more_comment`, `like_comment_post`, `dislike_comment_post`, `add_vote`, `add_to_newsletter`, `reading_list`, `load_more_posts`, `add_delete_reading_list_post`, `save_reaction`, `download_audio`, `is_page_exists`, `cookies_warning`, `checkPageAuth`, `error_404`.
- Language_controller.php — `languages`, `set_language_post`, `add_language_post`, `update_language`, `update_language_post`, `delete_language_post`, `update_phrases`, `update_phrases_post`, `search_phrases` · Permission `settings`.
- Location_controller.php — `locations`, `add_location_post`, `update_location`, `update_location_post`, `delete_location_post`, `sublocations`, `add_sublocation_post`, `update_sublocation`, `update_sublocation_post`, `get_top_locations_by_lang`, `get_sub_locations`, `gallery_locations`, `add_gallery_location_post`, `update_gallery_location`, `update_gallery_location_post`, `delete_gallery_location_post`, `gallery_locations_by_lang`, `gallery_locations_by_album`, `get_location_by_corp` · Permission `locations`, `gallery`.
- Page_controller.php — `add_page`, `add_page_post`, `pages`, `update_page`, `update_page_post`, `delete_page_post` · Permission `pages`.
- Post_controller.php — `add_post`, `add_post_post`, `update_post`, `update_post_closed`, `update_post_post`, `view_post`, `delete_post_additional_image`, `add_gallery`, `gallery_post_items`, `add_gallery_post`, `update_gallery_post_item_post`, `add_gallery_post_item_post`, `set_gallery_item_box_collapsed`, `delete_gallery_post_item_post`, `add_ordered_list`, `add_ordered_list_post`, `ordered_list_items`, `update_ordered_list_item_post`, `add_ordered_list_item_post`, `set_ordered_list_item_box_collapsed`, `delete_ordered_list_item_post`, `add_audio`, `delete_post_audio`, `add_video`, `get_video_from_url`, `get_video_thumbnail`, `delete_post_video`, `publish_draft_post`, `update_post_items_options_post`, `send_to_all_subscribers`, `posts`, `posts_in`, `posts_out`, `slider_posts`, `featured_posts`, `breaking_news`, `recommended_posts`, `pending_posts`, `scheduled_posts`, `drafts`, `post_options_post`, `delete_post`, `post_bulk_options`, `delete_selected_posts`, `featured_posts_order_post`, `home_slider_posts_order_post`, `delete_post_main_image`, `delete_rss_post_main_image`, `set_pagination_per_page` · Permission `add_post`, `manage_all_posts`.
- Profile_controller.php — `profile`, `update_profile`, `update_profile_post`, `social_accounts`, `social_accounts_post`, `preferences`, `preferences_post`, `change_password`, `change_password_post`, `follow_unfollow_user`.
- Report_controller.php — `reports`, `reports1_XLS`, `reports2_PDF`, `reports2_XLS`, `reports_in`, `reports_out` · Permission `add_post`.

### Model dan tabel literal

| Model | Tabel | Metode |
| --- | --- | ---: |
| `Auth_model.php` | `roles_permissions`, `users`, `users_logs` | 62 |
| `Category_model.php` | `categories` | 24 |
| `Corp_model.php` | `corps` | 16 |
| `Department_model.php` | `departments` | 25 |
| `Email_model.php` | `users` | 5 |
| `File_model.php` | `audios`, `images`, `videos` | 19 |
| `Language_model.php` | `general_settings`, `languages`, `pages`, `settings`, `widgets` | 18 |
| `Location_model.php` | `locations` | 24 |
| `Page_model.php` | `pages` | 16 |
| `Post_admin_model.php` | `categories`, `category`, `corpnya`, `dateChoice`, `department`, `lang_id`, `post_type`, `posts`, `posts_logs`, `q`, `role`, `subcategory`, `tglAkhir`, `tglAwal`, `user`, `users` | 85 |
| `Post_file_model.php` | `audios`, `post_audios`, `post_images`, `posts` | 13 |
| `Post_item_model.php` | `post_gallery_items`, `post_ordered_list_items`, `posts` | 18 |
| `Post_model.php` | `categories`, `post_hits`, `posts`, `tags`, `users` | 30 |
| `Profile_model.php` | `followers`, `users` | 14 |
| `Report_model.php` | `users` | 1 |
| `Settings_model.php` | `general_settings`, `settings` | 17 |
| `Upload_model.php` |  | 18 |
| `Video_model.php` |  | 2 |
| `Visual_settings_model.php` | `visual_settings` | 3 |

### Konfigurasi, helper, library dan schema referensi

- `application/config/` — `autoload.php`, `bcrypt.php`, `config.php`, `constants.php`, `database.php`, `doctypes.php`, `fonts.php`, `foreign_chars.php`, `hooks.php`, `memcached.php`, `migration.php`, `mimes.php`, `pagination.php`, `profiler.php`, `route_slugs.php`, `routes.php`, `smileys.php`, `user_agents.php`.
- `application/helpers/` — `custom_helper.php`.
- `application/libraries/` — `Bcrypt.php`, `Recaptcha.php`, `Rss_parser.php`.
- `application/core/` — `Core_Controller.php`.
- `final_db_deploy.sql` — `audios`, `categories`, `corps`, `general_settings`, `images`, `languages`, `locations`, `post_audios`, `post_images`, `posts`, `posts_logs`, `roles_permissions`, `settings`, `users`, `users_logs`, `videos`, `visual_settings`.
- `ewohotel_tntrm.sql` — `audios`, `categories`, `corps`, `departments`, `general_settings`, `images`, `languages`, `locations`, `posts`, `posts_logs`, `post_audios`, `post_images`, `roles_permissions`, `settings`, `users`, `users_logs`, `videos`, `visual_settings`.
- `user.sql` — `users`.

### Jalur controller yang disebut di view dan JavaScript

- `admin_controller/add_menu_link_post`
- `admin_controller/add_user_post`
- `admin_controller/ban_user_post`
- `admin_controller/cache_system_post`
- `admin_controller/change_user_password_post`
- `admin_controller/change_user_role_post`
- `admin_controller/confirm_user_email`
- `admin_controller/contact_email_settings_post`
- `admin_controller/control_panel_language_post`
- `admin_controller/delete_contact_message_post`
- `admin_controller/delete_navigation_post`
- `admin_controller/delete_selected_comments`
- `admin_controller/delete_selected_contact_messages`
- `admin_controller/delete_user_post`
- `admin_controller/edit_role_post`
- `admin_controller/edit_user_post`
- `admin_controller/email_preview`
- `admin_controller/email_settings_post`
- `admin_controller/email_verification_settings_post`
- `admin_controller/font_options_post`
- `admin_controller/get_menu_links_by_lang`
- `admin_controller/menu_limit_post`
- `admin_controller/preferences_post`
- `admin_controller/recaptcha_settings_post`
- `admin_controller/settings_post`
- `admin_controller/update_menu_link_post`
- `admin_controller/user_options_post`
- `admin_controller/visual_settings_post`
- `auth_controller/admin_login_post`
- `auth_controller/forgot_password_post`
- `auth_controller/get_users_by_corp`
- `auth_controller/login_post`
- `auth_controller/register_post`
- `auth_controller/reset_password_post`
- `category_controller/add_category_post`
- `category_controller/add_subcategory_post`
- `category_controller/delete_category_post`
- `category_controller/gallery_categories_by_album`
- `category_controller/get_sub_categories`
- `category_controller/get_top_categories_by_lang`
- `category_controller/update_category_post`
- `category_controller/update_subcategory_post`
- `corp_controller/add_corp_post`
- `corp_controller/delete_corp_post`
- `corp_controller/update_corp_post`
- `department_controller/add_department_post`
- `department_controller/delete_department_post`
- `department_controller/get_department_by_corp`
- `department_controller/update_department_post`
- `file_controller/select_image_file`
- `file_controller/upload_ck_multiple_images`
- `file_controller/upload_multiple_images`
- `gallery_controller/gallery_albums_by_lang`
- `gallery_controller/set_as_album_cover`
- `home_controller/add_comment_post`
- `home_controller/add_delete_reading_list_post`
- `home_controller/add_to_newsletter`
- `home_controller/add_vote`
- `home_controller/contact_post`
- `home_controller/cookies_warning`
- `home_controller/delete_comment_post`
- `home_controller/dislike_comment_post`
- `home_controller/like_comment_post`
- `home_controller/load_more_comment`
- `home_controller/load_more_comments`
- `home_controller/load_more_posts`
- `home_controller/load_subcomment_box`
- `home_controller/save_reaction`
- `language_controller/add_language_post`
- `language_controller/delete_language_post`
- `language_controller/set_language_post`
- `language_controller/update_language_post`
- `language_controller/update_phrases_post`
- `location_controller/add_location_post`
- `location_controller/add_sublocation_post`
- `location_controller/delete_location_post`
- `location_controller/get_location_by_corp`
- `location_controller/get_sub_locations`
- `location_controller/get_top_locations_by_lang`
- `location_controller/update_location_post`
- `location_controller/update_sublocation_post`
- `page_controller/add_page_post`
- `page_controller/delete_page_post`
- `page_controller/update_page_post`
- `post_controller/add_gallery_post`
- `post_controller/add_gallery_post_item_post`
- `post_controller/add_ordered_list_item_post`
- `post_controller/add_ordered_list_post`
- `post_controller/add_post_post`
- `post_controller/delete_gallery_post_item_post`
- `post_controller/delete_ordered_list_item_post`
- `post_controller/delete_post`
- `post_controller/delete_post_audio`
- `post_controller/delete_post_main_image`
- `post_controller/delete_post_video`
- `post_controller/delete_selected_posts`
- `post_controller/featured_posts_order_post`
- `post_controller/get_video_from_url`
- `post_controller/home_slider_posts_order_post`
- `post_controller/post_bulk_options`
- `post_controller/post_options_post`
- `post_controller/publish_draft_post`
- `post_controller/set_gallery_item_box_collapsed`
- `post_controller/set_ordered_list_item_box_collapsed`
- `post_controller/update_gallery_post_item_post`
- `post_controller/update_ordered_list_item_post`
- `post_controller/update_post_items_options_post`
- `post_controller/update_post_post`
- `profile_controller/change_password_post`
- `profile_controller/preferences_post`
- `profile_controller/social_accounts_post`
- `profile_controller/update_profile_post`
- `report_controller/reports1_XLS`
- `report_controller/reports2_XLS`
- `rss_controller/delete_feed_image`

## Pembaruan runtime dan dashboard, 19 November 2026 WIB

Target aktif adalah `C:/xampp8512/htdocs/eworeb`. Sumber CI3 tetap di `C:/xampp8212/htdocs/ewo`. Paket aktual tetap CI4 4.7.6. Path bootstrap dan manifest pada checkout ini telah diselaraskan dengan vendor stabil. PHP CLI XAMPP adalah 8.5.12. Apache harus memetakan `/eworeb` ke `public/`, dengan izin baca HTTP hanya pada public. Lihat [contoh Alias](eworeb-apache.conf.example) dan [progres runtime](MIGRATION_PROGRESS.md). [Apache mod_alias](https://httpd.apache.org/docs/2.4/mod/mod_alias.html)

### Kontrak dashboard

| Akun | Ringkasan | Scope |
| --- | --- | --- |
| Key admin dan ID 1 | Incoming dan Outgoing agregat | CI3 sudah menghitung keduanya. Corporation dari corpnya, role tujuan dari role untuk Incoming, department asal dari department untuk Outgoing. |
| ID 7/8 | Rekap Incoming Engineering, IT, Housekeeping | Corporation akun. Engineering 3, IT 5, Housekeeping 6. IT Waiting tetap memakai role 3 seperti perhitungan CI3. |
| ID 3/5/6 | Incoming dan Outgoing | Incoming corporation dan role tujuan sendiri. Outgoing corporation, department asal dan role tujuan selain role akun. |
| ID 4 | Incoming dan Outgoing | Incoming corporation, role tujuan, pembuat akun dan department. Outgoing sama seperti cabang 3/5/6. |
| ID lain, admin key/ID tidak selaras, atau scope sesi tidak lengkap | Statistik dihentikan | Tidak ada query statistik lintas cakupan sebagai fallback. |

Semua hitungan memakai category_id 1 New, 5 Pending, 3 Waiting, 2 Progress, dan 4 Closed. Syarat visibility, status publikasi, dan jadwal tetap 1, 1, dan 0. Pengelompokan SQL mempertahankan hitungan CI3 dengan SELECT saja. Aktivitas autentikasi tetap dicatat pada jalur existing. Sidebar mengarah ke dashboard, bagian statistik yang bisa dilihat, dan logout.

Panel Engineering, HK dan IT pada tampilan admin CI3 menunjuk ketiga-tiganya ke angka Outgoing agregat. Tampilan CI4 menyajikan angka tersebut sebagai agregat Outgoing dan menunda atribusi departemen admin sampai definisinya disepakati. Query Incoming global sudah tersedia di CI3, tetapi angka itu sebelumnya tidak ditampilkan untuk admin. Scope agregat Incoming CI4 mengikuti query CI3. Rekap 7/8 dan cabang akun biasa mengikuti panel yang ditampilkan CI3.
