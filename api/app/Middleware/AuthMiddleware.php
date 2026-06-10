<?php
declare(strict_types=1);

namespace App\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class AuthMiddleware implements MiddlewareInterface
{
    private $container;

    public function __construct($container)
    {
        $this->container = $container;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return error_response(new Response(), 401, '未提供有效的认证令牌');
        }

        $token = $matches[1];

        try {
            $config = $this->container->get('config')['auth'];
            $decoded = JWT::decode($token, new Key($config['jwt_secret'], $config['jwt_algorithm']));

            $request = $request->withAttribute('admin_id', $decoded->sub);
            $request = $request->withAttribute('admin_role', $decoded->role ?? null);
            $request = $request->withAttribute('admin_name', $decoded->name ?? null);
        } catch (ExpiredException $e) {
            return error_response(new Response(), 401, '令牌已过期，请重新登录');
        } catch (SignatureInvalidException $e) {
            return error_response(new Response(), 401, '无效的令牌签名');
        } catch (\Throwable $e) {
            return error_response(new Response(), 401, '令牌验证失败: ' . $e->getMessage());
        }

        return $handler->handle($request);
    }

    public static function generateToken(int $adminId, string $role, string $name): string
    {
        $config = include __DIR__ . '/../../config/config.php';

        $expireHours = (int)($config['auth']['jwt_expire_hours'] ?? 24);
        if ($expireHours < 1) {
            $expireHours = 24;
        }

        $jwtSecret = $config['auth']['jwt_secret'] ?? null;
        if (empty($jwtSecret)) {
            error_log('[SECURITY] JWT_SECRET is not configured!');
            throw new \RuntimeException('JWT_SECRET is not configured');
        }
        $jwtAlgorithm = $config['auth']['jwt_algorithm'] ?? 'HS256';

        $payload = [
            'iss' => 'examine-system',
            'iat' => time(),
            'exp' => time() + ($expireHours * 3600),
            'sub' => $adminId,
            'role' => $role,
            'name' => $name,
        ];

        return JWT::encode($payload, $jwtSecret, $jwtAlgorithm);
    }
}
