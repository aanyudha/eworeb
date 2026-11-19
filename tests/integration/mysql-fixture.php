<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[2])) {
    exit(1);
}

function buildReplyFixture(mysqli $fixture): void
{
    $root = dirname(__DIR__, 2);
    require $root . '/vendor/codeigniter4/framework/system/Config/DotEnv.php';
    (new CodeIgniter\Config\DotEnv($root))->load();
    if (!in_array(getenv('database.ewo.hostname'), ['localhost', '127.0.0.1'], true)
        || getenv('database.ewo.port') !== '3310' || getenv('database.ewo.database') !== 'eworeb') {
        throw new RuntimeException('Unapproved legacy schema source.');
    }
    $source = new mysqli('127.0.0.1', getenv('database.ewo.username'), getenv('database.ewo.password'), 'eworeb', 3310);
    $source->query('START TRANSACTION READ ONLY');
    try {
        foreach (['users','users_logs','roles_permissions','settings','general_settings','posts','posts_logs',
            'corps','categories','locations','departments','languages','post_images','post_audios','audios'] as $table) {
            $ddl = $source->query('SHOW CREATE TABLE ' . $table)->fetch_row()[1];
            if (!preg_match('/^CREATE TABLE \x60' . $table . '\x60 /', $ddl) || !str_contains($ddl, 'ENGINE=InnoDB')) {
                throw new RuntimeException('Unexpected legacy table definition.');
            }
            $fixture->query(preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl));
        }
    } finally {
        $source->rollback();
        $source->close();
    }
}


