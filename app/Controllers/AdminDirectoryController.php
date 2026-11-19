<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AdminDirectory;
use App\Models\MasterData;
use App\Models\UserManagement;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use DomainException;
use RuntimeException;

final class AdminDirectoryController extends BaseController
{
    public function roleOptions(string $record): ResponseInterface
    {
        return $this->roleRequest($record, static fn (AdminDirectory $directory, int $id): array =>
            $directory->roleOptions($id));
    }

    public function saveRole(string $record): ResponseInterface
    {
        return $this->roleRequest($record, function (AdminDirectory $directory, int $id): array {
            return $directory->saveRole($id, $this->request->getPost(AdminDirectory::ROLE_EDIT_FIELDS));
        });
    }

    private function roleRequest(string $record, callable $operation): ResponseInterface
    {
        $response = $this->response->setHeader('Cache-Control', 'private, no-store');
        $viewer = service('ewoIdentity')?->currentUser();
        if (env('ewo.accountWrites', false) !== true || (int)($viewer['status'] ?? 0) !== 1
            || (int)$viewer['role_id'] !== 1 || $viewer['role'] !== 'admin') {
            return $response->setStatusCode(403)->setJSON(['error' => 'Role editing is unavailable.']);
        }
        $id = filter_var($record, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return $response->setStatusCode(422)->setJSON(['error' => 'Check the role ID.']);
        }
        try {
            $result = $operation(new AdminDirectory(Database::connect('ewo')), $id);
            return $response->setJSON($result + ['csrf' => ['header' => csrf_header(), 'hash' => csrf_hash()]]);
        } catch (DomainException $error) {
            return $response->setStatusCode($error->getCode())->setJSON(['error' => $error->getMessage()]);
        } catch (RuntimeException) {
            return $response->setStatusCode(503)->setJSON(['error' => 'Could not complete the role request.']);
        }
    }

    public function options(string $resource, string $record = ''): ResponseInterface
    {
        return $this->writeRequest($resource, $record, static fn (MasterData $model, array $viewer, ?int $id): array =>
            $model->options($viewer, $resource, $id));
    }

    public function save(string $resource, string $record = ''): ResponseInterface
    {
        return $this->writeRequest($resource, $record, function (MasterData $model, array $viewer, ?int $id) use ($resource): array {
            return ['id' => $model->save($viewer, $resource, $id, $this->request->getPost(MasterData::FIELDS))];
        });
    }

    private function writeRequest(string $resource, string $record, callable $operation): ResponseInterface
    {
        $response = $this->response->setHeader('Cache-Control', 'private, no-store');
        $identity = service('ewoIdentity');
        $viewer = $identity?->currentUser();
        if ($viewer === null || !AdminDirectory::permits($identity, $viewer, $resource)
            || !MasterData::available($viewer, $resource)) {
            return $response->setStatusCode(403)->setJSON(['error' => 'Master-data editing is unavailable.']);
        }
        $id = $record === '' ? null : filter_var($record, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return $response->setStatusCode(422)->setJSON(['error' => 'Check the record ID.']);
        }
        try {
            $result = $operation(new MasterData(Database::connect('ewo')), $viewer, $id);
            return $response->setJSON($result + ['csrf' => ['header' => csrf_header(), 'hash' => csrf_hash()]]);
        } catch (DomainException $error) {
            return $response->setStatusCode($error->getCode())->setJSON(['error' => $error->getMessage()]);
        } catch (RuntimeException) {
            return $response->setStatusCode(503)->setJSON(['error' => 'Could not complete the master-data request.']);
        }
    }

    public function index(string $resource): ResponseInterface
    {
        $response = $this->response->setHeader('Cache-Control', 'private, no-store');
        $identity = service('ewoIdentity');
        $viewer = $identity?->currentUser();
        if ($viewer === null || !AdminDirectory::permits($identity, $viewer, $resource)) {
            return $response->setStatusCode(403)->setJSON(['error' => 'Directory access is unavailable.']);
        }

        $search = $this->request->getGet('q') ?? '';
        $page = filter_var($this->request->getGet('page') ?? 1, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        if (!is_string($search) || mb_strlen($search) > 100 || $page === false) {
            return $response->setStatusCode(422)->setJSON(['error' => 'Check the search and page values.']);
        }

        try {
            $directory = new AdminDirectory(Database::connect('ewo'));
            $data = $directory->page($resource, $viewer, trim($search), $page);
            if (MasterData::available($viewer, $resource)) {
                $data['editing'] = true;
                $data['canCreate'] = true;
                foreach ($data['items'] as &$item) {
                    $item['editable'] = !MasterData::protected($resource, (int)$item['id']);
                }
                unset($item);
            }
            if ($resource === 'roles' && env('ewo.accountWrites', false) === true && (int)$viewer['status'] === 1) {
                $data['editing'] = true;
                foreach ($data['items'] as &$item) {
                    $item['editable'] = (int)$item['id'] > 1 && $item['role'] !== 'admin';
                }
                unset($item);
            }
            if (in_array($resource, ['users', 'administrators'], true) && UserManagement::available($viewer)) {
                $data['editing'] = true;
                $data['canCreate'] = true;
                foreach ($data['items'] as &$item) {
                    $item['editable'] = UserManagement::canEdit((int)$viewer['id'], (int)$item['id']);
                }
                unset($item);
            }
            return $response->setJSON($data);
        } catch (DomainException) {
            return $response->setStatusCode(403)->setJSON(['error' => 'Directory scope is unavailable.']);
        } catch (DatabaseException) {
            return $response->setStatusCode(503)->setJSON(['error' => 'Directory data is unavailable.']);
        }
    }
}

