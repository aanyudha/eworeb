<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="<?= esc(base_url('favicon.ico'), 'attr') ?>">
    <title>EWO Reborn · Workspace</title>
    <?php foreach ($entry['css'] ?? [] as $file): ?>
        <link rel="stylesheet" href="<?= esc(base_url('workspace-assets/' . $file), 'attr') ?>">
    <?php endforeach ?>
</head>
<body>
<div id="root"
     data-workspace="<?= esc(site_url('workspace'), 'attr') ?>"
     data-knowledge-editor="<?= $knowledgeAdmin ? 'enabled' : 'disabled' ?>"
     data-knowledge-admin="<?= esc(site_url('api/v1/admin/kb/articles'), 'attr') ?>"
     data-knowledge-home="<?= esc(site_url('kb'), 'attr') ?>"
     data-dashboard-api="<?= esc(site_url('api/v1/dashboard'), 'attr') ?>"
     <?php if ($workOrderRole !== null): ?>
     data-work-orders-api="<?= esc(site_url('api/v1/work-orders'), 'attr') ?>"
     data-reports-api="<?= esc(site_url('api/v1/reports'), 'attr') ?>"
     data-schedule-access="<?= $scheduleAccess ? 'enabled' : 'disabled' ?>"
     data-work-order-role="<?= esc((string) $workOrderRole, 'attr') ?>"
     <?php endif; ?>

     data-directory-views="<?= esc(json_encode($directoryViews, JSON_THROW_ON_ERROR), 'attr') ?>"
     data-login="<?= esc(site_url('admin/login'), 'attr') ?>"
     data-logout="<?= esc(site_url('logout'), 'attr') ?>"
     data-original-dashboard="<?= esc(site_url('admin'), 'attr') ?>"></div>
<script type="module" src="<?= esc(base_url('workspace-assets/' . $entry['file']), 'attr') ?>"></script>
</body>
</html>
