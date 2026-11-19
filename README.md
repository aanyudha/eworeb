# EWO Reborn

## Build frontend dan shared hosting

Dari root project, jalankan dengan Node.js LTS yang kompatibel dengan Vite 7.

```powershell
npm.cmd --prefix frontend ci
npm.cmd --prefix frontend run lint
npm.cmd --prefix frontend run build
```

Paket build berada di `public/workspace-assets/`. Sertakan `manifest.json` dan seluruh folder aset saat menyiapkan salinan hosting. Apache melayani folder `public/` dan menjalankan CodeIgniter melalui PHP. Node.js hanya diperlukan saat build.

Ikuti [panduan shared hosting](docs/SHARED_HOSTING_DEPLOYMENT.md) untuk Composer, document root, subfolder, konfigurasi environment dan verifikasi setelah upload.

## What is CodeIgniter?

CodeIgniter is a PHP full-stack web framework that is light, fast, flexible and secure.
More information can be found at the [official site](https://codeigniter.com).

This repository holds a composer-installable app starter.
It has been built from the
[development repository](https://github.com/codeigniter4/CodeIgniter4).

More information about the plans for version 4 can be found in [CodeIgniter 4](https://forum.codeigniter.com/forumdisplay.php?fid=28) on the forums.

You can read the [user guide](https://codeigniter.com/user_guide/)
corresponding to the latest version of the framework.

## Installation & updates

`composer create-project codeigniter4/appstarter` then `composer update` whenever
there is a new release of the framework.

When updating, check the release notes to see if there are any changes you might need to apply
to your `app` folder. The affected files can be copied or merged from
`vendor/codeigniter4/framework/app`.

## Setup

Copy `env` to `.env` and tailor for your app, specifically the baseURL
and any database settings.

## Important Change with index.php

`index.php` is no longer in the root of the project! It has been moved inside the *public* folder,
for better security and separation of components.

This means that you should configure your web server to "point" to your project's *public* folder, and
not to the project root. A better practice would be to configure a virtual host to point there. A poor practice would be to point your web server to the project root and expect to enter *public/...*, as the rest of your logic and the
framework are exposed.

**Please** read the user guide for a better explanation of how CI4 works!

## Repository Management

We use GitHub issues, in our main repository, to track **BUGS** and to track approved **DEVELOPMENT** work packages.
We use our [forum](http://forum.codeigniter.com) to provide SUPPORT and to discuss
FEATURE REQUESTS.

This repository is a "distribution" one, built by our release preparation script.
Problems with it can be raised on our forum, or as issues in the main repository.

## Server Requirements

PHP version 8.2 or higher is required, with the following extensions installed:

- [intl](http://php.net/manual/en/intl.requirements.php)
- [mbstring](http://php.net/manual/en/mbstring.installation.php)

> [!WARNING]
> - The end of life date for PHP 7.4 was November 28, 2022.
> - The end of life date for PHP 8.0 was November 26, 2023.
> - The end of life date for PHP 8.1 was December 31, 2025.
> - If you are still using below PHP 8.2, you should upgrade immediately.
> - The end of life date for PHP 8.2 will be December 31, 2026.

Additionally, make sure that the following extensions are enabled in your PHP:

- json (enabled by default - don't turn it off)
- [mysqlnd](http://php.net/manual/en/mysqlnd.install.php) if you plan to use MySQL
- [libcurl](http://php.net/manual/en/curl.requirements.php) if you plan to use the HTTP\CURLRequest library

## Environment Configuration & Session Key Generation

EWO Reborn uses `ewo.sessionKey` to validate authentication sessions. Run in PowerShell:

```powershell
C:\xampp8512\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

This generates 64 hexadecimal characters from 256 bits of randomness. Copy the result into `.env` in the project root. Use a different key for each environment and retain it across restarts. Rotating it can invalidate active sessions. Keep the real key out of Git, source code, logs, and documentation.

Authentication uses the dedicated `database.ewo` group. Example for an approved local database:

```ini
database.ewo.hostname = 127.0.0.1
database.ewo.database = NAMA_DATABASE
database.ewo.username = USER_DATABASE
database.ewo.password = PASSWORD_DATABASE
database.ewo.DBDriver = MySQLi
database.ewo.port = 3310

ewo.sessionKey = "HASIL_RANDOM_BYTES_32"
```

For `EWO authentication is not configured.`, check that the root file is named `.env`, that the lines are uncommented, and that the connection keys start with `database.ewo`. Diagnose the effective settings without printing secrets:

```powershell
C:\xampp8512\php\php.exe spark ewo:diagnose
```

See [database diagnostics](docs/DATABASE_DIAGNOSTICS.md) and the [Knowledge Center guide](docs/KNOWLEDGE_BASE.md).

## Penerimaan database legacy

Ikuti [audit dan prosedur penyelarasan database legacy](docs/LEGACY_DATABASE_ALIGNMENT.md) untuk menyiapkan tujuan pengembangan yang disetujui dan menerima modul dengan akun serta data Work Order existing. Lihat [matriks parity](docs/FEATURE_PARITY_MATRIX.md) untuk menu yang tersedia, batas akses dan pekerjaan tersisa.
