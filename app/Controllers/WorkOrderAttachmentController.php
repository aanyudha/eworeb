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
        return $this->run($direction, $order, function (array $entries, WorkOrderAttachments $media) use ($direction, $order): ResponseInterface {
            $items = [];
            foreach ($entries as $entry) {
                $download = $media->resolve($entry) === null ? null : site_url(
                    'api/v1/work-orders/' . $direction . '/' . $order . '/attachments/' . $entry['kind'] . '/' . $entry['id']
                );
                $items[] = ['kind' => $entry['kind'], 'id' => $entry['id'], 'label' => $entry['label'], 'download' => $download];
            }
            return $this->response->setJSON(['items' => $items]);
        });
    }

    public function download(string $direction, string $order, string $kind, string $id): ResponseInterface
    {
        return $this->run($direction, $order, function (array $entries, WorkOrderAttachments $media) use ($kind, $id): ResponseInterface {
            foreach ($entries as $entry) {
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
            return $operation($media->entries($post), $media);
        } catch (DomainException) {
            return $response->setStatusCode(403)->setJSON(['error' => 'Work Order scope is unavailable.']);
        } catch (RuntimeException) {
            return $response->setStatusCode(503)->setJSON(['error' => 'Attachments are unavailable.']);
        }
    }
}
