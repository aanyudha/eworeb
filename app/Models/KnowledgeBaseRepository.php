<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

final class KnowledgeBaseRepository
{
    public const CATEGORIES = [
        'it-support' => 'IT Support',
        'engineering' => 'Engineering',
        'housekeeping' => 'Housekeeping',
        'general-how-to' => 'General How-To',
    ];

    private const MAX_STORAGE_BYTES = 32 * 1024 * 1024;
    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $requested = $directory ?? config('KnowledgeBase')->directory;
        $parent = realpath(dirname($requested));
        $web = realpath(FCPATH);
        $existing = realpath($requested);
        if ($parent === false || $web === false || is_link($requested)) {
            throw new RuntimeException('Knowledge Base storage is unavailable.');
        }
        $resolved = $existing === false ? $parent . DIRECTORY_SEPARATOR . basename($requested) : $existing;
        $normalize = static fn (string $path): string => strtolower(str_replace('\\', '/', rtrim($path, '/\\')));
        if (str_starts_with($normalize($resolved) . '/', $normalize($web) . '/')) {
            throw new RuntimeException('Knowledge Base storage must be outside the web root.');
        }
        $this->directory = $resolved;
    }

    public static function validSlug(string $slug): bool
    {
        return strlen($slug) <= 96 && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) === 1;
    }

    public function articles(bool $public): array
    {
        $articles = $this->read();
        if ($public) {
            $articles = array_filter($articles, static fn (array $article): bool =>
                $article['status'] === 'published' && $article['visibility'] === 'public');
        }
        usort($articles, static fn (array $a, array $b): int =>
            [$b['updated_at'], $b['id']] <=> [$a['updated_at'], $a['id']]);
        return $articles;
    }

    public static function summary(array $article): array
    {
        return array_intersect_key($article, array_flip(['slug', 'title', 'summary', 'category', 'updated_at']));
    }

    public function save(?string $id, array $values): array
    {
        $content = $this->validateContent($values);
        return $this->mutate(function (array &$articles) use ($id, $values, $content): array {
            $previous = $id === null ? null : $this->editableArticle($articles, $id, $values);
            if ($previous !== null && $previous['status'] === 'published') {
                throw new DomainException('Withdraw an article before editing.', 409);
            }
            foreach ($articles as $item) {
                if ($item['slug'] === $content['slug'] && $item['id'] !== $id) {
                    throw new DomainException('This slug is already in use.', 409);
                }
            }

            $id ??= bin2hex(random_bytes(16));
            $now = $this->timestamp();
            $article = $content + [
                'id' => $id, 'status' => 'draft',
                'created_at' => $previous['created_at'] ?? $now, 'updated_at' => $now,
            ];
            return $articles[$id] = $article;
        });
    }

    public function setPublished(string $id, array $revision, bool $publish): array
    {
        return $this->mutate(function (array &$articles) use ($id, $revision, $publish): array {
            $article = $this->editableArticle($articles, $id, $revision);
            if ($publish && $article['resolution_steps'] === []) {
                throw new InvalidArgumentException('Add at least one resolution step before publishing.');
            }
            $article['status'] = $publish ? 'published' : 'draft';
            $article['updated_at'] = $this->timestamp();
            return $articles[$id] = $article;
        });
    }

    private function editableArticle(array $articles, string $id, array $revision): array
    {
        if (!isset($articles[$id])) {
            throw new DomainException('Article not found.', 404);
        }
        if (($revision['updated_at'] ?? null) !== $articles[$id]['updated_at']) {
            throw new DomainException('This article changed. Reload it before saving.', 409);
        }
        return $articles[$id];
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function validateContent(array $values): array
    {
        $slug = $this->text($values, 'slug', 96);
        if (!self::validSlug($slug)) {
            throw new InvalidArgumentException('Use a lowercase slug with letters, digits and hyphens.');
        }
        $category = $this->text($values, 'category', 32);
        $visibility = $this->text($values, 'visibility', 12);
        if (!isset(self::CATEGORIES[$category]) || !in_array($visibility, ['public','internal'], true)) {
            throw new InvalidArgumentException('Select a valid category and visibility.');
        }
        $related = $this->list($values, 'related_articles', 12, 96);
        foreach ($related as $entry) {
            if (!self::validSlug($entry) || $entry === $slug) {
                throw new InvalidArgumentException('Related articles must use different valid slugs.');
            }
        }
        return [
            'slug' => $slug,
            'title' => $this->text($values, 'title', 150),
            'summary' => $this->text($values, 'summary', 1200),
            'category' => $category, 'visibility' => $visibility,
            'keywords' => $this->list($values, 'keywords', 16, 80),
            'symptoms' => $this->list($values, 'symptoms', 20, 1500),
            'causes' => $this->list($values, 'causes', 20, 1500),
            'resolution_steps' => $this->list($values, 'resolution_steps', 30, 1500),
            'related_articles' => array_values(array_unique($related)),
        ];
    }

    private function text(array $values, string $key, int $limit): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || trim($value) === ''
            || mb_strlen($value) > $limit || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Check the ' . str_replace('_', ' ', $key) . ' field.');
        }
        return trim($value);
    }

    private function list(array $values, string $key, int $length, int $limit): array
    {
        $items = $values[$key] ?? [];
        if (!is_array($items) || !array_is_list($items) || count($items) > $length) {
            throw new InvalidArgumentException('Check the ' . str_replace('_', ' ', $key) . ' list.');
        }
        return array_map(fn ($value): string => $this->text([$key => $value], $key, $limit), $items);
    }

    private function read(): array
    {
        $file = $this->directory . DIRECTORY_SEPARATOR . 'articles.json';
        clearstatcache(true, $file);
        if (is_link($file)) {
            throw new RuntimeException('Knowledge Base storage is unavailable.');
        }
        if (!file_exists($file)) {
            return [];
        }
        $contents = @file_get_contents($file, false, null, 0, self::MAX_STORAGE_BYTES + 1);
        if ($contents === false || strlen($contents) > self::MAX_STORAGE_BYTES) {
            throw new RuntimeException('Knowledge Base storage is unavailable.');
        }

        try {
            $entries = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($entries)) {
                throw new InvalidArgumentException('Invalid document.');
            }
            $articles = [];
            $slugs = [];
            foreach ($entries as $article) {
                if (!is_array($article)) {
                    throw new InvalidArgumentException('Invalid article.');
                }
                $content = $this->validateContent($article);
                $id = $this->text($article, 'id', 32);
                $status = $this->text($article, 'status', 9);
                if (!preg_match('/\A[0-9a-f]{32}\z/', $id) || !in_array($status, ['draft','published'], true)
                    || isset($articles[$id]) || isset($slugs[$content['slug']])) {
                    throw new InvalidArgumentException('Invalid article identity.');
                }
                $slugs[$content['slug']] = true;
                $articles[$id] = $content + [
                    'id' => $id, 'status' => $status,
                    'created_at' => $this->text($article, 'created_at', 35),
                    'updated_at' => $this->text($article, 'updated_at', 35),
                ];
            }
            return $articles;
        } catch (\JsonException | InvalidArgumentException $e) {
            throw new RuntimeException('Knowledge Base storage is unavailable.', 0, $e);
        }
    }

    private function mutate(callable $change): array
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700) && !is_dir($this->directory)) {
            throw new RuntimeException('Knowledge Base storage is unavailable.');
        }
        $lockFile = $this->directory . DIRECTORY_SEPARATOR . 'articles.lock';
        if (is_link($lockFile)) {
            throw new RuntimeException('Knowledge Base storage is unavailable.');
        }
        $lock = @fopen($lockFile, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('Knowledge Base storage is unavailable.');
        }
        $temporary = null;
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Knowledge Base storage is unavailable.');
            }
            $articles = $this->read();
            $result = $change($articles);
            $json = json_encode(array_values($articles), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $temporary = $this->directory . DIRECTORY_SEPARATOR . '.articles-' . bin2hex(random_bytes(12)) . '.tmp';
            if (strlen($json) > self::MAX_STORAGE_BYTES
                || @file_put_contents($temporary, $json) !== strlen($json)
                || !@rename($temporary, $this->directory . DIRECTORY_SEPARATOR . 'articles.json')) {
                throw new RuntimeException('Could not save the article.');
            }
            $temporary = null;
            return $result;
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }
            fclose($lock);
        }
    }
}
