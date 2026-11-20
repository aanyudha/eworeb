<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use DomainException;

final class WorkOrderList
{
    private const ACTIVE = ['p.visibility' => 1, 'p.status' => 1, 'p.is_scheduled' => 0];
    private const SCHEDULED = ['p.status' => 1, 'p.is_scheduled' => 1];
    private const FILTERS = [
        'lang_id' => 'lang_id', 'post_type' => 'post_type', 'category' => 'category_id', 'user' => 'user_id',
    ];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function page(array $viewer, string $direction, array $filters, int $page, int $pageSize, bool $report = false): array
    {
        if (!(new DashboardRepository($this->db))->supports($viewer)) {
            throw new DomainException('Work order scope is unavailable.');
        }

        $query = $this->db->table('posts p');
        if ($direction === 'scheduled') {
            $query->where(self::SCHEDULED);
            $this->applyScheduleScope($query, $viewer);
        } else {
            if ($direction === 'drafts') {
                $query->where('p.status !=', 1);
            } else {
                $query->where(self::ACTIVE);
            }
            $this->applyDirectionalScope($query, $viewer, $filters, $direction === 'incoming');
        }


        foreach (self::FILTERS as $name => $column) {
            if (!empty($filters[$name])) {
                $query->where('p.' . $column, $filters[$name]);
            }
        }
        if (!empty($filters['q'])) {
            $query->like('p.title', $filters['q']);
        }
        if (isset($filters['dateChoice'], $filters['tglAwal'], $filters['tglAkhir'])) {
            $dateColumn = 'p.' . $filters['dateChoice'];
            $query->where($dateColumn . ' >=', $filters['tglAwal'])
                ->where($dateColumn . ' <=', $filters['tglAkhir'] . ' 23:59:59.999999');
        }

        $total = (int) $query->countAllResults(false);
        $query->select('p.id, p.title, p.summary, p.category_id, p.corp_id, p.dept_id, p.role_id, p.user_id, p.created_at, p.edited_at');
        $subcategoryAvailable = !$report || in_array('subcategory_id', $this->db->getFieldNames('posts'), true);
        if ($report) {
            $this->addReportColumns($query, $subcategoryAvailable);
        }
        if ($direction === 'drafts') {
            $query->select('p.is_scheduled');
            if ($this->missingColumns('scheduled') === []) {
                $query->select('p.published_at, p.intervale');
            }
        }
        if ($direction === 'scheduled') {
            $query->select('p.intervale, p.published_at');
            $this->addScheduleLabels($query);
        } elseif ($direction !== 'drafts') {
            $query->orderBy('p.category_id', 'ASC');
        }
        $rows = $query->orderBy('p.created_at', 'DESC')->orderBy('p.id', 'DESC')
            ->get($pageSize, ($page - 1) * $pageSize)->getResultArray();

        foreach ($rows as &$row) {
            if ($report) {
                $this->formatReportRow($row);
            }
            foreach (['id', 'category_id', 'corp_id', 'dept_id', 'role_id', 'user_id'] as $field) {
                $row[$field] = $row[$field] === null ? null : (int) $row[$field];
            }
        }
        unset($row);

        $result = ['items' => $rows, 'page' => $page, 'pageSize' => $pageSize, 'total' => $total];
        if ($report) {
            $result['subcategory_available'] = $subcategoryAvailable;
        }
        return $result;
    }

    public function missingColumns(string $direction, bool $report = false): array
    {
        $required = !$report && $direction === 'scheduled' ? ['intervale', 'published_at'] : [];
        return $required === [] ? [] : array_values(array_diff($required, $this->db->getFieldNames('posts')));
    }

