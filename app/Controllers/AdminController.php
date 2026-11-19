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
        $data = $this->dashboardData();
        if ($data === null) {
            return $this->response->setStatusCode(403)->setBody('Dashboard statistics are unavailable for this account scope.');
        }

        return $this->response->setBody(view('admin/dashboard', $data));
    }

    public function workspaceDashboard(): ResponseInterface
    {
        $data = $this->dashboardData();
        $this->response->setHeader('Cache-Control', 'private, no-store');
        if ($data === null) {
            return $this->response->setStatusCode(403)->setJSON(['error' => 'Dashboard scope is unavailable.']);
        }

        return $this->response->setJSON($data);
    }

    private function dashboardData(): ?array
    {
        $identity = service('ewoIdentity');
        $user = $identity?->currentUser();
        if ($user === null) {
            return null;
        }

        $dashboard = new DashboardRepository(Database::connect('ewo'));
        if (!$dashboard->supports($user)) {
            return null;
        }

        $filters = [];
        foreach (['corpnya', 'role', 'department'] as $name) {
            $value = $this->request->getGet($name);
            $filters[$name] = is_string($value) ? $value : null;
        }

        $settings = $identity->siteSettings();
        $isAdmin = $user['role'] === 'admin';

        return [
            'settings' => ['application_name' => $settings['application_name'] ?? 'EWO'],
            'summary' => $dashboard->summary($user, $filters),
            'corporations' => $isAdmin ? $dashboard->corporations() : [],
            'filters' => $filters,
            'canFilter' => $isAdmin,
            'viewer' => [
                'name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
                'role' => $user['role'],
            ],
        ];
    }
}
