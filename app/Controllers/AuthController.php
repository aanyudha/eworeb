<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

final class AuthController extends BaseController
{
    public function adminLogin(): ResponseInterface
    {
        $identity = service('ewoIdentity');
        $user = $identity?->currentUser();
        if ($user !== null) {
            $identity->recordActivity($user, $this->request->getIPAddress());
        }

        return $this->response->setBody(view('admin/login', [
            'settings' => $identity?->siteSettings(),
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
        if (trim($password) === '' || mb_strlen($password) > 30) {
            $errors[] = 'password_invalid';
        }

        if ($errors === []) {
            $result = $identity->authenticate($email, $password, $this->request->getIPAddress());
            if ($result === '') {
                return redirect()->to(site_url('admin'), 302)->withCookies();
            }
            $errors[] = $result;
        }

        session()->setFlashdata(['messages' => $errors, 'old_email' => $email]);

        return redirect()->to(site_url('admin/login'), 302)->withCookies();
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

