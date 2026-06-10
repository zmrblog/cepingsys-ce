<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ExamineController
{
    public function index(Request $request, Response $response): Response
    {
        $page = (int)($request->getQueryParams()['page'] ?? 1);
        $perPage = (int)($request->getQueryParams()['per_page'] ?? 20);
        $status = $request->getQueryParams()['status'] ?? null;
        $keyword = trim($request->getQueryParams()['keyword'] ?? '');

        $query = DB::table('examines')
            ->leftJoin('templates', 'examines.template_id', '=', 'templates.id')
            ->leftJoin('admins', 'examines.created_by', '=', 'admins.id')
            ->select(
                'examines.*',
                'templates.template_name',
                'templates.template_type',
                'admins.real_name as creator_name'
            );

        if ($status && in_array($status, ['draft', 'active', 'finished', 'archived'])) {
            $query->where('examines.status', $status);
        }

        if ($keyword) {
            $query->where('examines.examine_name', 'like', '%' . like_escape($keyword) . '%');
        }

        $total = $query->count();
        $examines = $query->selectRaw('examines.period,
            (SELECT COUNT(*) FROM examine_targets WHERE examine_id=examines.id) as targets_count,
            (SELECT COUNT(*) FROM examine_users WHERE examine_id=examines.id) as users_count,
            (SELECT COUNT(*) FROM examine_users WHERE examine_id=examines.id AND status="completed") as completed_count')
            ->orderBy('examines.id', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return success_response($response, [
            'data' => $examines,
            'pagination' => paginate($page, $perPage, $total),
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        
        $examine = DB::table('examines')
            ->leftJoin('templates', 'examines.template_id', '=', 'templates.id')
            ->leftJoin('units', 'examines.unit_id', '=', 'units.id')
            ->select(
                'examines.*',
                'templates.template_name',
                'templates.template_type',
                'units.unit_name'
            )
            ->where('examines.id', $id)
            ->first();
        
        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        $targets = DB::table('examine_targets')
            ->where('examine_id', $id)
            ->orderBy('sort_order', 'asc')
            ->get();

        $users = DB::table('examine_users')
            ->leftJoin('users', 'examine_users.user_id', '=', 'users.id')
            ->select('examine_users.*', 'users.name', 'users.phone', 'users.user_type')
            ->where('examine_users.examine_id', $id)
            ->get();

        $templateItems = DB::table('template_items')
            ->where('template_id', $examine->template_id)
            ->orderBy('sort_order', 'asc')
            ->get()
            ->map(function ($item) {
                $item->options = json_decode($item->options ?? '[]', true);
                $item->reverse_options = json_decode($item->reverse_options ?? '[]', true);
                return $item;
            });

        $examine->targets = $targets;
        $examine->users = $users;
        $examine->template_items = $templateItems;

        return success_response($response, $examine);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        $examineName = trim($data['examine_name'] ?? '');
        $templateId = (int)($data['template_id'] ?? 0);
        $unitId = (int)($data['unit_id'] ?? 0);
        $startTime = $data['start_time'] ?? '';
        $endTime = $data['end_time'] ?? '';

        if (empty($examineName)) {
            return error_response($response, 400, '任务名称不能为空');
        }

        if (!$templateId || !DB::table('templates')->where('id', $templateId)->exists()) {
            return error_response($response, 400, '请选择有效的测评模板');
        }

        if (!$unitId || !DB::table('units')->where('id', $unitId)->exists()) {
            return error_response($response, 400, '请选择有效的部门');
        }

        if (empty($startTime) || empty($endTime)) {
            return error_response($response, 400, '开始时间和结束时间不能为空');
        }

        if (strtotime($startTime) >= strtotime($endTime)) {
            return error_response($response, 400, '结束时间必须大于开始时间');
        }

        $weightMode = in_array($data['weight_mode'] ?? '', ['equal', 'custom']) ? $data['weight_mode'] : 'equal';

        $period = trim($data['period'] ?? '');
        if (empty($period)) {
            return error_response($response, 400, '请选择考核周期');
        }

        $id = DB::table('examines')->insertGetId([
            'examine_name' => $examineName,
            'period' => $period,
            'template_id' => $templateId,
            'unit_id' => $unitId,
            'start_time' => date('Y-m-d H:i:s', strtotime($startTime)),
            'end_time' => date('Y-m-d H:i:s', strtotime($endTime)),
            'weight_mode' => $weightMode,
            'weight_a' => $weightMode === 'custom' ? (float)($data['weight_a'] ?? 1.0) : 1.0,
            'weight_b' => $weightMode === 'custom' ? (float)($data['weight_b'] ?? 1.0) : 1.0,
            'status' => 'draft',
            'created_by' => (int)$request->getAttribute('admin_id'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        log_operation((int)$request->getAttribute('admin_id'), 'examines', 'create', 'examine', $id, null, $request);

        return success_response($response, ['id' => $id], '测评任务创建成功');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();

        $examine = DB::table('examines')->where('id', $id)->first();
        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        if ($examine->status === 'active') {
            $allowedInActive = ['start_time', 'end_time', 'weight_mode', 'weight_a', 'weight_b'];
            $requestedKeys = array_keys($data);
            $disallowed = array_diff($requestedKeys, $allowedInActive);

            if (!empty($disallowed)) {
                return error_response($response, 400, '任务进行中，仅可修改测评时间和权重设置');
            }
        }

        $updateData = [];
        
        if (isset($data['examine_name'])) {
            $updateData['examine_name'] = trim($data['examine_name']);
        }
        if (isset($data['period']) && !empty(trim($data['period']))) {
            $updateData['period'] = trim($data['period']);
        }
        if (isset($data['start_time'])) {
            $updateData['start_time'] = date('Y-m-d H:i:s', strtotime($data['start_time']));
        }
        if (isset($data['end_time'])) {
            $updateData['end_time'] = date('Y-m-d H:i:s', strtotime($data['end_time']));
        }
        if (isset($data['weight_mode'])) {
            $updateData['weight_mode'] = $data['weight_mode'];
        }
        if (isset($data['weight_a'])) {
            $updateData['weight_a'] = (float)$data['weight_a'];
        }
        if (isset($data['weight_b'])) {
            $updateData['weight_b'] = (float)$data['weight_b'];
        }

        if (!empty($updateData)) {
            DB::table('examines')->where('id', $id)->update($updateData);
        }

        log_operation((int)$request->getAttribute('admin_id'), 'examines', 'update', 'examine', $id, null, $request);

        return success_response($response, null, '测评任务更新成功');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        $examine = DB::table('examines')->where('id', $id)->first();
        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        if ($examine->status === 'active') {
            return error_response($response, 400, '进行中的任务无法删除');
        }

        DB::transaction(function () use ($id) {
            DB::table('examine_answers')->where('examine_id', $id)->delete();
            DB::table('examine_users')->where('examine_id', $id)->delete();
            DB::table('examine_targets')->where('examine_id', $id)->delete();
            DB::table('examines')->where('id', $id)->delete();
        });

        log_operation((int)$request->getAttribute('admin_id'), 'examines', 'delete', 'examine', $id, null, $request);

        return success_response($response, null, '测评任务删除成功');
    }

    public function batchDestroy(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $ids = $data['ids'] ?? [];

        if (empty($ids) || !is_array($ids)) {
            return error_response($response, 400, '请选择要删除的测评任务');
        }

        $successCount = 0;
        $skipCount = 0;

        DB::transaction(function () use ($ids, $request, &$successCount, &$skipCount) {
            foreach ($ids as $id) {
            $examine = DB::table('examines')->where('id', (int)$id)->first();
            if (!$examine) {
                $skipCount++;
                continue;
            }
            if ($examine->status === 'active') {
                $skipCount++;
                continue;
            }

            DB::table('examine_answers')->where('examine_id', (int)$id)->delete();
            DB::table('examine_users')->where('examine_id', (int)$id)->delete();
            DB::table('examine_targets')->where('examine_id', (int)$id)->delete();
            DB::table('examines')->where('id', (int)$id)->delete();

            log_operation((int)$request->getAttribute('admin_id'), 'examines', 'batch_delete', 'examine', (int)$id, null, $request);
            $successCount++;
        }
        });

        return success_response($response, [
            'deleted' => $successCount,
            'skipped' => $skipCount,
        ], "成功删除 {$successCount} 个任务" . ($skipCount > 0 ? "，跳过 {$skipCount} 个" : ''));
    }

    public function addTargets(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();

        $examine = DB::table('examines')->where('id', $id)->first();
        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        $targets = $data['targets'] ?? [];
        if (!is_array($targets)) {
            return error_response($response, 400, '请提供测评对象数据');
        }

        foreach ($targets as $index => $target) {
            $targetName = trim($target['target_name'] ?? '');
            $targetType = $target['target_type'] ?? '';

            if (empty($targetName)) {
                return error_response($response, 400, "第" . ($index + 1) . "个测评对象名称不能为空");
            }

            if (!in_array($targetType, ['team', 'leader'])) {
                return error_response($response, 400, "第" . ($index + 1) . "个测评对象类型无效（team或leader）");
            }

            if ($targetType === 'leader' && empty(trim($target['position'] ?? ''))) {
                return error_response($response, 400, "第" . ($index + 1) . "个干部测评对象的职务不能为空");
            }
        }

        DB::table('examine_targets')->where('examine_id', $id)->delete();

        foreach ($targets as $index => $target) {
            DB::table('examine_targets')->insert([
                'examine_id' => $id,
                'target_type' => $target['target_type'],
                'target_name' => trim($target['target_name']),
                'position' => trim($target['position'] ?? ''),
                'unit_name' => trim($target['unit_name'] ?? ''),
                'sort_order' => $index,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        log_operation((int)$request->getAttribute('admin_id'), 'examines', 'add_targets', 'examine', $id, [
            'count' => count($targets),
        ], $request);

        return success_response($response, ['count' => count($targets)], '测评对象添加成功');
    }

    public function importTargets(Request $request, Response $response, array $args): Response
    {
        // [设计说明] 本方法仅负责解析Excel文件并返回数据给前端预览
        // 前端确认后调用 addTargets() 接口实际写入数据库
        // 这样设计允许用户在提交前检查和编辑导入的数据，避免错误数据直接入库
        $id = (int)$args['id'];

        $examine = DB::table('examines')->where('id', $id)->first();
        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        $uploadedFiles = $request->getUploadedFiles();
        if (!isset($uploadedFiles['file']) || $uploadedFiles['file']->getError() !== UPLOAD_ERR_OK) {
            return error_response($response, 400, '请上传Excel文件');
        }

        $file = $uploadedFiles['file'];
        $ext = strtolower(pathinfo($file->getClientFilename(), PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls'])) {
            return error_response($response, 400, '仅支持 xlsx/xls 格式');
        }

        $allowedMimes = [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
        ];
        if ($allowedMimes[$ext] !== null) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detectedMime = $finfo->file($file->getStream()->getMetadata('uri'));
            if ($detectedMime && !str_starts_with($detectedMime, explode('/', $allowedMimes[$ext])[0] . '/')) {
                return error_response($response, 400, '文件类型与扩展名不匹配');
            }
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'target_import_');
        $file->moveTo($tempPath);

        try {
            $spreadsheet = IOFactory::load($tempPath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);
            @unlink($tempPath);

            if (count($rows) < 2) {
                return error_response($response, 400, 'Excel 内容为空或只有表头');
            }

            $data = $request->getParsedBody();
            $defaultType = in_array(trim($data['target_type'] ?? ''), ['leader', 'team'])
                ? trim($data['target_type']) : 'leader';

            $imported = [];
            foreach ($rows as $rowNum => $row) {
                if ($rowNum === 0) continue;
                $name = trim((string)($row[0] ?? ''));
                if ($name === '') continue;

                $imported[] = [
                    'target_name' => $name,
                    'target_type' => $defaultType,
                    'position' => trim((string)($row[1] ?? '')),
                    'unit_name' => trim((string)($row[2] ?? '')),
                ];
            }

            if (empty($imported)) {
                return error_response($response, 400, '未解析到有效的测评对象数据');
            }

            log_operation('导入测评对象', "任务ID:{$id}, 导入" . count($imported) . "条");

            return success_response($response, [
                'count' => count($imported),
                'targets' => $imported,
            ], '成功解析 ' . count($imported) . ' 条测评对象');

        } catch (\Exception $e) {
            if (file_exists($tempPath)) @unlink($tempPath);
            log_operation('导入测评对象失败', "错误: {$e->getMessage()}");
            return error_response($response, 500, '文件解析失败: ' . $e->getMessage());
        }
    }

    public function downloadTargetTemplate(Request $request, Response $response): Response
    {
        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('测评对象模板');

            $headers = ['姓名', '职务', '单位'];
            foreach ($headers as $col => $header) {
                $sheet->setCellValue([$col + 1, 1], $header);
                $cell = $sheet->getCell([$col + 1, 1]);
                $cell
                    ->getStyle()
                    ->getFont()
                    ->setBold(true);
                $cell
                    ->getStyle()
                    ->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setRGB('E8F4FD');
            }

            $examples = [
                ['张三', '局长', '公安局'],
                ['李四', '副局长', '税务局'],
                ['王五', '科长', '教育局'],
            ];
            foreach ($examples as $rowIdx => $example) {
                foreach ($example as $colIdx => $value) {
                    $sheet->setCellValue([$colIdx + 1, $rowIdx + 2], $value);
                    $sheet->getCell([$colIdx + 1, $rowIdx + 2])
                        ->getStyle()
                        ->getFont()
                        ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF999999'));
                }
            }

            $sheet->getColumnDimension('A')->setWidth(15);
            $sheet->getColumnDimension('B')->setWidth(12);
            $sheet->getColumnDimension('C')->setWidth(18);

            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $tempPath = tempnam(sys_get_temp_dir(), 'target_tpl_') . '.xlsx';
            $writer->save($tempPath);

            $content = file_get_contents($tempPath);
            @unlink($tempPath);

            $response->getBody()->write($content);
            return $response
                ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->withHeader('Content-Disposition', 'attachment; filename="UTF-8\'\'测评对象导入模板.xlsx"')
                ->withHeader('Cache-Control', 'no-cache, must-revalidate')
                ->withHeader('Pragma', 'no-cache');

        } catch (\Exception $e) {
            return error_response($response, 500, '模板生成失败: ' . $e->getMessage());
        }
    }

    public function assignUsers(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();

        $examine = DB::table('examines')->where('id', $id)->first();
        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        $userIds = $data['user_ids'] ?? [];
        if (empty($userIds) || !is_array($userIds)) {
            return error_response($response, 400, '请选择参评人员');
        }

        DB::table('examine_users')->where('examine_id', $id)->delete();

        foreach ($userIds as $userId) {
            DB::table('examine_users')->insert([
                'examine_id' => $id,
                'user_id' => (int)$userId,
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        log_operation((int)$request->getAttribute('admin_id'), 'examines', 'assign_users', 'examine', $id, [
            'count' => count($userIds),
        ], $request);

        return success_response($response, ['count' => count($userIds)], '参评人员分配成功');
    }

    public function getAvailableUsers(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        $examine = DB::table('examines')->where('id', $id)->first();
        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        $unitId = (int)($request->getQueryParams()['unit_id'] ?? $examine->unit_id ?? 0);

        if (!$unitId && !$examine->unit_id) {
            return success_response($response, [
                'unit' => null,
                'users' => [],
                'all_units' => [],
            ]);
        }

        $queryUnitId = $unitId ?: (int)$examine->unit_id;

        $unit = DB::table('units')->where('id', $queryUnitId)->first();

        // 获取所有部门供切换
        $allUnits = DB::table('units')->orderBy('sort_order', 'asc')->orderBy('id', 'asc')->get();

        $selectedUserIds = DB::table('examine_users')
            ->where('examine_id', $id)
            ->pluck('user_id')
            ->toArray();

        $query = DB::table('users')
            ->whereIn('status', ['active', '1'])
            ->select('id', 'name', 'phone', 'position', 'user_type', 'unit_id', 'source');

        if ($queryUnitId > 0) {
            $query->where('unit_id', $queryUnitId);
        }

        $users = $query->orderBy('unit_id', 'asc')->orderBy('id', 'asc')
            ->get()
            ->map(function ($user) use ($selectedUserIds) {
                $user->selected = in_array((string)$user->id, array_map('strval', $selectedUserIds));
                return $user;
            });

        return success_response($response, [
            'unit' => $unit,
            'current_unit_id' => $queryUnitId,
            'users' => $users,
            'all_units' => $allUnits,
        ]);
    }

    public function activate(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        $examine = DB::table('examines')->where('id', $id)->first();
        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        if ($examine->status !== 'draft') {
            return error_response($response, 400, '只有草稿状态的任务可以激活');
        }

        $hasTargets = DB::table('examine_targets')->where('examine_id', $id)->exists();
        if (!$hasTargets) {
            return error_response($response, 400, '请先添加测评对象');
        }

        $hasUsers = DB::table('examine_users')->where('examine_id', $id)->exists();
        if (!$hasUsers) {
            return error_response($response, 400, '请先分配参评人员');
        }

        DB::table('examines')->where('id', $id)->update([
            'status' => 'active',
        ]);

        log_operation((int)$request->getAttribute('admin_id'), 'examines', 'activate', 'examine', $id, null, $request);

        return success_response($response, null, '测评任务已激活，用户可以开始答题');
    }

    public function finish(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        $examine = DB::table('examines')->where('id', $id)->first();
        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        if ($examine->status !== 'active') {
            return error_response($response, 400, '只有进行中的任务可以结束');
        }

        DB::table('examines')->where('id', $id)->update([
            'status' => 'finished',
        ]);

        log_operation((int)$request->getAttribute('admin_id'), 'examines', 'finish', 'examine', $id, null, $request);

        return success_response($response, null, '测评任务已结束');
    }

    public function listUsers(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        $examine = DB::table('examines')->where('id', $id)->first();
        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        $users = DB::table('examine_users')
            ->leftJoin('users', 'examine_users.user_id', '=', 'users.id')
            ->leftJoin('units', 'users.unit_id', '=', 'units.id')
            ->select(
                'examine_users.id as examine_user_id',
                'examine_users.user_id',
                'examine_users.status',
                'users.name',
                'users.phone',
                'users.position',
                'users.user_type',
                'units.unit_name'
            )
            ->where('examine_users.examine_id', $id)
            ->orderBy('examine_users.id', 'asc')
            ->get();

        return success_response($response, [
            'data' => $users,
            'total' => count($users),
        ]);
    }

    public function addUser(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();

        $userId = (int)($data['user_id'] ?? 0);
        if (!$userId) {
            return error_response($response, 400, '请选择用户');
        }

        $examine = DB::table('examines')->where('id', $id)->first();
        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        $exists = DB::table('examine_users')
            ->where('examine_id', $id)
            ->where('user_id', $userId)
            ->exists();

        if ($exists) {
            return error_response($response, 400, '该用户已在参评人员列表中');
        }

        $userExists = DB::table('users')->where('id', $userId)->exists();
        if (!$userExists) {
            return error_response($response, 404, '用户不存在');
        }

        DB::table('examine_users')->insert([
            'examine_id' => $id,
            'user_id' => $userId,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        log_operation((int)$request->getAttribute('admin_id'), 'examines', 'add_user', 'examine', $id, [
            'user_id' => $userId,
        ], $request);

        return success_response($response, null, '用户已添加到参评人员列表');
    }

    public function removeUser(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $userId = (int)$args['userId'];

        $examine = DB::table('examines')->where('id', $id)->first();
        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        $deleted = DB::table('examine_users')
            ->where('examine_id', $id)
            ->where('user_id', $userId)
            ->delete();

        if (!$deleted) {
            return error_response($response, 404, '该用户不在参评人员列表中');
        }

        log_operation((int)$request->getAttribute('admin_id'), 'examines', 'remove_user', 'examine', $id, [
            'user_id' => $userId,
        ], $request);

        return success_response($response, null, '用户已从参评人员列表中移除');
    }

    public function archive(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        $examine = DB::table('examines')->where('id', $id)->first();
        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        if ($examine->status !== 'finished') {
            return error_response($response, 400, '只有已结束的任务才能归档');
        }

        DB::table('examines')->where('id', $id)->update([
            'status' => 'archived',
        ]);

        log_operation((int)$request->getAttribute('admin_id'), 'examines', 'archive', 'examine', $id, null, $request);

        return success_response($response, null, '测评任务已归档');
    }

    public function unarchive(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        $examine = DB::table('examines')->where('id', $id)->first();
        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        if ($examine->status !== 'archived') {
            return error_response($response, 400, '只有已归档的任务才能取消归档');
        }

        DB::table('examines')->where('id', $id)->update([
            'status' => 'finished',
        ]);

        log_operation((int)$request->getAttribute('admin_id'), 'examines', 'unarchive', 'examine', $id, null, $request);

        return success_response($response, null, '已取消归档');
    }

    public function batchArchive(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $ids = $data['ids'] ?? [];
        $period = $data['period'] ?? '';

        if (!empty($period)) {
            $ids = DB::table('examines')
                ->where('period', $period)
                ->where('status', 'finished')
                ->pluck('id')
                ->toArray();
        }

        if (empty($ids) || !is_array($ids)) {
            return error_response($response, 400, '请选择要归档的测评任务');
        }

        $successCount = 0;
        $skipCount = 0;

        foreach ($ids as $id) {
            $examine = DB::table('examines')->where('id', (int)$id)->first();
            if (!$examine) {
                $skipCount++;
                continue;
            }
            if ($examine->status !== 'finished') {
                $skipCount++;
                continue;
            }
            DB::table('examines')->where('id', (int)$id)->update(['status' => 'archived']);
            log_operation((int)$request->getAttribute('admin_id'), 'examines', 'batch_archive', 'examine', (int)$id, null, $request);
            $successCount++;
        }

        return success_response($response, [
            'archived' => $successCount,
            'skipped' => $skipCount,
        ], "成功归档 {$successCount} 个任务" . ($skipCount > 0 ? "，跳过 {$skipCount} 个" : ''));
    }

    public function archiveOverview(Request $request, Response $response): Response
    {
        $examines = DB::table('examines')
            ->leftJoin('templates', 'examines.template_id', '=', 'templates.id')
            ->leftJoin('units', 'examines.unit_id', '=', 'units.id')
            ->select(
                'examines.id',
                'examines.examine_name',
                'examines.period',
                'examines.status',
                'examines.start_time',
                'examines.end_time',
                'templates.template_type',
                'units.unit_name'
            )
            ->orderBy('examines.period', 'asc')
            ->orderBy('examines.id', 'desc')
            ->get()
            ->map(function ($examine) {
                $examine->total_users = DB::table('examine_users')
                    ->where('examine_id', $examine->id)
                    ->count();
                $examine->completed_users = DB::table('examine_users')
                    ->where('examine_id', $examine->id)
                    ->where('status', 'completed')
                    ->count();
                return $examine;
            });

        $grouped = [];
        foreach ($examines as $examine) {
            $period = $examine->period ?: null;
            $key = $period ?? '__unclassified__';
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'period' => $period,
                    'type' => $period
                        ? (mb_strpos($period, '年度') !== false ? 'year' : 'quarter')
                        : 'unclassified',
                    'label' => $period ?: '未分类',
                    'tasks' => [],
                ];
            }
            $grouped[$key]['tasks'][] = $examine;
        }

        $periods = [];
        foreach ($grouped as $g) {
            $tasks = $g['tasks'];
            $total = count($tasks);
            $finished = count(array_filter($tasks, fn($t) => $t->status === 'finished'));
            $archived = count(array_filter($tasks, fn($t) => $t->status === 'archived'));
            $pending = $total - $finished - $archived;

            $periods[] = [
                'period' => $g['period'],
                'type' => $g['type'],
                'label' => $g['label'],
                'tasks' => $tasks,
                'all_completed' => ($pending === 0),
                'total_tasks' => $total,
                'finished_tasks' => $finished,
                'archived_tasks' => $archived,
                'pending_tasks' => $pending,
                'can_archive' => ($pending === 0 && $finished > 0),
            ];
        }

        usort($periods, function ($a, $b) {
            if ($a['period'] === null && $b['period'] !== null) return 1;
            if ($a['period'] !== null && $b['period'] === null) return -1;
            return strcmp((string)($b['period'] ?? ''), (string)($a['period'] ?? ''));
        });

        return success_response($response, ['periods' => $periods]);
    }
}
