<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[2])) {
    exit(1);
}
define('BASEPATH', true);
class CI_Model {}
require $argv[1] . '/application/models/Post_admin_model.php';

final class LegacyDashboardDatabase
{
    private array $conditions = [];
    private array $values = [];

    public function __construct(private readonly SQLite3 $db) {}

    public function where($field, $value = null): void
    {
        if (func_num_args() === 1) {
            // Raw predicates come only from the checked-in CI3 model with synthetic numeric user context.
            $this->conditions[] = $field;
        } else {
            $this->conditions[] = $field . ' = ?';
            $this->values[] = $value;
        }
    }

    public function get($table): object
    {
        if ($table !== 'posts') {
            throw new RuntimeException('Unexpected legacy query');
        }
        $statement = $this->db->prepare('SELECT COUNT(*) FROM posts WHERE ' . implode(' AND ', $this->conditions));
        foreach ($this->values as $i => $value) {
            $statement->bindValue($i + 1, $value);
        }
        $this->conditions = $this->values = [];
        return new class ((int) $statement->execute()->fetchArray(SQLITE3_NUM)[0]) {
            public function __construct(private readonly int $count) {}
            public function num_rows(): int { return $this->count; }
        };
    }
}

$db = new SQLite3($argv[2], SQLITE3_OPEN_READONLY);
$scenarios = [
    ['admin', ''], ['admin','corpnya=1&role=3&department=20'], ['employee','corpnya=2&department=20'],
    ['engineering',''], ['corpadmin','corpnya=2&role=5'], ['gm',''],
];
$expected = [];
foreach ($scenarios as [$account,$query]) {
    $row = $db->querySingle("SELECT id,role_id,corp_id,dept_id FROM users WHERE email = '" . $account . "@example.test'", true);
    $model = new Post_admin_model();
    $model->db = new LegacyDashboardDatabase($db);
    $model->auth_model = new class ($row) {
        public function __construct(private readonly array $row) {}
        public function get_user_id(): int { return $this->row['id']; }
        public function get_role_id_bylogin(): int { return $this->row['role_id']; }
        public function get_corp_id_by_user_login(): int { return $this->row['corp_id']; }
        public function get_dept_id_bylogin(): int { return $this->row['dept_id']; }
    };
    parse_str($query, $params);
    $model->input = new class ($params) {
        public function __construct(private readonly array $params) {}
        public function get($key, $clean): ?string { return $this->params[$key] ?? null; }
    };
    $counts = [];
    foreach (['New','Pending','Waiting','Progress','Closed'] as $label) {
        $status = strtolower($label);
        if ($account === 'corpadmin' || $account === 'gm') {
            foreach (['Engineering'=>'eng','IT'=>'it','Housekeeping'=>'hk'] as $name=>$suffix) {
                $counts['recap'][$name][$label] = $model->{'get_posts_count_'.$status.'_'.$suffix}();
            }
        } else {
            $counts['incoming'][''][$label] = $model->{'get_posts_count_'.$status}();
            $counts['outgoing'][''][$label] = $model->{'get_posts_out_count_'.$status}();
        }
    }
    $expected[] = [$account,$query,$counts];
}
echo json_encode($expected, JSON_THROW_ON_ERROR);
