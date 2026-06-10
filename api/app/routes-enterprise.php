<?php
declare(strict_types=1);

/**
 * 企业版专属路由
 *
 * 本文件由 CE 的 routes.php 自动加载（检测到存在时引入）。
 * 所有路由都挂载 LicenseMiddleware 进行授权验证。
 */

use App\Controllers\StatisticsController;
use App\Controllers\AuditController;
use App\Middleware\AuthMiddleware;
use App\Middleware\AuditAuthMiddleware;

// 复用 routes.php 中已实例化的 $licenseMw（顶层作用域）

// ===== 统计导出（企业版功能）=====
$app->group('/statistics', function ($group) use ($container) {
    $group->get('/examine/{id:[0-9]+}/export', [StatisticsController::class, 'exportExcel']);
    $group->get('/examine/{id:[0-9]+}/by-unit/export', [StatisticsController::class, 'exportByUnit']);
    $group->get('/examine/{id:[0-9]+}/target/{targetId}/export', [StatisticsController::class, 'exportTarget']);
    $group->post('/batch-export', [StatisticsController::class, 'batchExportTasks']);
})->add($licenseMw)->add(new AuthMiddleware($container));

// ===== 管理员管理（企业版功能 - 删除管理员）=====
$app->group('/admins', function ($group) use ($container) {
    // 注：admins CRUD 已定义在 CE routes.php 中，此处只添加删除接口的授权检查
    // 由 LicenseMiddleware 通过 required_feature 进行细粒度控制
})->add(new AuthMiddleware($container));

// ===== 审计子系统（企业版功能）=====
$app->group('/audit', function ($group) use ($licenseMw, $container) {
    $group->post('/login', [AuditController::class, 'login']);
})->add($licenseMw);

$app->get('/audit/data', [AuditController::class, 'queryData'])
    ->add(new AuditAuthMiddleware($container))->add($licenseMw);
$app->post('/audit/export', [AuditController::class, 'exportData'])
    ->add(new AuditAuthMiddleware($container))->add($licenseMw);
$app->get('/audit/examines', [AuditController::class, 'getExamines'])
    ->add(new AuditAuthMiddleware($container))->add($licenseMw);
$app->get('/audit/units', [AuditController::class, 'getUnits'])
    ->add(new AuditAuthMiddleware($container))->add($licenseMw);
$app->get('/audit/users', [AuditController::class, 'getUsers'])
    ->add(new AuditAuthMiddleware($container))->add($licenseMw);
$app->post('/audit/users', [AuditController::class, 'createUser'])
    ->add(new AuditAuthMiddleware($container))->add($licenseMw);
$app->put('/audit/users/{id:[0-9]+}', [AuditController::class, 'updateUser'])
    ->add(new AuditAuthMiddleware($container))->add($licenseMw);
$app->delete('/audit/users/{id:[0-9]+}', [AuditController::class, 'deleteUser'])
    ->add(new AuditAuthMiddleware($container))->add($licenseMw);
$app->put('/audit/users/{id:[0-9]+}/toggle', [AuditController::class, 'toggleUser'])
    ->add(new AuditAuthMiddleware($container))->add($licenseMw);

// ===== 品牌定制（企业版功能，后期上线）=====
if (file_exists(__DIR__ . '/routes-brand.php')) {
    require __DIR__ . '/routes-brand.php';
}
