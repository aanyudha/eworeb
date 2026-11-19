<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\DashboardRepository;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

final class AdminController extends BaseController
{
    public function index(): ResponseInterface
    {
        $identity = service('ewoIdentity');
        $user = $identity?->currentUser();
        if ($user === null) {
            return redirect()->to(site_url('admin/login'));
        }

        $dashboard = new DashboardRepository(Database::connect('ewo'));
        if (!$dashboard->supports($user)) {
            return $this->response->setStatusCode(403)->setBody('Dashboard statistics are unavailable for this account scope.');
        }

        $filters = [];
        foreach (['corpnya', 'role', 'department'] as $name) {
            $value = $this->request->getGet($name);
            $filters[$name] = is_string($value) ? $value : null;
        }

        return $this->response->setBody(view('admin/dashboard', [
            'settings' => $identity->siteSettings(),
            'summary' => $dashboard->summary($user, $filters),
            'corporations' => $user['role'] === 'admin' ? $dashboard->corporations() : [],
            'filters' => $filters,
        ]));
    }
}
