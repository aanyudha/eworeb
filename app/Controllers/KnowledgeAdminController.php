<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\KnowledgeBaseRepository;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class KnowledgeAdminController extends BaseController
{
    public function articles(): ResponseInterface
    {
        return $this->runOperation(static function (KnowledgeBaseRepository $repository): array {
            return [
                'articles' => array_map(static fn (array $article): array =>
                    KnowledgeBaseRepository::summary($article) + array_intersect_key($article, array_flip(['id','status','visibility'])),
                    $repository->articles(false)),
                'categories' => KnowledgeBaseRepository::CATEGORIES,
            ];
        });
    }

    public function article(string $id): ResponseInterface
    {
        return $this->runOperation(static function (KnowledgeBaseRepository $repository) use ($id): array {
            $byId = array_column($repository->articles(false), null, 'id');
            if (!isset($byId[$id])) {
                throw new DomainException('Article not found.', 404);
            }
            return ['article' => $byId[$id]];
        });
    }

    public function create(): ResponseInterface
    {
        return $this->runOperation(fn (KnowledgeBaseRepository $repository): array =>
            ['article' => $repository->save(null, $this->body())], 201);
    }

    public function update(string $id): ResponseInterface
    {
        return $this->runOperation(fn (KnowledgeBaseRepository $repository): array =>
            ['article' => $repository->save($id, $this->body())]);
    }

    public function publish(string $id): ResponseInterface
    {
        return $this->runOperation(fn (KnowledgeBaseRepository $repository): array =>
            ['article' => $repository->setPublished($id, $this->body(), true)]);
    }

    public function withdraw(string $id): ResponseInterface
    {
        return $this->runOperation(fn (KnowledgeBaseRepository $repository): array =>
            ['article' => $repository->setPublished($id, $this->body(), false)]);
    }

    private function body(): array
    {
        $body = $this->request->getBody();
        if (!is_string($body) || strlen($body) > 262144) {
            throw new InvalidArgumentException('Article payload is too large or missing.');
        }
        $values = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($values) || array_is_list($values)) {
            throw new InvalidArgumentException('A JSON article object is required.');
        }
        return $values;
    }

    private function runOperation(callable $operation, int $successStatus = 200): ResponseInterface
    {
        try {
            $data = $operation(new KnowledgeBaseRepository());
            $status = $successStatus;
        } catch (DomainException $e) {
            $data = ['error' => $e->getMessage()];
            $status = $e->getCode();
        } catch (InvalidArgumentException $e) {
            $data = ['error' => $e->getMessage()];
            $status = 422;
        } catch (JsonException) {
            $data = ['error' => 'Invalid article JSON.'];
            $status = 422;
        } catch (RuntimeException) {
            $data = ['error' => 'Could not complete the Knowledge Base operation.'];
            $status = 503;
        }

        return $this->response->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')->setStatusCode($status)
            ->setJSON($data + ['csrf' => ['header' => csrf_header(), 'hash' => csrf_hash()]]);
    }
}
