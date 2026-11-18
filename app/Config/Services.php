<?php

namespace Config;

use App\Libraries\AdminIdentity;
use App\Libraries\LegacyPassword;
use App\Models\AuthRepository;
use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    public static function ewoIdentity(bool $getShared = true): ?AdminIdentity
    {
        if ($getShared) {
            return static::getSharedInstance('ewoIdentity');
        }

        $key = env('ewo.sessionKey');
        $database = config(Database::class)->ewo;
        if (!is_string($key) || $key === '' || empty($database['database']) || empty($database['username'])) {
            return null;
        }

        return new AdminIdentity(new AuthRepository(Database::connect('ewo')), self::session(), new LegacyPassword(), $key);
    }

    /*
     * public static function example($getShared = true)
     * {
     *     if ($getShared) {
     *         return static::getSharedInstance('example');
     *     }
     *
     *     return new \CodeIgniter\Example();
     * }
     */
}