    public function detail(array $viewer, string $direction, int $id, int $page): ?array
    {
        if (!in_array($direction, ['incoming', 'outgoing', 'drafts', 'scheduled'], true)
            || !(new DashboardRepository($this->db))->supports($viewer)) {
            throw new DomainException('Work order scope is unavailable.');
        }

        $query = $this->db->table('posts p')
            ->select('p.id, p.title, p.summary, p.category_id, p.role_id, p.corp_id, p.dept_id, p.user_id,
                p.assign_to, p.created_at, p.edited_at, category.name AS category, location.name AS location,
                sublocation.name AS sublocation, department.name AS department')
            ->join('categories category', 'category.id = p.category_id', 'left')
            ->join('locations location', 'location.id = p.location_id AND location.corp_id = p.corp_id', 'left')
            ->join('locations sublocation', 'sublocation.id = p.sublocation_id AND sublocation.corp_id = p.corp_id', 'left')
            ->join('departments department', 'department.id = p.dept_id AND department.corp_id = p.corp_id', 'left')
            ->where('p.id', $id);
        $this->applyDetailScope($query, $viewer, $direction);
        if ($direction === 'scheduled') {
            $query->select('p.intervale, p.published_at');
            $this->addScheduleLabels($query, true);
        }
        $post = $query->get()->getRowArray();
        if ($post === null) {
            return null;
        }

        $history = $this->db->table('posts_logs log')->where(['log.post_id' => $id, 'log.corp_id' => $post['corp_id']]);
        $includesInitialEntry = (clone $history)->where(['log.assign_to' => null, 'log.edited_by' => null,
            'log.edited_at' => null])->countAllResults() > 0;
        $history->join('categories category', 'category.id = log.category_id')
            ->join('users editor', 'editor.id = log.edited_by', $includesInitialEntry || $direction === 'scheduled' ? 'left' : 'inner')
            ->join('users assignee', 'assignee.id = log.assign_to AND assignee.corp_id = log.corp_id', 'left');
        $size = 25;
        $total = $history->countAllResults(false);
        $editor = (int)$viewer['role_id'] === 1
            ? 'editor.first_name AS editor_first, editor.last_name AS editor_last'
            : 'CASE WHEN editor.corp_id = log.corp_id THEN editor.first_name END AS editor_first,
                CASE WHEN editor.corp_id = log.corp_id THEN editor.last_name END AS editor_last';
        if ($direction === 'scheduled' && $this->db->fieldExists('intervale', 'posts_logs')
            && $this->db->fieldExists('published_at', 'posts_logs')) {
            $history->select('log.intervale, log.published_at');
        }
        $rows = $history->select('log.id, log.summary, log.created_at, log.edited_at, category.name AS category,

            assignee.first_name AS assignee_first, assignee.last_name AS assignee_last')->select($editor, false)
            ->orderBy('log.edited_at', 'DESC')->orderBy('log.id', 'DESC')
            ->get($size, ($page - 1) * $size)->getResultArray();

        return ['item' => $post, 'history' => ['items' => $rows, 'total' => $total, 'page' => $page, 'pageSize' => $size]];
    }


    public function scopedActive(array $viewer, string $direction, int $id, bool $lock = false): ?array
    {
        if (!in_array($direction, ['incoming', 'outgoing', 'drafts', 'scheduled'], true) || !(new DashboardRepository($this->db))->supports($viewer)) {
            throw new DomainException('Work order scope is unavailable.', 403);
        }
        $query = $this->db->table('posts p')->where('p.id', $id);
        $this->applyDetailScope($query, $viewer, $direction);
        return $lock
            ? $this->db->query($query->getCompiledSelect() . ' FOR UPDATE')->getRowArray()
            : $query->get()->getRowArray();
    }

    public function publishScheduled(array $viewer, int $id): bool
    {
        if (!(new DashboardRepository($this->db))->supports($viewer)) {
            throw new DomainException('Work order scope is unavailable.', 403);
        }
        $query = $this->db->table('posts p')->where(self::SCHEDULED)->where('p.id', $id)
            ->where('p.published_at IS NOT NULL')->where('p.intervale >', 0);
        $this->applyScheduleScope($query, $viewer);
        return $query->update(['is_scheduled' => 0]) && $this->db->affectedRows() === 1;
    }

    private function applyDetailScope(BaseBuilder $query, array $viewer, string $direction): void
    {
        if ($direction === 'scheduled') {
            $query->where(self::SCHEDULED);
            $this->applyScheduleScope($query, $viewer);
        } elseif ($direction === 'drafts') {
            $query->where('p.status !=', 1);
            $this->applyDirectionalScope($query, $viewer, [], false);
        } else {
            $query->where(self::ACTIVE);
            $this->applyDirectionalScope($query, $viewer, [], $direction === 'incoming');
        }
    }

    private function addScheduleLabels(BaseBuilder $query, bool $detail = false): void
    {
        $query->select('schedule_corp.name AS corporation, schedule_role.role_name AS destination_role')
            ->select('ABS(TIMESTAMPDIFF(DAY, CURRENT_DATE, p.published_at)) AS days_remaining', false)
            ->join('corps schedule_corp', 'schedule_corp.id = p.corp_id', 'left')
            ->join('roles_permissions schedule_role', 'schedule_role.id = p.role_id', 'left');
        if (!$detail) {
            $query->select('schedule_dept.name AS department')
                ->join('departments schedule_dept', 'schedule_dept.id = p.dept_id AND schedule_dept.corp_id = p.corp_id', 'left');
        }
    }


    public function publishDraft(array $viewer, int $id, bool $canSchedule): bool
    {
        if (!(new DashboardRepository($this->db))->supports($viewer)) {
            throw new DomainException('Work order scope is unavailable.', 403);
        }

        $query = $this->db->table('posts p')->where('p.id', $id)->where('p.status !=', 1)->groupStart()
            ->where('p.is_scheduled', 0);
        if ($canSchedule && $this->missingColumns('scheduled') === []) {
            $query->orGroupStart()->where('p.is_scheduled', 1)
                ->where("p.published_at >= '1000-01-01 00:00:00' AND p.intervale > 0
                    AND DATE_ADD(p.published_at, INTERVAL p.intervale DAY) IS NOT NULL", null, false)->groupEnd();
        }
        $query->groupEnd();
        $this->applyDirectionalScope($query, $viewer, [], false);
        if (!$query->update(['status' => 1])) {
            throw new \RuntimeException('Could not publish the draft.');
        }
        return $this->db->affectedRows() === 1;
    }

    private function applyDirectionalScope(BaseBuilder $query, array $viewer, array $filters, bool $incoming): void
    {
        $role = (int) $viewer['role_id'];
        if ($role !== 1) {
            $query->where('p.corp_id', $viewer['corp_id']);
        } elseif (!empty($filters['corpnya'])) {
            $query->where('p.corp_id', $filters['corpnya']);
        }

        if (in_array($role, [1, 7, 8], true)) {
            $scopeFilter = $incoming ? 'role' : 'department';
            if (!empty($filters[$scopeFilter])) {
                $query->where($incoming ? 'p.role_id' : 'p.dept_id', $filters[$scopeFilter]);
            }
        } elseif ($incoming) {
            $query->where('p.role_id', $role);
            if ($role === 4) {
                $query->where(['p.user_id' => $viewer['id'], 'p.dept_id' => $viewer['dept_id']]);
            }
        } else {
            $query->where(['p.dept_id' => $viewer['dept_id'], 'p.role_id <>' => $role]);
        }
    }

    private function applyScheduleScope(BaseBuilder $query, array $viewer): void
    {
        $role = (int) $viewer['role_id'];
        if ($role === 7 || $role === 8 || (int) $viewer['id'] !== 1) {
            $query->where('p.corp_id', $viewer['corp_id']);
        }
        if ($role !== 7 && (int) $viewer['id'] !== 1) {
            if (!isset($viewer['dept_id'])) {
                throw new DomainException('Scheduled Work Order scope is unavailable.');
            }
            $query->groupStart()->where('p.user_id', $viewer['id'])->orWhere('p.role_id', $role)
                ->orWhere('p.dept_id', $viewer['dept_id'])->groupEnd();
        }
    }


    private function addReportColumns(BaseBuilder $query, bool $subcategoryAvailable): void
    {
        if ($subcategoryAvailable) {
            $query->select('child_category.name AS subcategory_name')
                ->join('categories child_category', 'child_category.id = p.subcategory_id', 'left');
        } else {
            $query->select('NULL AS subcategory_name', false);
        }
        $query->select('category.name AS category_name')
            ->select('location.name AS location_name, child_location.name AS sublocation_name, department.name AS department_name')
            ->select('author.first_name AS author_first, author.last_name AS author_last, author.role AS author_role')
            ->select('assignee.first_name AS assigned_first, assignee.last_name AS assigned_last, assigned_role.role_name AS assigned_role_name')
            ->select("CASE WHEN p.image_small IS NULL OR p.image_small = '' THEN 0 ELSE 1 END AS has_image", false)
            ->join('categories category', 'category.id = p.category_id', 'left')
            ->join('locations location', 'location.id = p.location_id AND location.corp_id = p.corp_id', 'left')
            ->join('locations child_location', 'child_location.id = p.sublocation_id AND child_location.corp_id = p.corp_id', 'left')
            ->join('departments department', 'department.id = p.dept_id AND department.corp_id = p.corp_id', 'left')
            ->join('users author', 'author.id = p.user_id AND author.corp_id = p.corp_id', 'left')
            ->join('users assignee', 'assignee.id = p.assign_to AND assignee.corp_id = p.corp_id', 'left')
            ->join('roles_permissions assigned_role', 'assigned_role.id = p.role_id', 'left');
    }

    private function formatReportRow(array &$row): void
    {
        $row['has_image'] = (int) $row['has_image'] === 1;
        foreach (['created_at', 'edited_at'] as $column) {
            if ($row[$column] !== null && ($timestamp = strtotime($row[$column])) !== false) {
                $row[$column] = date('Y-m-d / h:i', $timestamp);
            }
        }
    }
}
