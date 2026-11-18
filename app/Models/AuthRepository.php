<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Database\BaseConnection;

final class AuthRepository
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function findByEmail(string $email): ?array
    {
        return $this->database->table('users')->where('email', $email)->get()->getRowArray();
    }

    public function findById(int $id): ?array
    {
        return $this->database->table('users')->where('id', $id)->get()->getRowArray();
    }

    public function findRole(string $role): ?array
    {
        return $this->database->table('roles_permissions')->where('role', $role)->get()->getRowArray();
    }

    public function recordActivity(int $userId, string $time, string $ip): void
    {
        $activity = ['last_seen' => $time, 'ip_address' => $ip];
        $this->database->table('users')->where('id', $userId)->update($activity);
        $this->database->table('users_logs')->insert(['user_id' => $userId] + $activity);
    }

    public function generalSettings(): ?array
    {
        return $this->database->table('general_settings')->where('id', 1)->get()->getRowArray();
    }

    public function siteSettings(int $languageId): ?array
    {
        return $this->database->table('settings')->where('lang_id', $languageId)->get()->getRowArray();
    }
}

