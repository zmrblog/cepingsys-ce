<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

class SecurityController
{
    public function getIpBlockStats(Request $request, Response $response): Response
    {
        $today = date('Y-m-d');

        $isEnabled = false;
        $todayBlocks = 0;
        $totalBlocks = 0;
        $recentBlocks = [];

        try {
            $enabledRow = DB::table('system_configs')
                ->where('config_key', 'ip_filter_enabled')
                ->first();
            $isEnabled = $enabledRow && $enabledRow->config_value === '1';
        } catch (\Exception $e) {
            error_log("Security: 读取ip_filter_enabled配置失败: " . $e->getMessage());
        }

        try {
            $todayBlocks = DB::table('ip_block_logs')
                ->whereDate('created_at', $today)
                ->count();

            $totalBlocks = DB::table('ip_block_logs')->count();

            $recentBlocks = DB::table('ip_block_logs')
                ->orderBy('id', 'desc')
                ->take(10)
                ->get()
                ->map(function ($row) {
                    return [
                        'ip' => $row->ip_address,
                        'country' => $row->country_code,
                        'path' => $row->request_path,
                        'method' => $row->request_method,
                        'time' => $row->created_at,
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            error_log("Security: 读取ip_block_logs失败(表可能不存在): " . $e->getMessage());
        }

        return success_response($response, [
            'is_enabled' => $isEnabled,
            'today_blocks' => $todayBlocks,
            'total_blocks' => $totalBlocks,
            'recent_blocks' => $recentBlocks,
        ]);
    }
}
