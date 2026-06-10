<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Firebase\JWT\Key;
use Firebase\JWT\JWT;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Database\Capsule\Manager as DB;

class AnswerAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader) || !preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return \error_response($request->getAttribute('response') ?? new \Slim\Psr7\Response(), 401, '请先登录');
        }

        $token = $matches[1];

        try {
            $config = include __DIR__ . '/../../config/config.php';
            $decoded = JWT::decode($token, new Key($config['auth']['jwt_secret'], $config['auth']['jwt_algorithm']));
        } catch (ExpiredException) {
            return \error_response($request->getAttribute('response') ?? new \Slim\Psr7\Response(), 401, '登录已过期，请重新登录');
        } catch (SignatureInvalidException | \UnexpectedValueException | \InvalidArgumentException) {
            return \error_response($request->getAttribute('response') ?? new \Slim\Psr7\Response(), 401, '无效的登录凭证');
        } catch (\Throwable $e) {
            error_log('[AnswerAuth] Token decode error: ' . $e->getMessage());
            return \error_response($request->getAttribute('response') ?? new \Slim\Psr7\Response(), 401, '验证失败');
        }

        $userId = (int)($decoded->sub ?? 0);
        if ($userId <= 0) {
            return \error_response($request->getAttribute('response') ?? new \Slim\Psr7\Response(), 401, '无效的用户凭证');
        }

        $user = DB::table('users')->where('id', $userId)->where(function ($q) {
            $q->where('status', 'active')->orWhere('status', 1);
        })->first();
        if (!$user) {
            return \error_response($request->getAttribute('response') ?? new \Slim\Psr7\Response(), 404, '用户不存在或已被禁用');
        }

        $request = $request->withAttribute('user_id', $user->id)
            ->withAttribute('user_phone', $user->phone)
            ->withAttribute('user_name', $user->name ?? $user->real_name)
            ->withAttribute('user_type', $user->user_type)
            ->withAttribute('user_source', $user->source);

        return $handler->handle($request);
    }
}
