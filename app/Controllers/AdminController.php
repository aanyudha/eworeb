<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

final class AdminController extends BaseController
{
    public function index(): ResponseInterface
    {
        return $this->response->setStatusCode(501)->setBody(view('admin/pending'));
    }
}

