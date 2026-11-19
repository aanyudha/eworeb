<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use DomainException;
use RuntimeException;
use Throwable;

final class AccountImages
{
    private const FORMATS = ['gif' => 'image/gif', 'jpg' => 'image/jpeg', 'png' => 'image/png'];

    public static function canStore(string $kind): bool
    {
        $dir = self::directory($kind, true);
        return $dir !== null && is_writable($dir) && class_exists(\finfo::class)
            && extension_loaded('gd');
    }

    public static function resolve(string $kind, mixed $reference): ?string
    {
        if (!class_exists(\finfo::class) || !is_string($reference)) {
            return null;
        }
        $folder = self::folder($kind);
        $managed = preg_match('~^uploads/' . $folder . '/eworeb/[a-f0-9]{48}\.(?:gif|jpg|png)$~D', $reference) === 1;
        if (!$managed && preg_match('~^uploads/' . $folder . '/[a-z0-9_.-]+\.(?:gif|jpe?g|png)$~iD', $reference) !== 1) {
            return null;
        }
        $dir = self::directory($kind, $managed);
        $file = $dir === null ? false : realpath($dir . DIRECTORY_SEPARATOR . basename($reference));
        if ($file === false || !str_starts_with(self::prefix($file), self::prefix($dir)) || !is_file($file) || !is_readable($file)) {
            return null;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return (new \finfo(FILEINFO_MIME_TYPE))->file($file) === (self::FORMATS[$ext === 'jpeg' ? 'jpg' : $ext] ?? null)
            ? $file : null;
    }

    public function save(BaseConnection $db, string $kind, ?UploadedFile $file, int $viewerId): void
    {
        if (!self::canStore($kind)) {
            throw new RuntimeException('Private image storage is unavailable.');
        }
        [$extension, $dimensions, $image] = $this->validate($kind, $file);
        $dir = self::directory($kind, true);
        $name = bin2hex(random_bytes(24)) . '.' . ($kind === 'avatar' ? 'jpg' : $extension);
        $destination = $dir . DIRECTORY_SEPARATOR . $name;
        $reference = 'uploads/' . self::folder($kind) . '/eworeb/' . $name;
        $staged = false;
        $db->transException(true);
        if (!$db->transBegin()) {
            throw new RuntimeException('Could not begin image update.');
        }
        try {
            if ($kind === 'avatar') {
                $row = $db->query('SELECT status FROM users WHERE id = ? FOR UPDATE', [$viewerId])->getRowArray();
                if ($row === null || (int)$row['status'] !== 1) {
                    throw new DomainException('Avatar changes are unavailable.', 403);
                }
                $changed = $db->table('users')->where('id', $viewerId)->update(['avatar' => $reference]);
            } else {
                $row = $db->query('SELECT id FROM visual_settings WHERE id = 1 FOR UPDATE')->getRowArray();
                if ($row === null) {
                    throw new RuntimeException('Legacy visual settings are unavailable.');
                }
                $changed = $db->table('visual_settings')->where('id', 1)->update([$kind => $reference]);
            }
            if (!$changed || !$db->transStatus()) {
                throw new RuntimeException('Could not save the image reference.');
            }
            $staged = $this->stage($file, $kind, $dimensions, $image, $destination);
            if (!$staged || !$db->transCommit()) {
                throw new RuntimeException('Could not store the image.');
            }
        } catch (Throwable $error) {
            if ($db->transRollback() && $staged && !@unlink($destination)) {
                log_message('error', 'Could not remove an unlinked account or branding image.');
            }
            throw $error;
        }
    }

    private function validate(string $kind, ?UploadedFile $file): array
    {
        if ($file === null || !$file->isValid() || $file->getSize() < 1 || $file->getSize() > 5 * 1024 * 1024) {
            throw new DomainException('Select an image up to 5 MB.', 422);
        }
        $ext = strtolower($file->getClientExtension());
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        $path = $file->getTempName();
        $dimensions = @getimagesize($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($dimensions === false || $dimensions[0] * $dimensions[1] > 12000000
            || !isset(self::FORMATS[$ext]) || $dimensions['mime'] !== $mime || self::FORMATS[$ext] !== $mime
            || ($kind === 'favicon' && $ext !== 'png')) {
            throw new DomainException($kind === 'favicon' ? 'Select a valid PNG favicon.' : 'Select a valid raster image.', 422);
        }
        $image = @imagecreatefromstring((string)file_get_contents($path));
        if ($image === false) {
            throw new DomainException('The image could not be decoded.', 422);
        }
        return [$ext, $dimensions, $image];
    }

    private function stage(UploadedFile $file, string $kind, array $size, \GdImage $source, string $destination): bool
    {
        // Use exclusive creation and clean up partial output before leaving this method.
        $output = @fopen($destination, 'xb');
        if ($output === false) {
            return false;
        }
        $ok = false;
        try {
            $path = $file->getTempName();
            if ($kind !== 'avatar') {
                $input = fopen($path, 'rb');
                if ($input === false) {
                    return false;
                }
                try {
                    $ok = stream_copy_to_stream($input, $output) === $file->getSize() && fflush($output);
                    return $ok;
                } finally {
                    fclose($input);
                }
            }
            $avatar = imagecreatetruecolor(240, 240);
            $side = min($size[0], $size[1]);
            imagefill($avatar, 0, 0, imagecolorallocate($avatar, 255, 255, 255));
            $ok = imagecopyresampled($avatar, $source, 0, 0, intdiv($size[0] - $side, 2),
                intdiv($size[1] - $side, 2), 240, 240, $side, $side)
                && imagejpeg($avatar, $output, 85) && fflush($output);
            return $ok;
        } finally {
            fclose($output);
            if (!$ok) {
                @unlink($destination);
            }
        }
    }

    private static function folder(string $kind): string
    {
        return match ($kind) {
            'avatar' => 'profile',
            'logo', 'favicon' => 'logo',
        };
    }

    private static function directory(string $kind, bool $managed): ?string
    {
        $mount = env($managed ? 'ewo.accountMediaRoot' : 'ewo.workOrderMediaRoot', '');
        $root = is_string($mount) && $mount !== '' ? realpath($mount) : false;
        $public = realpath(FCPATH);
        if ($root === false || $public === false || str_starts_with(self::prefix($root), self::prefix($public))
            || str_starts_with(self::prefix($public), self::prefix($root))) {
            return null;
        }
        if ($managed) {
            $archive = env('ewo.workOrderMediaRoot', '');
            $legacy = is_string($archive) && $archive !== '' ? realpath($archive) : false;
            if ($legacy !== false && (str_starts_with(self::prefix($root), self::prefix($legacy))
                || str_starts_with(self::prefix($legacy), self::prefix($root)))) {
                return null;
            }
        }
        $expected = $root . DIRECTORY_SEPARATOR . self::folder($kind) . ($managed ? DIRECTORY_SEPARATOR . 'eworeb' : '');
        $dir = realpath($expected);
        return $dir !== false && self::prefix($dir) === self::prefix($expected) && is_dir($dir) ? $dir : null;
    }

    private static function prefix(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/') . '/';
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }
}

