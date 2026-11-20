<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Database\BaseConnection;
use DomainException;
use RuntimeException;
use Throwable;

final class UserManagement
{
    public const FIELDS = ['first_name', 'last_name', 'email', 'corp_id', 'dept_id', 'role_id', 'password', 'status', 'confirm_email'];

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
            $fields = $this->record($resource, $id, $viewerId, false);
            $confirmed = (int) $fields['email_status'] === 1;
            unset($fields['email_status']);
            $fields['password'] = '';
            if (!$confirmed) {
                $fields['confirm_email'] = 0;
            }
            $role = $this->roles()->where('id', $fields['role_id'])->countAllResults() > 0;
            if (!$role) {
                unset($fields['role_id']);
            }
            return ['fields' => $fields, 'checks' => ['confirm_email'], 'choices' => $this->scopeChoices($fields) + [
                'role_id' => $this->roles()->select('id AS value, role_name AS label')->orderBy('role_name')->get()->getResultArray(),
            ]];
        }
        $fields = ['first_name' => '', 'last_name' => '', 'email' => '', 'password' => '', 'corp_id' => '', 'dept_id' => ''];
        $choices = [];
        if ($resource === 'users') {
            $fields['role_id'] = '';
            $choices['role_id'] = $this->db->table('roles_permissions')->select('id AS value, role_name AS label')
                ->where(['id >' => 1, 'role !=' => 'admin'])->orderBy('role_name')->get()->getResultArray();
        }
        return ['fields' => $fields, 'choices' => $choices + $this->scopeChoices($fields)];
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
            $password = $input['password'] ?? '';
            if ($password !== '') {
                $data['password'] = $this->passwordHash($password, 4);
            }
        }

        return $this->withAccountLock(fn (): int => $this->persist($resource, $id, $viewerId, $data, $input));
    }

    public function changeOwnEmail(array $viewer, mixed $input): void
    {
        $email = $this->email($input);
        $this->withAccountLock(function () use ($viewer, $email): void {
            $this->db->transException(true);
            if (!$this->db->transBegin()) {
                throw new RuntimeException('Could not begin profile change.');
            }
            try {
                $general = $this->db->query('SELECT email_verification FROM general_settings WHERE id = 1 FOR SHARE')->getRowArray();
                if (!isset($general['email_verification']) || (int)$general['email_verification'] !== 0) {
                    throw new DomainException('Email changes require a verified email activation flow.', 409);
                }
                $id = (int)$viewer['id'];
                $user = $this->db->query('SELECT email, password, status FROM users WHERE id = ? FOR UPDATE', [$id])->getRowArray();
                if ($user === null || (int)$user['status'] !== 1 || $user['email'] !== $viewer['email']
                    || $user['password'] !== $viewer['password']) {
                    throw new DomainException('The account changed. Refresh and try again.', 409);
                }
                if ($this->db->table('users')->where(['email' => $email, 'id !=' => $id])->countAllResults() > 0) {
                    throw new DomainException('The email address is already in use.', 409);
                }
                if (!$this->db->table('users')->where('id', $id)->update(['email' => $email])
                    || !$this->db->transStatus() || !$this->db->transCommit()) {
                    throw new RuntimeException('Could not save the profile.');
                }
            } catch (Throwable $error) {
                $this->db->transRollback();
                throw $error;
            }
        });
    }

    private function withAccountLock(callable $operation): mixed
    {
        $database = (string)$this->db->database;
        $lock = 'eworeb-users-' . substr(hash('sha256', $database), 0, 40);
        $acquired = $this->db->query('SELECT GET_LOCK(?, 5) AS acquired', [$lock])->getRowArray();
        if ((int)($acquired['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('Account changes are busy.');
        }
        try {
            return $operation();
        } finally {
            $this->db->query('DO RELEASE_LOCK(?)', [$lock]);
        }
    }

    private function persist(string $resource, ?int $id, int $viewerId, array $data, array $input): int
    {
        $this->db->transException(true);
        if (!$this->db->transBegin()) {
            throw new RuntimeException('Could not begin account change.');
        }
        try {
            if ($id !== null) {
                $old = $this->record($resource, $id, $viewerId, true);
                if (isset($input['corp_id']) || isset($input['dept_id'])) {
                    $corpInput = $input['corp_id'] ?? null;
                    $deptInput = $input['dept_id'] ?? null;
                    if ($corpInput !== (string) $old['corp_id'] || $deptInput !== (string) $old['dept_id']) {
                        $corp = $this->id($corpInput);
                        $dept = $this->id($deptInput);
                        $this->validateScope($corp, $dept);
                        $data += ['corp_id' => $corp, 'dept_id' => $dept];
                    }
                }
                if (isset($input['role_id']) && $this->id($input['role_id']) !== (int) $old['role_id']) {
                    $role = $this->roles()->select('id, role')->where('id', $this->id($input['role_id']))->get()->getRowArray();
                    if ($role === null || ((int) $role['id'] === 1) !== ($role['role'] === 'admin')) {
                        throw new DomainException('Select an available account role.', 422);
                    }
                    $data += ['role_id' => (int) $role['id'], 'role' => $role['role']];
                }
                $confirm = $input['confirm_email'] ?? '0';
                if (!in_array($confirm, ['0', '1'], true)) {
                    throw new DomainException('Check the email confirmation choice.', 422);
                }
                $emailChanged = $old['email'] !== $data['email'];
                if ($emailChanged || ($confirm === '1' && (int) $old['email_status'] !== 1)) {
                    $data['email_status'] = $confirm === '1' ? 1 : 0;
                }
                if (isset($data['email_status']) || isset($data['role_id']) || isset($data['corp_id'])
                    || ((int) $old['status'] === 1 && $data['status'] === 0)) {
                    $data['token'] = bin2hex(random_bytes(24));
                }
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
        $query = $this->db->table('users')->select('first_name,last_name,email,status,role_id,corp_id,dept_id,email_status')->where('id', $id)
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
        $password = $this->passwordHash($input['password'] ?? null, 2);

        $roleRow = $this->db->table('roles_permissions')->select('role')->where('id', $role)->get()->getRowArray();
        $this->validateScope($corp, $dept);
        if ($roleRow === null || ($resource === 'administrators') !== ($roleRow['role'] === 'admin')) {
            throw new DomainException('Select a valid role and a department in the selected corporation.', 422);
        }
        return ['password' => $password, 'role' => $roleRow['role'], 'role_id' => $role,
            'corp_id' => $corp, 'dept_id' => $dept, 'status' => 1, 'email_status' => 1, 'user_type' => 'registered',
            'token' => bin2hex(random_bytes(24))];
    }

    private function scopeChoices(array $fields): array
    {
        $corporations = $this->db->table('corps')->select('id AS value, name AS label')->orderBy('corp_order')->get()->getResultArray();
        $departments = $this->db->table('departments')->select('id AS value, name AS label, corp_id')
            ->orderBy('department_order')->get()->getResultArray();
        if ($fields['corp_id'] !== '' && !in_array((int) $fields['corp_id'], array_map('intval', array_column($corporations, 'value')), true)) {
            $corporations[] = ['value' => $fields['corp_id'], 'label' => 'Current legacy corporation (' . $fields['corp_id'] . ')'];
        }
        $projectDepartments = array_filter($departments, fn (array $department): bool => (int) $department['corp_id'] === (int) $fields['corp_id']);
        if ($fields['dept_id'] !== '' && !in_array((int) $fields['dept_id'], array_map('intval', array_column($projectDepartments, 'value')), true)) {
            $departments[] = ['value' => $fields['dept_id'], 'label' => 'Current legacy department (' . $fields['dept_id'] . ')',
                'corp_id' => $fields['corp_id']];
        }
        return ['corp_id' => $corporations, 'dept_id' => $departments];
    }

    private function validateScope(int $corp, int $dept): void
    {
        $query = $this->db->table('departments d')->join('corps c', 'c.id = d.corp_id')
            ->where(['d.id' => $dept, 'd.corp_id' => $corp]);
        if ($query->countAllResults() !== 1) {
            throw new DomainException('Select a valid department in the selected corporation.', 422);
        }
    }

    private function roles(): \CodeIgniter\Database\BaseBuilder
    {
        return $this->db->table('roles_permissions');
    }

    private function passwordHash(mixed $password, int $minimum): string
    {
        if (!is_string($password) || trim($password) === '' || !mb_check_encoding($password, 'UTF-8') || mb_strlen($password) < $minimum
            || mb_strlen($password) > 50 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new DomainException('Enter a valid account password.', 422);
        }
        return password_hash($password, PASSWORD_BCRYPT);
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
