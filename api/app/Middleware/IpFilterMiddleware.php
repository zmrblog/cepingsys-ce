<?php
declare(strict_types=1);

namespace App\Middleware;

use GeoIp2\Database\Reader;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class IpFilterMiddleware implements MiddlewareInterface
{
    private Reader $geoipReader;

    private static ?bool $enabledCache = null;
    private static int $cacheTime = 0;
    private const CACHE_TTL = 10;

    private static function log_to_file(string $msg): void
    {
        $dir = __DIR__ . '/../../storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = date('Y-m-d H:i:s') . ' ' . $msg . PHP_EOL;
        @file_put_contents($dir . '/ipfilter.log', $line, FILE_APPEND);
    }

    public function __construct(string $mmdbPath)
    {
        if (!file_exists($mmdbPath)) {
            throw new \RuntimeException("GeoIP2 database file not found: {$mmdbPath}");
        }

        $this->geoipReader = new Reader($mmdbPath);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $this->getClientIp($request);
        $path = $request->getUri()->getPath();

        if (!$this->isEnabled()) {
            self::log_to_file("PASS | enabled=false | IP={$ip} | path={$path}");
            return $handler->handle($request);
        }

        if ($this->isPrivateOrLocalIp($ip)) {
            self::log_to_file("PASS | private_ip | IP={$ip} | path={$path}");
            return $handler->handle($request);
        }

        if (!$this->isChinaIP($ip)) {
            self::log_to_file("BLOCK | foreign_ip | IP={$ip} | path={$path}");
            $this->logBlockedAccess($ip, $request);

            $response = new Response();
            $response->getBody()->write(json_encode([
                'code' => 403,
                'message' => 'Access Denied: Foreign IP not allowed',
            ], JSON_UNESCAPED_UNICODE));

            return $response
                ->withStatus(403)
                ->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }

    private function isEnabled(): bool
    {
        if (self::$enabledCache !== null && (time() - self::$cacheTime) < self::CACHE_TTL) {
            return self::$enabledCache;
        }

        try {
            $row = DB::table('system_configs')
                ->where('config_key', 'ip_filter_enabled')
                ->first();

            $rawValue = $row ? $row->config_value : '(key_not_found)';
            self::$enabledCache = $row && $row->config_value === '1';
            self::$cacheTime = time();

            self::log_to_file("CONFIG | isEnabled=" . (self::$enabledCache ? 'true' : 'false') . " | raw_value={$rawValue}");

            return self::$enabledCache;
        } catch (\Exception $e) {
            self::log_to_file("CONFIG_ERR | DB query failed: " . $e->getMessage());
            return false;
        }
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        $remoteAddr = $serverParams['REMOTE_ADDR'] ?? '127.0.0.1';
        $trustedProxies = ['127.0.0.1', '::1'];

        $ip = $serverParams['HTTP_X_FORWARDED_FOR'] ?? null;
        if ($ip) {
            if (in_array($remoteAddr, $trustedProxies)) {
                $ips = explode(',', $ip);
                return trim($ips[0]);
            }
        }

        $ip = $serverParams['HTTP_X_REAL_IP'] ?? null;
        if ($ip) {
            return $ip;
        }

        return $remoteAddr;
    }

    private function isPrivateOrLocalIp(string $ip): bool
    {
        if ($ip === '127.0.0.1' || $ip === '::1' || $ip === 'localhost') {
            return true;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }

        $privateRanges = [
            [ip2long('10.0.0.0'),    ip2long('10.255.255.255')],
            [ip2long('172.16.0.0'),  ip2long('172.31.255.255')],
            [ip2long('192.168.0.0'), ip2long('192.168.255.255')],
        ];

        foreach ($privateRanges as [$start, $end]) {
            if ($long >= $start && $long <= $end) {
                return true;
            }
        }

        return false;
    }

    private function isChinaIP(string $ip): bool
    {
        try {
            $record = $this->geoipReader->country($ip);
            $countryCode = $record->country->isoCode;

            return $countryCode === 'CN';
        } catch (\Exception $e) {
            self::log_to_file("GEOIP_ERR | IP={$ip}: " . $e->getMessage());
            return true;
        }
    }

    private function getCountryCode(string $ip): string
    {
        try {
            $record = $this->geoipReader->country($ip);
            return $record->country->isoCode ?? '';
        } catch (\Exception $e) {
            return '';
        }
    }

    private function logBlockedAccess(string $ip, ServerRequestInterface $request): void
    {
        try {
            DB::table('ip_block_logs')->insert([
                'ip_address' => $ip,
                'request_path' => $request->getUri()->getPath(),
                'request_method' => $request->getMethod(),
                'user_agent' => substr($request->getServerParams()['HTTP_USER_AGENT'] ?? '', 0, 500),
                'country_code' => $this->getCountryCode($ip),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            self::log_to_file("BLOCK_LOG_ERR: " . $e->getMessage());
        }
    }

    public static function clearCache(): void
    {
        self::$enabledCache = null;
        self::$cacheTime = 0;
    }
}
