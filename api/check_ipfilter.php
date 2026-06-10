<?php

echo "========================================\n";
echo " IP Filter 诊断脚本 (PHP " . PHP_VERSION . ")\n";
echo " 时间: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

$basePath = __DIR__;

echo "--- 6项诊断 -----------------------------------------------------------------\n\n";

// 1. 检查 IpFilterMiddleware.php 版本
echo "1. 检查 IpFilterMiddleware.php 版本\n";
$mwFile = $basePath . '/app/Middleware/IpFilterMiddleware.php';
if (file_exists($mwFile)) {
    $content = file_get_contents($mwFile);
    $hasFileLog = strpos($content, 'log_to_file') !== false;
    $hasIsEnabled = strpos($content, 'private function isEnabled') !== false;
    if ($hasFileLog) {
        echo "   [OK] 版本: 最新版 (含 log_to_file 文件日志 + isEnabled 查库)\n";
    } elseif ($hasIsEnabled) {
        echo "   [WARN] 版本: 中间版 (有 isEnabled 但无文件日志, 使用 error_log)\n";
    } else {
        echo "   [FAIL] 版本: 旧版! (无 isEnabled 方法, 始终拦截所有非CN IP!)\n";
    }
} else {
    echo "   [FAIL] 文件不存在: {$mwFile}\n";
}

// 2. 解析 .env 获取数据库连接
echo "\n2. 读取数据库配置\n";
$envFile = $basePath . '/.env';
$env = [];
if (file_exists($envFile)) {
    foreach (file($envFile) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $eq = strpos($line, '=');
        if ($eq !== false) {
            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));
            $env[$key] = $val;
        }
    }
}
$dbHost = $env['DB_HOST'] ?? '127.0.0.1';
$dbPort = $env['DB_PORT'] ?? '3306';
$dbName = $env['DB_DATABASE'] ?? 'examine_admin';
$dbUser = $env['DB_USERNAME'] ?? 'root';
$dbPass = $env['DB_PASSWORD'] ?? '';
echo "   Host={$dbHost}:{$dbPort} DB={$dbName} User={$dbUser}\n";

// 3. 连接数据库并检查 system_configs
echo "\n3. 检查 system_configs 表\n";
try {
    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // 检查 ip_filter_enabled
    $stmt = $pdo->query("SELECT * FROM system_configs WHERE config_key = 'ip_filter_enabled'");
    $row = $stmt->fetch();
    if ($row) {
        echo "   [OK] ip_filter_enabled 配置项存在:\n";
        echo "        config_value = '{$row['config_value']}'\n";
        echo "        config_group = '{$row['config_group']}'\n";
        echo "        当前状态    = " . ($row['config_value'] === '1' ? '开启(会拦截国外IP)' : '关闭(不拦截)') . "\n";
    } else {
        echo "   [FAIL] ip_filter_enabled 配置项不存在!\n";
        echo "          需要执行: api/database/add_ip_filter_switch.sql\n";
    }

    // 列出所有 system_configs
    $all = $pdo->query("SELECT config_key, config_value, config_group FROM system_configs ORDER BY config_group, config_key")->fetchAll();
    echo "\n   共 " . count($all) . " 条配置:\n";
    foreach ($all as $cfg) {
        echo "      [{$cfg['config_group']}] {$cfg['config_key']} = {$cfg['config_value']}\n";
    }

    // 检查 ip_block_logs 表
    echo "\n4. 检查 ip_block_logs 表\n";
    $tables = $pdo->query("SHOW TABLES LIKE 'ip_block_logs'")->fetchAll();
    if (count($tables) > 0) {
        $count = $pdo->query("SELECT COUNT(*) as cnt FROM ip_block_logs")->fetch()['cnt'];
        echo "   [OK] ip_block_logs 表存在, 共 {$count} 条拦截记录\n";
    } else {
        echo "   [WARN] ip_block_logs 表不存在 (不影响开关功能, 但无拦截统计)\n";
    }

} catch (Exception $e) {
    echo "   [FAIL] 数据库连接失败: " . $e->getMessage() . "\n";
    $pdo = null;
}

