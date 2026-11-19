<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="<?= esc(base_url('favicon.ico'), 'attr') ?>">
    <title>EWO Knowledge Center</title>
    <?php foreach ($entry['css'] ?? [] as $file): ?>
        <link rel="stylesheet" href="<?= esc(base_url('workspace-assets/' . $file), 'attr') ?>">
    <?php endforeach ?>
</head>
<body>
<div id="knowledge-root" data-home="<?= esc(site_url('/'), 'attr') ?>"
     data-api="<?= esc(site_url('api/v1/kb'), 'attr') ?>"
     data-staff="<?= esc(site_url('admin/login'), 'attr') ?>"></div>
<script type="module" src="<?= esc(base_url('workspace-assets/' . $entry['file']), 'attr') ?>"></script>
</body>
</html>
