<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class ScheduledWorkOrders
{
    private const VALID_SCHEDULE = "intervale > 0 AND published_at >= '1000-01-01 00:00:00'
        AND DATE_ADD(published_at, INTERVAL intervale DAY) IS NOT NULL";
    private const COPY_FIELDS = [
        'lang_id', 'title', 'summary', 'category_id', 'location_id', 'sublocation_id', 'image_big',
        'image_default', 'image_slider', 'image_mid', 'image_small', 'image_mime', 'visibility',
        'post_type', 'video_path', 'image_url', 'video_embed_code', 'dept_id', 'corp_id', 'user_id',
        'role_id', 'assign_to', 'status', 'image_description', 'edited_by', 'edited_at', 'created_at',
    ];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function schemaReady(): bool
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            return false;
        }
        $row = $this->db->query("SELECT COUNT(*) AS matched FROM information_schema.COLUMNS c
            JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME
            WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME IN ('posts','posts_logs') AND t.ENGINE='InnoDB'
            AND c.IS_NULLABLE='YES' AND c.COLUMN_DEFAULT IS NULL AND c.EXTRA=''
            AND ((c.COLUMN_NAME='published_at' AND c.COLUMN_TYPE='datetime')
              OR (c.COLUMN_NAME='intervale' AND c.COLUMN_TYPE='int unsigned'))")->getRowArray();
        return (int) $row['matched'] === 4;
    }

    public function inspect(DateTimeImmutable $now): array
    {
        $valid = self::VALID_SCHEDULE;
        [$from, $until] = $this->minuteBounds($now);
        return $this->db->query("SELECT COUNT(*) AS templates,
            COUNT(CASE WHEN $valid THEN 1 END) AS valid,
            COUNT(*) - COUNT(CASE WHEN $valid THEN 1 END) AS unavailable,
            COUNT(CASE WHEN $valid AND published_at < ? THEN 1 END) AS past_due,
            COUNT(CASE WHEN $valid AND published_at >= ? AND published_at < ? THEN 1 END) AS due_this_minute
            FROM posts WHERE is_scheduled = 1", [$from, $from, $until])->getRowArray();
    }

    private function minuteBounds(DateTimeImmutable $now): array
    {
        $floor = $now->setTime((int)$now->format('H'), (int)$now->format('i'));
        return [$floor->format('Y-m-d H:i:s'), $floor->modify('+1 minute')->format('Y-m-d H:i:s')];
    }

    public function publishMinute(DateTimeImmutable $now): array
    {
        [$from, $until] = $this->minuteBounds($now);
        $this->db->transException(true);
        if (!$this->db->transBegin()) {
            throw new RuntimeException('Could not begin scheduled Work Order processing.');
        }
        try {
            $templates = $this->db->query('SELECT * FROM posts WHERE is_scheduled = 1 AND ' . self::VALID_SCHEDULE . '
                AND published_at >= ? AND published_at < ? ORDER BY id FOR UPDATE', [$from, $until])->getResultArray();
            $created = [];
            foreach ($templates as $template) {
                $next = $this->db->query('SELECT DATE_ADD(?, INTERVAL ? DAY) AS next_at',
                    [$template['published_at'], (int)$template['intervale']])->getRowArray()['next_at'];
                if ($next === null) {
                    throw new RuntimeException('Scheduled interval exceeds the publication date range.');
                }
                $entry = array_intersect_key($template, array_flip(self::COPY_FIELDS)) + ['is_scheduled' => 0, 'intervale' => 0];
                if (!$this->db->table('posts')->insert($entry + ['published_at' => $now->format('Y-m-d H:i:s')])) {
                    throw new RuntimeException('Could not create scheduled Work Order occurrence.');
                }
                $id = (int)$this->db->insertID();
                if (!$this->db->table('posts_logs')->insert($entry + ['post_id' => $id, 'published_at' => $template['published_at']])
                    || !$this->db->query('UPDATE posts SET published_at = ?
                       WHERE id = ? AND is_scheduled = 1 AND published_at = ?',
                        [$next, $template['id'], $template['published_at']])
                    || $this->db->affectedRows() !== 1) {
                    throw new RuntimeException('Could not save scheduled history and next publication date.');
                }
                $created[] = $id;
            }
            if (!$this->db->transStatus() || !$this->db->transCommit()) {
                throw new RuntimeException('Could not commit scheduled Work Order processing.');
            }
            return $created;
        } catch (Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
    }
}
