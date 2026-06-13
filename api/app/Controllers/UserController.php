<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

class UserController
{
    public function index(Request $request, Response $response): Response
    {
        $page = (int)($request->getQueryParams()['page'] ?? 1);
        $perPage = (int)($request->getQueryParams()['per_page'] ?? 20);
        $unitId = $request->getQueryParams()['unit_id'] ?? null;
        $source = $request->getQueryParams()['source'] ?? null;
        $keyword = trim($request->getQueryParams()['keyword'] ?? '');

        $query = DB::table('users')
            ->leftJoin('units', 'users.unit_id', '=', 'units.id')
            ->select(
                'users.id',
                'users.name',
                'users.real_name',
                'users.phone',
                'users.unit_id',
                'users.position',
                'users.user_type',
                'users.source',
                'users.status',
                'users.created_at',
                'users.updated_at',
                'units.unit_name',
                DB::raw("CASE WHEN users.status = 1 OR users.status = '1' OR users.status = 'active' THEN 'active' ELSE 'disabled' END as status_text")
            );

        if ($unitId) {
            $query->where('users.unit_id', (int)$unitId);
        }

        if ($source && in_array($source, ['admin', 'registered'])) {
            $query->where('users.source', $source);
        }

        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('users.name', 'like', '%' . like_escape($keyword) . '%')
                  ->orWhere('users.phone', 'like', '%' . like_escape($keyword) . '%');
            });
        }

        $total = $query->count();
        $users = $query->orderBy('users.id', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $sensitiveFields = ['password_hash', 'security_answer', 'device_fingerprint'];
        $users = $users->map(function ($user) use ($sensitiveFields) {
            foreach ($sensitiveFields as $field) {
                if (isset($user->$field)) {
                    unset($user->$field);
                }
            }
            return $user;
        });

        return success_response($response, [
            'data' => $users,
            'pagination' => paginate($page, $perPage, $total),
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        
        $user = DB::table('users')
            ->leftJoin('units', 'users.unit_id', '=', 'units.id')
            ->select(
                'users.id',
                'users.name',
                'users.real_name',
                'users.phone',
                'users.unit_id',
                'users.position',
                'users.user_type',
                'users.source',
                'users.status',
                'users.created_at',
                'users.updated_at',
                'units.unit_name'
            )
            ->where('users.id', $id)
            ->first();
        
        if (!$user) {
            return error_response($response, 404, '用户不存在');
        }

        return success_response($response, $user);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        $name = trim($data['name'] ?? '');
        $unitId = (int)($data['unit_id'] ?? 0);

        if (empty($name)) {
            return error_response($response, 400, '姓名不能为空');
        }

        if (!$unitId || !DB::table('units')->where('id', $unitId)->exists()) {
            return error_response($response, 400, '请选择有效的单位');
        }

        $phone = trim($data['phone'] ?? '');
        if (!empty($phone)) {
            $exists = DB::table('users')
                ->where('source', 'admin')
                ->where('phone', $phone)
                ->exists();

            if ($exists) {
                return error_response($response, 400, '该手机号已存在');
            }
        }

        $id = DB::table('users')->insertGetId([
            'unit_id' => $unitId,
            'name' => $name,
            'phone' => $phone,
            'position' => trim($data['position'] ?? ''),
            'user_type' => in_array($data['user_type'] ?? '', ['A', 'B']) ? $data['user_type'] : 'A',
            'source' => 'admin',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        log_operation(
            (int)$request->getAttribute('admin_id'),
            'users',
            'create',
            'user',
            $id,
            null,
            $request
        );

        return success_response($response, ['id' => $id], '用户创建成功');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();

        $user = DB::table('users')->where('id', $id)->first();
        if (!$user) {
            return error_response($response, 404, '用户不存在');
        }

        $updateData = [];
        if (isset($data['name'])) {
            $updateData['name'] = trim($data['name']);
        }
        if (isset($data['phone'])) {
            $phone = trim($data['phone']);
            // 检查手机号是否已被其他用户使用
            $exists = DB::table('users')
                ->where('phone', $phone)
                ->where('id', '!=', $id)
                ->exists();
            if ($exists) {
                return error_response($response, 400, '该手机号已被其他用户使用');
            }
            $updateData['phone'] = $phone;
        }
        if (isset($data['position'])) {
            $updateData['position'] = trim($data['position']);
        }
        if (isset($data['user_type']) && in_array($data['user_type'], ['A', 'B'])) {
            $updateData['user_type'] = $data['user_type'];
        }
        if (isset($data['status'])) {
            $statusValue = $data['status'];
            if ($statusValue === 1 || $statusValue === '1' || $statusValue === 'active') {
                $updateData['status'] = 'active';
            } elseif ($statusValue === 0 || $statusValue === '0' || $statusValue === 'disabled') {
                $updateData['status'] = 'disabled';
            }
        }

        DB::table('users')->where('id', $id)->update($updateData);

        log_operation(
            (int)$request->getAttribute('admin_id'),
            'users',
            'update',
            'user',
            $id,
            null,
            $request
        );

        return success_response($response, null, '用户更新成功');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        $user = DB::table('users')->where('id', $id)->first();
        if (!$user) {
            return error_response($response, 404, '用户不存在');
        }

        $hasExamineRecords = DB::table('examine_users')->where('user_id', $id)->exists();
        if ($hasExamineRecords) {
            return error_response($response, 400, '该用户已有测评记录，无法删除');
        }

        DB::table('users')->where('id', $id)->delete();

        log_operation(
            (int)$request->getAttribute('admin_id'),
            'users',
            'delete',
            'user',
            $id,
            null,
            $request
        );

        return success_response($response, null, '用户删除成功');
    }

    public function adminResetPassword(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $currentRole = $request->getAttribute('admin_role') ?? '';

        if ($currentRole !== 'super') {
            return error_response($response, 403, '仅超级管理员可重置用户密码');
        }
        $data = $request->getParsedBody();
        if (empty($data) || !is_array($data)) {
            $rawBody = (string) $request->getBody();
            if (!empty($rawBody)) {
                $data = json_decode($rawBody, true) ?? [];
            }
        }

        $newPassword = trim((string)($data['new_password'] ?? ''));

        if (empty($newPassword)) {
            return error_response($response, 400, '新密码不能为空');
        }

        if (strlen($newPassword) < 8) {
            return error_response($response, 400, '密码长度不能少于8位');
        }
        if (!preg_match('/[a-zA-Z]/', $newPassword)) {
            return error_response($response, 400, '密码必须包含至少一个字母');
        }
        if (!preg_match('/[0-9]/', $newPassword)) {
            return error_response($response, 400, '密码必须包含至少一个数字');
        }

        $user = DB::table('users')->where('id', $id)->first();
        if (!$user) {
            return error_response($response, 404, '用户不存在');
        }

        DB::table('users')
            ->where('id', $id)
            ->update([
                'password_hash' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        log_operation(
            (int)$request->getAttribute('admin_id'),
            'users',
            'reset_password',
            'user',
            $id,
            json_encode([
                'user_name' => $user->name,
                'user_phone' => $user->phone,
                'operation' => '管理员重置用户密码'
            ], JSON_UNESCAPED_UNICODE),
            $request
        );

        return success_response($response, [
            'message' => '密码重置成功',
        ]);
    }

}