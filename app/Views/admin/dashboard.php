<?php
$applicationName = $settings['application_name'] ?? 'EWO';
$sections = [
    'incoming' => 'Incoming Work Order',
    'outgoing' => 'Outgoing Work Order',
    'recap' => 'Incoming by Department',
];
$colors = ['New' => 'red', 'Pending' => 'yellow', 'Waiting' => 'aqua', 'Progress' => 'blue', 'Closed' => 'green'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - <?= esc($applicationName) ?></title>
    <?php foreach (['vendor/bootstrap/css/bootstrap.min.css', 'admin/css/AdminLTE.min.css', 'admin/css/_all-skins.min.css', 'admin/css/dashboard.css'] as $asset): ?>
        <link rel="stylesheet" href="<?= esc(base_url('assets/' . $asset), 'attr') ?>">
    <?php endforeach ?>
</head>
<body class="hold-transition skin-blue sidebar-mini">
<div class="wrapper">
    <header class="main-header">
        <a class="logo" href="<?= esc(site_url('admin'), 'attr') ?>"><?= esc($applicationName) ?></a>
        <nav class="navbar navbar-static-top" aria-label="Account">
            <button class="sidebar-toggle" type="button" data-toggle="push-menu" aria-label="Toggle sidebar">
                <span aria-hidden="true">&#9776;</span>
            </button>
            <div class="navbar-custom-menu">
                <ul class="nav navbar-nav"><li><a href="<?= esc(site_url('logout'), 'attr') ?>">Logout</a></li></ul>
            </div>
        </nav>
    </header>
    <aside class="main-sidebar">
        <nav class="sidebar" aria-label="Dashboard">
            <ul class="sidebar-menu">
                <li class="active"><a href="<?= esc(site_url('admin'), 'attr') ?>" aria-current="page">Dashboard</a></li>
                <?php foreach ($summary as $key => $values): ?>
                    <li><a href="#<?= esc($key, 'attr') ?>"><?= esc($sections[$key]) ?></a></li>
                <?php endforeach ?>
            </ul>
        </nav>
    </aside>
    <main class="content-wrapper">
        <header class="content-header"><h1>Dashboard</h1></header>
        <div class="content">
            <?php if ($corporations !== []): ?>
                <form class="dashboard-filter form-inline" method="get" action="<?= esc(site_url('admin'), 'attr') ?>">
                    <label for="corporation">Corporation</label>
                    <select class="form-control" name="corpnya" id="corporation">
                        <option value="">All corporations</option>
                        <?php foreach ($corporations as $corp): ?>
                            <option value="<?= esc((string) $corp['id'], 'attr') ?>"<?= (string) $corp['id'] === $filters['corpnya'] ? ' selected' : '' ?>><?= esc($corp['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                    <?php foreach (['role', 'department'] as $name): ?>
                        <?php if (!empty($filters[$name])): ?>
                            <input type="hidden" name="<?= esc($name, 'attr') ?>" value="<?= esc($filters[$name], 'attr') ?>">
                        <?php endif ?>
                    <?php endforeach ?>
                    <button type="submit" class="btn btn-primary">Filter</button>
                </form>
            <?php endif ?>
            <?php foreach ($summary as $key => $values): ?>
                <section class="box box-primary" id="<?= esc($key, 'attr') ?>" aria-labelledby="<?= esc($key, 'attr') ?>-heading">
                    <header class="box-header with-border">
                        <h2 class="box-title" id="<?= esc($key, 'attr') ?>-heading"><?= esc($sections[$key]) ?></h2>
                    </header>
                    <div class="box-body">
                        <?php foreach ($key === 'recap' ? $values : ['' => $values] as $label => $counts): ?>
                            <div class="dashboard-group" data-group="<?= esc($label, 'attr') ?>">
                                <?php if ($label !== ''): ?>
                                    <h3><?= esc($label) ?></h3>
                                <?php endif ?>
                                <dl class="dashboard-stats">
                                    <?php foreach ($counts as $status => $count): ?>
                                        <div class="small-box bg-<?= esc($colors[$status], 'attr') ?>">
                                            <dt><?= esc($status) ?></dt>
                                            <dd><?= $count ?></dd>
                                        </div>
                                    <?php endforeach ?>
                                </dl>
                            </div>
                        <?php endforeach ?>
                    </div>
                </section>
            <?php endforeach ?>
        </div>
    </main>
</div>
<?php foreach (['admin/js/jquery.min.js', 'vendor/bootstrap/js/bootstrap.min.js', 'admin/js/adminlte.min.js'] as $asset): ?>
    <script src="<?= esc(base_url('assets/' . $asset), 'attr') ?>"></script>
<?php endforeach ?>
</body>
</html>
