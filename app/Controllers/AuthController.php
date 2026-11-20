<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\ResponseInterface;

final class AuthController extends BaseController
{
    public function adminLogin(): ResponseInterface
    {
        $identity = service('ewoIdentity');
        try {
            $user = $identity?->currentUser();
            if ($user !== null) {
                $identity->recordActivity($user, $this->request->getIPAddress());
            }
            $settings = $identity?->siteSettings();
        } catch (DatabaseException) {
            return $this->databaseUnavailable();
        }

        return $this->response->setBody(view('admin/login', [
            'settings' => $settings,
            'branding' => BrandingController::assets(),
            'messages' => session()->getFlashdata('messages') ?? [],
            'email'    => session()->getFlashdata('old_email') ?? '',
        ]));
    }

    public function adminLoginPost(): ResponseInterface
    {
        $identity = service('ewoIdentity');
        if ($identity === null) {
            return $this->response->setStatusCode(503)->setBody('EWO authentication is not configured.');
        }

        $email = $this->request->getPost('email');
        $password = $this->request->getPost('password');
        $email = is_string($email) ? $email : '';
        $password = is_string($password) ? $password : '';
        $errors = [];

        if (trim($email) === '' || mb_strlen($email) > 200) {
            $errors[] = 'email_invalid';
        }
        if (trim($password) === '' || strlen($password) > 72 || str_contains($password, "\0")) {
            $errors[] = 'password_invalid';
        }

        if ($errors === []) {
            try {
                $result = $identity->authenticate($email, $password, $this->request->getIPAddress());
            } catch (DatabaseException) {
                return $this->databaseUnavailable();
            }
            if ($result === '') {
                return redirect()->to(site_url('workspace'), 302)->withCookies();
            }
            $errors[] = $result;
        }

        session()->setFlashdata(['messages' => $errors, 'old_email' => $email]);

        return redirect()->to(site_url('admin/login'), 302)->withCookies();
    }

    private function databaseUnavailable(): ResponseInterface
    {
        return $this->response->setStatusCode(503)
            ->setBody('EWO database is unavailable or incompatible. Run php spark ewo:diagnose locally.');
    }

    public function logout(): ResponseInterface
    {
        $identity = service('ewoIdentity');
        $user = $identity?->currentUser();
        if ($user !== null) {
            $identity->recordActivity($user, $this->request->getIPAddress());
        }
        session()->destroy();

        return redirect()->to(site_url('admin/login'), 302)
            ->deleteCookie(config('Session')->cookieName)
            ->deleteCookie('a2n_remember_user_id');
    }
}