function runFixture(string $sqlitePath, string $action, ?string $orderId): void
{
    $settings = json_decode((string) getenv('EWO_HTTP_MYSQL_FIXTURE'), true, 8, JSON_THROW_ON_ERROR);
    $port = (int) ($settings['port'] ?? 0);
    if ($port < 49152 || $port > 65535 || !is_dir($settings['datadir'] ?? '')) {
        throw new RuntimeException('Invalid isolated MySQL fixture configuration.');
    }

    $dataDirectory = realpath($settings['datadir']);
    $temporary = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'eworeb-mysql-';
    if (!$dataDirectory || !str_starts_with(strtolower($dataDirectory), strtolower($temporary))) {
        throw new RuntimeException('MySQL fixture must use a newly created temporary data directory.');
    }

    $initializer = $action === 'init';
    $replies = ($settings['replies'] ?? false) === true;
    $refuse = $replies && in_array($action, ['refuse-reply-history', 'refuse-audio-link', 'refuse-settings-timezone'], true);
    $password = $initializer ? $settings['rootPassword'] : ($refuse ? getenv('EWO_REPLY_FIXTURE_ADMIN') : $settings['password']);
    $db = new mysqli('127.0.0.1', $initializer || $refuse ? 'root' : 'ewo_fixture', $password, '', $port);
    if (realpath($db->query('SELECT @@datadir')->fetch_row()[0]) !== $dataDirectory) {
        throw new RuntimeException('MySQL fixture server identity mismatch.');
    }
    $db->set_charset('utf8mb4');

    if ($initializer) {
        $db->query('CREATE DATABASE ewo_fixture CHARACTER SET utf8mb4');
        $db->select_db('ewo_fixture');
        if ($replies) {
            buildReplyFixture($db);
        } else {
            $ddl = trim(file_get_contents(__DIR__ . '/mysql-fixture.sql'), ";\r\n");
            foreach (explode(';', $ddl) as $statement) {
                if (trim($statement) !== '') {
                    $db->query($statement);
                }
            }
        }
        $sqlite = new SQLite3($sqlitePath, SQLITE3_OPEN_READONLY);
        foreach (['users', 'roles_permissions', 'users_logs', 'general_settings', 'settings', 'posts', 'corps'] as $table) {
            $rows = $sqlite->query("SELECT * FROM $table");
            $fixtureFields = match ($table) {
                'posts' => '(id,category_id,role_id,corp_id,dept_id,user_id,visibility,status,is_scheduled)',
                'users' => '(id,email,password,status,role,role_id,corp_id,dept_id,last_seen,ip_address)',
                                'roles_permissions' => $replies ? '(role,admin_panel,add_post,departments)' : '(role,admin_panel,add_post)',
                'general_settings' => $replies ? '(id,timezone,site_lang)' : '',
                'settings' => $replies ? '(lang_id,site_title,application_name)' : '',
                'corps' => $replies ? '(id,name,corp_order)' : '',
                default => '',
            };
            while ($row = $rows->fetchArray(SQLITE3_NUM)) {
                if ($replies && $table === 'roles_permissions') {
                    $row[] = 0;
                }
                $parameters = implode(',', array_fill(0, count($row), '?'));

                $db->execute_query("INSERT INTO $table $fixtureFields VALUES ($parameters)", $row);
            }
        }

        $db->query("UPDATE users SET username = SUBSTRING_INDEX(email, '@', 1), first_name = 'Fixture'");
        $db->query("UPDATE roles_permissions SET categories=1, locations=1, departments=1, users=1 WHERE role IN ('engineering','gm')");
        $db->query("UPDATE roles_permissions SET manage_all_posts=1 WHERE role='corpadmin'");
        if ($replies) {
            $db->query("UPDATE posts SET title='Fixture WO', location_id=1, summary='Original request',
                image_default='uploads/images/fixture.png'");
            $db->query("INSERT INTO post_images (post_id,image_default) SELECT id,'uploads/images/../outside.png' FROM posts");
            $db->query("INSERT INTO categories (id,lang_id,name,parent_id,visibility) VALUES
                (1,1,'New',0,2),(2,1,'Progress',0,1),(3,1,'Waiting',0,1),(4,1,'Closed',0,1),(5,1,'Pending',0,1),(6,1,'Reopen',0,0)");
            $db->query("INSERT INTO posts (title,post_type,category_id,role_id,corp_id,dept_id,user_id,visibility,status,is_scheduled)
                VALUES ('Publishable fixture draft','post',1,5,1,10,1,1,0,0),
                    ('Different department draft','post',1,5,1,20,1,1,0,0),
                    ('Different corporation draft','post',1,5,2,10,1,1,0,0),
                    ('Fixture audio','audio',9,3,1,10,1,1,1,0)");
            $db->query("INSERT INTO languages (id,name,short_form,language_code,folder_name,text_direction)
                VALUES (1,'English','en','en','english','ltr')");
            $db->query("INSERT INTO departments (id,lang_id,name,corp_id,parent_id,color)
                VALUES (10,1,'Fixture department',1,0,'#ffffff'),(20,1,'Foreign department',2,0,'#ffffff')");
            $db->query("INSERT INTO locations (id,lang_id,name,corp_id,parent_id,color)
                VALUES (300,1,'Other corporation test location',2,0,'#ffffff')");
            $db->query("UPDATE roles_permissions SET manage_all_posts=1 WHERE role='engineering'");
            $db->query("INSERT INTO users (id,username,email,role,role_id,corp_id,dept_id,status)
                VALUES (250,'fixture-other-corp','other-corp@example.test','engineering',3,'2','10',1)");
        }
        $fixturePassword = $settings['password'];
        if (!preg_match('/^[a-f0-9]{48}$/D', $fixturePassword)) {
            throw new RuntimeException('Invalid generated fixture password.');
        }
        $db->query("CREATE USER 'ewo_fixture'@'localhost' IDENTIFIED BY '$fixturePassword'");
        $db->query("GRANT SELECT ON ewo_fixture.* TO 'ewo_fixture'@'localhost'");
        $db->query("GRANT UPDATE (last_seen, ip_address) ON ewo_fixture.users TO 'ewo_fixture'@'localhost'");
        $db->query("GRANT INSERT ON ewo_fixture.users_logs TO 'ewo_fixture'@'localhost'");
        if ($replies) {
            $db->query("GRANT UPDATE (category_id,assign_to,edited_by,edited_at,status) ON ewo_fixture.posts TO 'ewo_fixture'@'localhost'");
            $db->query("GRANT INSERT ON ewo_fixture.posts_logs TO 'ewo_fixture'@'localhost'");
            $db->query("GRANT UPDATE (password,first_name,last_name,email,status) ON ewo_fixture.users TO 'ewo_fixture'@'localhost'");
            $db->query("GRANT INSERT ON ewo_fixture.users TO 'ewo_fixture'@'localhost'");
            $db->query("GRANT UPDATE (timezone) ON ewo_fixture.general_settings TO 'ewo_fixture'@'localhost'");
            $db->query("GRANT UPDATE (application_name,about_footer,optional_url_button_name,copyright,contact_address,contact_email,contact_phone)
                ON ewo_fixture.settings TO 'ewo_fixture'@'localhost'");
            $db->query("GRANT UPDATE (admin_panel,add_post,manage_all_posts,navigation,categories,users,settings)
                ON ewo_fixture.roles_permissions TO 'ewo_fixture'@'localhost'");
            foreach (['categories', 'locations', 'departments', 'corps'] as $directoryTable) {
                $db->query("GRANT INSERT, UPDATE ON ewo_fixture.$directoryTable TO 'ewo_fixture'@'localhost'");
            }
            foreach (['post_images', 'post_audios', 'audios'] as $mediaTable) {
                $db->query("GRANT INSERT ON ewo_fixture.$mediaTable TO 'ewo_fixture'@'localhost'");
            }
        }
        return;
    }

    $db->select_db('ewo_fixture');
    if ($action === 'refuse-reply-history' && $replies) {
        $db->query("REVOKE INSERT ON ewo_fixture.posts_logs FROM 'ewo_fixture'@'localhost'");
        return;
    }
    if ($action === 'refuse-audio-link' && $replies) {
        $db->query("REVOKE INSERT ON ewo_fixture.post_audios FROM 'ewo_fixture'@'localhost'");
        return;
    }
    if ($action === 'refuse-settings-timezone' && $replies) {
        $db->query("REVOKE UPDATE (timezone) ON ewo_fixture.general_settings FROM 'ewo_fixture'@'localhost'");
        return;
    }
    if ($action === 'audio-count' && $replies) {
        echo $db->query('SELECT COUNT(*) FROM audios')->fetch_row()[0];
        return;
    }
    if ($action === 'reply-state' && $replies) {
        $id = filter_var($orderId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new InvalidArgumentException('Missing fixture Work Order ID.');
        $order = $db->execute_query('SELECT * FROM posts WHERE id=?', [$id])->fetch_assoc();
        $history = $db->execute_query('SELECT * FROM posts_logs WHERE post_id=? ORDER BY id', [$id])->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['order' => $order, 'history' => $history], JSON_THROW_ON_ERROR);
        return;
    }
    if ($action === 'activity-count') {
        echo $db->query('SELECT COUNT(*) FROM users_logs')->fetch_row()[0];
    } elseif ($action === 'snapshot') {
        $rows = $db->query('SELECT * FROM posts ORDER BY id')->fetch_all(MYSQLI_ASSOC);
        echo hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    } elseif ($action === 'check') {
        if ((int) $db->query('SELECT COUNT(*) FROM users_logs WHERE user_id = 1')->fetch_row()[0] < 2
            || (int) $db->query('SELECT COUNT(*) FROM posts')->fetch_row()[0] !== ($replies ? 138 : 134)
            || $db->query('SELECT last_seen FROM users WHERE id = 1')->fetch_row()[0] === null) {
            throw new RuntimeException('Unexpected fixture activity or Work Order count.');
        }
        echo 'MySQL activity recorded and fixture Work Order row count preserved';
    } else {
        throw new InvalidArgumentException('Unknown fixture action.');
    }
}

try {
    runFixture($argv[1], $argv[2], $argv[3] ?? null);
} catch (Throwable $error) {
    fwrite(STDERR, 'Isolated MySQL fixture action failed (code ' . $error->getCode() . ").\n");
    exit(1);
}
