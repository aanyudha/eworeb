<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\WorkOrderAttachments;
use App\Models\WorkOrderList;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use DomainException;
use RuntimeException;

final class WorkOrderAttachmentController extends BaseController
{
    public function index(string $direction, string $order): ResponseInterface
    {
        return $this->run($direction, $order, function (array $post, WorkOrderAttachments $media, array $viewer) use ($direction, $order): ResponseInterface {
            $items = [];
            foreach ($media->entries($post) as $entry) {
                $download = $media->resolve($entry) === null ? null : site_url(
                    'api/v1/work-orders/' . $direction . '/' . $order . '/attachments/' . $entry['kind'] . '/' . $entry['id']
                );
                $items[] = ['kind' => $entry['kind'], 'id' => $entry['id'], 'label' => $entry['label'], 'download' => $download];
            }
            $kind = $media->uploadKind($viewer, $direction, $post);
            $upload = $kind === null ? [] : ['upload' => ['kind' => $kind, 'header' => csrf_header(), 'hash' => csrf_hash()]];
            return $this->response->setJSON(['items' => $items] + $upload);
        });
    }

    public function upload(string $order, string $kind): ResponseInterface
    {
        if (env('ewo.workOrderWrites', false) !== true) {
            return $this->response->setHeader('Cache-Control', 'private, no-store')->setStatusCode(503)
                ->setJSON(['error' => 'Attachment uploads are disabled in this environment.']);
        }
        return $this->run('incoming', $order, function (array $post, WorkOrderAttachments $media, array $viewer) use ($kind): ResponseInterface {
            if ($media->uploadKind($viewer, 'incoming', $post) !== $kind) {
                throw new DomainException('This Work Order cannot accept attachments.', 403);
            }
            $media->upload($viewer, 'incoming', (int)$post['id'], $kind, $this->request->getFile('attachment'),
                $this->request->getPost(['audio_name', 'musician', 'download_button']));
            return $this->response->setStatusCode(201)->setJSON(['uploaded' => true]);
        });
    }

    public function download(string $direction, string $order, string $kind, string $id): ResponseInterface
    {
        return $this->run($direction, $order, function (array $post, WorkOrderAttachments $media) use ($kind, $id): ResponseInterface {
            foreach ($media->entries($post) as $entry) {
                if ($entry['kind'] === $kind && (string)$entry['id'] === $id && ($file = $media->resolve($entry)) !== null) {
                    return $this->response->download($file, null)
                        ->setHeader('Cache-Control', 'private, no-store')->setHeader('X-Content-Type-Options', 'nosniff');
                }
            }
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Attachment not found.']);
        });
    }

    private function run(string $direction, string $order, callable $operation): ResponseInterface
    {
        $response = $this->response->setHeader('Cache-Control', 'private, no-store');
        $viewer = service('ewoIdentity')?->currentUser();
        if ($viewer === null) {
            return $response->setStatusCode(401)->setJSON(['error' => 'Your session has expired.']);
        }
        $id = filter_var($order, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return $response->setStatusCode(422)->setJSON(['error' => 'Check the Work Order ID.']);
        }
        try {
            $db = Database::connect('ewo');
            $post = (new WorkOrderList($db))->scopedActive($viewer, $direction, $id);
            if ($post === null) {
                return $response->setStatusCode(404)->setJSON(['error' => 'Work Order not found.']);
            }
            $media = new WorkOrderAttachments($db);
            return $operation($post, $media, $viewer);
        } catch (DomainException $error) {
            return $response->setStatusCode($error->getCode())->setJSON(['error' => $error->getMessage()]);
        } catch (RuntimeException) {
            return $response->setStatusCode(503)->setJSON(['error' => 'Attachments are unavailable.']);
        }
    }
}
