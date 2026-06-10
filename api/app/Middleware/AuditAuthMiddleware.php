<?php
declare(strict_types=1);

namespace App\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class AuditAuthMiddleware implements MiddlewareInterface
{
    private $container;

    public function __construct($container)
    {
        $this->container = $container;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $config = $this->container->get('config');
        $secret = $config['audit']['jwt_secret'] ?? '';

        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode([
                'code' => 401,
                'message' => '未授权访问'
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(401)
                ->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        $token = substr($authHeader, 7);

        try {
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            $request = $request->withAttribute('audit_user_id', $decoded->sub);
            $request = $request->withAttribute('audit_username', $decoded->username);
            return $handler->handle($request);
        } catch (\Exception $e) {
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode([
                'code' => 401,
                'message' => 'Token无效或已过期'
            ], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(401)
                ->withHeader('Content-Type', 'application/json; charset=utf-8');
        }
    }
}