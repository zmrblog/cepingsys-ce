<?php
declare(strict_types=1);

return [
    'database' => [
        'driver' => 'mysql',
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '3306'),
        'database' => env('DB_DATABASE', 'examine_system'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD'),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
        'engine' => null,
    ],

    'app' => [
        'name' => env('APP_NAME', '考核测评系统社区版'),
        'env' => env('APP_ENV', 'production'),
        'debug' => env('APP_DEBUG', false),
        'url' => env('APP_URL', 'http://test.com:2001'),
        'timezone' => 'Asia/Shanghai',
    ],

    'auth' => [
        'jwt_secret' => env('JWT_SECRET'),
        'jwt_expire_hours' => (int)env('JWT_EXPIRE_HOURS', '24'),
        'jwt_algorithm' => 'HS256',
    ],

    'login' => [
        'max_attempts' => (int)env('LOGIN_MAX_ATTEMPTS', 5),
        'lockout_minutes' => (int)env('LOGIN_LOCKOUT_MINUTES', 5),
    ],

    'ip_filter' => [
        'enabled' => env('IP_FILTER_ENABLED', true),
        'mmdb_path' => env('MMDB_PATH', __DIR__ . '/../data/Country-without-asn.mmdb'),
    ],

    'audit' => [
        'jwt_secret' => env('AUDIT_JWT_SECRET'),
        'jwt_expire_hours' => (int)env('AUDIT_JWT_EXPIRE_HOURS', '2'),
        'jwt_algorithm' => 'HS256',
    ],

    'upload' => [
        'max_size' => (int)env('UPLOAD_MAX_SIZE', '10485760'), // 10MB
        'allowed_extensions' => ['xlsx', 'xls'],
        'path' => __DIR__ . '/../../uploads/templates/',
    ],

    'logging' => [
        'path' => __DIR__ . '/../../logs/app.log',
        'level' => \Monolog\Level::Warning,
    ],
];
