<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\AuthRepository;
use CodeIgniter\Session\SessionInterface;
use DateTimeImmutable;
use DateTimeZone;

final class AdminIdentity
{
    public function __construct(
        private readonly AuthRepository $users,
        private readonly SessionInterface $session,
        private readonly LegacyPassword $passwords,
        private readonly string $sessionKey,
    ) {
    }

    public function authenticate(string $email, string $password, string $ip): string
    {
        $user = $this->users->findByEmail($email);
        if ($user === null || !$this->passwords->verify($password, (string) $user['password'])) {
            return 'login_error';
        }
        if ((int) $user['status'] !== 1) {
            return 'message_ban_error';
        }

        $this->session->regenerate(true);
        $this->session->set([
            'vr_sess_user_id'      => $user['id'],
            'vr_sess_user_email'   => $user['email'],
            'vr_sess_user_role'    => $user['role'],
            'vr_sess_logged_in'    => true,
            'vr_sess_ip_address'   => $ip,
            'vr_sess_user_role_id' => $user['role_id'],
            'vr_sess_app_key'      => $this->sessionKey,
            'vr_sess_account_state' => $this->accountState($user),
        ]);

        return '';
    }

    public function currentUser(): ?array
    {
        if ($this->session->get('vr_sess_logged_in') != true
            || $this->session->get('vr_sess_app_key') !== $this->sessionKey
        ) {
            return null;
        }

        $user = $this->users->findById((int) $this->session->get('vr_sess_user_id'));
        $state = $this->session->get('vr_sess_account_state');
        if ($user === null || (int) $user['status'] !== 1 || !is_string($state)
            || !hash_equals($this->accountState($user), $state)) {
            return null;
        }
        return $user;
    }

    private function accountState(array $user): string
    {
        return hash_hmac('sha256', serialize([(int) $user['id'], $user['password'], $user['role'], (int) $user['role_id'],
            (int) ($user['corp_id'] ?? 0), (int) ($user['dept_id'] ?? 0), $user['token'] ?? null]), $this->sessionKey);
    }

    public function allows(array $user, string $permission): bool
    {
        $role = $this->users->findRole((string) $user['role']);

        return $role !== null && ($user['role'] === 'admin' || ($role[$permission] ?? 0) == 1);
    }

    public function recordActivity(array $user, string $ip): void
    {
        $general = $this->users->generalSettings();
        $zone = empty($general['timezone']) ? null : new DateTimeZone($general['timezone']);
        $time = new DateTimeImmutable('now', $zone);
        $this->users->recordActivity((int) $user['id'], $time->format('Y-m-d H:i:s'), $ip);
    }

    public function siteSettings(): ?array
    {
        $general = $this->users->generalSettings();
        $languageId = (int) ($general['site_lang'] ?? 1);

        return $this->users->siteSettings($languageId);
    }
}

