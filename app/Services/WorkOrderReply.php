<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\WorkOrderList;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use RuntimeException;
use Throwable;

final class WorkOrderReply
{
    private const HISTORY_FIELDS = [
        'lang_id', 'title', 'category_id', 'location_id', 'sublocation_id', 'image_description',
        'created_at', 'dept_id', 'corp_id', 'user_id', 'role_id', 'assign_to', 'edited_by',
        'edited_at', 'visibility', 'is_scheduled',
    ];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function options(array $viewer, int $id): array
    {
        $row = $this->eligible($viewer, $id, false);
        return [
            'revision' => $this->revision($row),
            'category_id' => $row['category_id'],
            'assign_to' => $row['assign_to'],
            'categories' => $this->categoryQuery($row)->select('id, name')->orderBy('category_order')->get()->getResultArray(),
            'assignees' => $this->assigneeQuery($row)->select('id, first_name, last_name')->orderBy('id')->get()->getResultArray(),
        ];
    }

    public function reply(array $viewer, int $id, array $input): int
    {
        $category = filter_var($input['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $assignee = filter_var($input['assign_to'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $note = $input['summary_edit'] ?? null;
        $revision = $input['revision'] ?? null;
        if ($category === false || $assignee === false || !is_string($note) || !mb_check_encoding($note, 'UTF-8')
            || trim($note) === '' || mb_strlen($note) > 5000 || strip_tags($note) !== $note
            || !is_string($revision) || preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            throw new DomainException('Select a status and assignee, and enter a plain-text reply of at most 5000 characters.', 422);
        }

        $this->db->transException(true);
        if (!$this->db->transBegin()) {
            throw new RuntimeException('Could not begin Work Order reply.');
        }
        try {
            $row = $this->eligible($viewer, $id, true);
            if (!hash_equals($this->revision($row), $revision)) {
                throw new DomainException('The Work Order changed. Reload before replying.', 409);
            }
            if ($this->categoryQuery($row)->where('id', $category)->get()->getRowArray() === null
                || $this->assigneeQuery($row)->where('id', $assignee)->get()->getRowArray() === null) {
                throw new DomainException('Status or assignee is outside the available legacy choices.', 422);
            }
            $timezone = $this->db->table('general_settings')->select('timezone')->where('id', 1)->get()->getRowArray()['timezone'] ?? '';
            $now = new DateTimeImmutable('now', $timezone === '' ? null : new DateTimeZone($timezone));
            $changes = ['category_id' => $category, 'assign_to' => $assignee,
                'edited_by' => (int)$viewer['id'], 'edited_at' => $now->format('Y-m-d H:i:s')];
            $history = array_intersect_key(array_replace($row, $changes), array_flip(self::HISTORY_FIELDS));
            $history += ['post_id' => $id, 'summary' => $note];
            if (!$this->db->table('posts')->where('id', $id)->update($changes)
                || !$this->db->table('posts_logs')->insert($history)) {
                throw new RuntimeException('Could not persist Work Order and its history.');
            }
            $logId = (int)$this->db->insertID();
            if (!$this->db->transStatus() || !$this->db->transCommit()) {
                throw new RuntimeException('Could not commit Work Order reply.');
            }
            return $logId;
        } catch (Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
    }

    private function eligible(array $viewer, int $id, bool $lock): array
    {
        if ((int)($viewer['status'] ?? 0) !== 1) {
            throw new DomainException('Work Order reply permission is unavailable.', 403);
        }
        $row = (new WorkOrderList($this->db))->scopedActive($viewer, 'incoming', $id, $lock);
        if ($row === null || $row['post_type'] !== 'post') {
            throw new DomainException('Work Order not found.', 404);
        }
        if (!in_array((int)$viewer['role_id'], [1, 7, (int)$row['role_id']], true)) {
            throw new DomainException('Work Order reply permission is unavailable.', 403);
        }
        return $row;
    }

    private function categoryQuery(array $row): \CodeIgniter\Database\BaseBuilder
    {
        return $this->db->table('categories')->where(['lang_id' => $row['lang_id'], 'parent_id' => 0,
            'visibility' => (int)$row['category_id'] === 4 ? 0 : 1]);
    }

    private function assigneeQuery(array $row): \CodeIgniter\Database\BaseBuilder
    {
        return $this->db->table('users')->where(['corp_id' => $row['corp_id'], 'role_id' => $row['role_id']]);
    }

    private function revision(array $row): string
    {
        $latest = $this->db->table('posts_logs')->selectMax('id')->where(['post_id' => $row['id'], 'corp_id' => $row['corp_id']])
            ->get()->getRowArray()['id'];
        return hash('sha256', serialize([$row, $latest]));
    }
}
