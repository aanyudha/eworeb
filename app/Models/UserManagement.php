<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Database\BaseConnection;
use DomainException;
use RuntimeException;
use Throwable;

final class UserManagement
{
    public const FIELDS = ['first_name', 'last_name', 'email', 'corp_id', 'dept_id', 'role_id', 'password', 'status'];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public static function available(?array $viewer): bool
    {
        return env('ewo.accountWrites', false) === true && (int)($viewer['status'] ?? 0) === 1
            && (int)$viewer['role_id'] === 1 && $viewer['role'] === 'admin';
    }

    public static function canEdit(int $viewerId, int $id): bool
    {
        return $id > 1 && $viewerId !== $id;
    }

    public function options(string $resource, ?int $id, int $viewerId): array
    {
        if ($id !== null) {
            return ['fields' => $this->record($resource, $id, $viewerId, false)];
        }
        $fields = ['first_name' => '', 'last_name' => '', 'email' => '', 'password' => '', 'corp_id' => '', 'dept_id' => ''];
        $choices = [];
        if ($resource === 'users') {
            $fields['role_id'] = '';
            $choices['role_id'] = $this->db->table('roles_permissions')->select('id AS value, role_name AS label')
                ->where(['id >' => 1, 'role !=' => 'admin'])->orderBy('role_name')->get()->getResultArray();
        }
        return ['fields' => $fields, 'choices' => $choices];
    }

    public function save(string $resource, ?int $id, int $viewerId, array $input): int
    {
        $data = [
            'first_name' => $this->name($input['first_name'] ?? null, 'first name'),
            'last_name' => $this->name($input['last_name'] ?? null, 'last name'),
            'email' => $this->email($input['email'] ?? null),
        ];
        if ($id === null) {
            $data += $this->registration($resource, $input);
        } else {
            $status = $input['status'] ?? null;
            if (!in_array($status, ['0', '1'], true)) {
                throw new DomainException('Select an account status.', 422);
            }
            $data['status'] = (int)$status;
        }

        $database = (string)$this->db->database;
        $lock = 'eworeb-users-' . substr(hash('sha256', $database), 0, 40);
        $acquired = $this->db->query('SELECT GET_LOCK(?, 5) AS acquired', [$lock])->getRowArray();
        if ((int)($acquired['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('Account changes are busy.');
        }
        try {
            return $this->persist($resource, $id, $viewerId, $data);
        } finally {
            $this->db->query('DO RELEASE_LOCK(?)', [$lock]);
        }
    }

    private function persist(string $resource, ?int $id, int $viewerId, array $data): int
    {
        $this->db->transException(true);
        if (!$this->db->transBegin()) {
            throw new RuntimeException('Could not begin account change.');
        }
        try {
            if ($id !== null) {
                $this->record($resource, $id, $viewerId, true);
            }
            $duplicate = $this->db->table('users')->where('email', $data['email']);
            if ($id !== null) {
                $duplicate->where('id !=', $id);
            }
            if ($duplicate->countAllResults() > 0) {
                throw new DomainException('The email address is already in use.', 409);
            }
            $query = $this->db->table('users');
            $saved = $id === null ? $query->insert($data) : $query->where('id', $id)->update($data);
            $number = $id ?? (int)$this->db->insertID();
            if (!$saved || !$this->db->transStatus() || !$this->db->transCommit()) {
                throw new RuntimeException('Could not save the account.');
            }
            return $number;
        } catch (Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
    }

    private function record(string $resource, int $id, int $viewerId, bool $lock): array
    {
        if (!self::canEdit($viewerId, $id)) {
            throw new DomainException('This account is protected.', 403);
        }
        $query = $this->db->table('users')->select('first_name,last_name,email,status')->where('id', $id)
            ->where($resource === 'administrators' ? 'role' : 'role !=', 'admin');
        $row = $lock ? $this->db->query($query->getCompiledSelect() . ' FOR UPDATE')->getRowArray()
            : $query->get()->getRowArray();
        if ($row === null) {
            throw new DomainException('Account not found.', 404);
        }
        return $row;
    }

    private function registration(string $resource, array $input): array
    {
        $corp = $this->id($input['corp_id'] ?? null);
        $dept = $this->id($input['dept_id'] ?? null);
        $role = $resource === 'administrators' ? 1 : $this->id($input['role_id'] ?? null);
        $password = $input['password'] ?? null;
        if (!is_string($password) || !mb_check_encoding($password, 'UTF-8') || mb_strlen($password) < 2
            || mb_strlen($password) > 50 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new DomainException('Enter a valid initial password.', 422);
        }

        $roleRow = $this->db->table('roles_permissions')->select('role')->where('id', $role)->get()->getRowArray();
        $department = $this->db->table('departments d')->select('d.id')->join('corps c', 'c.id = d.corp_id')
            ->where(['d.id' => $dept, 'd.corp_id' => $corp])->get()->getRowArray();
        if ($roleRow === null || ($resource === 'administrators') !== ($roleRow['role'] === 'admin')
            || $department === null) {
            throw new DomainException('Select a valid role and a department in the selected corporation.', 422);
        }
        return ['password' => password_hash($password, PASSWORD_BCRYPT), 'role' => $roleRow['role'], 'role_id' => $role,
            'corp_id' => $corp, 'dept_id' => $dept, 'status' => 1, 'email_status' => 1, 'user_type' => 'registered',
            'token' => bin2hex(random_bytes(24))];
    }

    private function id(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        if ($id === false) {
            throw new DomainException('Select a valid corporation, department and role.', 422);
        }
        return $id;
    }

    private function email(mixed $value): string
    {
        $email = is_string($value) ? trim($value) : '';
        if (strlen($email) > 200 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('Enter a valid email address.', 422);
        }
        return $email;
    }

    private function name(mixed $value, string $field): string
    {
        $name = is_string($value) ? trim($value) : '';
        if (!mb_check_encoding($name, 'UTF-8') || mb_strlen($name) < 2 || mb_strlen($name) > 100
            || strip_tags($name) !== $name || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            throw new DomainException('Enter a valid ' . $field . '.', 422);
        }
        return $name;
    }
}
