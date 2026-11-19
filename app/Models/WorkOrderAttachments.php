<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Database\BaseConnection;

final class WorkOrderAttachments
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function entries(array $post): array
    {
        $rows = [];
        $main = ($post['image_default'] ?? '') ?: ($post['image_big'] ?? '');
        if (is_string($main) && $main !== '') {
            $rows[] = ['kind' => 'main', 'id' => 0, 'label' => 'Primary image', 'reference' => $main, 'allowed' => true];
        }
        if (($post['post_type'] ?? '') === 'post' && $this->db->tableExists('post_images')) {
            foreach ($this->db->table('post_images')->select('id, image_default')->where('post_id', $post['id'])
                ->orderBy('id')->get()->getResultArray() as $image) {
                $rows[] = ['kind' => 'image', 'id' => (int)$image['id'], 'label' => 'Additional image',
                    'reference' => $image['image_default'], 'allowed' => true];
            }
        }
        if (($post['post_type'] ?? '') === 'audio' && $this->db->tableExists('post_audios') && $this->db->tableExists('audios')) {
            $audios = $this->db->table('post_audios pa')->select('pa.id, a.audio_name, a.audio_path, a.download_button')
                ->join('audios a', 'a.id = pa.audio_id')->where('pa.post_id', $post['id'])->orderBy('a.id')->get()->getResultArray();
            foreach ($audios as $audio) {
                $rows[] = ['kind' => 'audio', 'id' => (int)$audio['id'], 'label' => $audio['audio_name'] ?: 'Audio',
                    'reference' => $audio['audio_path'], 'allowed' => (int)$audio['download_button'] === 1];
            }
        }
        return $rows;
    }

    public function resolve(array $entry): ?string
    {
        $mount = env('ewo.workOrderMediaRoot', '');
        if (!class_exists(\finfo::class) || !$entry['allowed'] || !is_string($mount) || $mount === '' || !is_string($entry['reference'])) {
            return null;
        }
        $root = realpath($mount);
        $public = realpath(FCPATH);
        if ($root === false || $public === false || !is_dir($root)
            || str_starts_with(self::prefix($root), self::prefix($public))
            || str_starts_with(self::prefix($public), self::prefix($root))) {
            return null;
        }
        $folder = $entry['kind'] === 'audio' ? 'audios' : 'images';
        $extensions = $entry['kind'] === 'audio' ? 'mp3|wav' : 'gif|jpe?g|png';
        $ref = $entry['reference'];
        if (preg_match('~^uploads/' . $folder . '/(?:[a-z0-9_-]+/)*[a-z0-9_.-]+\.(?:' . $extensions . ')$~iD', $ref) !== 1) {
            return null;
        }
        $allowedRoot = realpath($root . DIRECTORY_SEPARATOR . $folder);
        $file = realpath($root . DIRECTORY_SEPARATOR . substr($ref, strlen('uploads/')));
        if ($allowedRoot === false || $file === false || !str_starts_with(self::prefix($allowedRoot), self::prefix($root))
            || !str_starts_with(self::prefix($file), self::prefix($allowedRoot)) || !is_file($file) || !is_readable($file)) {
            return null;
        }
        $mimes = $entry['kind'] === 'audio'
            ? ['audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/vnd.wave']
            : ['image/gif', 'image/jpeg', 'image/png'];
        return in_array((new \finfo(FILEINFO_MIME_TYPE))->file($file), $mimes, true) ? $file : null;
    }

    private static function prefix(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/') . '/';
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }
}
