<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

final class DashboardRepository
{
    private const CATEGORIES = ['New' => 1, 'Pending' => 5, 'Waiting' => 3, 'Progress' => 2, 'Closed' => 4];
    private const DEPARTMENTS = ['Engineering' => 3, 'IT' => 5, 'Housekeeping' => 6];

    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function supports(array $user): bool
    {
        $role = (int) $user['role_id'];
        if ($role === 1 || $user['role'] === 'admin') {
            return $role === 1 && $user['role'] === 'admin';
        }
        if (in_array($role, [7, 8], true)) {
            return isset($user['corp_id']);
        }

        return in_array($role, [3, 4, 5, 6], true) && isset($user['corp_id'], $user['dept_id']);
    }

    public function summary(array $user, array $filters): array
    {
        if (!$this->supports($user)) {
            throw new \DomainException('Dashboard scope is undefined for this user.');
        }

        $role = (int) $user['role_id'];
        if (in_array($role, [7, 8], true)) {
            return ['recap' => $this->departmentRecap($user['corp_id'])];
        }

        $incoming = $this->posts();
        $outgoing = $this->posts();
        if ($role === 1) {
            $this->applyFilter($incoming, 'corp_id', $filters['corpnya']);
            $this->applyFilter($outgoing, 'corp_id', $filters['corpnya']);
            $this->applyFilter($incoming, 'role_id', $filters['role']);
            $this->applyFilter($outgoing, 'dept_id', $filters['department']);
        } else {
            $incoming->where(['corp_id' => $user['corp_id'], 'role_id' => $role]);
            if ($role === 4) {
                $incoming->where(['user_id' => $user['id'], 'dept_id' => $user['dept_id']]);
            }
            $outgoing->where(['corp_id' => $user['corp_id'], 'dept_id' => $user['dept_id'], 'role_id <>' => $role]);
        }

        return ['incoming' => $this->statusCounts($incoming), 'outgoing' => $this->statusCounts($outgoing)];
    }

    public function corporations(): array
    {
        return $this->database->table('corps')->select('id, name')->orderBy('corp_order')->get()->getResultArray();
    }

    private function posts(): BaseBuilder
    {
        return $this->database->table('posts')
            ->where(['visibility' => 1, 'status' => 1, 'is_scheduled' => 0])
            ->whereIn('category_id', array_values(self::CATEGORIES));
    }

    private function applyFilter(BaseBuilder $query, string $column, ?string $value): void
    {
        if (!empty($value)) {
            $query->where($column, $value);
        }
    }

    private function statusCounts(BaseBuilder $query): array
    {
        $rows = $query->select('category_id, COUNT(*) AS total')->groupBy('category_id')->get()->getResultArray();
        $byCategory = array_column($rows, 'total', 'category_id');

        return array_map(static fn (int $category): int => (int) ($byCategory[$category] ?? 0), self::CATEGORIES);
    }

    private function departmentRecap(int|string $corporation): array
    {
        $rows = $this->posts()->where('corp_id', $corporation)->whereIn('role_id', array_values(self::DEPARTMENTS))
            ->select('role_id, category_id, COUNT(*) AS total')->groupBy(['role_id', 'category_id'])->get()->getResultArray();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['role_id']][$row['category_id']] = (int) $row['total'];
        }

        $recap = [];
        foreach (self::DEPARTMENTS as $department => $role) {
            $recap[$department] = array_map(static fn (int $category): int => $counts[$role][$category] ?? 0, self::CATEGORIES);
        }

        // CI3 get_posts_count_waiting_it() uses Engineering role ID 3.
        $recap['IT']['Waiting'] = $recap['Engineering']['Waiting'];

        return $recap;
    }
}
