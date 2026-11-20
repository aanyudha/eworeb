<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuthRepository;
use App\Services\WorkOrderCreate;
use App\Services\WorkOrderImages;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use DomainException;
use RuntimeException;

final class WorkOrderCreateController extends BaseController
{
    public function preview(): ResponseInterface
    {
        return $this->run($this->previewData(...));
    }

    public function save(): ResponseInterface
    {
        return $this->run(function (BaseConnection $db, array $viewer, array $settings): array {
            $create = new WorkOrderCreate($db);
            if (($reason = $create->writeBlocker()) !== null) {
                throw new DomainException($reason, 503);
            }
            $id = $create->create($viewer, $settings, $this->request->getPost());
            return ['id' => $id, 'csrf' => $this->csrf()];
        }, 201);
    }

    public function images(): ResponseInterface
    {
        return $this->run(function (BaseConnection $db, array $viewer, array $settings): array {
            $images = $this->imageService($db);
            $corp = $images->corporation($viewer, $this->request->getGet('corp_id'));
            $before = filter_var($this->request->getGet('before') ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($before === false) {
                throw new DomainException('Invalid image cursor.', 422);
            }
            $items = $images->list($viewer, $settings, $corp, $before);
            foreach ($items as &$item) {
                $item['preview'] = $item['preview'] ? site_url('api/v1/work-orders/create/images/' . $item['id']) . '?corp_id=' . $corp : null;
            }
            return ['items' => $items, 'canUpload' => $images->canUpload($viewer, $corp)];
        });
    }

    public function image(string $id): ResponseInterface
    {
        return $this->run(function (BaseConnection $db, array $viewer, array $settings) use ($id): ResponseInterface {
            $images = $this->imageService($db);
            $corp = $images->corporation($viewer, $this->request->getGet('corp_id'));
            try {
                $file = $images->file($images->choose($viewer, $settings, $corp, (int)$id));
            } catch (DomainException) {
                $file = null;
            }
            if ($file === null) {
                throw new DomainException('Image preview is unavailable.', 404);
            }
            return $this->response->download($file, null, true)->inline()->setHeader('Cache-Control', 'private, no-store')
                ->setHeader('X-Content-Type-Options', 'nosniff');
        });
    }

    public function uploadImage(): ResponseInterface
    {
        return $this->run(function (BaseConnection $db, array $viewer): array {
            $images = $this->imageService($db);
            $corp = $images->corporation($viewer, $this->request->getPost('corp_id'));
            return ['id' => $images->upload($viewer, $corp, $this->request->getFile('image')), 'csrf' => $this->csrf()];
        }, 201);
    }

    private function imageService(BaseConnection $db): WorkOrderImages
    {
        if (($reason = (new WorkOrderCreate($db))->writeBlocker()) !== null) {
            throw new DomainException($reason, 503);
        }
        return new WorkOrderImages($db);
    }

    private function run(callable $operation, int $status = 200): ResponseInterface
    {
        $response = $this->response->setHeader('Cache-Control', 'private, no-store');
        $identity = service('ewoIdentity');
        $viewer = $identity?->currentUser();
        if ($viewer === null) {
            return $response->setStatusCode(401)->setJSON(['error' => 'Your session has expired.']);
        }
        if ((int) $viewer['status'] !== 1) {
            return $response->setStatusCode(403)->setJSON(['error' => 'This account cannot create work orders.', 'csrf' => $this->csrf()]);
        }

        try {
            $db = Database::connect('ewo');
            $settings = (new AuthRepository($db))->generalSettings();
            if ($viewer['role'] !== 'admin' && (int) ($settings['email_verification'] ?? 0) === 1
                && (int) ($viewer['email_status'] ?? 0) === 0) {
                throw new DomainException('Confirm your email before creating a work order.', 403);
            }
            $result = $operation($db, $viewer, $settings ?? []);
            return $result instanceof ResponseInterface ? $result : $response->setStatusCode($status)->setJSON($result);
        } catch (DomainException $error) {
            return $response->setStatusCode($error->getCode())->setJSON(['error' => $error->getMessage(), 'csrf' => $this->csrf()]);
        } catch (RuntimeException $error) {
            log_message('error', 'Create Work Order request failed ({type}, {code}).', ['type' => $error::class, 'code' => $error->getCode()]);
            return $response->setStatusCode(503)->setJSON(['error' => 'Could not complete the Create Work Order request.', 'csrf' => $this->csrf()]);
        }
    }

    private function previewData(BaseConnection $db, array $viewer): array
    {
        $admin = $viewer['role'] === 'admin';
        $corporations = $db->table('corps')->select('id, name')->orderBy('corp_order')->orderBy('id');
        $departments = $db->table('departments')->select('id, name, corp_id')->orderBy('department_order')->orderBy('id');
        $locations = $db->table('locations')->select('id, name, corp_id, parent_id')->orderBy('location_order')->orderBy('id');
        if (!$admin) {
            $corporations->where('id', (int) $viewer['corp_id']);
            $departments->where(['corp_id' => (int) $viewer['corp_id'], 'id' => (int) $viewer['dept_id']]);
            $locations->where('corp_id', (int) $viewer['corp_id']);
        }

        $corporationOptions = $corporations->get()->getResultArray();
        $departmentOptions = $departments->get()->getResultArray();
        if (!$admin && ($corporationOptions === [] || $departmentOptions === [])) {
            throw new DomainException('Your Work Order corporation or department is unavailable.', 403);
        }

        $missing = [];
        foreach (['posts', 'posts_logs'] as $table) {
            if (!$db->tableExists($table) || !$db->fieldExists('published_at', $table)) {
                $missing[] = $table . '.published_at';
            }
        }

        $reason = $missing === [] ? (new WorkOrderCreate($db))->writeBlocker()
            : 'Saving is blocked by missing legacy columns (' . implode(', ', $missing) . ').';
        return [
            'reason' => $reason,
            'submission' => $reason === null ? ['endpoint' => site_url('api/v1/work-orders/create'), 'csrf' => $this->csrf()] : null,
            'imagesEndpoint' => $reason === null ? site_url('api/v1/work-orders/create/images') : null,
            'canChooseScope' => $admin,
            'canSchedule' => service('ewoIdentity')->allows($viewer, 'manage_all_posts'),
            'corporations' => $corporationOptions,
            'departments' => $departmentOptions,
            'locations' => $locations->get()->getResultArray(),
            'destinationRoles' => $db->table('roles_permissions')->select('id, role_name AS name')
                ->whereIn('role', WorkOrderCreate::DESTINATIONS)->orderBy('id')->get()->getResultArray(),
            'category' => $db->table('categories')->select('id, name')->where(['id' => 1, 'parent_id' => 0])
                ->get()->getRowArray(),
        ];
    }

    private function csrf(): array
    {
        return ['header' => csrf_header(), 'hash' => csrf_hash()];
    }
}
