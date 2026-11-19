<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Config\Paths;
use mysqli;
use SQLite3;
use Throwable;

final class EwoDiagnose extends BaseCommand
{
    protected $group = 'EWO';
    protected $name = 'ewo:diagnose';
    protected $description = 'Inspect effective local EWO database settings and schema without writing data.';
    protected $options = ['--inventory' => 'Also compare known EWO schemas on the configured local default group.'];

    private const REQUIRED = [
        'users' => ['id', 'email', 'password', 'role', 'role_id', 'corp_id', 'dept_id', 'status', 'last_seen', 'ip_address'],
        'roles_permissions' => ['role', 'admin_panel', 'add_post'],
        'users_logs' => ['user_id', 'last_seen', 'ip_address'],
        'general_settings' => ['id', 'timezone', 'site_lang'],
        'settings' => ['lang_id', 'site_title', 'application_name'],
        'posts' => ['category_id', 'role_id', 'corp_id', 'dept_id', 'user_id', 'visibility', 'status', 'is_scheduled'],
        'corps' => ['id', 'name', 'corp_order'],
    ];

    public function run(array $params): int
    {
        if (PHP_SAPI !== 'cli' || !in_array(ENVIRONMENT, ['development', 'testing'], true)) {
            CLI::error('This diagnostic requires the development or testing CLI.');
            return EXIT_ERROR;
        }

        $database = config(Database::class);
        $group = $database->ewo;
        $key = env('ewo.sessionKey');
        $keyPresent = is_string($key) && $key !== '';
        $keyFormat = $keyPresent && preg_match('/\A[0-9a-f]{64}\z/i', $key) === 1;
        $dotenv = (new Paths())->envDirectory . '/.env';
        CLI::write('PHP ' . PHP_VERSION . ' (' . PHP_SAPI . '), environment: ' . ENVIRONMENT);
        CLI::write('Project .env: ' . (is_readable($dotenv) ? 'readable' : 'unavailable'));
        CLI::write('ewo.sessionKey: ' . ($keyFormat ? '64-character hexadecimal format' : ($keyPresent ? 'set, format differs from generated key' : 'missing')));
        foreach (['hostname','port','database','username','password','DBDriver'] as $name) {
            $value = $group[$name] ?? null;
            CLI::write('database.ewo.' . $name . ': ' . ($value !== null && $value !== '' ? 'set' : 'empty'));
        }

        $schema = $group['database'] ?? '';
        $user = $group['username'] ?? '';
        $ready = false;
        if (!$schema || !$user) {
            CLI::error('EWO connection group is incomplete. Set database.ewo.database and database.ewo.username.');
            if (!empty($database->default['database'])) {
                CLI::write('database.default is populated. Authentication uses database.ewo exclusively.');
            }
        } else {
            $schemas = $this->inspect($group, [$schema]);
            $ready = $schemas !== null && $this->reportSchema('database.ewo', $schemas[$schema] ?? []);
        }

        if (CLI::getOption('inventory') !== null) {
            $names = array_values(array_unique(array_filter([$database->default['database'] ?? '', 'eworeb', 'ewohotel_tentrem'])));
            CLI::write('Default group inventory (read-only, never used for authentication):');
            $schemas = $this->inspect($database->default, $names);
            if ($schemas !== null) {
                foreach ($names as $name) {
                    $label = in_array($name, ['eworeb', 'ewohotel_tentrem'], true) ? $name : 'configured default schema';
                    $this->reportSchema($label, $schemas[$name] ?? []);
                }
            }
        }

        if (!$keyPresent) {
            CLI::error('Authentication requires ewo.sessionKey.');
        }
        return $ready && $keyPresent ? EXIT_SUCCESS : EXIT_ERROR;
    }

    private function inspect(array $group, array $schemas): ?array
    {
        try {
            if (($group['DBDriver'] ?? '') === 'SQLite3') {
                $path = (string) ($group['database'] ?? '');
                if (!extension_loaded('sqlite3') || !is_file($path)) {
                    CLI::error('SQLite3 fixture file or extension is unavailable.');
                    return null;
                }
                $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
                CLI::write('Read-only SQLite connection: OK');
                $result = [];
                foreach (array_keys(self::REQUIRED) as $table) {
                    $rows = $db->query('PRAGMA table_info(' . $table . ')');
                    while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
                        $result[$schemas[0]][$table][] = $row['name'];
                    }
                }
                $db->close();
                return $result;
            }

            if (($group['DBDriver'] ?? '') !== 'MySQLi' || !extension_loaded('mysqli')) {
                CLI::error('MySQLi driver or PHP extension is unavailable.');
                return null;
            }
            $host = $group['hostname'] ?? '';
            $port = filter_var($group['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true) || $port === false) {
                CLI::error('Connection probe requires a loopback hostname and valid TCP port.');
                return null;
            }

            $db = mysqli_init();
            $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 4);
            $db->real_connect($host === 'localhost' ? '127.0.0.1' : $host, $group['username'] ?? '', $group['password'] ?? '', null, $port);
            $db->query('START TRANSACTION READ ONLY');
            CLI::write('Read-only MySQL connection: OK (TCP ' . $port . ')');
            $statement = $db->prepare('SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?');
            $statement->bind_param('s', $schema);
            $result = [];
            foreach ($schemas as $schema) {
                $statement->execute();
                $rows = $statement->get_result();
                while ($row = $rows->fetch_assoc()) {
                    $result[$schema][$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
                }
                $rows->free();
            }
            $db->rollback();
            $db->close();
            return $result;
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            $reason = match ($code) {
                1044, 1045 => 'database credentials or privileges rejected',
                2002, 2003 => 'local server unreachable on the configured host and port',
                default => 'read-only connection or metadata query failed',
            };
            CLI::error('Database probe failed: ' . $reason . ' (code ' . $code . ').');
            return null;
        }
    }

    private function reportSchema(string $label, array $tables): bool
    {
        $ready = true;
        CLI::write($label . ' schema:');
        foreach (self::REQUIRED as $table => $columns) {
            $missing = array_diff($columns, $tables[$table] ?? []);
            $ready = $ready && $missing === [];
            CLI::write('  ' . $table . ': ' . (!isset($tables[$table]) ? 'missing or not accessible'
                : ($missing === [] ? 'compatible' : 'missing ' . implode(', ', $missing))));
        }
        return $ready;
    }
}
