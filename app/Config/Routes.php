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
$routes->get('api/v1/account/profile', 'AccountController::profile', ['filter' => 'ewo-admin:admin_panel,json']);
$routes->get('api/v1/account/profile/avatar', 'AccountController::avatar', ['filter' => 'ewo-admin:admin_panel,json']);
$routes->post('api/v1/account/profile/avatar', 'AccountController::updateAvatar', ['filter' => 'ewo-admin:admin_panel,json']);
$routes->get('brand/(logo|favicon)', 'BrandingController::image/$1');
$routes->get('api/v1/settings/visual', 'BrandingController::index', ['filter' => 'ewo-admin:admin_panel,settings,json']);
$routes->post('api/v1/settings/visual/(logo|favicon)', 'BrandingController::save/$1', ['filter' => 'ewo-admin:admin_panel,settings,json']);
$routes->post('api/v1/account/profile/email', 'AccountController::changeProfileEmail', ['filter' => 'ewo-admin:admin_panel,json']);
$routes->get('api/v1/settings/application', 'ApplicationSettingsController::index', ['filter' => 'ewo-admin:admin_panel,settings,json']);
$routes->post('api/v1/settings/application', 'ApplicationSettingsController::save', ['filter' => 'ewo-admin:admin_panel,settings,json']);
$routes->get('api/v1/account/password', 'AccountController::passwordForm', ['filter' => 'ewo-admin:admin_panel,json']);
$routes->post('api/v1/account/password', 'AccountController::changePassword', ['filter' => 'ewo-admin:admin_panel,json']);
$routes->get('api/v1/dashboard', 'AdminController::workspaceDashboard', ['filter' => 'ewo-admin:admin_panel,json']);

$routes->get('api/v1/work-orders/(incoming|outgoing)', 'WorkOrderController::index/$1',
    ['filter' => 'ewo-admin:admin_panel,add_post,json']);
$routes->get('api/v1/work-orders/create/preview', 'WorkOrderCreateController::preview',
    ['filter' => 'ewo-admin:admin_panel,add_post,json']);
$routes->post('api/v1/work-orders/create', 'WorkOrderCreateController::save',
    ['filter' => 'ewo-admin:admin_panel,add_post,json']);


$routes->get('api/v1/admin-directory/(categories|subcategories|locations|sublocations|departments|corporations|users|administrators|roles)',
    'AdminDirectoryController::index/$1', ['filter' => 'ewo-admin:admin_panel,json']);
$routes->group('api/v1/admin-directory/(categories|subcategories|locations|sublocations|departments|corporations)',
    ['filter' => 'ewo-admin:admin_panel,json'], static function (RouteCollection $routes): void {
        $routes->get('new', 'AdminDirectoryController::options/$1');
        $routes->post('new', 'AdminDirectoryController::save/$1');
        $routes->get('(:num)/edit', 'AdminDirectoryController::options/$1/$2');
        $routes->post('(:num)/edit', 'AdminDirectoryController::save/$1/$2');
    });
$routes->group('api/v1/admin-directory/(users|administrators)',
    ['filter' => 'ewo-admin:admin_panel,users,json,administrator'], static function (RouteCollection $routes): void {
        $routes->get('new', 'AccountController::managedUserForm/$1');
        $routes->post('new', 'AccountController::saveManagedUser/$1');
        $routes->get('(:num)/edit', 'AccountController::managedUserForm/$1/$2');
        $routes->post('(:num)/edit', 'AccountController::saveManagedUser/$1/$2');
    });
$routes->get('api/v1/admin-directory/roles/(:num)/edit', 'AdminDirectoryController::roleOptions/$1',
    ['filter' => 'ewo-admin:admin_panel,json,administrator']);
$routes->post('api/v1/admin-directory/roles/(:num)/edit', 'AdminDirectoryController::saveRole/$1',
    ['filter' => 'ewo-admin:admin_panel,json,administrator']);
$routes->get('api/v1/reports/(incoming|outgoing)', 'WorkOrderController::report/$1',
    ['filter' => 'ewo-admin:admin_panel,add_post,json']);
$routes->get('api/v1/work-orders/drafts', 'WorkOrderController::index/drafts',
    ['filter' => 'ewo-admin:admin_panel,add_post,json']);
$routes->post('api/v1/work-orders/drafts/(:num)/publish', 'WorkOrderController::publishDraft/$1',
    ['filter' => 'ewo-admin:admin_panel,add_post,json']);
$routes->get('api/v1/work-orders/scheduled', 'WorkOrderController::index/scheduled',
    ['filter' => 'ewo-admin:admin_panel,add_post,manage_all_posts,json']);
$routes->get('api/v1/work-orders/(incoming|outgoing)/(:num)', 'WorkOrderController::detail/$1/$2',
    ['filter' => 'ewo-admin:admin_panel,add_post,json']);
$routes->get('api/v1/work-orders/incoming/(:num)/reply', 'WorkOrderReplyController::options/$1',
    ['filter' => 'ewo-admin:admin_panel,add_post,manage_all_posts,json']);
$routes->post('api/v1/work-orders/incoming/(:num)/reply', 'WorkOrderReplyController::save/$1',
    ['filter' => 'ewo-admin:admin_panel,add_post,manage_all_posts,json']);
$routes->get('api/v1/work-orders/(incoming|outgoing)/(:num)/attachments', 'WorkOrderAttachmentController::index/$1/$2',
    ['filter' => 'ewo-admin:admin_panel,add_post,json']);
$routes->post('api/v1/work-orders/incoming/(:num)/attachments/(image|audio)', 'WorkOrderAttachmentController::upload/$1/$2',
    ['filter' => 'ewo-admin:admin_panel,add_post,manage_all_posts,json']);
$routes->get('api/v1/work-orders/(incoming|outgoing)/(:num)/attachments/(main|image|audio)/(:num)', 'WorkOrderAttachmentController::download/$1/$2/$3/$4',
    ['filter' => 'ewo-admin:admin_panel,add_post,json']);
