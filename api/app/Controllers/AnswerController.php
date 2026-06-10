<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

class AnswerController
{
    public function save(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();

        $examineId = (int)($data['examine_id'] ?? 0);
        $targetId = (int)($data['target_id'] ?? 0);
        $itemId = (int)($data['item_id'] ?? 0);
        $answerValue = $data['answer_value'] ?? null;
        $exampleText = trim($data['example_text'] ?? '');

        if (!$examineId || !$targetId || !$itemId) {
            return error_response($response, 400, '参数不完整');
        }

        $examine = DB::table('examines')->where('id', $examineId)->first();
        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        if ($examine->status !== 'active') {
            return error_response($response, 400, '该测评任务未在答题时间范围内');
        }

        $now = time();
        if ($now < strtotime($examine->start_time) || $now > strtotime($examine->end_time)) {
            return error_response($response, 400, '当前不在答题时间范围内');
        }

        $item = DB::table('template_items')->where('id', $itemId)->first();
        if (!$item) {
            return error_response($response, 404, '指标项不存在');
        }

        // 验证答案格式
        switch ($item->item_type) {
            case 'radio':
                if (empty($answerValue)) {
                    return error_response($response, 400, '请选择一个选项');
                }
                $options = json_decode($item->options ?? '[]', true);
                $optionValues = array_map(function ($opt) {
                    return is_array($opt) ? ($opt['text'] ?? $opt['letter'] ?? '') : (string)$opt;
                }, $options);
                if (!in_array($answerValue, $optionValues, true)) {
                    return error_response($response, 400, '无效的选项值');
                }

                // 反向测评验证：如果选择了负面选项，必须填写事例
                if (!empty($item->is_reverse)) {
                    $reverseOptions = json_decode($item->reverse_options ?? '[]', true);
                    $reverseTexts = array_map([$this, 'normalizeReverseOption'], $reverseOptions);
                    if (in_array($answerValue, $reverseTexts, true) && empty($exampleText)) {
                        return error_response($response, 400, '选择负面评价时，请填写具体事例说明');
                    }
                }
                break;

            case 'checkbox':
                if (!is_array($answerValue) || empty($answerValue)) {
                    return error_response($response, 400, '请至少选择一个选项');
                }

                $options = json_decode($item->options ?? '[]', true);
                $optionValues = array_map(function ($opt) {
                    return is_array($opt) ? ($opt['text'] ?? $opt['letter'] ?? '') : (string)$opt;
                }, $options);
                foreach ($answerValue as $val) {
                    if (!in_array($val, $optionValues, true)) {
                        return error_response($response, 400, '包含无效的选项值');
                    }
                }

                // 多选约束检查
                $minSelect = (int)$item->min_select;
                $maxSelect = (int)$item->max_select;

                $selectedCount = count($answerValue);
                if ($minSelect > 0 && $selectedCount < $minSelect) {
                    return error_response($response, 400, "至少需要选择{$minSelect}项");
                }
                if ($maxSelect > 0 && $selectedCount > $maxSelect) {
                    return error_response($response, 400, "最多只能选择{$maxSelect}项");
                }

                // 反向测评验证
                if (!empty($item->is_reverse)) {
                    $reverseOptions = json_decode($item->reverse_options ?? '[]', true);
                    $reverseTexts = array_map([$this, 'normalizeReverseOption'], $reverseOptions);
                    $hasNegativeSelection = !empty(array_intersect($answerValue, $reverseTexts));

                    if ($hasNegativeSelection && empty($exampleText)) {
                        return error_response($response, 400, '包含负面评价选项，请填写具体事例说明');
                    }
                }

                $answerValue = json_encode($answerValue, JSON_UNESCAPED_UNICODE);
                break;

            case 'textarea':
                if (empty(trim($answerValue))) {
                    return error_response($response, 400, '请填写内容');
                }
                if (mb_strlen(trim($answerValue)) > 2000) {
                    return error_response($response, 400, '内容不能超过2000字');
                }
                break;

            default:
                return error_response($response, 400, '无效的题型');
        }

        // 获取用户信息（通过手机号或设备指纹）
        // 支持两种来源：admin用户（有unit_id）和注册用户（source=registered，可能无unit_id）
        $userPhone = trim($data['user_phone'] ?? '');
        $deviceFingerprint = trim($data['device_fingerprint'] ?? '');

        $user = null;

        if (!empty($userPhone)) {
            // 优先按 unit_id + phone 查找（admin用户）
            $user = DB::table('users')
                ->where('unit_id', $examine->unit_id)
                ->where('phone', $userPhone)
                ->whereIn('status', [1, '1', 'active'])
                ->first();

            // 如果没找到，尝试按 phone + source=registered 查找（注册用户）
            if (!$user) {
                $user = DB::table('users')
                    ->where('phone', $userPhone)
                    ->where('source', 'registered')
                    ->whereIn('status', [1, '1', 'active'])
                    ->first();
            }
        } elseif (!empty($deviceFingerprint)) {
            $user = DB::table('users')
                ->where('device_fingerprint', $deviceFingerprint)
                ->whereIn('status', [1, '1', 'active'])
                ->first();
        }

        if (!$user) {
            return error_response($response, 401, '用户身份验证失败，请重新登录');
        }

        // 检查用户是否被分配到该任务
        // 支持注册用户通过 phone+name 关联到被分配的 admin 用户
        $examineUser = DB::table('examine_users')
            ->where('examine_id', $examineId)
            ->where('user_id', $user->id)
            ->first();

        // 如果当前用户没有直接分配记录，尝试通过 phone+name 找关联的 admin 用户
        if (!$examineUser && ($user->source ?? 'admin') === 'registered' && !empty($user->phone) && !empty($user->name)) {
            $linkedAdminUserIds = DB::table('users')
                ->where('source', 'admin')
                ->where('phone', $user->phone)
                ->where('name', $user->name)
                ->whereIn('status', ['1', 'active'])
                ->pluck('id')
                ->toArray();

            if (!empty($linkedAdminUserIds)) {
                $examineUser = DB::table('examine_users')
                    ->where('examine_id', $examineId)
                    ->whereIn('user_id', $linkedAdminUserIds)
                    ->first();
            }
        }

        if (!$examineUser) {
            return error_response($response, 403, '您未被分配参与此次测评');
        }

        // 更新设备指纹（如果变更则自动更新）
        if (!empty($deviceFingerprint) && $examineUser->device_fingerprint !== $deviceFingerprint) {
            DB::table('examine_users')
                ->where('id', $examineUser->id)
                ->update([
                    'device_fingerprint' => $deviceFingerprint,
                ]);

            // 同时更新用户的设备指纹
            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'device_fingerprint' => $deviceFingerprint,
                ]);
        }

        // 更新参评人员状态
        if ($examineUser->status === 'pending') {
            $serverParams = $request->getServerParams();
            DB::table('examine_users')
                ->where('id', $examineUser->id)
                ->update([
                    'status' => 'in_progress',
                    'started_at' => date('Y-m-d H:i:s'),
                    'ip_address' => $serverParams['HTTP_X_FORWARDED_FOR']
                        ?? $serverParams['HTTP_X_REAL_IP']
                        ?? $serverParams['REMOTE_ADDR']
                        ?? null,
                ]);
        }

        // 检查该对象是否已被锁定（完成）
        $lockedCount = DB::table('examine_answers')
            ->where('examine_id', $examineId)
            ->where('user_id', $user->id)
            ->where('target_id', $targetId)
            ->whereNotNull('completed_at')
            ->count();

        if ($lockedCount > 0) {
            return error_response($response, 403, '该测评对象已完成，无法修改答案');
        }

        // 保存或更新答案（使用upsert，处理并发重复键冲突）
        $answerAttrs = [
            'examine_id' => $examineId,
            'user_id' => $user->id,
            'target_id' => $targetId,
            'item_id' => $itemId,
        ];
        $updateData = [
            'answer_value' => $answerValue,
            'example_text' => !empty($exampleText) ? $exampleText : null,
            'answered_at' => date('Y-m-d H:i:s'),
        ];

        try {
            DB::table('examine_answers')->updateOrInsert($answerAttrs, $updateData);
        } catch (\PDOException $e) {
            // 处理唯一键冲突：记录已存在时回退到显式更新
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                DB::table('examine_answers')
                    ->where($answerAttrs)
                    ->update($updateData);
            } else {
                throw $e;  // 重新抛出非重复键异常
            }
        }

        return success_response($response, null, '答案保存成功');
        } catch (\Throwable $e) {
            error_log('[AnswerController::save] ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return error_response($response, 500, '保存答案时发生错误，请稍后重试');
        }
    }

    public function myExamines(Request $request, Response $response): Response
    {
        $userPhone = trim($request->getQueryParams()['phone'] ?? '');
        $deviceFingerprint = trim($request->getQueryParams()['fingerprint'] ?? '');

        if (empty($userPhone) && empty($deviceFingerprint)) {
            return error_response($response, 400, '请提供手机号或设备指纹');
        }

        $user = null;
        if (!empty($userPhone)) {
            $user = DB::table('users')
                ->where('phone', $userPhone)
                ->whereIn('status', [1, '1', 'active'])
                ->first();
        } elseif (!empty($deviceFingerprint)) {
            $user = DB::table('users')
                ->where('device_fingerprint', $deviceFingerprint)
                ->whereIn('status', [1, '1', 'active'])
                ->first();
        }

        if (!$user) {
            return success_response($response, ['data' => []]);
        }

        // 1. 直接分配的测评ID（当前登录用户）
        $directExamineIds = DB::table('examine_users')
            ->where('user_id', $user->id)
            ->pluck('examine_id')
            ->toArray();

        // 2. 通过 phone+name 关联的 admin 用户被分配的测评ID
        $linkedExamineIds = [];
        if (!empty($user->phone) && !empty($user->name)) {
            $linkedAdminUserIds = DB::table('users')
                ->where('source', 'admin')
                ->where('phone', $user->phone)
                ->where('name', $user->name)
                ->whereIn('status', ['1', 'active'])
                ->pluck('id')
                ->toArray();

            if (!empty($linkedAdminUserIds)) {
                $linkedExamineIds = DB::table('examine_users')
                    ->whereIn('user_id', $linkedAdminUserIds)
                    ->pluck('examine_id')
                    ->toArray();
            }
        }

        // 3. 合并去重
        $allExamineIds = array_unique(array_merge($directExamineIds, $linkedExamineIds));

        if (empty($allExamineIds)) {
            return success_response($response, [
                'user_info' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'unit_id' => $user->unit_id,
                    'source' => $user->source ?? 'admin',
                ],
                'data' => [],
            ]);
        }

        // 获取用户参与的进行中和待测评的任务
        $examines = DB::table('examines')
            ->join('examine_users', 'examines.id', '=', 'examine_users.examine_id')
            ->leftJoin('units', 'examines.unit_id', '=', 'units.id')
            ->select(
                'examines.id',
                'examines.examine_name',
                'examines.start_time',
                'examines.end_time',
                'examines.status as examine_status',
                'units.unit_name',
                'examine_users.status as user_status',
                'examine_users.user_id'
            )
            ->whereIn('examines.id', $allExamineIds)
            ->where('examines.status', 'active')
            ->orderBy('examines.start_time', 'desc')
            ->get()
            ->unique('id')
            ->map(function ($examine) use ($user) {
                $totalTargets = DB::table('examine_targets')
                    ->where('examine_id', $examine->id)
                    ->count();

                // 只统计"所有题目都已答完"的测评对象（而非任意答1题就算完成）
                $totalItemsPerTarget = DB::table('template_items')
                    ->join('examines', 'examines.template_id', '=', 'template_items.template_id')
                    ->where('examines.id', $examine->id)
                    ->count();

                $answeredItemsPerTarget = DB::table('examine_answers')
                    ->where('examine_id', $examine->id)
                    ->where('user_id', $user->id)
                    ->select('target_id', DB::raw('COUNT(DISTINCT item_id) as cnt'))
                    ->groupBy('target_id')
                    ->pluck('cnt', 'target_id')
                    ->toArray();

                // 该测评的总题目数（每个 target 的题目数相同）
                $itemsPerTarget = $totalTargets > 0 ? max(1, (int)($totalItemsPerTarget / max(1, $totalTargets))) : 0;

                $answeredTargets = collect($answeredItemsPerTarget)->filter(function ($cnt) use ($itemsPerTarget) {
                    return $cnt >= $itemsPerTarget;
                })->count();

                $examine->total_targets = $totalTargets;
                $examine->answered_targets = $answeredTargets;
                $examine->total_items = (int)$itemsPerTarget;
                $examine->answered_items = array_sum($answeredItemsPerTarget);
                $examine->progress = $itemsPerTarget > 0 ? round((array_sum($answeredItemsPerTarget) / ($itemsPerTarget * max(1, $totalTargets))) * 100, 1) : 0;
                unset($examine->user_id);

                return $examine;
            })
            ->values();

        return success_response($response, [
            'user_info' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'unit_id' => $user->unit_id,
                'source' => $user->source ?? 'admin',
            ],
            'data' => $examines,
        ]);
    }

    public function getTargets(Request $request, Response $response, array $args): Response
    {
        $examineId = (int)$args['examineId'];

        $examine = DB::table('examines')->where('id', $examineId)->first();
        if (!$examine || $examine->status !== 'active') {
            return error_response($response, 404, '测评任务不存在或未激活');
        }

        $queryParams = $request->getQueryParams();
        $userPhone = trim($queryParams['phone'] ?? '');
        $deviceFingerprint = trim($queryParams['fingerprint'] ?? '');

        // 查找用户
        $user = null;
        if (!empty($userPhone)) {
            $user = DB::table('users')
                ->where('phone', $userPhone)
                ->whereIn('status', [1, '1', 'active'])
                ->first();
        } elseif (!empty($deviceFingerprint)) {
            $user = DB::table('users')
                ->where('device_fingerprint', $deviceFingerprint)
                ->whereIn('status', [1, '1', 'active'])
                ->first();
        }

        $targets = DB::table('examine_targets')
            ->where('examine_id', $examineId)
            ->orderBy('sort_order', 'asc')
            ->get()
            ->map(function ($target) use ($examineId, $user) {
                $target->answered = false;
                $target->is_locked = false;

                if ($user) {
                    $answerCount = DB::table('examine_answers')
                        ->where('examine_id', $examineId)
                        ->where('user_id', $user->id)
                        ->where('target_id', $target->id)
                        ->count();

                    $target->answered = $answerCount > 0;

                    $lockedCount = DB::table('examine_answers')
                        ->where('examine_id', $examineId)
                        ->where('user_id', $user->id)
                        ->where('target_id', $target->id)
                        ->whereNotNull('completed_at')
                        ->count();

                    $target->is_locked = $lockedCount > 0;
                }

                return $target;
            });

        return success_response($response, $targets);
    }

    public function getItems(Request $request, Response $response, array $args): Response
    {
        $examineId = (int)$args['examineId'];
        $targetId = (int)$args['targetId'];

        $examine = DB::table('examines')->where('id', $examineId)->first();
        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        $items = DB::table('template_items')
            ->where('template_id', $examine->template_id)
            ->orderBy('sort_order', 'asc')
            ->get()
            ->map(function ($item) {
                $item->options = json_decode($item->options ?? '[]', true);
                $item->reverse_options = json_decode($item->reverse_options ?? '[]', true);
                return $item;
            });

        return success_response($response, $items);
    }

    public function getAnswers(Request $request, Response $response, array $args): Response
    {
        $examineId = (int)$args['examineId'];
        $targetId = (int)$args['targetId'];

        $userPhone = trim($request->getQueryParams()['phone'] ?? '');
        $deviceFingerprint = trim($request->getQueryParams()['fingerprint'] ?? '');

        // 验证用户身份
        $user = null;
        if (!empty($userPhone)) {
            $user = DB::table('users')
                ->where('phone', $userPhone)
                ->whereIn('status', [1, '1', 'active'])
                ->first();
        } elseif (!empty($deviceFingerprint)) {
            $user = DB::table('users')
                ->where('device_fingerprint', $deviceFingerprint)
                ->whereIn('status', [1, '1', 'active'])
                ->first();
        }

        if (!$user) {
            return error_response($response, 401, '用户身份验证失败');
        }

        $answers = DB::table('examine_answers')
            ->where('examine_id', $examineId)
            ->where('user_id', $user->id)
            ->where('target_id', $targetId)
            ->get()
            ->map(function ($answer) {
                if ($answer->answer_value && str_starts_with($answer->answer_value, '[')) {
                    $answer->answer_value = json_decode($answer->answer_value, true);
                }
                return $answer;
            });

        return success_response($response, $answers);
    }

    public function submitAll(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        $examineId = (int)($data['examine_id'] ?? 0);
        $userPhone = trim($data['user_phone'] ?? '');
        $deviceFingerprint = trim($data['device_fingerprint'] ?? '');

        if (!$examineId) {
            return error_response($response, 400, '缺少测评任务ID');
        }

        // 验证用户
        $user = null;
        if (!empty($userPhone)) {
            $user = DB::table('users')
                ->where('phone', $userPhone)
                ->whereIn('status', [1, '1', 'active'])
                ->first();
        } elseif (!empty($deviceFingerprint)) {
            $user = DB::table('users')
                ->where('device_fingerprint', $deviceFingerprint)
                ->whereIn('status', [1, '1', 'active'])
                ->first();
        }

        if (!$user) {
            return error_response($response, 401, '用户身份验证失败');
        }

        // 检查是否所有题目都已作答
        $examine = DB::table('examines')->where('id', $examineId)->first();
        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        $totalTargets = DB::table('examine_targets')
            ->where('examine_id', $examineId)
            ->count();

        $totalItems = DB::table('template_items')
            ->where('template_id', $examine->template_id)
            ->count();

        $expectedAnswers = $totalTargets * $totalItems;
        $actualAnswers = DB::table('examine_answers')
            ->where('examine_id', $examineId)
            ->where('user_id', $user->id)
            ->count();

        if ($actualAnswers < $expectedAnswers) {
            return error_response($response, 400, "还有题目未完成作答（已完成{$actualAnswers}/共需{$expectedAnswers}题）");
        }

        // 标记完成（支持注册用户通过关联admin用户更新状态）
        $updateRows = DB::table('examine_users')
            ->where('examine_id', $examineId)
            ->where('user_id', $user->id)
            ->update([
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
            ]);

        if ($updateRows === 0 && ($user->source ?? 'admin') === 'registered' && !empty($user->phone) && !empty($user->name)) {
            $linkedAdminUserIds = DB::table('users')
                ->where('source', 'admin')
                ->where('phone', $user->phone)
                ->where('name', $user->name)
                ->whereIn('status', ['1', 'active'])
                ->pluck('id')
                ->toArray();

            if (!empty($linkedAdminUserIds)) {
                DB::table('examine_users')
                    ->where('examine_id', $examineId)
                    ->whereIn('user_id', $linkedAdminUserIds)
                    ->update([
                        'status' => 'completed',
                        'completed_at' => date('Y-m-d H:i:s'),
                    ]);
            }
        }

        return success_response($response, null, '提交成功！感谢您的参与');
    }

    public function completeTarget(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
        $examineId = (int)($data['examine_id'] ?? 0);
        $targetId = (int)($data['target_id'] ?? 0);

        if (!$examineId || !$targetId) {
            return error_response($response, 400, '参数错误');
        }

        $user = $this->authenticateUser($request, $response, $examineId);
        if (!($user instanceof \stdClass) && !is_array($user)) {
            return $user;
        }

        // 标记该对象的所有答案为已完成（锁定）
        DB::table('examine_answers')
            ->where('examine_id', $examineId)
            ->where('user_id', $user->id)
            ->where('target_id', $targetId)
            ->whereNull('completed_at')
            ->update(['completed_at' => date('Y-m-d H:i:s')]);

        // 检查该用户是否已完成所有测评对象的答题，若是则标记整体完成
        $totalTargetsAll = DB::table('examine_targets')
            ->where('examine_id', $examineId)
            ->count();

        $completedTargetCount = 0;
        if ($totalTargetsAll > 0) {
            $targets = DB::table('examine_targets')
                ->where('examine_id', $examineId)
                ->get();

            $totalItems = DB::table('template_items')
                ->where('template_id', function ($query) use ($examineId) {
                    $query->select('template_id')->from('examines')->where('id', $examineId);
                })
                ->count();

            foreach ($targets as $target) {
                $answeredForTarget = DB::table('examine_answers')
                    ->where('examine_id', $examineId)
                    ->where('user_id', $user->id)
                    ->where('target_id', $target->id)
                    ->count();
                if ($answeredForTarget >= $totalItems && $totalItems > 0) {
                    $completedTargetCount++;
                }
            }
        }

        if ($completedTargetCount >= $totalTargetsAll && $totalTargetsAll > 0) {
            DB::table('examine_users')
                ->where('examine_id', $examineId)
                ->where('user_id', $user->id)
                ->update([
                    'status' => 'completed',
                    'completed_at' => date('Y-m-d H:i:s'),
                ]);
        }

        return success_response($response, null, '该测评对象已锁定');
        } catch (\Throwable $e) {
            error_log('[AnswerController::completeTarget] ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return error_response($response, 500, '提交答案时发生错误，请稍后重试');
        }
    }

    private function authenticateUser(Request $request, Response $response, int $examineId)
    {
        $data = $request->getParsedBody();
        $queryParams = $request->getQueryParams();
        $userPhone = trim($data['user_phone'] ?? $queryParams['phone'] ?? '');
        $deviceFingerprint = trim($data['device_fingerprint'] ?? $queryParams['fingerprint'] ?? '');

        $examine = DB::table('examines')->find($examineId);
        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        $user = null;
        if (!empty($userPhone)) {
            $user = DB::table('users')
                ->where('unit_id', $examine->unit_id)
                ->where('phone', $userPhone)
                ->whereIn('status', [1, '1', 'active'])
                ->first();
            if (!$user) {
                $user = DB::table('users')
                    ->where('phone', $userPhone)
                    ->where('source', 'registered')
                    ->whereIn('status', [1, '1', 'active'])
                    ->first();
            }
        } elseif (!empty($deviceFingerprint)) {
            $user = DB::table('users')
                ->where('device_fingerprint', $deviceFingerprint)
                ->whereIn('status', [1, '1', 'active'])
                ->first();
        }

        if (!$user) {
            return error_response($response, 401, '用户身份验证失败，请重新登录');
        }

        return $user;
    }

    private function normalizeReverseOption($opt): string
    {
        if (is_array($opt)) {
            return trim($opt['text'] ?? $opt['letter'] ?? '');
        }
        if (is_string($opt) && strpos($opt, ':') !== false) {
            $parts = explode(':', $opt, 2);
            return trim($parts[1] ?? '');
        }
        return trim((string)$opt);
    }
}
