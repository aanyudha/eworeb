<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use DomainException;
use RuntimeException;
use Throwable;

final class MasterData
{
    private const RESOURCES = [
        'categories' => ['categories', 'category_order', false],
        'subcategories' => ['categories', 'category_order', true],
        'locations' => ['locations', 'location_order', false],
        'sublocations' => ['locations', 'location_order', true],
        'departments' => ['departments', 'department_order', false],
        'corporations' => ['corps', 'corp_order', false],
    ];

    public const FIELDS = [
        'name', 'name_slug', 'color', 'order', 'visibility', 'corp_id', 'corp_code', 'lang_id', 'parent_id',
    ];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public static function available(array $viewer, string $resource): bool
    {
        return env('ewo.masterDataWrites', false) === true && (int)$viewer['status'] === 1
            && isset(self::RESOURCES[$resource])
            && ($resource !== 'corporations' || $viewer['role'] === 'admin');
    }

    public static function protected(string $resource, int $id): bool
    {
        return $resource === 'categories' && $id >= 1 && $id <= 6;
    }

    public function options(array $viewer, string $resource, ?int $id): array
    {
        [$table, $order, $child] = self::RESOURCES[$resource];
        $row = $id === null ? [] : $this->record($viewer, $resource, $id, false);
        $fields = ['name' => $row['name'] ?? '', 'order' => $row[$order] ?? ($table === 'corps' ? 1 : 0)];
        if ($table === 'corps') {
            return ['fields' => $fields + ['corp_code' => $row['corp_code'] ?? '']];
        }
        $fields += ['name_slug' => $row['name_slug'] ?? '', 'lang_id' => $row['lang_id'] ?? 1];
        if (!$child) {
            $fields['color'] = $row['color'] ?? '';
        }
        if ($resource === 'categories') {
            $fields['visibility'] = $row['visibility'] ?? 1;
        }
        if ($child) {
            $fields['parent_id'] = $row['parent_id'] ?? '';
        }
        if ($table !== 'categories' && $viewer['role'] === 'admin') {
            $fields['corp_id'] = $row['corp_id'] ?? '';
        }
        return ['fields' => $fields];
    }

