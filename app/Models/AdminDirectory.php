<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\AdminIdentity;
use CodeIgniter\Database\BaseConnection;

final class AdminDirectory
{
    public const ROLE_EDIT_FIELDS = ['admin_panel', 'add_post', 'manage_all_posts', 'navigation', 'categories', 'users', 'settings'];

    public const VIEWS = [
        'categories' => ['categories', 'categories', 'Categories'],
        'subcategories' => ['categories', 'categories', 'Subcategories'],
        'locations' => ['locations', 'locations', 'Locations'],
        'sublocations' => ['locations', 'locations', 'Sublocations'],
        'departments' => ['departments', 'departments', 'Departments'],
        'users' => ['users', 'users', 'Users'],
        'administrators' => ['users', 'users', 'Administrators'],
        'corporations' => ['corps', 'corps', 'Corporations'],
        'roles' => ['roles_permissions', 'administrator', 'Roles and Permissions'],
    ];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public static function permits(AdminIdentity $identity, array $viewer, string $resource): bool
    {
        if (!isset(self::VIEWS[$resource])) {
            return false;
        }
        $admin = $viewer['role'] === 'admin';
        if ($admin !== ((int) $viewer['role_id'] === 1)) {
            return false;
        }

        if ($resource === 'roles') {
            return $admin;
        }
        return $identity->allows($viewer, self::VIEWS[$resource][1])
            && ($resource !== 'administrators' || $admin);
    }

    public function page(string $resource, array $viewer, string $search, int $page): array
    {
        if ($resource === 'roles') {
            return $this->roles($search, $page);
        }
        [$table] = self::VIEWS[$resource];
        $admin = $viewer['role'] === 'admin';
        $userList = $table === 'users';
        $query = $this->db->table($table . ' record');
        if (!$admin && $table !== 'categories') {
            if (!isset($viewer['corp_id']) || !ctype_digit((string) $viewer['corp_id']) || (int) $viewer['corp_id'] <= 0) {
                throw new \DomainException('Corporation scope is unavailable.');
            }
            $query->where($table === 'corps' ? 'record.id' : 'record.corp_id', $viewer['corp_id']);
        }

        if ($userList) {
            $query->where($resource === 'administrators' ? 'record.role' : 'record.role !=', 'admin');
            if ($search !== '') {
                $query->groupStart()->like('record.username', $search)->orLike('record.first_name', $search)
                    ->orLike('record.last_name', $search)->groupEnd();
            }
        } elseif ($table === 'corps') {
            if ($search !== '') {
                $query->groupStart()->like('record.name', $search)->orLike('record.corp_code', $search)->groupEnd();
            }
        } else {
            $child = in_array($resource, ['subcategories', 'sublocations'], true);
            $query->where($child ? 'record.parent_id !=' : 'record.parent_id', 0);
            if (!$admin && $resource === 'sublocations') {
                $parents = $this->db->table('locations')->select('id')->where('corp_id', $viewer['corp_id']);
                $query->whereIn('record.parent_id', $parents);
            }
            if ($search !== '') {
                $query->like('record.name', $search);
            }
        }

        $size = 25;
        $total = (int) $query->countAllResults(false);
        if ($userList) {
            $query->select('record.id, record.username AS name, record.first_name, record.last_name, record.role, record.status, record.corp_id, record.dept_id')
                ->orderBy('record.username');
        } elseif ($table === 'corps') {
            $query->select('record.id, record.name, record.corp_code, record.status AS corporation_status')->orderBy('record.corp_order');
        } else {
            $query->select('record.id, record.name, record.parent_id, record.lang_id');
            if ($table !== 'categories') {
                $query->select('record.corp_id');
            }
            $order = ['categories' => 'category_order', 'locations' => 'location_order', 'departments' => 'department_order'];
            $query->orderBy('record.' . $order[$table]);
        }

        $items = $query->orderBy('record.id')->get($size, ($page - 1) * $size)->getResultArray();
        return ['items' => $items, 'page' => $page, 'pageSize' => $size, 'total' => $total];
    }

    public function roleOptions(int $id): array
    {
        return ['fields' => $this->editableRole($id), 'checks' => self::ROLE_EDIT_FIELDS];
    }

    public function saveRole(int $id, array $input): array
    {
        $this->editableRole($id);
        $changes = [];
        foreach (self::ROLE_EDIT_FIELDS as $field) {
            $value = $input[$field] ?? null;
            if ($value !== '0' && $value !== '1') {
                throw new \DomainException('Check the selected role permissions.', 422);
            }
            $changes[$field] = (int)$value;
        }
        if (!$this->db->table('roles_permissions')->where(['id' => $id, 'id >' => 1, 'role !=' => 'admin'])->update($changes)) {
            throw new \RuntimeException('Could not save role permissions.');
        }
        return ['id' => $id];
    }

    private function editableRole(int $id): array
    {
        $row = $this->db->table('roles_permissions')->select(self::ROLE_EDIT_FIELDS)
            ->where(['id' => $id, 'id >' => 1, 'role !=' => 'admin'])->get()->getRowArray();
        if ($row === null) {
            throw new \DomainException('Editable role not found.', 404);
        }
        return $row;
    }

    private function roles(string $search, int $page): array
    {
        $fields = ['admin_panel', 'add_post', 'manage_all_posts', 'categories', 'locations', 'departments', 'corps', 'users', 'settings'];
        $query = $this->db->table('roles_permissions');
        if ($search !== '') {
            $query->groupStart()->like('role_name', $search)->orLike('role', $search)->groupEnd();
        }
        $size = 25;
        $total = $query->countAllResults(false);
        $rows = $query->select(array_merge(['id', 'role_name AS name', 'role'], $fields))->orderBy('id')
            ->get($size, ($page - 1) * $size)->getResultArray();
        $items = array_map(static function (array $row) use ($fields): array {
            $granted = array_filter($fields, static fn (string $field) => (int) $row[$field] === 1);
            return ['id' => $row['id'], 'name' => $row['name'], 'role' => $row['role'],
                'permissions' => $row['role'] === 'admin' ? 'All permissions' : implode(', ', str_replace('_', ' ', $granted))];
        }, $rows);

        return ['items' => $items, 'page' => $page, 'pageSize' => $size, 'total' => $total];
    }

}
