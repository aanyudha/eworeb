<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="<?= esc($branding['favicon'] ?? base_url('favicon.ico'), 'attr') ?>">
    <title>Login - Admin <?= esc($settings['site_title'] ?? 'EWO') ?></title>
    <?php foreach (['vendor/bootstrap/css/bootstrap.min.css', 'admin/css/AdminLTE.min.css', 'admin/css/_all-skins.min.css', 'admin/css/custom.css'] as $style): ?>
        <link rel="stylesheet" href="<?= esc(base_url('assets/' . $style), 'attr') ?>">
    <?php endforeach ?>
</head>
<body class="hold-transition login-page">
<div class="login-box">
    <div class="login-logo">
        <a href="<?= esc(site_url('admin/login'), 'attr') ?>"><?php if (isset($branding['logo'])): ?><img src="<?= esc($branding['logo'], 'attr') ?>" alt="" width="34" height="34"> <?php endif; ?><b><?= esc($settings['application_name'] ?? 'EWO') ?></b>&nbsp;Panel</a>
    </div>
    <div class="login-box-body">
        <h1 class="login-box-msg h4">Login</h1>
        <?php if ($messages !== []): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach ($messages as $message): ?>
                    <p><?= esc(lang('Auth.' . $message)) ?></p>
                <?php endforeach ?>
            </div>
        <?php endif ?>
        <form action="<?= esc(site_url('auth_controller/admin_login_post'), 'attr') ?>" method="post">
            <?= csrf_field() ?>
            <div class="form-group has-feedback">
                <label class="sr-only" for="email">Email</label>
                <input class="form-control form-input" type="email" id="email" name="email"
                       value="<?= esc($email, 'attr') ?>" placeholder="Email" autocomplete="username" required>
                <span class="glyphicon glyphicon-envelope form-control-feedback" aria-hidden="true"></span>
            </div>
            <div class="form-group has-feedback">
                <label class="sr-only" for="password">Password</label>
                <input class="form-control form-input" type="password" id="password" name="password"
                       placeholder="Password" autocomplete="current-password" required>
                <span class="glyphicon glyphicon-lock form-control-feedback" aria-hidden="true"></span>
            </div>
            <div class="row">
                <div class="col-xs-4 col-xs-offset-8">
                    <button type="submit" class="btn btn-primary btn-block btn-flat">Login</button>
                </div>
            </div>
        </form>
    </div>
</div>
</body>
</html>

