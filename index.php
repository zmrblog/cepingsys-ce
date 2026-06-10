<?php
declare(strict_types=1);

header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noai, noimageai', true);

$basePath = __DIR__;
$lockFile    = $basePath . '/install.lock';
$installedFile = $basePath . '/api/storage/.installed';
$envFile     = $basePath . '/api/.env';
$installPhp   = $basePath . '/install.php';

$lockExists     = file_exists($lockFile);
$installedExists = file_exists($installedFile);
$envExists      = file_exists($envFile);
$installPhpExists = file_exists($installPhp);

$isInstalled = $lockExists || $installedExists;

// === 未安装 → 跳转到安装向导 ===
if (!$isInstalled) {
    if ($installPhpExists) {
        header('Location: /install.php');
        exit;
    }
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>系统未安装</title></head>'
       . '<body style="font-family:-apple-system,sans-serif;display:flex;justify-content:center;'
       . 'align-items:center;min-height:100vh;background:#f0f4f8;color:#2d3748;margin:0">'
       . '<div style="background:#fff;padding:48px;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);'
       . 'text-align:center;max-width:420px">'
       . '<h2 style="color:#e53e3e;margin-bottom:12px">系统未安装</h2>'
       . '<p style="color:#718096">请先运行安装向导完成系统初始化</p>'
       . '<p style="color:#c53030;font-size:12px;margin-top:12px">安装包可能不完整，请重新下载</p>'
       . '</div></body></html>';
    exit;
}

// === 已安装 → 跳转到移动端前台（2002 端口）===
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$host = preg_replace('/:\d+$/', '', $host);
header("Location: http://{$host}:2002/");
exit;
