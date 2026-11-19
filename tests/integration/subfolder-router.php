<?php

declare(strict_types=1);

$public = realpath(__DIR__ . '/../../public');
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if (str_starts_with($uri, '/eworeb/')) {
    $relative = substr($uri, strlen('/eworeb/'));
    $file = realpath($public . DIRECTORY_SEPARATOR . $relative);
    if ($file !== false && str_starts_with($file, $public . DIRECTORY_SEPARATOR) && is_file($file)) {
        $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
            'css' => 'text/css',
            'js' => 'text/javascript',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ico' => 'image/x-icon',
            default => null,
        };
        if ($mime !== null) {
            header('Content-Type: ' . $mime);
            readfile($file);
            return true;
        }
    }
}

$_SERVER['SCRIPT_NAME'] = '/eworeb/index.php';
require $public . '/index.php';