    public function save(array $viewer, string $resource, ?int $id, array $input): int
    {
        [$table, $order, $child] = self::RESOURCES[$resource];
        $data = [
            'name' => $this->label($input['name'] ?? null, 'name', 200),
            $order => $this->integer($input['order'] ?? null, 'order', $table === 'corps' ? 1 : 0,
                $table === 'corps' ? 32767 : 2147483647),
        ];
        if ($table === 'corps') {
            $data['corp_code'] = $this->label($input['corp_code'] ?? null, 'corporation code', 100);
        } else {
            helper(['url', 'text']);
            $slug = $this->label($input['name_slug'] ?? '', 'slug', 200, false);
            $data['name_slug'] = mb_url_title($slug === '' ? $data['name'] : $slug, '-', true);
            $data['lang_id'] = $this->integer($input['lang_id'] ?? null, 'language ID', 1);
            $data['parent_id'] = $child ? $this->integer($input['parent_id'] ?? null, 'parent ID', 1) : 0;
            if (!$child) {
                $data['color'] = $this->label($input['color'] ?? null, 'color', 200);
            }
            if ($resource === 'categories') {
                $data['visibility'] = $this->integer($input['visibility'] ?? null, 'visibility', 0, 2);
            }
            if ($table !== 'categories') {
                $data['corp_id'] = $this->integer($viewer['role'] === 'admin' ? ($input['corp_id'] ?? null)
                    : ($viewer['corp_id'] ?? null), 'corporation ID', 1);
            }
        }

        $this->db->transException(true);
        if (!$this->db->transBegin()) {
            throw new RuntimeException('Could not begin master-data save.');
        }
        try {
            $existing = $id === null ? null : $this->record($viewer, $resource, $id, true);
            $this->validateRelations($table, $child, $data, $existing);
            $this->persist($table, $data, $id);
            $number = $id ?? (int)$this->db->insertID();
            if ($table !== 'corps') {
                $this->finalizeSlug($table, $number, $data['name_slug']);
                if ($existing !== null && !$child && $existing['color'] !== $data['color']) {
                    $children = $this->db->table($table)->where('parent_id', $number);
                    if ($table !== 'categories') {
                        $children->where('corp_id', $data['corp_id']);
                    }
                    $this->requireWrite($children->update(['color' => $data['color']]));
                }
            }
            $this->requireWrite($this->db->transStatus() && $this->db->transCommit());
            return $number;
        } catch (Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
    }

    private function record(array $viewer, string $resource, int $id, bool $lock): array
    {
        if (self::protected($resource, $id)) {
            throw new DomainException('This category controls a Work Order status.', 409);
        }
        [$table, , $child] = self::RESOURCES[$resource];
        $query = $this->db->table($table)->where('id', $id);
        if ($table !== 'corps') {
            $query->where($child ? 'parent_id >' : 'parent_id', 0);
        }
        if ($table !== 'categories' && $viewer['role'] !== 'admin') {
            $query->where('corp_id', $this->integer($viewer['corp_id'] ?? null, 'corporation scope', 1));
            if ($child) {
                $parents = $this->db->table($table)->select('id')->where(['corp_id' => $viewer['corp_id'], 'parent_id' => 0]);
                $query->whereIn('parent_id', $parents);
            }
        }

        $row = $this->row($query, $lock);
        if ($row === null) {
            throw new DomainException('Master-data record not found.', 404);
        }
        return $row;
    }

    private function validateRelations(string $table, bool $child, array &$data, ?array $existing): void
    {
        if ($table === 'corps') {
            return;
        }
        if ($this->db->table('languages')->where('id', $data['lang_id'])->countAllResults() !== 1) {
            throw new DomainException('Language not found.', 422);
        }
        if (isset($data['corp_id'])) {
            if ($this->db->table('corps')->where('id', $data['corp_id'])->countAllResults() !== 1) {
                throw new DomainException('Corporation not found.', 422);
            }
            if ($existing !== null && (int)$existing['corp_id'] !== $data['corp_id']) {
                throw new DomainException('Changing the corporation requires a separate review of existing references.', 409);
            }
        }
        if ($existing !== null && ((int)$existing['lang_id'] !== $data['lang_id']
                || (int)$existing['parent_id'] !== $data['parent_id'])) {
            throw new DomainException('Changing language or parent requires a separate review of existing references.', 409);
        }
        if ($child) {
            $parent = $this->db->table($table)->where([
                'id' => $data['parent_id'], 'parent_id' => 0, 'lang_id' => $data['lang_id'],
            ]);
            if (isset($data['corp_id'])) {
                $parent->where('corp_id', $data['corp_id']);
            }
            $row = $this->row($parent, true);
            if ($row === null) {
                throw new DomainException('The parent must belong to the same language and corporation scope.', 422);
            }
            $data['color'] = $existing === null ? ($row['color'] ?? '#0a0a0a') : $existing['color'];
        }
    }

    private function row(BaseBuilder $query, bool $lock): ?array
    {
        return $lock ? $this->db->query($query->getCompiledSelect() . ' FOR UPDATE')->getRowArray()
            : $query->get()->getRowArray();
    }

    private function persist(string $table, array $data, ?int $id): void
    {
        $query = $this->db->table($table);
        $this->requireWrite($id === null ? $query->insert($data) : $query->where('id', $id)->update($data));
    }

    private function finalizeSlug(string $table, int $id, string $slug): void
    {
        if ($slug === '' || $slug === '-') {
            $this->persist($table, ['name_slug' => (string)$id], $id);
        } elseif ($this->db->table($table)->where(['name_slug' => $slug, 'id !=' => $id])->countAllResults() > 0) {
            $this->persist($table, ['name_slug' => $slug . '-' . $id], $id);
        }
    }

    private function requireWrite(bool $success): void
    {
        if (!$success) {
            throw new RuntimeException('Could not save master data.');
        }
    }

    private function label(mixed $value, string $name, int $limit, bool $required = true): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit
            || $value !== strip_tags($value) || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || ($required && trim($value) === '')) {
            throw new DomainException('Enter a valid ' . $name . '.', 422);
        }
        return trim($value);
    }

    private function integer(mixed $value, string $name, int $min, int $max = 2147483647): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($number === false) {
            throw new DomainException('Enter a valid ' . $name . '.', 422);
        }
        return $number;
    }
}
