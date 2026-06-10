<?php
declare(strict_types=1);

namespace App\Controllers;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

class AuditController
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

        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');

        if (empty($username) || empty($password)) {
            return error_response($response, 400, '账号和密码不能为空');
        }

        $user = DB::table('audit_users')
            ->where('username', $username)
            ->where('is_active', 1)
            ->first();

        if (!$user || !password_verify($password, $user->password_hash)) {
            return error_response($response, 401, '账号或密码错误');
        }

        DB::table('audit_users')
            ->where('id', $user->id)
            ->update(['last_login_at' => date('Y-m-d H:i:s')]);

        $config = include __DIR__ . '/../../config/config.php';
        $secret = $config['audit']['jwt_secret'] ?? null;
        if (empty($secret)) {
            error_log('[SECURITY] AUDIT_JWT_SECRET is not configured!');
            throw new \RuntimeException('AUDIT_JWT_SECRET is not configured');
        }
        $expireHours = (int)($config['audit']['jwt_expire_hours'] ?? 2);

        $now = time();
        $payload = [
            'iss' => 'audit-system',
            'sub' => $user->id,
            'username' => $user->username,
            'real_name' => $user->real_name,
            'iat' => $now,
            'exp' => $now + ($expireHours * 3600),
        ];

        $token = JWT::encode($payload, $secret, 'HS256');

        return success_response($response, [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $expireHours * 3600,
            'real_name' => $user->real_name,
        ], '登录成功');
    }

    public function getExamines(Request $request, Response $response): Response
    {
        $examines = DB::table('examines')
            ->select('id', 'examine_name', 'unit_id', 'status', 'start_time', 'end_time')
            ->orderBy('id', 'desc')
            ->get();

        return success_response($response, ['list' => $examines]);
    }

    public function getUnits(Request $request, Response $response): Response
    {
        $units = DB::table('units')
            ->select('id', 'unit_name')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return success_response($response, ['list' => $units]);
    }

    public function queryData(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $unitId = intval($params['unit_id'] ?? 0);
        $examineId = intval($params['examine_id'] ?? 0);
        $keyword = trim($params['keyword'] ?? '');

        $query = DB::table('examine_answers as ea')
            ->join('users as u', 'ea.user_id', '=', 'u.id')
            ->join('examine_targets as et', 'ea.target_id', '=', 'et.id')
            ->join('template_items as ti', 'ea.item_id', '=', 'ti.id')
            ->join('examines as e', 'ea.examine_id', '=', 'e.id');

        if ($unitId > 0) {
            $query->where('u.unit_id', $unitId);
        }

        if ($examineId > 0) {
            $query->where('ea.examine_id', $examineId);
        }

        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $escapedKeyword = like_escape($keyword);
        $q->where('ea.answer_value', 'like', '%' . $escapedKeyword . '%')
            ->orWhere('ea.example_text', 'like', '%' . $escapedKeyword . '%');
            });
        }

        $records = $query->select(
                DB::raw('COALESCE(u.real_name, u.name) as user_name'),
                'u.phone as user_phone',
                'u.user_type',
                'e.examine_name',
                'et.target_name',
                'et.target_type',
                'ti.item_title',
                'ti.item_type',
                'ea.answer_value',
                'ea.example_text',
                'ea.answered_at'
            )
            ->orderBy('e.examine_name')
            ->orderBy('u.name')
            ->orderBy('et.sort_order')
            ->orderBy('ti.sort_order');

        $total = (clone $query)->count();
        $page = max(1, intval($params['page'] ?? 1));
        $perPage = min(500, max(20, intval($params['per_page'] ?? 100)));
        $records = $records->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return success_response($response, [
            'records' => $records,
            'pagination' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    public function getUsers(Request $request, Response $response): Response
    {
        $users = DB::table('audit_users')
            ->select('id', 'username', 'real_name', 'is_active', 'last_login_at', 'created_at')
            ->orderBy('id')
            ->get();

        return success_response($response, ['list' => $users]);
    }

    public function createUser(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        if (empty($data) || !is_array($data)) {
            $rawBody = (string) $request->getBody();
            if (!empty($rawBody)) {
                $data = json_decode($rawBody, true) ?? [];
            }
        }

        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $realName = trim((string)($data['real_name'] ?? ''));

        if (empty($username) || empty($password)) {
            return error_response($response, 400, '账号和密码不能为空');
        }

        if (mb_strlen($password) < 8) {
            return error_response($response, 400, '密码长度不能少于8位');
        }
        if (!preg_match('/[a-zA-Z]/', $password)) {
            return error_response($response, 400, '密码必须包含至少一个字母');
        }
        if (!preg_match('/[0-9]/', $password)) {
            return error_response($response, 400, '密码必须包含至少一个数字');
        }

        $exists = DB::table('audit_users')->where('username', $username)->exists();
        if ($exists) {
            return error_response($response, 400, '账号已存在');
        }

        $id = DB::table('audit_users')->insertGetId([
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'real_name' => $realName,
        ]);

        return success_response($response, ['id' => $id], '创建成功');
    }

    public function updateUser(Request $request, Response $response, array $args): Response
    {
        $id = intval($args['id'] ?? 0);

        $user = DB::table('audit_users')->where('id', $id)->first();
        if (!$user) {
            return error_response($response, 404, '审计账号不存在');
        }

        $data = $request->getParsedBody();
        if (empty($data) || !is_array($data)) {
            $rawBody = (string) $request->getBody();
            if (!empty($rawBody)) {
                $data = json_decode($rawBody, true) ?? [];
            }
        }

        $update = [];

        $realName = trim((string)($data['real_name'] ?? ''));
        if ($realName !== '') {
            $update['real_name'] = $realName;
        }

        $password = (string)($data['password'] ?? '');
        if (!empty($password)) {
            if (mb_strlen($password) < 6) {
                return error_response($response, 400, '密码长度不能少于6位');
            }
            $update['password_hash'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        }

        if (!empty($update)) {
            DB::table('audit_users')->where('id', $id)->update($update);
        }

        return success_response($response, [], '更新成功');
    }

    public function deleteUser(Request $request, Response $response, array $args): Response
    {
        $id = intval($args['id'] ?? 0);

        $user = DB::table('audit_users')->where('id', $id)->first();
        if (!$user) {
            return error_response($response, 404, '审计账号不存在');
        }

        DB::table('audit_users')->where('id', $id)->delete();

        return success_response($response, [], '删除成功');
    }

    public function toggleUser(Request $request, Response $response, array $args): Response
    {
        $id = intval($args['id'] ?? 0);

        $user = DB::table('audit_users')->where('id', $id)->first();
        if (!$user) {
            return error_response($response, 404, '审计账号不存在');
        }

        $newStatus = $user->is_active ? 0 : 1;
        DB::table('audit_users')->where('id', $id)->update(['is_active' => $newStatus]);

        return success_response($response, ['is_active' => $newStatus], $newStatus ? '已启用' : '已禁用');
    }

    public function exportData(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        if (empty($data) || !is_array($data)) {
            $rawBody = (string) $request->getBody();
            if (!empty($rawBody)) {
                $data = json_decode($rawBody, true) ?? [];
            }
        }

        $records = $data['records'] ?? [];

        if (empty($records)) {
            return error_response($response, 400, '没有可导出的数据');
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('审计查询结果');

        $headers = ['序号', '测评任务', '投票人', '手机号', '用户类型', '被评对象', '对象类型', '测评项目', '题型', '评分/答案', '事例说明', '作答时间'];
        $cols = ['examine_name', 'user_name', 'user_phone', 'user_type', 'target_name', 'target_type', 'item_title', 'item_type', 'answer_value', 'example_text', 'answered_at'];

        foreach ($headers as $i => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($col . '1', $h);
        }

        $headerStyle = [
            'font' => ['bold' => true, 'size' => 11],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E0E0E0']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => '000000']]],
        ];
        $sheet->getStyle('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers)) . '1')->applyFromArray($headerStyle);

        $row = 2;
        foreach ($records as $idx => $record) {
            $sheet->setCellValue('A' . $row, $idx + 1);
            foreach ($cols as $ci => $col) {
                $cellCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci + 2);
                $val = $record[$col] ?? '';
                if ($col === 'user_phone' && !empty($val)) {
                    $val = preg_replace('/(\d{3})\d{4}(\d{4})/', '$1****$2', $val);
                }
                if ($col === 'user_type') {
                    $val = $val === 'A' ? 'A类' : ($val === 'B' ? 'B类' : $val);
                }
                if ($col === 'target_type') {
                    $val = $val === 'team' ? '班子' : ($val === 'leader' ? '干部' : $val);
                }
                if ($col === 'item_type') {
                    $val = $val === 'radio' ? '单选' : ($val === 'checkbox' ? '多选' : ($val === 'textarea' ? '文本' : $val));
                }
                if ($col === 'answer_value' && !empty($val)) {
                    $decoded = json_decode($val, true);
                    if (is_array($decoded)) {
                        $val = implode('、', $decoded);
                    }
                }
                $sheet->setCellValue($cellCol . $row, $val);
            }
            $row++;
        }

        $dataStyle = [
            'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => '000000']]],
            'alignment' => ['vertical' => 'center'],
        ];
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle('A1:' . $lastCol . ($row - 1))->applyFromArray($dataStyle);

        $colWidths = [6, 22, 10, 14, 8, 14, 8, 16, 8, 16, 20, 18];
        foreach ($colWidths as $i => $w) {
            $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1))->setWidth($w);
        }

        $sheet->freezePane('A2');

        $tempFile = tempnam(sys_get_temp_dir(), 'audit_export_') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);

        $fileContent = file_get_contents($tempFile);
        $fileSize = filesize($tempFile);
        unlink($tempFile);

        $response->getBody()->write($fileContent);

        return $response
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', 'attachment; filename="审计查询结果_' . date('Ymd_His') . '.xlsx"')
            ->withHeader('Content-Length', (string) $fileSize);
    }
}