<?php
declare(strict_types=1);

if (!function_exists('env')) {
function env(string $key, $default = null)
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false) {
        return $default;
    }

    switch (strtolower((string)$value)) {
        case 'true':
        case '(true)':
            return true;
        case 'false':
        case '(false)':
            return false;
        case 'null':
        case '(null)':
            return null;
    }

    if (strlen((string)$value) > 1 && str_starts_with((string)$value, '"') && str_ends_with((string)$value, '"')) {
        return substr((string)$value, 1, -1);
    }

    return $value;
}
}

function json_response(\Psr\Http\Message\ResponseInterface $response, int $code, string $message, $data = null): \Psr\Http\Message\ResponseInterface
{
    $payload = [
        'code' => $code,
        'message' => $message,
    ];

    if ($data !== null) {
        $payload['data'] = $data;
    }

    $json = @json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($json === false || strlen($json) < 2) {
        $fallback = ['code' => 500, 'message' => '数据编码错误'];
        $json = json_encode($fallback, JSON_UNESCAPED_UNICODE) ?: '{"code":500}';
    }
    $response->getBody()->write($json);
    return $response->withStatus($code)->withHeader('Content-Type', 'application/json; charset=utf-8');
}

function success_response(\Psr\Http\Message\ResponseInterface $response, $data = null, string $message = '操作成功'): \Psr\Http\Message\ResponseInterface
{
    return json_response($response, 200, $message, $data);
}

function error_response(\Psr\Http\Message\ResponseInterface $response, int $code, string $message, $errors = null): \Psr\Http\Message\ResponseInterface
{
    $payload = [
        'code' => $code,
        'message' => $message,
    ];

    if ($errors !== null) {
        $payload['errors'] = $errors;
    }

    $json = @json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($json === false || strlen($json) < 2) {
        $fallback = ['code' => $code, 'message' => '服务器错误'];
        $json = json_encode($fallback, JSON_UNESCAPED_UNICODE) ?: '{"code":500}';
    }
    $response->getBody()->write($json);
    return $response->withStatus($code)->withHeader('Content-Type', 'application/json; charset=utf-8');
}

function paginate(int $page, int $perPage, int $total): array
{
    return [
        'current_page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'last_page' => (int)ceil($total / $perPage),
        'from' => ($page - 1) * $perPage + 1,
        'to' => min($page * $perPage, $total),
    ];
}

function like_escape(string $value, string $char = '\\'): string
{
    return str_replace([$char, '%', '_'], [$char . $char, $char . '%', $char . '_'], $value);
}

function log_operation(...$args): void
{
    try {
        $adminId = 0;
        $request = null;
        $module = '';
        $action = '';
        $targetType = '';
        $targetId = 0;
        $detail = '';
        $serverParams = [];

        // 兼容不同的调用方式：
        if (count($args) >= 3) {
            if ($args[0] instanceof \Psr\Http\Message\ServerRequestInterface) {
                // 方式1: log_operation($request, $module, $detail)
                $request = $args[0];
                $module = $args[1];
                $detail = $args[2];
                $serverParams = $request ? $request->getServerParams() : [];
            } elseif (count($args) >= 6) {
                // 方式2: log_operation($adminId, $module, $action, $targetType, $targetId, $detail, $request)
                $adminId = $args[0];
                $module = $args[1];
                $action = $args[2];
                $targetType = $args[3];
                $targetId = $args[4];
                $detail = $args[5];
                $request = $args[6] ?? null;
                $serverParams = $request ? $request->getServerParams() : [];
            }
        }

        // 如果没有提取到 adminId，但有 request，从 request 中获取
        if ($request && (!isset($adminId) || $adminId === 0)) {
            $adminId = $request->getAttribute('admin_id') ?? 0;
        }

        // 如果是数组类型的 detail，转换为 JSON
        if (is_array($detail)) {
            $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
        }

        \Illuminate\Database\Capsule\Manager::table('operation_logs')->insert([
            'admin_id' => $adminId ?? 0,
            'module' => $module,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'detail' => $detail,
            'ip_address' => $serverParams['HTTP_X_FORWARDED_FOR']
                ?? $serverParams['HTTP_X_REAL_IP']
                ?? $serverParams['REMOTE_ADDR']
                ?? null,
            'user_agent' => $serverParams['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {
        error_log("log_operation failed: " . $e->getMessage());
    }
}
