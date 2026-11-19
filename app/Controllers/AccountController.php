<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\LegacyPassword;
use App\Models\AuthRepository;
use App\Models\UserManagement;
use App\Services\AccountImages;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use DomainException;
use RuntimeException;

final class AccountController extends BaseController
{
    public function profile(): ResponseInterface
    {
        $response = $this->response->setHeader('Cache-Control', 'private, no-store');
        $viewer = service('ewoIdentity')?->currentUser();
        if ($viewer === null) {
            return $response->setStatusCode(401)->setJSON(['error' => 'Your session has expired.']);
        }
        $emailEditable = false;
        if (self::canChangePassword($viewer)) {
            $general = (new AuthRepository(Database::connect('ewo')))->generalSettings();
            $emailEditable = isset($general['email_verification']) && (int)$general['email_verification'] === 0;
        }
        return $response->setJSON(['values' => array_intersect_key($viewer,
            array_flip(['first_name', 'last_name', 'email', 'corp_id', 'dept_id'])),
            'email_editable' => $emailEditable,
            'avatar_url' => AccountImages::resolve('avatar', $viewer['avatar'] ?? null) === null ? null
                : site_url('api/v1/account/profile/avatar'),
            'avatar_editable' => $this->canUpdateAvatar($viewer),
            'csrf' => ['header' => csrf_header(), 'hash' => csrf_hash()]]);
    }

    public function avatar(): ResponseInterface
    {
        $viewer = service('ewoIdentity')?->currentUser();
        $file = AccountImages::resolve('avatar', $viewer['avatar'] ?? null);
        if ($file === null) {
            return $this->response->setStatusCode(404);
        }
        return $this->response->download($file, null, true)->inline()->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff');
    }

    public function updateAvatar(): ResponseInterface
    {
        $response = $this->response->setHeader('Cache-Control', 'private, no-store');
        $viewer = service('ewoIdentity')?->currentUser();
        if (!$this->canUpdateAvatar($viewer)) {
            return $response->setStatusCode(403)->setJSON(['error' => 'Avatar changes are unavailable.']);
        }
        try {
            (new AccountImages())->save(Database::connect('ewo'), 'avatar', $this->request->getFile('avatar'), (int)$viewer['id']);
            return $response->setJSON(['saved' => true]);
        } catch (DomainException $error) {
            return $response->setStatusCode($error->getCode())->setJSON(['error' => $error->getMessage()]);
        } catch (RuntimeException) {
            return $response->setStatusCode(503)->setJSON(['error' => 'Could not update the avatar.']);
        }
    }

    private function canUpdateAvatar(?array $viewer): bool
    {
        return env('ewo.accountWrites', false) === true && (int)($viewer['status'] ?? 0) === 1 && AccountImages::canStore('avatar');
    }

    public function changeProfileEmail(): ResponseInterface
    {
        $response = $this->response->setHeader('Cache-Control', 'private, no-store');
        $viewer = service('ewoIdentity')?->currentUser();
        if (!self::canChangePassword($viewer)) {
            return $response->setStatusCode(403)->setJSON(['error' => 'Profile email changes are unavailable.']);
        }
        $password = $this->request->getPost('old_password');
        if (!is_string($password) || strlen($password) > 1024 || !(new LegacyPassword())->verify($password, $viewer['password'])) {
            return $response->setStatusCode(422)->setJSON(['error' => 'Enter your current password.']);
        }

        try {
            (new UserManagement(Database::connect('ewo')))->changeOwnEmail($viewer, $this->request->getPost('email'));
            return $response->setJSON(['saved' => true]);
        } catch (DomainException $error) {
            return $response->setStatusCode($error->getCode())->setJSON(['error' => $error->getMessage()]);
        } catch (RuntimeException) {
            return $response->setStatusCode(503)->setJSON(['error' => 'Could not update the profile email.']);
        }
    }

