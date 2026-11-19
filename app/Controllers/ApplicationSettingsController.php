<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use DateTimeZone;
use DomainException;
use RuntimeException;
use Throwable;

final class ApplicationSettingsController extends BaseController
{
    private const FIELDS = [
        'application_name' => 255, 'about_footer' => 1000, 'optional_url_button_name' => 500,
        'copyright' => 500, 'contact_address' => 500, 'contact_email' => 255, 'contact_phone' => 255,
    ];

    public function index(): ResponseInterface
    {
        return $this->run(function (BaseConnection $db, array $viewer): array {
            $general = $this->general($db);
            $fields = $db->table('settings')->select(array_keys(self::FIELDS))
                ->where('lang_id', $general['site_lang'])->get()->getRowArray();
            if ($fields === null) {
                throw new RuntimeException('Settings for the active language are unavailable.');
            }
            return ['values' => ['timezone' => $general['timezone']] + $fields,
                'writable' => $this->canWrite($viewer), 'csrf' => ['header' => csrf_header(), 'hash' => csrf_hash()]];
        });
    }

    public function save(): ResponseInterface
    {
        return $this->run(function (BaseConnection $db, array $viewer): array {
            if (!$this->canWrite($viewer)) {
                throw new DomainException('Settings editing is unavailable.', 403);
            }
            $timezone = $this->request->getPost('timezone');
            if (!is_string($timezone) || !in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
                throw new DomainException('Enter a valid timezone identifier.', 422);
            }
            $data = [];
            foreach (self::FIELDS as $key => $limit) {
                $value = $this->request->getPost($key);
                if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit
                    || str_contains($value, "\0")) {
                    throw new DomainException('Check the application setting values.', 422);
                }
                $data[$key] = $value;
            }

            $db->transException(true);
            if (!$db->transBegin()) {
                throw new RuntimeException('Could not begin settings save.');
            }
            try {
                $general = $this->general($db, true);
                $settings = $db->table('settings')->where('lang_id', $general['site_lang']);
                if ($settings->countAllResults(false) !== 1 || !$settings->update($data)
                    || !$db->table('general_settings')->where('id', 1)->update(['timezone' => $timezone])
                    || !$db->transStatus() || !$db->transCommit()) {
                    throw new RuntimeException('Could not save application settings.');
                }
                return ['saved' => true];
            } catch (Throwable $error) {
                $db->transRollback();
                throw $error;
            }
        });
    }

    private function general(BaseConnection $db, bool $lock = false): array
    {
        $query = 'SELECT timezone, site_lang FROM general_settings WHERE id = 1';
        return $db->query($query . ($lock ? ' FOR UPDATE' : ''))->getRowArray()
            ?? throw new RuntimeException('Application settings are unavailable.');
    }

    private function canWrite(array $viewer): bool
    {
        return env('ewo.accountWrites', false) === true && (int)$viewer['status'] === 1;
    }

    private function run(callable $operation): ResponseInterface
    {
        $response = $this->response->setHeader('Cache-Control', 'private, no-store');
        $viewer = service('ewoIdentity')?->currentUser();
        if ($viewer === null) {
            return $response->setStatusCode(401)->setJSON(['error' => 'Your session has expired.']);
        }

        try {
            return $response->setJSON($operation(Database::connect('ewo'), $viewer));
        } catch (DomainException $error) {
            return $response->setStatusCode($error->getCode())->setJSON(['error' => $error->getMessage()]);
        } catch (RuntimeException) {
            return $response->setStatusCode(503)->setJSON(['error' => 'Application settings are unavailable.']);
        }
    }
}
