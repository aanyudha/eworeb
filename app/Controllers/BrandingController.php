<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AccountImages;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use DomainException;
use RuntimeException;

final class BrandingController extends BaseController
{
    private const ASSETS = ['logo', 'favicon'];

    public static function assets(): array
    {
        if (env('ewo.accountMediaRoot', '') === '' && env('ewo.workOrderMediaRoot', '') === '') {
            return [];
        }
        try {
            $db = Database::connect('ewo');
            if (!$db->tableExists('visual_settings')) {
                return [];
            }
            $config = $db->table('visual_settings')->select(self::ASSETS)->where('id', 1)->get()->getRowArray() ?? [];
        } catch (RuntimeException) {
            return [];
        }
        $links = [];
        foreach (self::ASSETS as $kind) {
            if (AccountImages::resolve($kind, $config[$kind] ?? null) !== null) {
                $links[$kind] = site_url('brand/' . $kind) . '?v=' . substr(hash('sha256', $config[$kind]), 0, 16);
            }
        }
        return $links;
    }

    public function index(): ResponseInterface
    {
        $response = $this->response->setHeader('Cache-Control', 'private, no-store');
        try {
            $viewer = service('ewoIdentity')?->currentUser();
            $assets = self::assets();
            $items = [];
            foreach (self::ASSETS as $kind) {
                $items[$kind] = ['url' => $assets[$kind] ?? null, 'editable' => $this->canUpdate($viewer, $kind)];
            }
            return $response->setJSON(['items' => $items, 'csrf' => ['header' => csrf_header(), 'hash' => csrf_hash()]]);
        } catch (RuntimeException) {
            return $response->setStatusCode(503)->setJSON(['error' => 'Visual settings are unavailable.']);
        }
    }

    public function image(string $kind): ResponseInterface
    {
        if (!in_array($kind, self::ASSETS, true)) {
            return $this->response->setStatusCode(404);
        }
        try {
            $db = Database::connect('ewo');
            $row = $db->table('visual_settings')->select($kind)->where('id', 1)->get()->getRowArray();
            $file = AccountImages::resolve($kind, $row[$kind] ?? null);
            if ($file !== null) {
                return $this->response->download($file, null, true)->inline()->setHeader('Cache-Control', 'public, max-age=60')
                    ->setHeader('X-Content-Type-Options', 'nosniff');
            }
        } catch (RuntimeException) {
            return $this->response->setStatusCode(404);
        }
        return $this->response->setStatusCode(404);
    }

    public function save(string $kind): ResponseInterface
    {
        $response = $this->response->setHeader('Cache-Control', 'private, no-store');
        $viewer = service('ewoIdentity')?->currentUser();
        if (!$this->canUpdate($viewer, $kind)) {
            return $response->setStatusCode(403)->setJSON(['error' => 'Visual settings changes are unavailable.']);
        }
        try {
            (new AccountImages())->save(Database::connect('ewo'), $kind, $this->request->getFile($kind), (int)$viewer['id']);
            return $response->setJSON(['saved' => true]);
        } catch (DomainException $error) {
            return $response->setStatusCode($error->getCode())->setJSON(['error' => $error->getMessage()]);
        } catch (RuntimeException) {
            return $response->setStatusCode(503)->setJSON(['error' => 'Could not update the branding image.']);
        }
    }

    private function canUpdate(?array $viewer, string $kind): bool
    {
        return env('ewo.accountWrites', false) === true && (int)($viewer['status'] ?? 0) === 1
            && in_array($kind, self::ASSETS, true) && AccountImages::canStore($kind);
    }
}