    public function managedUserForm(string $resource, string $record = ''): ResponseInterface
    {
        return $this->manage($resource, $record, static fn (UserManagement $users, ?int $id, int $viewerId): array =>
            $users->options($resource, $id, $viewerId));
    }

    public function saveManagedUser(string $resource, string $record = ''): ResponseInterface
    {
        return $this->manage($resource, $record, function (UserManagement $users, ?int $id, int $viewerId) use ($resource): array {
            return ['id' => $users->save($resource, $id, $viewerId, $this->request->getPost(UserManagement::FIELDS))];
        });
    }

    private function manage(string $resource, string $record, callable $operation): ResponseInterface
    {
        $response = $this->response->setHeader('Cache-Control', 'private, no-store');
        $viewer = service('ewoIdentity')?->currentUser();
        if (!UserManagement::available($viewer) || !in_array($resource, ['users', 'administrators'], true)) {
            return $response->setStatusCode(403)->setJSON(['error' => 'Account editing is unavailable.']);
        }
        $id = $record === '' ? null : filter_var($record, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return $response->setStatusCode(422)->setJSON(['error' => 'Check the account ID.']);
        }
        try {
            $result = $operation(new UserManagement(Database::connect('ewo')), $id, (int)$viewer['id']);
            return $response->setJSON($result + ['csrf' => ['header' => csrf_header(), 'hash' => csrf_hash()]]);
        } catch (DomainException $error) {
            return $response->setStatusCode($error->getCode())->setJSON(['error' => $error->getMessage()]);
        } catch (RuntimeException) {
            return $response->setStatusCode(503)->setJSON(['error' => 'Could not complete the account request.']);
        }
    }

    public static function canChangePassword(?array $viewer): bool
    {
        $hash = $viewer['password'] ?? null;
        return env('ewo.accountWrites', false) === true && (int)($viewer['status'] ?? 0) === 1
            && is_string($hash) && $hash !== '' && $hash[0] !== '*';
    }

    public function passwordForm(): ResponseInterface
    {
        $response = $this->response->setHeader('Cache-Control', 'private, no-store');
        if (!self::canChangePassword(service('ewoIdentity')?->currentUser())) {
            return $response->setStatusCode(403)->setJSON(['error' => 'Password changes are unavailable.']);
        }
        return $response->setJSON(['csrf' => ['header' => csrf_header(), 'hash' => csrf_hash()]]);
    }

    public function changePassword(): ResponseInterface
    {
        $response = $this->response->setHeader('Cache-Control', 'private, no-store');
        $viewer = service('ewoIdentity')?->currentUser();
        if (!self::canChangePassword($viewer)) {
            return $response->setStatusCode(403)->setJSON(['error' => 'Password changes are unavailable.']);
        }

        $old = $this->request->getPost('old_password');
        $new = $this->request->getPost('password');
        $confirm = $this->request->getPost('password_confirm');
        if (!is_string($old) || $old === '' || strlen($old) > 1024 || !is_string($new)
            || !mb_check_encoding($new, 'UTF-8') || mb_strlen($new) < 4 || mb_strlen($new) > 50
            || strlen($new) > 72 || str_contains($new, "\0") || $new !== $confirm) {
            return $response->setStatusCode(422)->setJSON(['error' => 'Enter your current password and matching new passwords.']);
        }
        if (!(new LegacyPassword())->verify($old, $viewer['password'])) {
            return $response->setStatusCode(422)->setJSON(['error' => 'The current password is incorrect.']);
        }

        try {
            $users = new AuthRepository(Database::connect('ewo'));
            if (!$users->changePassword((int)$viewer['id'], $viewer['password'], password_hash($new, PASSWORD_BCRYPT))) {
                return $response->setStatusCode(409)->setJSON(['error' => 'The account changed. Refresh and try again.']);
            }
        } catch (RuntimeException) {
            return $response->setStatusCode(503)->setJSON(['error' => 'Could not change the password.']);
        }
        return $response->setJSON(['saved' => true]);
    }
}
