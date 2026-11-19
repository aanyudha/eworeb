<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AdminDirectory;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use DomainException;

final class AdminDirectoryController extends BaseController
{
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
            return $response->setJSON($directory->page($resource, $viewer, trim($search), $page));
        } catch (DomainException) {
            return $response->setStatusCode(403)->setJSON(['error' => 'Directory scope is unavailable.']);
        } catch (DatabaseException) {
            return $response->setStatusCode(503)->setJSON(['error' => 'Directory data is unavailable.']);
        }
    }
}

