<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

class AdminController
{
    public function index(Request $request, Response $response): Response
    {
        $page = (int)($request->getQueryParams()['page'] ?? 1);
        $perPage = (int)($request->getQueryParams()['per_page'] ?? 20);
        $keyword = trim($request->getQueryParams()['keyword'] ?? '');
        $role = $request->getQueryParams()['role'] ?? null;

        $query = DB::table('admins');

        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('username', 'like', '%' . like_escape($keyword) . '%')
                  ->orWhere('real_name', 'like', '%' . like_escape($keyword) . '%');
            });
        }

        if ($role && in_array($role, ['super', 'template', 'viewer'])) {
            $query->where('role', $role);
        }

        $total = (clone $query)->count();
        $admins = $query->orderBy('id', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(function ($admin) {
                $admin->password_hash = '******';
                return $admin;
            });

        return success_response($response, [
            'data' => $admins,
            'pagination' => paginate($page, $perPage, $total),
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        $admin = DB::table('admins')->where('id', $id)->first();

        if (!$admin) {
            return error_response($response, 404, '管理员不存在');
        }

        $admin->password_hash = '******';

        return success_response($response, $admin);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        $username = trim($data['username'] ?? '');
        $password = trim($data['password'] ?? '');
        $realName = trim($data['real_name'] ?? '');
        $role = trim($data['role'] ?? 'viewer');
        $currentRole = $request->getAttribute('admin_role') ?? '';

        if ($role === 'super' && $currentRole !== 'super') {
            return error_response($response, 403, '只有超级管理员可以创建超级管理员账号');
        }
        $status = trim($data['status'] ?? 'active');

        if (empty($username)) {
            return error_response($response, 400, '用户名不能为空');
        }

        if (strlen($username) < 3 || strlen($username) > 50) {
            return error_response($response, 400, '用户名长度应在3-50个字符之间');
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            return error_response($response, 400, '用户名只能包含字母、数字和下划线');
        }

        if (DB::table('admins')->where('username', $username)->exists()) {
            return error_response($response, 409, '用户名已存在');
        }

        if (empty($password)) {
            return error_response($response, 400, '密码不能为空');
        }

        if (strlen($password) < 6) {
            return error_response($response, 400, '密码长度不能少于6位');
        }

        if (empty($realName)) {
            return error_response($response, 400, '真实姓名不能为空');
        }

        if (!in_array($role, ['super', 'template', 'viewer'])) {
            return error_response($response, 400, '无效的角色类型');
        }

        if (in_array($status, ['active', 'inactive'], true)) {
            $statusInt = $status === 'active' ? 1 : 0;
        } elseif (in_array($status, [0, 1], true)) {
            $statusInt = (int)$status;
        } else {
            return error_response($response, 400, '无效的状态');
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $id = DB::table('admins')->insertGetId([
            'username' => $username,
            'password_hash' => $passwordHash,
            'real_name' => $realName,
            'role' => $role,
            'status' => $statusInt,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        log_operation(
            $request,
            'create_admin',
            "创建管理员: {$username} (ID: {$id})"
        );

        return success_response($response, [
            'id' => $id,
            'message' => '管理员创建成功',
        ], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();

        if (!$data || !is_array($data)) {
            return error_response($response, 400, '无效的请求数据');
        }

        $admin = DB::table('admins')->where('id', $id)->first();
        if (!$admin) {
            return error_response($response, 404, '管理员不存在');
        }

        $updateData = [];

        if (array_key_exists('real_name', $data)) {
            $realName = trim($data['real_name']);
            if (empty($realName)) {
                return error_response($response, 400, '真实姓名不能为空');
            }
            $updateData['real_name'] = $realName;
        }

        if (array_key_exists('role', $data)) {
            $role = trim($data['role']);
            if (!in_array($role, ['super', 'template', 'viewer'])) {
                return error_response($response, 400, '无效的角色类型');
            }
            $updateData['role'] = $role;
        }

        if (array_key_exists('status', $data)) {
            $status = $data['status'];
            if (in_array($status, ['active', 'inactive'], true)) {
                $statusInt = $status === 'active' ? 1 : 0;
            } elseif (in_array($status, [0, 1], true)) {
                $statusInt = (int)$status;
            } else {
                return error_response($response, 400, '无效的状态');
            }
            $updateData['status'] = $statusInt;
        }

        if (array_key_exists('password', $data) && !empty(trim($data['password']))) {
            $password = trim($data['password']);
            if (strlen($password) < 6) {
                return error_response($response, 400, '密码长度不能少于6位');
            }
            $updateData['password_hash'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        }

        if (empty($updateData)) {
            return error_response($response, 400, '没有需要更新的数据');
        }

        $updateData['updated_at'] = date('Y-m-d H:i:s');

        DB::table('admins')->where('id', $id)->update($updateData);

        log_operation(
            $request,
            'update_admin',
            "更新管理员: {$admin->username} (ID: {$id})"
        );

        return success_response($response, [
            'message' => '管理员更新成功',
        ]);
    }
}
