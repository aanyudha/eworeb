<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\KnowledgeBaseRepository;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

final class KnowledgeController extends BaseController
{
    public function index(?string $slug = null): ResponseInterface
    {
        $manifest = FCPATH . 'workspace-assets/manifest.json';
        $entry = is_file($manifest)
            ? (json_decode((string) file_get_contents($manifest), true)['src/knowledge.tsx'] ?? null)
            : null;
        if (!is_array($entry) || !is_string($entry['file'] ?? null)) {
            return $this->response->setStatusCode(503)->setBody('Knowledge Center assets are unavailable. Run npm run build in frontend.');
        }
        $status = 200;
        if ($slug !== null) {
            try {
                $published = KnowledgeBaseRepository::validSlug($slug)
                    && in_array($slug, array_column((new KnowledgeBaseRepository())->articles(true), 'slug'), true);
                $status = $published ? 200 : 404;
            } catch (RuntimeException) {
                $status = 503;
            }
        }
        return $this->response->setHeader('Cache-Control', 'no-store')->setStatusCode($status)
            ->setBody(view('knowledge/index', ['entry' => $entry]));
    }

    public function categories(): ResponseInterface
    {
        try {
            $counts = array_count_values(array_column((new KnowledgeBaseRepository())->articles(true), 'category'));
            $categories = [];
            foreach (KnowledgeBaseRepository::CATEGORIES as $id => $label) {
                $categories[] = ['id' => $id, 'label' => $label, 'count' => $counts[$id] ?? 0];
            }
            return $this->json(['categories' => $categories]);
        } catch (RuntimeException) {
            return $this->unavailable();
        }
    }

    public function articles(): ResponseInterface
    {
        $q = $this->request->getGet('q') ?? '';
        $category = $this->request->getGet('category') ?? '';
        $page = filter_var($this->request->getGet('page') ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        $limit = filter_var($this->request->getGet('limit') ?? '12', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 24]]);
        if (!is_string($q) || !mb_check_encoding($q, 'UTF-8') || mb_strlen($q) > 120 || !is_string($category)
            || ($category !== '' && !isset(KnowledgeBaseRepository::CATEGORIES[$category])) || $page === false || $limit === false) {
            return $this->json(['error' => 'Invalid Knowledge Base filters.'], 422);
        }

        try {
            $q = trim($q);
            $entries = (new KnowledgeBaseRepository())->articles(true);
            $matches = array_values(array_filter($entries, static function (array $entry) use ($q, $category): bool {
                return ($category === '' || $entry['category'] === $category)
                    && ($q === '' || mb_stripos($entry['title'] . "\n" . $entry['summary'] . "\n" . implode("\n", $entry['keywords']), $q) !== false);
            }));
            return $this->json([
                'articles' => array_map(KnowledgeBaseRepository::summary(...), array_slice($matches, ($page - 1) * $limit, $limit)),
                'total' => count($matches), 'page' => $page, 'limit' => $limit,
            ]);
        } catch (RuntimeException) {
            return $this->unavailable();
        }
    }

    public function article(string $slug): ResponseInterface
    {
        if (!KnowledgeBaseRepository::validSlug($slug)) {
            return $this->notFound();
        }
        try {
            $entries = array_column((new KnowledgeBaseRepository())->articles(true), null, 'slug');
            $selected = $entries[$slug] ?? null;
            if ($selected === null) {
                return $this->notFound();
            }
            $related = [];
            foreach ($selected['related_articles'] as $relation) {
                if (isset($entries[$relation])) {
                    $related[] = KnowledgeBaseRepository::summary($entries[$relation]);
                }
            }
            $out = KnowledgeBaseRepository::summary($selected) + array_intersect_key($selected,
                array_flip(['symptoms', 'causes', 'resolution_steps', 'created_at']));
            return $this->json(['article' => $out, 'related' => $related]);
        } catch (RuntimeException) {
            return $this->unavailable();
        }
    }

    private function json(array $data, int $status = 200): ResponseInterface
    {
        return $this->response->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'no-store')->setStatusCode($status)->setJSON($data);
    }

    private function notFound(): ResponseInterface
    {
        return $this->json(['error' => 'Article not found.'], 404);
    }

    private function unavailable(): ResponseInterface
    {
        return $this->json(['error' => 'Knowledge Base is temporarily unavailable.'], 503);
    }
}
