<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

class AuthController
{
    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        if (empty($data) || !is_array($data)) {
            $rawBody = (string) $request->getBody();
            if (!empty($rawBody)) {
                $data = json_decode($rawBody, true) ?? [];
            }
        }

        $clientIp = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        $config = include __DIR__ . '/../../config/config.php';
        $maxAttempts = (int)($config['login']['max_attempts'] ?? 5);
        $lockoutMinutes = (int)($config['login']['lockout_minutes'] ?? 5);

        $attemptRecord = DB::table('ip_attempts')
            ->where('ip_address', $clientIp)
            ->where('attempts', '>=', $maxAttempts)
            ->first();

        if ($attemptRecord) {
            $lockedUntil = strtotime($attemptRecord->last_attempt) + ($lockoutMinutes * 60);
            if (time() < $lockedUntil) {
                $remainSeconds = $lockedUntil - time();
                return error_response($response, 429, "登录尝试次数过多，请{$lockoutMinutes}分钟后再试");
            } else {
                DB::table('ip_attempts')->where('id', $attemptRecord->id)->delete();
            }
        }

        $username = trim((string)($data['username'] ?? $data['phone'] ?? ''));
        $password = (string)($data['password'] ?? '');

        if (empty($username) || empty($password)) {
            return error_response($response, 400, '用户名和密码不能为空');
        }

        $isPhoneLogin = preg_match('/^1[3-9]\d{9}$/', $username);

        $admin = null;
        $user = null;

        if ($isPhoneLogin) {
            $user = DB::table('users')
                ->where('phone', $username)
                ->where('source', 'registered')
                ->first();
        } else {
            $admin = DB::table('admins')
                ->where('username', $username)
                ->first();
        }

        if (!$admin && !$user) {
        $this->recordLoginAttempt($clientIp);
        return error_response($response, 401, '用户名或密码错误');
    }

        if ($admin) {
            if ($admin->status == 0) {
                return error_response($response, 403, '账号已被禁用，请联系管理员');
            }
            if (!password_verify($password, $admin->password_hash)) {
            $this->recordLoginAttempt($clientIp);
            return error_response($response, 401, '用户名或密码错误');
        }
        } else {
            if ($user->status !== 'active') {
                return error_response($response, 403, '账号已被禁用，请联系管理员');
            }
            if (($user->source ?? 'admin') !== 'registered') {
                return error_response($response, 403, '该账号不支持登录，请联系管理员');
            }
            if (empty($user->password_hash)) {
                return error_response($response, 401, '该账号未设置密码，请使用找回密码功能');
            }
            if (!password_verify($password, $user->password_hash)) {
            $this->recordLoginAttempt($clientIp);
            return error_response($response, 401, '用户名或密码错误');
        }
        }

        $token = AuthMiddleware::generateToken(
            (int)($admin ? $admin->id : $user->id),
            $admin ? $admin->role : 'user',
            $admin ? ($admin->real_name ?: $admin->username) : ($user->real_name ?: $user->name)
        );

        $ip = $request->getServerParams()['HTTP_X_FORWARDED_FOR']
            ?? $request->getServerParams()['HTTP_X_REAL_IP']
            ?? $request->getServerParams()['REMOTE_ADDR']
            ?? '127.0.0.1';

        if ($admin) {
            DB::table('admins')
                ->where('id', $admin->id)
                ->update([
                    'last_login_at' => date('Y-m-d H:i:s'),
                    'last_login_ip' => $ip,
                ]);

            log_operation($request, 'login', "管理员登录: {$admin->username} (IP: {$ip})");
        } else {
            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            log_operation($request, 'login', "用户登录: {$user->phone} / {$user->real_name} (IP: {$ip})");
        }

