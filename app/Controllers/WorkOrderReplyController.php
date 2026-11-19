<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\WorkOrderReply;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use DomainException;
use RuntimeException;

final class WorkOrderReplyController extends BaseController
{
    public function options(string $id): ResponseInterface
    {
        return $this->run($id, static fn (WorkOrderReply $reply, array $viewer, int $number): array =>
            $reply->options($viewer, $number));
    }

    public function save(string $id): ResponseInterface
    {
        return $this->run($id, function (WorkOrderReply $reply, array $viewer, int $number): array {
            $input = $this->request->getPost(['category_id', 'assign_to', 'summary_edit', 'revision']);
            return ['history_id' => $reply->reply($viewer, $number, $input)];
        });
    }

    private function run(string $id, callable $operation): ResponseInterface
    {
        $response = $this->response->setHeader('Cache-Control', 'private, no-store');
        if (env('ewo.workOrderWrites', false) !== true) {
            return $response->setStatusCode(503)->setJSON(['error' => 'Work Order replies are disabled in this environment.']);
        }
        $viewer = service('ewoIdentity')?->currentUser();
        if ($viewer === null) {
            return $response->setStatusCode(401)->setJSON(['error' => 'Your session has expired.']);
        }
        $number = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($number === false) {
            return $response->setStatusCode(422)->setJSON(['error' => 'Check the Work Order ID.']);
        }
        try {
            $result = $operation(new WorkOrderReply(Database::connect('ewo')), $viewer, $number);
            return $response->setJSON($result + ['csrf' => ['header' => csrf_header(), 'hash' => csrf_hash()]]);
        } catch (DomainException $error) {
            return $response->setStatusCode($error->getCode())->setJSON(['error' => $error->getMessage()]);
        } catch (RuntimeException) {
            return $response->setStatusCode(503)->setJSON(['error' => 'Could not complete the Work Order reply.']);
        }
    }
}
