<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AdminDirectory;
use CodeIgniter\HTTP\ResponseInterface;

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


        return $this->response->setHeader('Cache-Control', 'private, no-store')->setBody(view('admin/workspace', [
            'entry' => $entry,
            'workOrderRole' => $workOrderRole,
            'scheduleAccess' => $workOrderRole !== null && $identity->allows($viewer, 'manage_all_posts'),
            'directoryViews' => $directoryViews,
            'knowledgeAdmin' => ($viewer['role'] ?? null) === 'admin',
        ]));
    }
}
