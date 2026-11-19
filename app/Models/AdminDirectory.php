<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\AdminIdentity;
use CodeIgniter\Database\BaseConnection;

final class AdminDirectory
{
    public const VIEWS = [
        'categories' => ['categories', 'categories', 'Categories'],
        'subcategories' => ['categories', 'categories', 'Subcategories'],
        'locations' => ['locations', 'locations', 'Locations'],
        'sublocations' => ['locations', 'locations', 'Sublocations'],
        'departments' => ['departments', 'departments', 'Departments'],
        'users' => ['users', 'users', 'Users'],
        'administrators' => ['users', 'users', 'Administrators'],
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

        return $identity->allows($viewer, self::VIEWS[$resource][1])
            && ($resource !== 'administrators' || $admin);
    }

    public function page(string $resource, array $viewer, string $search, int $page): array
    {
        [$table] = self::VIEWS[$resource];
        $admin = $viewer['role'] === 'admin';
        $userList = $table === 'users';
        $query = $this->db->table($table . ' record');
        if (!$admin && $table !== 'categories') {
            if (!isset($viewer['corp_id']) || !ctype_digit((string) $viewer['corp_id']) || (int) $viewer['corp_id'] <= 0) {
                throw new \DomainException('Corporation scope is unavailable.');
            }
            $query->where('record.corp_id', $viewer['corp_id']);
        }

        if ($userList) {
            $query->where($resource === 'administrators' ? 'record.role' : 'record.role !=', 'admin');
            if ($search !== '') {
                $query->groupStart()->like('record.username', $search)->orLike('record.first_name', $search)
                    ->orLike('record.last_name', $search)->groupEnd();
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
}

