<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AdminDirectory;
use App\Models\WorkOrderList;
use App\Services\WorkOrderCreate;
use Config\Database;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

final class WorkspaceController extends BaseController
{
    public function index(): ResponseInterface
    {
        $manifest = FCPATH . 'workspace-assets/manifest.json';
        $entry = is_file($manifest)
            ? (json_decode((string) file_get_contents($manifest), true)['src/main.tsx'] ?? null)
            : null;
        if (!is_array($entry) || !is_string($entry['file'] ?? null)) {
            return $this->response->setStatusCode(503)->setBody('Workspace build is missing. Run npm run build in frontend.');
        }

        $identity = service('ewoIdentity');
        $viewer = $identity?->currentUser();
        $workOrderRole = $viewer !== null && $identity->allows($viewer, 'add_post')
            && (new \App\Models\DashboardRepository(\Config\Database::connect('ewo')))->supports($viewer)
            ? (int) $viewer['role_id'] : null;


        $directoryViews = [];
        if ($viewer !== null) {
            foreach (AdminDirectory::VIEWS as $slug => [, , $label]) {
                if (AdminDirectory::permits($identity, $viewer, $slug)) {
                    $directoryViews[] = ['path' => '/directory/' . $slug, 'label' => $label,
                        'endpoint' => site_url('api/v1/admin-directory/' . $slug)];
                }
            }
        }


        $workOrders = $workOrderRole !== null ? new WorkOrderList(Database::connect('ewo')) : null;
        $createAccess = $viewer !== null && $identity->allows($viewer, 'add_post');
        $createReady = false;
        if ($createAccess && env('ewo.workOrderCreateWrites', false) === true) {
            try {
                $createReady = (new WorkOrderCreate(Database::connect('ewo')))->writeBlocker() === null;
            } catch (RuntimeException) {
                // A metadata error must not prevent access to the rest of the Workspace.
            }
        }
        return $this->response->setHeader('Cache-Control', 'private, no-store')->setBody(view('admin/workspace', [
            'entry' => $entry,
            'branding' => BrandingController::assets(),
            'passwordAccess' => AccountController::canChangePassword($viewer),
            'settingsAccess' => $viewer !== null && $identity->allows($viewer, 'settings'),
            'workOrderRole' => $workOrderRole,
            'createWorkOrderAccess' => $createAccess,
            'createWorkOrderReady' => $createReady,
            'scheduleAccess' => $workOrders !== null && $identity->allows($viewer, 'manage_all_posts')
                && $workOrders->missingColumns('scheduled') === [],
            'replyAccess' => $workOrders !== null && env('ewo.workOrderWrites', false) === true
                && $identity->allows($viewer, 'manage_all_posts'),
            'directoryViews' => $directoryViews,
            'knowledgeAdmin' => ($viewer['role'] ?? null) === 'admin',
        ]));
    }
}