// 5. 检查 GeoIP 数据库
echo "\n5. 检查 GeoIP 数据库\n";
$mmdbPath = ($env['MMDB_PATH'] ?? '') ?: ($basePath . '/data/Country-without-asn.mmdb');
echo "   配置路径: {$mmdbPath}\n";
echo "   文件存在: " . (file_exists($mmdbPath) ? 'YES' : 'NO') . "\n";
if (file_exists($mmdbPath)) {
    echo "   文件大小: " . round(filesize($mmdbPath) / 1024 / 1024, 2) . " MB\n";
} else {
    echo "   [WARN] mmdb 文件不存在, IP过滤中间件不会被加载!\n";
}

// 6. 检查日志文件
echo "\n6. 检查 ipfilter.log 日志文件\n";
$logFile = $basePath . '/storage/logs/ipfilter.log';
echo "   预期路径: {$logFile}\n";
if (file_exists($logFile)) {
    $size = filesize($logFile);
    echo "   文件大小: {$size} bytes\n";
    if ($size > 0) {
        $lines = file($logFile);
        echo "   最近 30 行:\n";
        foreach (array_slice($lines, -30) as $line) {
            echo "      " . trim($line) . "\n";
        }
    } else {
        echo "   [WARN] 日志文件为空 — 中间件从未被调用!\n";
    }
} else {
    echo "   [WARN] 日志文件不存在!\n";
    echo "         可能原因:\n";
    echo "         1. 文件未覆盖到生产服务器对应路径\n";
    echo "         2. PHP OPcache 缓存了旧代码 — 需重启 PHP 服务\n";
    echo "         3. mmdb 文件不存在导致中间件未加载\n";
}

// 总结
echo "\n========================================================================\n";
echo " 诊断结论\n";
echo "========================================================================\n";

$hasNewCode = isset($hasFileLog) && $hasFileLog;
$hasLog = file_exists($logFile) && filesize($logFile) > 0;
$configExists = isset($row) && $row !== false;
$isOff = isset($row) && $row !== false && $row['config_value'] === '0';
$mmdbOk = file_exists($mmdbPath);

if (!$hasNewCode) {
    echo "\n [!] 最严重: 中间件文件是旧版本, 始终拦截所有国外IP!\n";
    echo "     操作: 用 D:\\code\\tmp\\api\\app\\Middleware\\IpFilterMiddleware.php 覆盖生产\n";
} elseif (!$hasLog) {
    echo "\n [!] 关键: 新版代码已部署 但 日志文件未生成 — PHP 可能在运行旧缓存!\n";
    echo "     操作: 重启 PHP-FPM / IIS / Apache 服务\n";
} elseif ($configExists && $isOff) {
    echo "\n [OK] 配置项存在且值为 '0' (关闭)\n";
    if (preg_grep('/BLOCK/', $lines ?? [])) {
        echo " [!!!] 但日志中有 BLOCK 记录 — 中间件仍在拦截!\n";
        echo "       请检查是否有多个 PHP 进程, 或另有一个运行旧代码的进程\n";
    } elseif (preg_grep('/PASS.*enabled=false/', $lines ?? [])) {
        echo " [OK] 日志中有 PASS enabled=false — 开关生效, 国外IP可正常访问\n";
    }
} elseif ($configExists && !$isOff) {
    echo "\n [!!!] 配置值为 '1' (开启) — 开关实际是开启状态!\n";
    echo "       在 Dashboard 中关闭开关, 或手动执行:\n";
    echo "       UPDATE system_configs SET config_value='0' WHERE config_key='ip_filter_enabled'\n";
} elseif (!$configExists) {
    echo "\n [!!!] 配置项不存在 — SQL 迁移未执行!\n";
    echo "       执行: INSERT INTO system_configs (config_key,config_value,config_group,description) \n";
    echo "              VALUES ('ip_filter_enabled','0','security','全局IP过滤开关: 0关闭/1开启')\n";
}

if (!$mmdbOk) {
    echo "\n [WARN] GeoIP 数据库文件缺失, IP过滤中间件根本不会被加载 (不影响使用但无法拦截)\n";
}

echo "\n------------------------------------------------------------------------\n";