<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->setAutoRoute(false);
$routes->get('/', 'KnowledgeController::index');
$routes->get('kb', 'KnowledgeController::index');
$routes->get('kb/(:segment)', 'KnowledgeController::index/$1');
$routes->get('api/v1/kb/categories', 'KnowledgeController::categories');
$routes->get('api/v1/kb/articles', 'KnowledgeController::articles');
$routes->get('api/v1/kb/articles/(:segment)', 'KnowledgeController::article/$1');

$routes->group('api/v1/admin/kb', ['filter' => 'ewo-admin:admin_panel,json,administrator'], static function (RouteCollection $routes): void {
    $routes->get('articles', 'KnowledgeAdminController::articles');
    $routes->get('articles/(:segment)', 'KnowledgeAdminController::article/$1');
    $routes->post('articles', 'KnowledgeAdminController::create');
    $routes->put('articles/(:segment)', 'KnowledgeAdminController::update/$1');
    $routes->post('articles/(:segment)/publish', 'KnowledgeAdminController::publish/$1');
    $routes->post('articles/(:segment)/withdraw', 'KnowledgeAdminController::withdraw/$1');
});
$routes->get('admin', 'AdminController::index', ['filter' => 'ewo-admin:admin_panel']);
$routes->get('admin/login', 'AuthController::adminLogin');
$routes->post('auth_controller/admin_login_post', 'AuthController::adminLoginPost');
$routes->get('logout', 'AuthController::logout');

$routes->get('workspace', 'WorkspaceController::index', ['filter' => 'ewo-admin:admin_panel']);
$routes->get('workspace/(:any)', 'WorkspaceController::index', ['filter' => 'ewo-admin:admin_panel']);
$routes->get('api/v1/dashboard', 'AdminController::workspaceDashboard', ['filter' => 'ewo-admin:admin_panel,json']);
