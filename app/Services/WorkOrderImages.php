<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\WorkOrderAttachments;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use DomainException;
use RuntimeException;
use Throwable;

final class WorkOrderImages
{
    private const SIZES = [
        'image_big' => [750, 422], 'image_default' => [750, 0], 'image_slider' => [600, 460],
        'image_mid' => [380, 226], 'image_small' => [140, 98],
    ];

    private readonly WorkOrderAttachments $media;

    public function __construct(private readonly BaseConnection $db)
    {
        $this->media = new WorkOrderAttachments($db);
    }

    public function corporation(array $viewer, mixed $requested): int
    {
        $id = filter_var($viewer['role'] === 'admin' ? $requested : $viewer['corp_id'], FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        if ($id === false || !$this->db->table('corps')->where('id', $id)->countAllResults()) {
            throw new DomainException('Select a valid corporation.', 422);
        }
        return $id;
    }

    private function scope(array $viewer, array $settings, int $corp): BaseBuilder
    {
        $query = $this->db->table('images i')->select('i.*')->join('users u', 'u.id = i.user_id')
            ->where('u.corp_id', $corp)->where('i.image_default !=', '');
        if ((int)($settings['file_manager_show_files'] ?? 0) !== 1) {
            $query->where('i.user_id', $viewer['id']);
        }
        return $query;
    }

    public function list(array $viewer, array $settings, int $corp, int $before): array
    {
        $query = $this->scope($viewer, $settings, $corp);
        if ($before > 0) {
            $query->where('i.id <', $before);
        }
        $rows = $query->orderBy('i.id', 'DESC')->get(25)->getResultArray();
        return array_map(fn (array $row): array => ['id' => (int)$row['id'], 'preview' => $this->file($row) !== null], $rows);
    }

    public function choose(array $viewer, array $settings, int $corp, int $id, bool $lock = false): array
    {
        $query = $this->scope($viewer, $settings, $corp)->where('i.id', $id);
        $row = $lock ? $this->db->query($query->getCompiledSelect() . ' FOR SHARE')->getRowArray() : $query->get()->getRowArray();
        if ($row === null) {
            throw new DomainException('Select an image available in your Work Order scope.', 422);
        }
        return $row;
    }

    public function file(array $row): ?string
    {
        foreach (['image_small', 'image_default'] as $field) {
            $file = $this->media->resolve(['allowed' => true, 'reference' => $row[$field], 'kind' => 'image']);
            if ($file !== null) {
                return $file;
            }
        }
        return null;
    }

    public function canUpload(array $viewer, int $corp): bool
    {
        $root = $this->media->uploadDirectory('image');
        return $corp === (int)$viewer['corp_id'] && extension_loaded('gd') && extension_loaded('fileinfo')
            && $root !== null && is_writable($root);
    }

    public function upload(array $viewer, int $corp, ?UploadedFile $file): int
    {
        if (!$this->canUpload($viewer, $corp)) {
            throw new DomainException('Image upload is unavailable for this corporation.', 403);
        }
        $directory = $this->media->uploadDirectory('image');
        $extension = $this->media->validateUpload('image', $file);
        $written = [];
        $transaction = false;
        try {
            $source = match ($extension) {
                'gif' => @imagecreatefromgif($file->getTempName()),
                'png' => @imagecreatefrompng($file->getTempName()),
                'jpg' => @imagecreatefromjpeg($file->getTempName()),
            };
            if ($source === false) {
                throw new DomainException('Could not decode the image.', 422);
            }
            $row = ['user_id' => (int)$viewer['id'], 'lang_id' => 1, 'image_mime' => $extension === 'gif' ? 'gif' : 'jpg'];
            $renditions = [];
            foreach (self::SIZES as $key => [$width, $height]) {
                if ($extension === 'gif' && isset($row['image_big'])) {
                    $row[$key] = $row['image_big'];
                    continue;
                }
                $name = bin2hex(random_bytes(24)) . '.' . $row['image_mime'];
                $renditions[$name] = [$width, $height];
                $row[$key] = 'uploads/images/eworeb/' . $name;
            }
            $this->db->transException(true);
            if (!$this->db->transBegin()) {
                throw new RuntimeException('Could not begin image catalog upload.');
            }
            $transaction = true;
            if (!$this->db->table('images')->insert($row)) {
                throw new RuntimeException('Could not save image metadata.');
            }
            $id = (int)$this->db->insertID();
            foreach ($renditions as $name => [$width, $height]) {
                $path = $directory . DIRECTORY_SEPARATOR . $name;
                $written[] = $path;
                if ($extension === 'gif') {
                    if (!$file->move($directory, $name)) {
                        throw new RuntimeException('Could not store the GIF.');
                    }
                } else {
                    $this->renderJpeg($source, $path, $width, $height);
                }
            }
            if (!$this->db->transStatus() || !$this->db->transCommit()) {
                throw new RuntimeException('Could not commit image upload.');
            }
            return $id;
        } catch (Throwable $error) {
            if (!$transaction || $this->db->transRollback()) {
                foreach ($written as $path) {
                    if (is_file($path) && !@unlink($path)) {
                        log_message('error', 'Could not remove an unlinked catalog image.');
                    }
                }
            }
            throw $error;
        }
    }

    private function renderJpeg(\GdImage $source, string $path, int $width, int $height): void
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        if ($height === 0) {
            $width = min($width, $sourceWidth);
        }
        $cropWidth = $height === 0 ? $sourceWidth : min($sourceWidth, max(1, (int)round($sourceHeight * $width / $height)));
        $cropHeight = $height === 0 ? $sourceHeight : min($sourceHeight, max(1, (int)round($sourceWidth * $height / $width)));
        $height = $height ?: max(1, (int)round($sourceHeight * $width / $sourceWidth));
        $image = imagecreatetruecolor($width, $height);
        if ($image === false || !imagecopyresampled($image, $source, 0, 0, intdiv($sourceWidth - $cropWidth, 2),
            intdiv($sourceHeight - $cropHeight, 2), $width, $height, $cropWidth, $cropHeight)
            || !imagejpeg($image, $path, 85)) {
            throw new RuntimeException('Could not store the image rendition.');
        }
    }
}
