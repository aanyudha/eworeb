<?php
declare(strict_types=1);

$db = new SQLite3($argv[1]);
if (($argv[2] ?? '') === 'check') {
    $rows = $db->querySingle('SELECT COUNT(*) FROM users_logs WHERE user_id = 1');
    $stamp = $db->querySingle('SELECT last_seen FROM users WHERE id = 1');
    if ($rows < 2 || $stamp === null) {
        exit(1);
    }
    echo 'HTTP fixture activity verified';
    exit;
}

$db->enableExceptions(true);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password TEXT,
    status INTEGER, role TEXT, role_id INTEGER, last_seen TEXT, ip_address TEXT)');
$db->exec('CREATE TABLE general_settings (id INTEGER PRIMARY KEY, timezone TEXT, site_lang INTEGER)');
$db->exec('CREATE TABLE settings (lang_id INTEGER, site_title TEXT, application_name TEXT)');
$db->exec('CREATE TABLE roles_permissions (role TEXT PRIMARY KEY, admin_panel INTEGER, add_post INTEGER)');
$db->exec('CREATE TABLE users_logs (user_id INTEGER, last_seen TEXT, ip_address TEXT)');
$db->exec("INSERT INTO general_settings VALUES (1, 'Asia/Jakarta', 1)");
$db->exec("INSERT INTO settings VALUES (1, 'Synthetic EWO', 'Fixture')");
$db->exec("INSERT INTO roles_permissions VALUES ('admin', 0, 0), ('staff', 0, 0)");
$insert = $db->prepare('INSERT INTO users (id,email,password,status,role,role_id) VALUES (?, ?, ?, ?, ?, ?)');
$hash = password_hash('fixture-password', PASSWORD_BCRYPT);
foreach ([[1,'admin@example.test',1,'admin',1],[2,'blocked@example.test',0,'staff',4],[3,'staff@example.test',1,'staff',4]] as [$id,$email,$status,$role,$roleId]) {
    foreach ([$id,$email,$hash,$status,$role,$roleId] as $i=>$value) {
        $insert->bindValue($i+1,$value);
    }
    $insert->execute()->finalize();
    $insert->reset();
}
