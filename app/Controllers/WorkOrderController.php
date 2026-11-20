<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\WorkOrderList;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use DomainException;

final class WorkOrderController extends BaseController
{
    private const FILTERS = ['lang_id', 'post_type', 'category', 'user', 'corpnya', 'role', 'department', 'q'];

    public function index(string $direction): ResponseInterface
    {
        return $this->list($direction, false);
    }

    public function publishDraft(string $order): ResponseInterface
    {
        return $this->publish($order, false);
    }

    public function publishScheduled(string $order): ResponseInterface
    {
        return $this->publish($order, true);
    }

    private function publish(string $order, bool $scheduled): ResponseInterface
    {
        $response = $this->response->setHeader('Cache-Control', 'private, no-store');
        if (env('ewo.workOrderWrites', false) !== true) {
            return $response->setStatusCode(503)->setJSON(['error' => 'Work Order publication is disabled in this environment.']);
        }
        $identity = service('ewoIdentity');
        $viewer = $identity?->currentUser();
        if ($viewer === null || (int)$viewer['status'] !== 1) {
            return $response->setStatusCode(403)->setJSON(['error' => 'Draft publication permission is unavailable.']);
        }
        $id = filter_var($order, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return $response->setStatusCode(422)->setJSON(['error' => 'Check the Work Order ID.']);
        }
        try {
            $list = new WorkOrderList(Database::connect('ewo'));
            if ($scheduled && $list->missingColumns('scheduled') !== []) {
                return $response->setStatusCode(503)->setJSON(['error' => 'Scheduled Work Order schema is unavailable.']);
            }
            if (!($scheduled ? $list->publishScheduled($viewer, $id) :
                $list->publishDraft($viewer, $id, $identity->allows($viewer, 'manage_all_posts')))) {
                return $response->setStatusCode(404)->setJSON(['error' => 'A publishable Work Order was not found.']);
            }
            return $response->setJSON(['id' => $id]);
        } catch (DomainException) {
            return $response->setStatusCode(403)->setJSON(['error' => 'Work order scope is unavailable.']);
        } catch (\RuntimeException) {
            return $response->setStatusCode(503)->setJSON(['error' => 'Could not publish the Work Order.']);
        }
    }

    public function report(string $direction): ResponseInterface
    {
        return $this->list($direction, true);
    }

    public function detail(string $direction, string $id): ResponseInterface
    {
        $response = $this->response->setHeader('Cache-Control', 'private, no-store');
        $viewer = service('ewoIdentity')?->currentUser();
        if ($viewer === null) {
            return $response->setStatusCode(401)->setJSON(['error' => 'Your session has expired.']);
        }
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $page = $this->positiveInteger('page', 1, 1000000);
        if ($id === false || $page === false) {
            return $response->setStatusCode(422)->setJSON(['error' => 'Check the Work Order ID and page.']);
        }
        try {
            $list = new WorkOrderList(Database::connect('ewo'));
            if ($list->missingColumns($direction) !== []) {
                return $response->setStatusCode(503)->setJSON(['error' => 'Scheduled Work Order schema is unavailable.']);
            }
            $data = $list->detail($viewer, $direction, $id, $page);
            return $data === null
                ? $response->setStatusCode(404)->setJSON(['error' => 'Work Order not found.'])
                : $response->setJSON($data);
        } catch (DomainException) {
            return $response->setStatusCode(403)->setJSON(['error' => 'Work order scope is unavailable.']);
        } catch (DatabaseException) {
            return $response->setStatusCode(503)->setJSON(['error' => 'Work order detail is unavailable.']);
        }
    }


    private function list(string $direction, bool $report): ResponseInterface
    {
        $response = $this->response->setHeader('Cache-Control', 'private, no-store');
        $viewer = service('ewoIdentity')?->currentUser();
        if ($viewer === null) {
            return $response->setStatusCode(401)->setJSON(['error' => 'Your session has expired.']);
        }

        $page = $this->positiveInteger('page', 1, 1000000);
        $pageSize = $this->positiveInteger($this->request->getGet('show') === null ? 'per_page' : 'show', 15, 100);
        $filters = $this->filters();
        if ($page === false || $pageSize === false || $filters === null) {
            return $response->setStatusCode(422)->setJSON(['error' => 'Check the filter and pagination values.']);
        }

        try {
            $list = new WorkOrderList(Database::connect('ewo'));
            $missing = $list->missingColumns($direction, $report);
            if ($missing !== []) {
                return $response->setStatusCode(503)->setJSON([
                    'error' => 'This menu is unavailable because the legacy schema lacks ' . implode(', ', $missing) . '.',
                ]);
            }
            $data = $list->page($viewer, $direction, $filters, $page, $pageSize, $report);
            if (in_array($direction, ['drafts', 'scheduled'], true) && env('ewo.workOrderWrites', false) === true && (int)$viewer['status'] === 1) {
                $data['publish_csrf'] = ['header' => csrf_header(), 'hash' => csrf_hash()];
                if ($direction === 'drafts') {
                    $data['can_publish_scheduled_drafts'] = service('ewoIdentity')->allows($viewer, 'manage_all_posts');
                }
            }
            return $response->setJSON($data);
        } catch (DomainException) {
            return $response->setStatusCode(403)->setJSON(['error' => 'Work order scope is unavailable.']);
        } catch (DatabaseException) {
            return $response->setStatusCode(503)->setJSON(['error' => 'Work order data is unavailable.']);
        }
    }

    private function positiveInteger(string $name, int $default, int $max): int|false
    {
        return filter_var($this->request->getGet($name) ?? $default, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => $max]]);
    }

    private function filters(): ?array
    {
        $filters = [];
        foreach (self::FILTERS as $key) {
            $value = $this->request->getGet($key);
            if ($value === null) {
                continue;
            }
            if (!is_string($value) || strlen($value) > 255) {
                return null;
            }
            $filters[$key] = $key === 'q' ? trim($value) : $value;
        }
        $dateChoice = $this->request->getGet('dateChoice');
        $from = $this->request->getGet('tglAwal');
        $to = $this->request->getGet('tglAkhir');
        if ($dateChoice || $from || $to) {
            if (!in_array($dateChoice, ['created_at', 'edited_at'], true)
                || !$this->isDate($from) || !$this->isDate($to) || $from > $to) {
                return null;
            }
            $filters += ['dateChoice' => $dateChoice, 'tglAwal' => $from, 'tglAkhir' => $to];
        }
        return $filters;
    }

    private function isDate(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year);
    }
}

