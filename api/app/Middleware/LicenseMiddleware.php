<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class LicenseMiddleware implements MiddlewareInterface
{
    private $container;

    public function __construct($container)
    {
        $this->container = $container;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $feature = $request->getAttribute('required_feature');

        // 检查版别
        $edition = $this->getEdition();

        // 社区版：不需要许可证，直接放行
        if ($edition !== '企业版') {
            return $handler->handle($request);
        }

        // 企业版：以下步骤全部通过才能放行
        // 1. 加载授权文件
        $licenseInfo = $this->loadLicense();
        if (!$licenseInfo) {
            return error_response(new Response(), 403, '未检测到有效的企业版授权，请联系作者获取');
        }

        // 2. RSA 签名验证（防篡改）
        if (!$this->verifySignature($licenseInfo)) {
            return error_response(new Response(), 403, '授权文件签名无效，可能已被篡改');
        }

        // 3. 有效期检查
        if (isset($licenseInfo['expire_at']) && time() > strtotime($licenseInfo['expire_at'])) {
            return error_response(new Response(), 403, '授权已过期，请续费后使用');
        }

        // 4. 绑定验证（域名 + IP）
        $bindResult = $this->verifyBinding($licenseInfo);
        if ($bindResult !== true) {
            return error_response(new Response(), 403, $bindResult);
        }

        // 5. 细粒度功能检查
        if ($feature && !in_array($feature, $licenseInfo['features'] ?? [])) {
            return error_response(new Response(), 403,
                '当前授权不包含此功能（' . $feature . '），请升级企业版套餐');
        }

        $request = $request->withAttribute('license', $licenseInfo);
        return $handler->handle($request);
    }

    /**
     * 获取当前版别
     */
    private function getEdition(): string
    {
        $editionFile = __DIR__ . '/../../edition.json';
        if (file_exists($editionFile)) {
            $data = json_decode(file_get_contents($editionFile), true);
            return $data['edition'] ?? '社区版';
        }
        return '社区版';
    }

    /**
     * 加载授权文件
     */
    private function loadLicense(): ?array
    {
        $licenseFile = __DIR__ . '/../../storage/.license.json';
        if (!file_exists($licenseFile)) return null;
        $content = file_get_contents($licenseFile);
        $data = json_decode($content, true);
        if (!$data || !isset($data['license_key'])) return null;
        // 如果有签名，必须通过验证
        if (isset($data['signature']) && !$this->verifySignature($data)) return null;
        return $data;
    }

    /**
     * RSA+SHA256 签名验证
     */
    private function verifySignature(array $data): bool
    {
        $publicKey = __DIR__ . '/../../storage/license_public.key';
        if (!file_exists($publicKey)) return false;
        $keyContent = file_get_contents($publicKey);
        $publicKeyResource = openssl_pkey_get_public($keyContent);
        if (!$publicKeyResource) return false;

        $signData = json_encode([
            'licensee' => $data['licensee'] ?? '',
            'license_key' => $data['license_key'] ?? '',
            'features' => $data['features'] ?? [],
            'bind_type' => $data['bind_type'] ?? 'none',
            'bind_domain' => $data['bind_domain'] ?? '',
            'bind_ip' => $data['bind_ip'] ?? '',
            'issue_at' => $data['issue_at'] ?? '',
            'expire_at' => $data['expire_at'] ?? '',
            'max_users' => $data['max_users'] ?? 0,
        ], JSON_UNESCAPED_UNICODE);

        $result = openssl_verify($signData, base64_decode($data['signature']), $publicKeyResource, OPENSSL_ALGO_SHA256);
        openssl_free_key($publicKeyResource);
        return $result === 1;
    }

    /**
     * 绑定验证
     *
     * bind_type 支持三种模式：
     * - domain_ip: 域名 + IP 双绑（最严格）
     * - ip: 仅 IP 绑定（无域名的内网场景）
     * - none: 不绑定（试用/特殊场景）
     *
     * @return true|string 通过返回 true，失败返回错误消息
     */
    private function verifyBinding(array $license)
    {
        $bindType = $license['bind_type'] ?? 'domain_ip';
        $currentHost = $_SERVER['HTTP_HOST'] ?? '';
        // 只取 host（去掉端口号）
        $currentHost = preg_replace('/:\d+$/', '', $currentHost);
        $currentIp = $this->getServerIp();

        switch ($bindType) {
            case 'domain_ip':
                $bindDomain = $license['bind_domain'] ?? '';
                $bindIp = $license['bind_ip'] ?? '';
                if ($bindDomain && $currentHost !== $bindDomain) {
                    return "授权域名不匹配（当前：{$currentHost}，授权：{$bindDomain}）";
                }
                if ($bindIp && $currentIp !== $bindIp) {
                    return "授权IP不匹配（当前：{$currentIp}，授权：{$bindIp}）";
                }
                return true;

            case 'ip':
                $bindIp = $license['bind_ip'] ?? '';
                if ($bindIp && $currentIp !== $bindIp) {
                    return "授权IP不匹配（当前：{$currentIp}，授权：{$bindIp}）";
                }
                return true;

            case 'none':
                return true;

            default:
                return '未知的授权绑定类型';
        }
    }

    /**
     * 获取当前服务器的 IP 地址
     * 优先顺序：SERVER_ADDR > LOCAL_ADDR > gethostbyname
     */
    private function getServerIp(): string
    {
        if (!empty($_SERVER['SERVER_ADDR'])) {
            return $_SERVER['SERVER_ADDR'];
        }
        if (!empty($_SERVER['LOCAL_ADDR'])) {
            return $_SERVER['LOCAL_ADDR'];
        }
        $hostname = gethostname();
        if ($hostname) {
            $ip = gethostbyname($hostname);
            if ($ip !== $hostname) {
                return $ip;
            }
        }
        return 'unknown';
    }
}
