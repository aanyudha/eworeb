<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[1])) {
    exit(1);
}
$db = new SQLite3($argv[1]);
$db->enableExceptions(true);
if (($argv[2] ?? '') === 'snapshot') {
    $rows = $db->query('SELECT * FROM posts ORDER BY id');
    $hash = hash_init('sha256');
    while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
        hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR));
    }
    echo hash_final($hash);
    exit;
}
if (($argv[2] ?? '') === 'check') {
    $activity = $db->querySingle('SELECT COUNT(*) FROM users_logs WHERE user_id = 1');
    $count = $db->querySingle('SELECT COUNT(*) FROM posts');
    if ($activity < 2 || $count !== 134 || $db->querySingle('SELECT last_seen FROM users WHERE id = 1') === null) {
        exit(1);
    }
    echo 'Activity recorded and fixture WO row count preserved';
    exit;
}

$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password TEXT, status INTEGER,
    role TEXT, role_id INTEGER, corp_id INTEGER, dept_id INTEGER, last_seen TEXT, ip_address TEXT)');
$db->exec('CREATE TABLE general_settings (id INTEGER PRIMARY KEY, timezone TEXT, site_lang INTEGER)');
$db->exec('CREATE TABLE settings (lang_id INTEGER, site_title TEXT, application_name TEXT)');
$db->exec('CREATE TABLE roles_permissions (role TEXT PRIMARY KEY, admin_panel INTEGER, add_post INTEGER)');
$db->exec('CREATE TABLE users_logs (user_id INTEGER, last_seen TEXT, ip_address TEXT)');
$db->exec('CREATE TABLE corps (id INTEGER PRIMARY KEY, name TEXT, corp_order INTEGER)');
$db->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, category_id INTEGER, role_id INTEGER, corp_id INTEGER,
    dept_id INTEGER, user_id INTEGER, visibility INTEGER, status INTEGER, is_scheduled INTEGER)');
$db->exec("INSERT INTO general_settings VALUES (1, 'Asia/Jakarta', 1)");
$db->exec("INSERT INTO settings VALUES (1, 'Synthetic EWO', 'Fixture')");
$db->exec("INSERT INTO corps VALUES (1, 'Corporation One', 1), (2, 'Corporation Two', 2)");
$db->exec("INSERT INTO roles_permissions VALUES ('admin',0,0), ('staff',0,0), ('employee',1,1),
    ('engineering',1,1), ('corpadmin',1,1), ('gm',1,1), ('other',1,1)");
$user = $db->prepare('INSERT INTO users (id,email,password,status,role,role_id,corp_id,dept_id) VALUES (?,?,?,?,?,?,?,?)');
$hash = password_hash('fixture-password', PASSWORD_BCRYPT);
$accounts = [
    [1,'admin',1,'admin',1,1,10], [2,'blocked',0,'staff',4,1,10], [3,'staff',1,'staff',4,1,10],
    [4,'employee',1,'employee',4,1,10], [5,'engineering',1,'engineering',3,1,10],
    [6,'corpadmin',1,'corpadmin',7,1,10], [7,'gm',1,'gm',8,2,10], [8,'other',1,'other',9,1,10],
];
foreach ($accounts as [$id,$name,$active,$role,$roleId,$corp,$dept]) {
    foreach ([$id,$name.'@example.test',$hash,$active,$role,$roleId,$corp,$dept] as $i=>$value) {
        $user->bindValue($i+1,$value);
    }
    $user->execute()->finalize();
    $user->reset();
}

$post = $db->prepare('INSERT INTO posts (category_id,role_id,corp_id,dept_id,user_id,visibility,status,is_scheduled) VALUES (?,?,?,?,?,?,?,?)');
foreach (range(1, 5) as $category) {
    $buckets = [
        [$category,3,1,10,1], [$category+1,5,1,10,1], [2*$category,6,1,20,1],
        [1,4,1,10,4], [1,4,1,20,4], [1,4,1,10,1], [11-$category,3,2,10,1], [2,4,2,10,4],
    ];
    foreach ($buckets as [$count,$role,$corp,$dept,$creator]) {
        for ($i=0;$i<$count;$i++) {
            foreach ([$category,$role,$corp,$dept,$creator,1,1,0] as $n=>$value) {
                $post->bindValue($n+1,$value);
            }
            $post->execute()->finalize();
            $post->reset();
        }
    }
}
$db->exec('INSERT INTO posts (category_id,role_id,corp_id,dept_id,user_id,visibility,status,is_scheduled)
    VALUES (1,3,1,10,1,0,1,0),(1,3,1,10,1,1,0,0),(1,3,1,10,1,1,1,1),(9,3,1,10,1,1,1,0)');
