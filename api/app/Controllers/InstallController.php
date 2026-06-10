<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

class InstallController
{
    private $basePath;

    public function __construct()
    {
        $this->basePath = str_replace('\\', '/', dirname(__DIR__, 2));
    }

    public function checkEnv(Request $request, Response $response): Response
    {
        $checks = [
            'php_version' => PHP_VERSION,
            'php_version_ok' => version_compare(PHP_VERSION, '8.0', '>='),
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'mbstring' => extension_loaded('mbstring'),
            'json' => extension_loaded('json'),
            'openssl' => extension_loaded('openssl'),
            'fileinfo' => extension_loaded('fileinfo'),
            'curl' => extension_loaded('curl'),
            'gd' => extension_loaded('gd'),
        ];

        $allOk = !in_array(false, $checks, true);
        $payload = [
            'code' => $allOk ? 1 : 0,
            'data' => $checks,
            'message' => $allOk ? '环境检查通过' : '部分扩展未安装，请检查',
        ];
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function getPhpInfo(Request $request, Response $response): Response
    {
        $payload = [
            "php_version" => PHP_VERSION,
            "php_sapi" => PHP_SAPI,
            "os" => PHP_OS,
            "detected_mode" => $this->detectPhpMode()
        ];
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader("Content-Type", "application/json");
    }

    /**
     * Scan Baota/PHP environments to detect the real FastCGI listen port.
     *
     * Strategy (tried in order):
     *   1. Linux  Baota: /www/server/nginx/conf/enable-php-*.conf
     *   2. Linux  PHP-FPM pool config: /www/server/php/X.Y/etc/php-fpm.d/www.conf
     *   3. Windows Baota: D:/BtSoft/ or C:/BtSoft/nginx/conf/php/*.conf
     *   4. Unix socket files: /tmp/php-cgi-X.Y.sock
     *   5. Process detection: ps aux | grep php-fpm
     *   6. Fallback defaults
     */
    private function detectPhpMode(): array
    {
        $sapi = strtolower(PHP_SAPI);
        $currentVersion = PHP_VERSION;
        $majorMinor = implode(".", array_slice(explode(".", $currentVersion), 0, 2));
        $currentVerInt = (int) str_replace(".", "", $majorMinor);

        $versionPorts = [];

        // === 1. Linux Baota: /www/server/nginx/conf/enable-php-*.conf ===
        $linuxBtConfDir = "/www/server/nginx/conf";
        if (is_dir($linuxBtConfDir)) {
            $confFiles = glob($linuxBtConfDir . "/enable-php-*.conf");
            if ($confFiles) {
                foreach ($confFiles as $cf) {
                    $base = basename($cf, ".conf");
                    if (!preg_match("/enable-php-(\d+)/", $base, $vm)) continue;
                    $ver = (int)$vm[1];
                    $content = @file_get_contents($cf);
                    if ($content && preg_match("/fastcgi_pass\s+(.+?);/", $content, $m)) {
                        $versionPorts[$ver] = trim($m[1]);
                    }
                }
            }
        }

        // === 2. Linux PHP-FPM pool config ===
        if (empty($versionPorts)) {
            $poolPaths = [
                "/www/server/php/{$majorMinor}/etc/php-fpm.d/www.conf",
                "/www/server/php/{$majorMinor}/etc/php-fpm.conf",
                "/etc/php/{$majorMinor}/fpm/pool.d/www.conf",
            ];
            foreach ($poolPaths as $pp) {
                if (file_exists($pp)) {
                    $content = @file_get_contents($pp);
                    if ($content && preg_match("/^\s*listen\s*=\s*(.+)/m", $content, $m)) {
                        $addr = trim($m[1]);
                        $versionPorts[(int)str_replace(".", "", $majorMinor)] = $addr;
                        break;
                    }
                }
            }
        }

        // === 3. Windows Baota: D:/BtSoft/ or C:/BtSoft/ ===
        if (empty($versionPorts)) {
            foreach (["D:/BtSoft/nginx/conf/php", "C:/BtSoft/nginx/conf/php"] as $dir) {
                if (!is_dir($dir)) continue;
                foreach ((array)glob($dir . "/*.conf") as $cf) {
                    $base = basename($cf, ".conf");
                    if (!preg_match("/^\d+$/", $base)) continue;
                    $ver = (int)$base;
                    $content = @file_get_contents($cf);
                    if ($content && preg_match("/fastcgi_pass\s+([\d\.]+:\d+)/", $content, $m)) {
                        $versionPorts[$ver] = $m[1];
                    }
                }
                if (!empty($versionPorts)) break;
            }
        }

        // === 4. Unix socket file detection ===
        if (empty($versionPorts)) {
            $sockPatterns = [
                "/tmp/php-cgi-{$majorMinor}.sock",
                "/tmp/php-cgi-" . str_replace(".", "", $majorMinor) . ".sock",
                "/run/php/php{$majorMinor}-fpm.sock",
            ];
            foreach ($sockPatterns as $sp) {
                if (file_exists($sp)) {
                    $versionPorts[(int)str_replace(".", "", $majorMinor)] = "unix:" . $sp;
                    break;
                }
            }
        }

        // === 5. Process-based detection (last resort) ===
        if (empty($versionPorts)) {
            $out = @shell_exec("ps aux 2>/dev/null | grep -E 'php-fpm' | grep -v grep | head -1");
            if ($out && preg_match("/php(\d+\.\d+)/", $out, $pm)) {
                $v = (int)str_replace(".", "", $pm[1]);
                $versionPorts[$v] = "127.0.0.1:9000";
            }
        }

        // === Match closest version ===
        $detectedPort = null;
        $detectedLabel = "";
        if (!empty($versionPorts)) {
            $best = null; $bestDist = PHP_INT_MAX;
            foreach ($versionPorts as $ver => $port) {
                $dist = abs($ver - $currentVerInt);
                if ($dist < $bestDist) { $bestDist = $dist; $best = $port; }
            }
            if ($best) {
                $detectedPort = $best;
                $versions = implode(", ", array_map(function($v) { return sprintf("PHP %.1f", $v / 10); }, array_keys($versionPorts)));
                $detectedLabel = "Baota {$versions} -> {$best}";
            }
        }

        if ($detectedPort) {
            $hint = "系统已自动检测，如不正确请手动修改";
            if (str_contains($sapi, "fpm")) {
                return ["mode" => "fpm", "label" => $detectedLabel, "default_pass" => $detectedPort, "hint" => $hint, "auto_detected" => $detectedPort];
            }
            return ["mode" => "cgi", "label" => $detectedLabel, "default_pass" => $detectedPort, "hint" => $hint, "auto_detected" => $detectedPort];
        }

        // === Fallbacks ===
        if (str_contains($sapi, "fpm")) {
            return ["mode" => "fpm", "label" => "PHP-FPM (Linux)", "default_pass" => "127.0.0.1:9000",
                "hint" => "未检测到具体端口，如使用 Unix socket 请填写: unix:/tmp/php-cgi-XX.sock"];
        }
        if (str_contains($sapi, "cgi")) {
            return ["mode" => "cgi", "label" => "PHP-CGI (Windows)", "default_pass" => "127.0.0.1:9000",
                "hint" => "Windows 环境，请填写实际 CGI 监听端口（如 9010）"];
        }
        return ["mode" => "unknown", "label" => "未知运行模式", "default_pass" => "127.0.0.1:9000",
            "hint" => "无法自动检测，请根据 PHP 环境填写 fastcgi_pass 地址"];
    }

    public function testDbConnection(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $host = trim($data['host'] ?? '127.0.0.1');
        $port = trim($data['port'] ?? '3306');
        $dbname = trim($data['database'] ?? '');
        $user = trim($data['username'] ?? 'root');
        $pass = $data['password'] ?? '';

        if (empty($dbname)) {
            $payload = ['code' => 0, 'message' => '数据库名不能为空'];
            $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        try {
            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $pdo = new \PDO($dsn, $user, $pass, [\PDO::ATTR_TIMEOUT => 5]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $payload = ['code' => 1, 'message' => '数据库连接成功'];
        } catch (\PDOException $e) {
            $payload = ['code' => 0, 'message' => '连接失败: ' . $e->getMessage()];
        }

        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function runInstall(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $db = $data['db'] ?? [];
        $admin = $data['admin'] ?? [];
        $phpPass = $data['php_pass'] ?? '127.0.0.1:9000';

        $steps = [];
        $payload = [];

        // Step 1: Write .env
        try {
            $envContent = "APP_NAME=年度考核测评系统\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_URL=http://localhost\n";
            $envContent .= "DB_HOST=" . ($db['host'] ?? '127.0.0.1') . "\n";
            $envContent .= "DB_PORT=" . ($db['port'] ?? '3306') . "\n";
            $envContent .= "DB_DATABASE=" . ($db['database'] ?? 'examine_system') . "\n";
            $envContent .= "DB_USERNAME=" . ($db['username'] ?? 'root') . "\n";
            $envContent .= "DB_PASSWORD=" . ($db['password'] ?? '') . "\n";
            $envContent .= "JWT_SECRET=" . bin2hex(random_bytes(32)) . "\n";
            $envContent .= "AUDIT_JWT_SECRET=" . bin2hex(random_bytes(32)) . "\n";
            file_put_contents($this->basePath . "/.env", $envContent);
            $steps[] = ["action" => "写入配置文件", "status" => "ok"];
        } catch (\Throwable $e) {
            $steps[] = ["action" => "写入配置文件", "status" => "fail", "error" => $e->getMessage()];
            $payload = ["success" => false, "steps" => $steps];
            $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
            return $response->withHeader("Content-Type", "application/json");
        }

        // Step 2: Run migrations
        try {
            $capsule = new \Illuminate\Database\Capsule\Manager();
            $capsule->addConnection([
                'driver' => 'mysql',
                'host' => $db['host'] ?? '127.0.0.1',
                'port' => $db['port'] ?? '3306',
                'database' => $db['database'] ?? 'examine_system',
                'username' => $db['username'] ?? 'root',
                'password' => $db['password'] ?? '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ]);
            $capsule->setAsGlobal();
            $capsule->bootEloquent();

            $schema = $capsule->getConnection()->getSchemaBuilder();
            $sqlFile = $this->basePath . "/database/schema.sql";
            if (file_exists($sqlFile)) {
                $sql = file_get_contents($sqlFile);
                foreach (explode(";", $sql) as $stmt) {
                    $stmt = trim($stmt);
                    if (!empty($stmt)) {
                        $capsule->getConnection()->statement($stmt);
                    }
                }
            } else {
                $this->runInlineSchema($capsule);
            }
            $steps[] = ["action" => "创建数据表", "status" => "ok"];
        } catch (\Throwable $e) {
            $steps[] = ["action" => "创建数据表", "status" => "fail", "error" => $e->getMessage()];
            $payload = ["success" => false, "steps" => $steps];
            $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
            return $response->withHeader("Content-Type", "application/json");
        }

        // Step 3: Create admin
        try {
            DB::table('admins')->insert([
                'username' => $admin['username'] ?? 'admin',
                'password_hash' => password_hash($admin['password'] ?? 'admin123', PASSWORD_BCRYPT),
                'real_name' => '系统管理员',
                'role' => 'super',
                'status' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $steps[] = ["action" => "创建管理员账号", "status" => "ok"];
        } catch (\Throwable $e) {
            $steps[] = ["action" => "创建管理员账号", "status" => "fail", "error" => $e->getMessage()];
            $payload = ["success" => false, "steps" => $steps];
            $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
            return $response->withHeader("Content-Type", "application/json");
        }

        // Step 4: Preview Nginx config
        try {
            $conf = $this->generateNginxConfig($phpPass);
            $path = $this->basePath . "/nginx/generated.conf";
            $dir = dirname($path);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents($path, $conf);
            $steps[] = ["action" => "生成Nginx配置", "status" => "ok", "path" => $path];
        } catch (\Throwable $e) {
            $steps[] = ["action" => "生成Nginx配置", "status" => "fail", "error" => $e->getMessage()];
        }

        // Step 5: Install lock
        try {
            $editionData = json_decode(@file_get_contents($this->basePath . "/edition.json"), true);
            $edition = $editionData['edition'] ?? '社区版';
            file_put_contents($this->basePath . "/storage/.installed", json_encode([
                "installed_at" => date("c"),
                "version" => "v2026.05.28-1",
                "edition" => $edition
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $steps[] = ["action" => "安装锁定", "status" => "ok"];
        } catch (\Throwable $e) {
            $steps[] = ["action" => "安装锁定", "status" => "fail", "error" => $e->getMessage()];
            $payload = ["success" => false, "steps" => $steps];
            $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
            return $response->withHeader("Content-Type", "application/json");
        }

        $payload = ["success" => true, "steps" => $steps];
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader("Content-Type", "application/json");
    }

    private function generateNginxConfig(string $pp): string
    {
        $root = $this->basePath;
        $ap = 2001;
        $mp = 2002;

        $conf = "# Auto-generated by Installer - " . date('Y-m-d H:i:s') . "\n\n";
        $conf .= "server {\n";
        $conf .= "    listen {$ap};\n";
        $conf .= "    server_name localhost;\n";
        $conf .= "    root {$root}/backend/dist;\n";
        $conf .= "    index index.html;\n\n";
        $conf .= "    location /api/ {\n";
        $conf .= "        fastcgi_pass {$pp};\n";
        $conf .= "        fastcgi_index index.php;\n";
        $conf .= "        fastcgi_param SCRIPT_FILENAME {$root}/api/public/index.php;\n";
        $conf .= "        fastcgi_param REQUEST_URI \$request_uri;\n\n";
        $conf .= "        set \$real_script_name \$fastcgi_script_name;\n";
        $conf .= "        if (\$fastcgi_script_name ~ \"^(.+?\.php)(/.+)\$\") {\n";
        $conf .= "            set \$real_script_name \$1;\n";
        $conf .= "            set \$path_info \$2;\n";
        $conf .= "        }\n";
        $conf .= "        fastcgi_param SCRIPT_NAME \$real_script_name;\n";
        $conf .= "        fastcgi_param PATH_INFO \$path_info;\n\n";
        $conf .= "        include fastcgi_params;\n";
        $conf .= "        fastcgi_read_timeout 3600s;\n";
        $conf .= "    }\n\n";
        $conf .= "    location / { try_files \$uri \$uri/ /index.html; }\n";
        $conf .= "}\n\n";
        $conf .= "server {\n";
        $conf .= "    listen {$mp};\n";
        $conf .= "    server_name localhost;\n";
        $conf .= "    root {$root}/mobile/dist;\n";
        $conf .= "    index index.html;\n\n";
        $conf .= "    location /api/ {\n";
        $conf .= "        proxy_pass http://127.0.0.1:{$ap}/api/;\n";
        $conf .= "        proxy_set_header Host \$host;\n";
        $conf .= "        proxy_set_header X-Real-IP \$remote_addr;\n";
        $conf .= "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n";
        $conf .= "        proxy_read_timeout 120s;\n";
        $conf .= "    }\n\n";
        $conf .= "    location / { try_files \$uri \$uri/ /index.html; }\n";
        $conf .= "}\n";

        $path = $this->basePath . '/nginx/generated.conf';
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        file_put_contents($path, $conf);
        return $path;
    }

    private function runInlineSchema($capsule): void
    {
        $capsule->getConnection()->statement("CREATE TABLE IF NOT EXISTS `units` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `unit_name` VARCHAR(255) NOT NULL,
            `unit_code` VARCHAR(100) NULL,
            `sort_order` INT DEFAULT 0,
            `created_at` DATETIME,
            `updated_at` DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $capsule->getConnection()->statement("CREATE TABLE IF NOT EXISTS `users` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `unit_id` INT UNSIGNED NULL,
            `name` VARCHAR(100) NOT NULL,
            `real_name` VARCHAR(100) NULL,
            `password_hash` VARCHAR(255) NULL,
            `phone` VARCHAR(20) NULL,
            `position` VARCHAR(100) NULL,
            `user_type` VARCHAR(10) DEFAULT 'A',
            `source` VARCHAR(20) DEFAULT 'admin',
            `status` TINYINT DEFAULT 1,
            `security_question` VARCHAR(255) NULL,
            `security_answer` VARCHAR(255) NULL,
            `device_fingerprint` VARCHAR(255) NULL,
            `created_at` DATETIME,
            `updated_at` DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $capsule->getConnection()->statement("CREATE TABLE IF NOT EXISTS `templates` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `template_name` VARCHAR(255) NOT NULL,
            `items` LONGTEXT NULL,
            `created_at` DATETIME,
            `updated_at` DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $capsule->getConnection()->statement("CREATE TABLE IF NOT EXISTS `examines` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `examine_name` VARCHAR(255) NOT NULL,
            `template_id` INT UNSIGNED NULL,
            `unit_id` INT UNSIGNED NULL,
            `status` VARCHAR(20) DEFAULT 'draft',
            `start_time` DATETIME NULL,
            `end_time` DATETIME NULL,
            `created_at` DATETIME,
            `updated_at` DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $capsule->getConnection()->statement("CREATE TABLE IF NOT EXISTS `system_configs` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `config_key` VARCHAR(100) NOT NULL,
            `config_value` TEXT NULL,
            `config_group` VARCHAR(50) DEFAULT 'auto',
            `description` VARCHAR(255) NULL,
            `created_at` DATETIME,
            `updated_at` DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $capsule->getConnection()->statement("CREATE TABLE IF NOT EXISTS `audit_users` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(100) NOT NULL,
            `password_hash` VARCHAR(255) NOT NULL,
            `real_name` VARCHAR(100) NULL,
            `is_active` TINYINT DEFAULT 1,
            `last_login_at` DATETIME NULL,
            `created_at` DATETIME,
            `updated_at` DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}