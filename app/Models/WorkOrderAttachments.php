<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use DomainException;
use RuntimeException;
use Throwable;

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

    public function uploadKind(array $viewer, string $direction, array $post): ?string
    {
        if ($direction !== 'incoming' || env('ewo.workOrderWrites', false) !== true
            || !class_exists(\finfo::class) || (int)$viewer['status'] !== 1
            || !in_array((int)$viewer['role_id'], [1, 7, (int)$post['role_id']], true)
            || !service('ewoIdentity')->allows($viewer, 'manage_all_posts')) {
            return null;
        }
        $kind = match ($post['post_type']) {
            'post' => 'image',
            'audio' => 'audio',
            default => null,
        };
        if ($kind === null || (int)$post['category_id'] === 4) {
            return null;
        }
        $dir = $this->uploadDirectory($kind);
        return $dir !== null && is_writable($dir) ? $kind : null;
    }

    public function upload(array $viewer, string $direction, int $id, string $kind, ?UploadedFile $file, array $input): void
    {
        $directory = $this->uploadDirectory($kind);
        if ($directory === null || !is_writable($directory)) {
            throw new RuntimeException('Attachment storage is unavailable.');
        }
        $extension = $this->validateUpload($kind, $file);
        $filename = bin2hex(random_bytes(24)) . '.' . $extension;
        $reference = 'uploads/' . ($kind === 'audio' ? 'audios' : 'images') . '/eworeb/' . $filename;
        $audio = $kind === 'audio' ? $this->audioFields($viewer, $input, $reference) : [];
        $moved = false;
        $this->db->transException(true);
        if (!$this->db->transBegin()) {
            throw new RuntimeException('Could not begin attachment upload.');
        }
        try {
            $post = (new WorkOrderList($this->db))->scopedActive($viewer, $direction, $id, true);
            if ($post === null || $this->uploadKind($viewer, $direction, $post) !== $kind) {
                throw new DomainException('This Work Order cannot accept attachments.', 403);
            }

            // Stage the database references in this transaction. Commit only after the file is in place.
            if ($kind === 'audio') {
                if (!$this->db->table('audios')->insert($audio)) {
                    throw new RuntimeException('Could not save audio metadata.');
                }
                $saved = $this->db->table('post_audios')->insert(['post_id' => $id, 'audio_id' => $this->db->insertID()]);
            } else {
                $saved = $this->db->table('post_images')->insert([
                    'post_id' => $id, 'image_default' => $reference, 'image_big' => $reference,
                ]);
            }
            if (!$saved || !$file->move($directory, $filename)) {
                throw new RuntimeException('Could not store the attachment.');
            }
            $moved = true;
            if (!$this->db->transStatus() || !$this->db->transCommit()) {
                throw new RuntimeException('Could not commit the attachment.');
            }
        } catch (Throwable $error) {
            $rolledBack = $this->db->transRollback();
            // Preserve the file for reconciliation if the database outcome is unknown.
            if ($rolledBack && $moved && !@unlink($directory . DIRECTORY_SEPARATOR . $filename)) {
                log_message('error', 'Could not remove an unlinked Work Order upload.');
            }
            throw $error;
        }
    }

    private function validateUpload(string $kind, ?UploadedFile $file): string
    {
        $limit = $kind === 'audio' ? 20 * 1024 * 1024 : 5 * 1024 * 1024;
        if ($file === null || !$file->isValid() || $file->getSize() < 1 || $file->getSize() > $limit) {
            throw new DomainException('Select a file within the upload size limit.', 422);
        }
        $path = $file->getTempName();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $expected = match ($mime) {
            'image/gif' => 'gif',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'audio/mpeg' => 'mp3',
            'audio/wav', 'audio/x-wav', 'audio/vnd.wave' => 'wav',
            default => null,
        };
        $extension = strtolower($file->getClientExtension());
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }
        $accepted = $kind === 'audio' ? ['wav', 'mp3'] : ['gif', 'png', 'jpg'];
        if ($expected !== $extension || !in_array($extension, $accepted, true)) {
            throw new DomainException('The attachment format does not match the selected file.', 422);
        }
        if ($kind === 'image') {
            $dimensions = @getimagesize($path);
            if ($dimensions === false || $dimensions['mime'] !== $mime || $dimensions[0] * $dimensions[1] > 25000000) {
                throw new DomainException('The image is invalid or exceeds the dimension limit.', 422);
            }
        }
        return $extension;
    }

    private function audioFields(array $viewer, array $input, string $reference): array
    {
        foreach (['audio_name', 'musician'] as $field) {
            $value = $input[$field] ?? '';
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > 150
                || $value !== strip_tags($value) || ($field === 'audio_name' && trim($value) === '')) {
                throw new DomainException('Enter a valid audio title and artist.', 422);
            }
        }

        return ['audio_path' => $reference, 'audio_name' => trim($input['audio_name']),
            'musician' => trim($input['musician'] ?? ''), 'download_button' => ($input['download_button'] ?? '') === '1' ? 1 : 0,
            'user_id' => (int)$viewer['id']];
    }

    private function uploadDirectory(string $kind): ?string
    {
        $root = $this->privateRoot('ewo.workOrderUploadRoot');
        $archive = $this->privateRoot('ewo.workOrderMediaRoot');
        if ($root === null || ($archive !== null && (str_starts_with(self::prefix($root), self::prefix($archive))
                || str_starts_with(self::prefix($archive), self::prefix($root))))) {
            return null;
        }
        $folder = $kind === 'audio' ? 'audios' : 'images';
        $expected = $root . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . 'eworeb';
        $directory = realpath($expected);
        return $directory !== false && self::prefix($directory) === self::prefix($expected) && is_dir($directory)
            ? $directory : null;
    }

    private function privateRoot(string $key): ?string
    {
        $mount = env($key, '');
        $root = is_string($mount) && $mount !== '' ? realpath($mount) : false;
        $public = realpath(FCPATH);
        if ($root === false || $public === false || !is_dir($root)
            || str_starts_with(self::prefix($root), self::prefix($public))
            || str_starts_with(self::prefix($public), self::prefix($root))) {
            return null;
        }
        return $root;
    }

    public function resolve(array $entry): ?string
    {
        if (!class_exists(\finfo::class) || !$entry['allowed'] || !is_string($entry['reference'])) {
            return null;
        }
        $folder = $entry['kind'] === 'audio' ? 'audios' : 'images';
        $extensions = $entry['kind'] === 'audio' ? 'mp3|wav' : 'gif|jpe?g|png';
        $ref = $entry['reference'];
        if (preg_match('~^uploads/' . $folder . '/(?:[a-z0-9_-]+/)*[a-z0-9_.-]+\.(?:' . $extensions . ')$~iD', $ref) !== 1) {
            return null;
        }
        if (preg_match('~^uploads/' . $folder . '/eworeb/[a-f0-9]{48}\.[a-z]+$~D', $ref) === 1) {
            $allowedRoot = $this->uploadDirectory($entry['kind']);
            $root = $this->privateRoot('ewo.workOrderUploadRoot');
        } else {
            $root = $this->privateRoot('ewo.workOrderMediaRoot');
            $allowedRoot = $root === null ? false : realpath($root . DIRECTORY_SEPARATOR . $folder);
        }
        if (!$allowedRoot || $root === null) {
            return null;
        }
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
