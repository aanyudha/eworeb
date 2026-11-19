<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[2])) {
    exit(1);
}

function runFixture(string $sqlitePath, string $action): void
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
    $db = new mysqli('127.0.0.1', $initializer ? 'root' : 'ewo_fixture',
        $initializer ? $settings['rootPassword'] : $settings['password'], '', $port);
    if (realpath($db->query('SELECT @@datadir')->fetch_row()[0]) !== $dataDirectory) {
        throw new RuntimeException('MySQL fixture server identity mismatch.');
    }
    $db->set_charset('utf8mb4');

    if ($initializer) {
        $db->query('CREATE DATABASE ewo_fixture CHARACTER SET utf8mb4');
        $db->select_db('ewo_fixture');
        $ddl = trim(file_get_contents(__DIR__ . '/mysql-fixture.sql'), ";\r\n");
        foreach (explode(';', $ddl) as $statement) {
            if (trim($statement) !== '') {
                $db->query($statement);
            }
        }
        $sqlite = new SQLite3($sqlitePath, SQLITE3_OPEN_READONLY);
        foreach (['users', 'roles_permissions', 'users_logs', 'general_settings', 'settings', 'posts', 'corps'] as $table) {
            $rows = $sqlite->query("SELECT * FROM $table");
            $fixtureFields = match ($table) {
                'posts' => '(id,category_id,role_id,corp_id,dept_id,user_id,visibility,status,is_scheduled)',
                'users' => '(id,email,password,status,role,role_id,corp_id,dept_id,last_seen,ip_address)',
                'roles_permissions' => '(role,admin_panel,add_post)',
                default => '',
            };
            while ($row = $rows->fetchArray(SQLITE3_NUM)) {
                $parameters = implode(',', array_fill(0, count($row), '?'));

                $db->execute_query("INSERT INTO $table $fixtureFields VALUES ($parameters)", $row);
            }
        }

        $db->query("UPDATE users SET username = SUBSTRING_INDEX(email, '@', 1), first_name = 'Fixture'");
        $db->query("UPDATE roles_permissions SET categories=1, locations=1, departments=1, users=1 WHERE role IN ('engineering','gm')");
        $db->query("UPDATE roles_permissions SET manage_all_posts=1 WHERE role='corpadmin'");
        $fixturePassword = $settings['password'];
        if (!preg_match('/^[a-f0-9]{48}$/D', $fixturePassword)) {
            throw new RuntimeException('Invalid generated fixture password.');
        }
        $db->query("CREATE USER 'ewo_fixture'@'localhost' IDENTIFIED BY '$fixturePassword'");
        $db->query("GRANT SELECT ON ewo_fixture.* TO 'ewo_fixture'@'localhost'");
        $db->query("GRANT UPDATE (last_seen, ip_address) ON ewo_fixture.users TO 'ewo_fixture'@'localhost'");
        $db->query("GRANT INSERT ON ewo_fixture.users_logs TO 'ewo_fixture'@'localhost'");
        return;
    }

    $db->select_db('ewo_fixture');
    if ($action === 'activity-count') {
        echo $db->query('SELECT COUNT(*) FROM users_logs')->fetch_row()[0];
    } elseif ($action === 'snapshot') {
        $rows = $db->query('SELECT * FROM posts ORDER BY id')->fetch_all(MYSQLI_ASSOC);
        echo hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    } elseif ($action === 'check') {
        if ((int) $db->query('SELECT COUNT(*) FROM users_logs WHERE user_id = 1')->fetch_row()[0] < 2
            || (int) $db->query('SELECT COUNT(*) FROM posts')->fetch_row()[0] !== 134
            || $db->query('SELECT last_seen FROM users WHERE id = 1')->fetch_row()[0] === null) {
            throw new RuntimeException('Unexpected fixture activity or Work Order count.');
        }
        echo 'MySQL activity recorded and fixture Work Order row count preserved';
    } else {
        throw new InvalidArgumentException('Unknown fixture action.');
    }
}

try {
    runFixture($argv[1], $argv[2]);
} catch (Throwable $error) {
    fwrite(STDERR, 'Isolated MySQL fixture action failed (code ' . $error->getCode() . ").\n");
    exit(1);
}
