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
        if (!session()->get('vr_sess_logged_in')) {
            return redirect()->to(site_url('admin/login'));
        }

        $identity = service('ewoIdentity');
        $user = $identity?->currentUser();
        if ($user === null) {
            return redirect()->to(site_url('admin/login'));
        }

        $identity->recordActivity($user, $request->getIPAddress());
        foreach ($arguments ?? [] as $permission) {
            if (!$identity->allows($user, $permission)) {
                return redirect()->to(site_url('/'));
            }
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}

