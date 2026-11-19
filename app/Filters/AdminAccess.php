<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

final class AdminAccess implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $permissions = $arguments ?? [];
        $json = in_array('json', $permissions, true);
        $identity = session()->get('vr_sess_logged_in') ? service('ewoIdentity') : null;
        $user = $identity?->currentUser();
        if ($user === null) {
            return $json
                ? $this->reject(401, 'Your session has expired.')
                : redirect()->to(site_url('admin/login'));
        }

        if (!$json) {
            $identity->recordActivity($user, $request->getIPAddress());
        }

        foreach ($permissions as $permission) {
            if ($permission !== 'json' && !$identity->allows($user, $permission)) {
                return $json
                    ? $this->reject(403, 'You do not have permission to view the dashboard.')
                    : service('response')->setStatusCode(403)->setBody('Forbidden');
            }
        }

        return null;
    }

    private function reject(int $status, string $message): ResponseInterface
    {
        return service('response')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setStatusCode($status)
            ->setJSON(['error' => $message]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
