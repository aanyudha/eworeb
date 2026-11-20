<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuthRepository;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

final class WorkOrderCreateController extends BaseController
{
    public function preview(): ResponseInterface
    {
        $response = $this->response->setHeader('Cache-Control', 'private, no-store');
        $identity = service('ewoIdentity');
        $viewer = $identity?->currentUser();
        if ($viewer === null) {
            return $response->setStatusCode(401)->setJSON(['error' => 'Your session has expired.']);
        }
        if ((int) $viewer['status'] !== 1) {
            return $response->setStatusCode(403)->setJSON(['error' => 'This account cannot create work orders.']);
        }

        try {
            $db = Database::connect('ewo');
            $admin = $viewer['role'] === 'admin';
            $settings = (new AuthRepository($db))->generalSettings();
            if (!$admin && (int) ($settings['email_verification'] ?? 0) === 1
                && (int) ($viewer['email_status'] ?? 0) === 0) {
                return $response->setStatusCode(403)->setJSON(['error' => 'Confirm your email before creating a work order.']);
            }

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
                return $response->setStatusCode(403)->setJSON(['error' => 'Your Work Order corporation or department is unavailable.']);
            }

            $missing = [];
            foreach (['posts', 'posts_logs'] as $table) {
                if (!$db->tableExists($table) || !$db->fieldExists('published_at', $table)) {
                    $missing[] = $table . '.published_at';
                }
            }

            return $response->setJSON([
                'reason' => $missing === []
                    ? 'Saving requires isolated MySQL verification and owner approval.'
                    : 'Saving is blocked by missing legacy columns (' . implode(', ', $missing) . ').',
                'canChooseScope' => $admin,
                'canSchedule' => $identity->allows($viewer, 'manage_all_posts'),
                'corporations' => $corporationOptions,
                'departments' => $departmentOptions,
                'locations' => $locations->get()->getResultArray(),
                'destinationRoles' => $db->table('roles_permissions')->select('id, role_name AS name')
                    ->whereIn('role', ['author', 'author1', 'author2'])->orderBy('id')->get()->getResultArray(),
                'category' => $db->table('categories')->select('id, name')->where(['id' => 1, 'parent_id' => 0])
                    ->get()->getRowArray(),
            ]);
        } catch (DatabaseException) {
            return $response->setStatusCode(503)->setJSON(['error' => 'Create Work Order options are unavailable.']);
        }
    }
}
