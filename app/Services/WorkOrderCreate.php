<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use DomainException;
use RuntimeException;
use Throwable;

final class WorkOrderCreate
{
    public const DESTINATIONS = ['author', 'author1', 'author2'];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function writeBlocker(): ?string
    {
        if (env('ewo.workOrderCreateWrites', false) !== true) {
            return 'Creating work orders is disabled in this environment.';
        }
        if ($this->db->DBDriver !== 'MySQLi') {
            return 'Create Work Order requires the verified MySQL schema.';
        }
        $rows = $this->db->query("SELECT t.TABLE_NAME AS table_name, t.ENGINE AS engine,
                c.COLUMN_TYPE AS column_type, c.IS_NULLABLE AS nullable, c.COLUMN_DEFAULT AS default_value, c.EXTRA AS extra
            FROM information_schema.TABLES t LEFT JOIN information_schema.COLUMNS c
                ON c.TABLE_SCHEMA=t.TABLE_SCHEMA AND c.TABLE_NAME=t.TABLE_NAME AND c.COLUMN_NAME='published_at'
            WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME IN ('posts','posts_logs','post_images')")->getResultArray();
        if (count($rows) !== 3) {
            return 'Create Work Order requires posts, posts_logs and post_images.';
        }
        foreach ($rows as $row) {
            if ($row['engine'] !== 'InnoDB') {
                return 'Create Work Order requires transactional tables.';
            }
            if ($row['table_name'] !== 'post_images' && ($row['column_type'] !== 'datetime'
                || $row['nullable'] !== 'YES' || $row['default_value'] !== null || $row['extra'] !== '')) {
                return 'Create Work Order requires nullable DATETIME publication columns with a NULL default.';
            }
        }

        return null;
    }

    public function create(array $viewer, array $settings, array $input): int
    {
        $admin = $viewer['role'] === 'admin';
        $corp = $this->id($admin ? ($input['corp_id'] ?? null) : $viewer['corp_id'], 'corporation');
        $dept = $this->id($admin ? ($input['dept_id'] ?? null) : $viewer['dept_id'], 'department');
        $location = $this->id($input['location_id'] ?? null, 'location');
        $sublocation = $this->id($input['sublocation_id'] ?? 0, 'sublocation', 0);
        $role = $this->id($input['role_id'] ?? null, 'destination role');
        $status = $input['status'] ?? null;
        if (!in_array($status, ['0', '1'], true)) {
            throw new DomainException('Choose Draft or Publish.', 422);
        }
        foreach (['scheduled_post','date_published','intervale','published_at','send_post_to_subscribes'] as $unsupported) {
            if (array_key_exists($unsupported, $input)) {
                throw new DomainException('Scheduled creation and subscriber notifications are unavailable.', 422);
            }
        }

        $entry = [
            'lang_id' => 1, 'visibility' => 1, 'post_type' => 'post', 'category_id' => 1,
            'title' => $this->text($input['title'] ?? null, 'title', 500, true),
            'summary' => array_key_exists('summary', $input) ? $this->text($input['summary'], 'description', 5000) : null,
            'image_description' => $this->text($input['image_description'] ?? '', 'image description', 500),
            'corp_id' => $corp, 'dept_id' => $dept, 'user_id' => (int) $viewer['id'], 'role_id' => $role,
            'location_id' => $location, 'sublocation_id' => $sublocation, 'status' => (int) $status,
            'is_scheduled' => 0, 'published_at' => null,
            'image_big' => '', 'image_default' => '', 'image_slider' => '', 'image_mid' => '',
            'image_small' => '', 'image_mime' => 'jpg', 'image_url' => '',
        ];
        $main = $input['post_image_id'] ?? '';
        $main = $main === '' ? null : $this->id($main, 'main image');
        $additional = $input['additional_post_image_id'] ?? [];
        if (!is_array($additional) || !array_is_list($additional) || count($additional) > 50) {
            throw new DomainException('Select up to 50 additional images.', 422);
        }
        $additional = array_unique(array_map(fn ($id) => $this->id($id, 'additional image'), $additional));

        $this->db->transException(true);
        if (!$this->db->transBegin()) {
            throw new RuntimeException('Could not begin Work Order creation.');
        }
        try {
            $this->requireChoice('corps', ['id' => $corp], 'corporation');
            $this->requireChoice('departments', ['id' => $dept, 'corp_id' => $corp], 'department');
            $this->requireChoice('locations', ['id' => $location, 'corp_id' => $corp, 'parent_id' => 0], 'location');
            if ($sublocation !== 0) {
                $this->requireChoice('locations', ['id' => $sublocation, 'corp_id' => $corp, 'parent_id' => $location], 'sublocation');
            }
            $this->requireChoice('categories', ['id' => 1, 'parent_id' => 0], 'New category');
            $destination = $this->requireChoice('roles_permissions', ['id' => $role], 'destination role');
            if (!in_array($destination['role'], self::DESTINATIONS, true)) {
                throw new DomainException('Select a valid destination role.', 422);
            }

            if ($main !== null) {
                $image = $this->image($main, $viewer, $settings, $corp);
                $entry = array_replace($entry, array_intersect_key($image, array_flip([
                    'image_big', 'image_default', 'image_slider', 'image_mid', 'image_small', 'image_mime',
                ])));
            }
            $links = [];
            foreach ($additional as $id) {
                $image = $this->image($id, $viewer, $settings, $corp);
                $links[] = ['image_big' => $image['image_big'], 'image_default' => $image['image_default']];
            }

            if (!$this->db->table('posts')->insert($entry)) {
                throw new RuntimeException('Could not insert Work Order.');
            }
            $id = (int) $this->db->insertID();
            $created = $this->db->table('posts')->select('created_at')->where('id', $id)->get()->getRowArray();
            if ($id < 1 || $created === null || !$this->db->table('posts_logs')->insert($entry + ['post_id' => $id] + $created)) {
                throw new RuntimeException('Could not insert initial Work Order history.');
            }
            foreach ($links as $link) {
                if (!$this->db->table('post_images')->insert($link + ['post_id' => $id])) {
                    throw new RuntimeException('Could not insert Work Order image relationship.');
                }
            }
            if (!$this->db->transStatus() || !$this->db->transCommit()) {
                throw new RuntimeException('Could not commit Work Order.');
            }
            return $id;
        } catch (Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
    }

    private function id(mixed $value, string $field, int $minimum = 1): int
    {
        $id = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $minimum, 'max_range' => 2147483647]]) : false;
        if ($id === false || is_bool($value) || is_float($value)) {
            throw new DomainException('Select a valid ' . $field . '.', 422);
        }
        return $id;
    }

    private function text(mixed $value, string $field, int $max, bool $required = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max
            || ($required && trim($value) === '') || strip_tags($value) !== $value
            || preg_match('/[\x{10000}-\x{10FFFF}\x00]/u', $value) === 1) {
            throw new DomainException('Enter a valid plain-text ' . $field . ' of at most ' . $max . ' characters.', 422);
        }
        return $field === 'title' ? trim($value) : $value;
    }

    private function requireChoice(string $table, array $criteria, string $field): array
    {
        $query = $this->db->table($table)->where($criteria);
        $row = $this->db->query($query->getCompiledSelect() . ' FOR SHARE')->getRowArray();
        if ($row === null) {
            throw new DomainException('Select a valid ' . $field . ' within your Work Order scope.', 422);
        }
        return $row;
    }

    private function image(int $id, array $viewer, array $settings, int $corp): array
    {
        $row = $this->requireChoice('images', ['id' => $id], 'image');
        if ((int) $row['user_id'] !== (int) $viewer['id']) {
            if ((int) ($settings['file_manager_show_files'] ?? 0) !== 1) {
                throw new DomainException('Select an image available in your Work Order scope.', 422);
            }
            $this->requireChoice('users', ['id' => $row['user_id'], 'corp_id' => $corp], 'image owner');
        } elseif ((int) $viewer['corp_id'] !== $corp) {
            throw new DomainException('Select an image from the selected corporation.', 422);
        }
        if (empty($row['image_default'])) {
            throw new DomainException('Select an image with an available reference.', 422);
        }
        return $row;
    }
}