        if ($admin) {
            return success_response($response, [
                'token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => $config['auth']['jwt_expire_hours'] * 3600,
                'admin' => [
                    'id' => $admin->id,
                    'username' => $admin->username,
                    'real_name' => $admin->real_name,
                    'role' => $admin->role,
                    'role_text' => $this->getRoleText($admin->role),
                ],
            ], '登录成功');
        } else {
            return success_response($response, [
                'token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => $config['auth']['jwt_expire_hours'] * 3600,
                'user' => [
                    'id' => $user->id,
                    'phone' => $user->phone,
                    'name' => $user->name,
                    'real_name' => $user->real_name,
                    'unit_id' => $user->unit_id,
                    'position' => $user->position,
                    'security_question' => (int)$user->security_question,
                ],
            ], '登录成功');
        }
    }

    public function logout(Request $request, Response $response): Response
    {
        $adminId = $request->getAttribute('admin_id');

        log_operation($adminId, 'auth', 'logout', 'admin', $adminId, null, $request);

        return success_response($response, null, '已退出登录');
    }

    public function resetPassword(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        $phone = trim($data['phone'] ?? '');
        $realName = trim($data['real_name'] ?? '');
        $securityQuestion = (int)($data['security_question'] ?? 0);
        $securityAnswer = trim($data['security_answer'] ?? '');
        $newPassword = trim($data['password'] ?? '');

        if (empty($phone) || empty($realName) || !$securityQuestion || empty($securityAnswer)) {
            return error_response($response, 400, '请填写完整信息');
        }

        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return error_response($response, 400, '手机号格式不正确');
        }

        if ($securityQuestion < 1 || $securityQuestion > 5) {
            return error_response($response, 400, '无效的安全问题');
        }

        if (strlen($newPassword) < 8 || strlen($newPassword) > 64) {
            return error_response($response, 400, '密码长度应为8-64位');
        }
        if (!preg_match('/[a-zA-Z]/', $newPassword)) {
            return error_response($response, 400, '密码必须包含至少一个字母');
        }
        if (!preg_match('/[0-9]/', $newPassword)) {
            return error_response($response, 400, '密码必须包含至少一个数字');
        }

        $user = DB::table('users')
            ->where('phone', $phone)
            ->where('real_name', $realName)
            ->first();

        if (!$user) {
            return error_response($response, 400, '密码重置失败，请检查信息后重试');
        }

        if ((int)$user->security_question !== $securityQuestion || !password_verify($securityAnswer, $user->security_answer)) {
            return error_response($response, 400, '密码重置失败，请检查信息后重试');
        }

        DB::table('users')
            ->where('id', $user->id)
            ->update([
                'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return success_response($response, ['message' => '密码重置成功，请使用新密码登录']);
    }

    public function register(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        $phone = trim($data['phone'] ?? '');
        $realName = trim($data['real_name'] ?? '');
        $unitId = isset($data['unit_id']) && $data['unit_id'] ? (int)$data['unit_id'] : null;
        $position = trim($data['position'] ?? '');
        $password = trim($data['password'] ?? '');
        $deviceFingerprint = trim($data['device_fingerprint'] ?? '');
        $securityQuestion = (int)($data['security_question'] ?? 0);
        $securityAnswer = trim($data['security_answer'] ?? '');

        if (empty($phone)) {
            return error_response($response, 400, '请输入手机号');
        }
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return error_response($response, 400, '手机号格式不正确');
        }
        if (empty($realName)) {
            return error_response($response, 400, '请输入真实姓名');
        }
        if (empty($password) || strlen($password) < 8) {
            return error_response($response, 400, '密码长度不能少于8位');
        }
        if (!preg_match('/[a-zA-Z]/', $password)) {
            return error_response($response, 400, '密码必须包含至少一个字母');
        }
        if (!preg_match('/[0-9]/', $password)) {
            return error_response($response, 400, '密码必须包含至少一个数字');
        }
        if (!$securityQuestion || $securityQuestion < 1 || $securityQuestion > 5) {
            return error_response($response, 400, '请选择安全问题');
        }
        if (empty($securityAnswer)) {
            return error_response($response, 400, '请输入安全问题答案');
        }
        if (empty($deviceFingerprint)) {
            return error_response($response, 400, '无法获取设备指纹');
        }

        $exists = DB::table('users')
            ->where('phone', $phone)
            ->where('source', 'registered')
            ->first();
        if ($exists) {
            return success_response($response, ['message' => '验证码已发送']);
        }

        if ($unitId) {
            $unitExists = DB::table('units')->where('id', $unitId)->first();
            if (!$unitExists) {
                return error_response($response, 400, '所选部门不存在');
            }
        }

        $userId = DB::table('users')->insertGetId([
            'name' => $realName,
            'real_name' => $realName,
            'phone' => $phone,
            'unit_id' => $unitId,
            'position' => $position ?: '',
            'source' => 'registered',
            'status' => 'active',
            'device_fingerprint' => $deviceFingerprint,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'security_question' => $securityQuestion,
            'security_answer' => password_hash(strtolower(trim($securityAnswer)), PASSWORD_BCRYPT, ['cost' => 12]),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        log_operation(
            $request,
            'register',
            "用户注册: {$realName} (ID: {$userId})"
        );

        return success_response($response, [
            'message' => '注册成功',
            'user_id' => $userId,
        ]);
    }

    private function getRoleText(string $role): string
    {
        return match ($role) {
            'super' => '超级管理员',
            'template' => '模板管理员',
            'viewer' => '查看管理员',
            default => $role,
        };
    }

    public function securityQuestions(Request $request, Response $response): Response
    {
        return success_response($response, [
            'questions' => [
                1 => '您的小学学校名称是什么？',
                2 => '您的父亲姓名是什么？',
                3 => '您出生的城市是哪里？',
                4 => '您最喜欢的运动是什么？',
                5 => '您第一只宠物的名字是什么？',
            ],
        ]);
    }

    private function recordLoginAttempt(string $ip): void
    {
        try {
            DB::table('ip_attempts')->updateOrInsert(
                ['ip_address' => $ip],
                [
                    'attempts' => DB::raw('COALESCE(attempts, 0) + 1'),
                    'last_attempt' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]
            );
        } catch (\Throwable $e) {
            error_log('[Auth] recordLoginAttempt failed: ' . $e->getMessage());
        }
    }
}
