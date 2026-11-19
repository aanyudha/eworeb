<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

final class LocalDebugToolbar extends DebugToolbar
{
    public static function isLoopback(RequestInterface $request): bool
    {
        return $request instanceof IncomingRequest
            && in_array($request->getIPAddress(), ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (!CI_DEBUG || !$request instanceof IncomingRequest || !self::isLoopback($request)) {
            return null;
        }
        $path = trim($request->getUri()->getRoutePath(), '/');
        if ($path === '' || $path === 'kb' || str_starts_with($path, 'kb/')
            || str_starts_with($path, 'api/v1/kb/') || str_starts_with($path, 'api/v1/admin/kb/')) {
            return null;
        }
        return parent::after($request, $response, $arguments);
    }
}
