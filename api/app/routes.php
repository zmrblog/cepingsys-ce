<?php
declare(strict_types=1);

/**
 * 社区版路由
 *
 * 精简自企业版完整路由，仅保留社区版可用的基础功能。
 * 删除的功能：批量操作、Excel导入导出、审计子系统、操作日志、管理员删除。
 */

use App\Controllers\AuthController;
use App\Controllers\UnitController;
use App\Controllers\UserController;
use App\Controllers\TemplateController;
use App\Controllers\ExamineController;
use App\Controllers\AnswerController;
use App\Controllers\StatisticsController;
use App\Controllers\AdminController;
use App\Controllers\InstallController;
use App\Middleware\AuthMiddleware;
use App\Middleware\AnswerAuthMiddleware;

// ===== 安装向导路由 =====
$app->get('/install/check-env', [InstallController::class, 'checkEnv']);
$app->get('/install/php-info', [InstallController::class, 'getPhpInfo']);
$app->post('/install/test-db', [InstallController::class, 'testDbConnection']);
$app->post('/install/run', [InstallController::class, 'runInstall']);

// ===== 系统信息 =====
$app->get('/system/edition', function ($request, $response) {
    $editionFile = __DIR__ . '/../edition.json';
    $edition = '社区版';
    $version = 'v2026.05.28-1';
    if (file_exists($editionFile)) {
        $data = json_decode(file_get_contents($editionFile), true);
        $edition = $data['edition'] ?? '社区版';
        $version = $data['version'] ?? $version;
    }

    $features = [
        ['key' => 'template_manage',    'name' => '测评模板管理',   'ce' => true,  'ee' => true],
        ['key' => 'examine_manage',     'name' => '测评任务管理',   'ce' => true,  'ee' => true],
        ['key' => 'mobile_answer',      'name' => '移动端H5答题',   'ce' => true,  'ee' => true],
        ['key' => 'weighted_stats',     'name' => 'A/B类加权统计',  'ce' => true,  'ee' => true],
        ['key' => 'reverse_evaluation', 'name' => '反向测评防刷',   'ce' => true,  'ee' => true],
        ['key' => 'data_archive',       'name' => '数据归档',       'ce' => true,  'ee' => true],
        ['key' => 'user_manage',        'name' => '用户管理',       'ce' => '≤100人', 'ee' => '无限制'],
        ['key' => 'excel_export',       'name' => 'Excel导出',      'ce' => false, 'ee' => true],
        ['key' => 'batch_excel_export', 'name' => '批量Excel导出',  'ce' => false, 'ee' => true],
        ['key' => 'batch_delete',       'name' => '批量删除',       'ce' => false, 'ee' => true],
        ['key' => 'audit',              'name' => '审计子系统',     'ce' => false, 'ee' => true],
        ['key' => 'batch_import',       'name' => '批量导入',       'ce' => false, 'ee' => true],
        ['key' => 'brand_custom',       'name' => '品牌定制',       'ce' => '默认水印', 'ee' => '自定义'],
    ];

    $payload = ['code' => 200, 'data' => [
        'edition' => $edition,
        'version' => $version,
        'features' => $features,
    ]];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $response->getBody()->write($json === false ? '{"code":500}' : $json);
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

// ===== 认证路由 =====
$app->group('/auth', function ($group) {
    $group->post('/login', [AuthController::class, 'login']);
    $group->post('/logout', [AuthController::class, 'logout']);
    $group->post('/register', [AuthController::class, 'register']);
    $group->post('/reset-password', [AuthController::class, 'resetPassword']);
    $group->get('/security-questions', [AuthController::class, 'securityQuestions']);
});

// ===== 单位路由 =====
$app->get('/units', [UnitController::class, 'index'])->add(new AuthMiddleware($container));
$app->get('/units/{id:[0-9]+}', [UnitController::class, 'show'])->add(new AuthMiddleware($container));

$app->group('/units', function ($group) use ($container) {
    $group->post('', [UnitController::class, 'store']);
    $group->put('/{id:[0-9]+}', [UnitController::class, 'update']);
    $group->delete('/{id:[0-9]+}', [UnitController::class, 'destroy']);
})->add(new AuthMiddleware($container));

// ===== 用户路由 =====
$app->group('/users', function ($group) use ($container) {
    $group->get('', [UserController::class, 'index']);
    $group->get('/{id:[0-9]+}', [UserController::class, 'show']);
    $group->post('', [UserController::class, 'store']);
    $group->put('/{id:[0-9]+}', [UserController::class, 'update']);
    $group->put('/{id:[0-9]+}/reset-password', [UserController::class, 'adminResetPassword']);
    $group->delete('/{id:[0-9]+}', [UserController::class, 'destroy']);
})->add(new AuthMiddleware($container));

// ===== 模板路由 =====
$app->group('/templates', function ($group) use ($container) {
    $group->get('', [TemplateController::class, 'index']);
    $group->get('/{id:[0-9]+}', [TemplateController::class, 'show']);
    $group->post('', [TemplateController::class, 'store']);
    $group->put('/{id:[0-9]+}', [TemplateController::class, 'update']);
    $group->delete('/{id:[0-9]+}', [TemplateController::class, 'destroy']);
    $group->post('/{id:[0-9]+}/duplicate', [TemplateController::class, 'duplicate']);
})->add(new AuthMiddleware($container));

// ===== 测评任务路由 =====
$app->group('/examines', function ($group) use ($container) {
    $group->get('', [ExamineController::class, 'index']);
    $group->get('/{id:[0-9]+}', [ExamineController::class, 'show']);
    $group->post('', [ExamineController::class, 'store']);
    $group->put('/{id:[0-9]+}', [ExamineController::class, 'update']);
    $group->delete('/{id:[0-9]+}', [ExamineController::class, 'destroy']);
    $group->post('/{id:[0-9]+}/targets', [ExamineController::class, 'addTargets']);
    $group->post('/{id:[0-9]+}/users', [ExamineController::class, 'assignUsers']);
    $group->get('/{id:[0-9]+}/users', [ExamineController::class, 'listUsers']);
    $group->post('/{id:[0-9]+}/users/add', [ExamineController::class, 'addUser']);
    $group->delete('/{id:[0-9]+}/users/{userId:[0-9]+}', [ExamineController::class, 'removeUser']);
    $group->get('/{id:[0-9]+}/available-users', [ExamineController::class, 'getAvailableUsers']);
    $group->post('/{id:[0-9]+}/activate', [ExamineController::class, 'activate']);
    $group->post('/{id:[0-9]+}/finish', [ExamineController::class, 'finish']);
    $group->post('/{id:[0-9]+}/archive', [ExamineController::class, 'archive']);
    $group->post('/{id:[0-9]+}/unarchive', [ExamineController::class, 'unarchive']);
    $group->post('/batch-archive', [ExamineController::class, 'batchArchive']);
    $group->get('/archive-overview', [ExamineController::class, 'archiveOverview']);
})->add(new AuthMiddleware($container));

// ===== 答题路由（移动端，公开）=====
$app->group('/answers', function ($group) {
    $group->post('', [AnswerController::class, 'save']);
    $group->get('/my-examines', [AnswerController::class, 'myExamines']);
    $group->get('/examine/{examineId}/targets', [AnswerController::class, 'getTargets']);
    $group->get('/examine/{examineId}/target/{targetId}/items', [AnswerController::class, 'getItems']);
    $group->get('/examine/{examineId}/target/{targetId}/answers', [AnswerController::class, 'getAnswers']);
    $group->post('/submit-all', [AnswerController::class, 'submitAll']);
    $group->post('/complete-target', [AnswerController::class, 'completeTarget']);
})->add(new AnswerAuthMiddleware($container));

// ===== 统计路由（仅查看，无导出）=====
$app->group('/statistics', function ($group) use ($container) {
    $group->get('/examine/{id:[0-9]+}', [StatisticsController::class, 'examineStats']);
    $group->get('/examine/{id:[0-9]+}/target/{targetId}', [StatisticsController::class, 'targetStats']);
    $group->get('/examine/{id:[0-9]+}/by-unit', [StatisticsController::class, 'getStatsByUnit']);
    $group->get('/examine/{id:[0-9]+}/vote-summary', [StatisticsController::class, 'voteSummary']);
    $group->get('/examine/{id:[0-9]+}/score-summary', [StatisticsController::class, 'scoreSummary']);
})->add(new AuthMiddleware($container));

// ===== 管理员路由（无删除）=====
$app->group('/admins', function ($group) use ($container) {
    $group->get('', [AdminController::class, 'index']);
    $group->get('/{id:[0-9]+}', [AdminController::class, 'show']);
    $group->post('', [AdminController::class, 'store']);
    $group->put('/{id:[0-9]+}', [AdminController::class, 'update']);
})->add(new AuthMiddleware($container));

// ===== 系统配置路由 =====
$app->group('/system-configs', function ($group) {
    $group->get('', [\App\Controllers\SystemConfigController::class, 'getConfig']);
    $group->put('', [\App\Controllers\SystemConfigController::class, 'updateConfig']);
})->add(new AuthMiddleware($container));

// ===== 安全路由 =====
$app->group('/security', function ($group) {
    $group->get('/ip-block-stats', [\App\Controllers\SecurityController::class, 'getIpBlockStats']);
})->add(new AuthMiddleware($container));

$GLOBALS['container'] = $container;
