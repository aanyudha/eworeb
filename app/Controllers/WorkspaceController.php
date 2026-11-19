<?php

declare(strict_types=1);

namespace App\Controllers;

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

        return $this->response->setHeader('Cache-Control', 'private, no-store')->setBody(view('admin/workspace', [
            'entry' => $entry,
        ]));
    }
}
