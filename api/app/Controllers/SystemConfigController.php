<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

class SystemConfigController
{
    public function getConfig(Request $request, Response $response): Response
    {
        $keys = $request->getQueryParams()['keys'] ?? null;
        $query = DB::table('system_configs');

        if ($keys) {
            $keyList = array_map('trim', explode(',', $keys));
            $query->whereIn('config_key', $keyList);
        }

        $configs = $query->get();

        $result = [];
        foreach ($configs as $config) {
            $result[$config->config_key] = $config->config_value;
        }

        return success_response($response, $result);
    }

    public function updateConfig(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $key = trim($data['config_key'] ?? '');

        if (empty($key)) {
            return error_response($response, 400, 'config_key不能为空');
        }

        $exists = DB::table('system_configs')->where('config_key', $key)->exists();

        if (!$exists) {
            DB::table('system_configs')->insert([
                'config_key' => $key,
                'config_value' => (string)($data['config_value'] ?? ''),
                'config_group' => 'auto',
                'description' => "自动创建: {$key}",
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            DB::table('system_configs')
                ->where('config_key', $key)
                ->update([
                    'config_value' => (string)($data['config_value'] ?? ''),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        }

        log_operation(
            (int)$request->getAttribute('admin_id'),
            'system_config',
            $exists ? 'update' : 'create',
            'config',
            0,
            json_encode(['key' => $key, 'value' => $data['config_value'] ?? '', 'auto_created' => !$exists], JSON_UNESCAPED_UNICODE),
            $request
        );

        return success_response($response, null, $exists ? '配置更新成功' : '配置已创建并启用');
    }
}
