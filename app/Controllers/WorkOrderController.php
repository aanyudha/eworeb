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

    public function report(string $direction): ResponseInterface
    {
        return $this->list($direction, true);
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
            return $response->setJSON($list->page($viewer, $direction, $filters, $page, $pageSize, $report));
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

