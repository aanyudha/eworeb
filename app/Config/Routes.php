<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->setAutoRoute(false);
$routes->get('/', 'AdminController::index', ['filter' => 'ewo-admin:admin_panel']);
$routes->get('admin', 'AdminController::index', ['filter' => 'ewo-admin:admin_panel']);
$routes->get('admin/login', 'AuthController::adminLogin');
$routes->post('auth_controller/admin_login_post', 'AuthController::adminLoginPost');
$routes->get('logout', 'AuthController::logout');

$routes->get('workspace', 'WorkspaceController::index', ['filter' => 'ewo-admin:admin_panel']);
$routes->get('workspace/(:any)', 'WorkspaceController::index', ['filter' => 'ewo-admin:admin_panel']);
$routes->get('api/v1/dashboard', 'AdminController::workspaceDashboard', ['filter' => 'ewo-admin:admin_panel,json']);
