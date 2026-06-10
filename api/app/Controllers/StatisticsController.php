<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

class StatisticsController
{
    private function safeJsonDecode($value): array
    {
        if (is_array($value)) return $value;
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    public function examineStats(Request $request, Response $response, array $args): Response
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

        // 基本统计
        $totalUsers = DB::table('examine_users')->where('examine_id', $id)->count();
        $completedUsers = DB::table('examine_users')
            ->where('examine_id', $id)
            ->where('status', 'completed')
            ->count();

        $totalTargets = DB::table('examine_targets')->where('examine_id', $id)->count();

        // 各测评对象统计
        $targetStats = DB::table('examine_targets')
            ->where('examine_id', $id)
            ->orderBy('sort_order', 'asc')
            ->get()
            ->map(function ($target) use ($examine, $id) {
                $answeredCount = DB::table('examine_answers')
                    ->where('examine_id', $id)
                    ->where('target_id', $target->id)
                    ->distinct('user_id')
                    ->count('user_id');

                $target->answered_users = $answeredCount;

                // 获取该对象的各项指标统计
                $itemStats = $this->getTargetItemStats($id, (int)$target->id, $examine);
                $target->items_stats = $itemStats;

                return $target;
            });

        return success_response($response, [
            'examine_info' => $examine,
            'summary' => [
                'total_users' => $totalUsers,
                'completed_users' => $completedUsers,
                'completion_rate' => $totalUsers > 0 ? round(($completedUsers / $totalUsers) * 100, 1) : 0,
                'total_targets' => $totalTargets,
            ],
            'targets_stats' => $targetStats,
        ]);
    }

    public function targetStats(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $targetId = (int)$args['targetId'];

        $examine = DB::table('examines')->where('id', $id)->first();
        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        $target = DB::table('examine_targets')->where('id', $targetId)->first();
        if (!$target || $target->examine_id != $id) {
            return error_response($response, 404, '测评对象不存在');
        }

        // 详细统计
        $itemStats = $this->getTargetItemStats($id, $targetId, $examine);

        // A/B用户分类统计
        $userTypeStats = DB::table('examine_answers')
            ->join('users', 'examine_answers.user_id', '=', 'users.id')
            ->select(
                'users.user_type',
                DB::raw('COUNT(DISTINCT examine_answers.user_id) as user_count')
            )
            ->where('examine_answers.examine_id', $id)
            ->where('examine_answers.target_id', $targetId)
            ->groupBy('users.user_type')
            ->get()
            ->keyBy('user_type');

        // 反向测评事例
        $reverseExamples = DB::table('examine_answers')
            ->join('template_items', 'examine_answers.item_id', '=', 'template_items.id')
            ->join('users', 'examine_answers.user_id', '=', 'users.id')
            ->select(
                'examine_answers.*',
                'template_items.item_title',
                'users.name as user_name'
            )
            ->where('examine_answers.examine_id', $id)
            ->where('examine_answers.target_id', $targetId)
            ->where('template_items.is_reverse', 1)
            ->whereNotNull('examine_answers.example_text')
            ->get();

        return success_response($response, [
            'target_info' => $target,
            'items_stats' => $itemStats,
            'user_type_distribution' => $userTypeStats,
            'reverse_examples' => $reverseExamples,
        ]);
    }

    private function getTargetItemStats(int $examineId, int $targetId, $examine): array
    {
        $items = DB::table('template_items')
            ->where('template_id', $examine->template_id)
            ->orderBy('sort_order', 'asc')
            ->get();

        $stats = [];

        foreach ($items as $item) {
            $answers = DB::table('examine_answers')
                ->where('examine_id', $examineId)
                ->where('target_id', $targetId)
                ->where('item_id', $item->id)
                ->get();

            $itemStat = [
                'item_id' => $item->id,
                'item_title' => $item->item_title,
                'item_type' => $item->item_type,
                'total_responses' => $answers->count(),
                'option_stats' => [],
            ];

            switch ($item->item_type) {
                case 'radio':
                    $options = $this->safeJsonDecode($item->options);
                    $optionCounts = array_fill_keys($options, 0);

                    foreach ($answers as $answer) {
                        if (isset($optionCounts[$answer->answer_value])) {
                            $optionCounts[$answer->answer_value]++;
                        }
                    }

                    $optionStats = [];
                    foreach ($options as $opt) {
                        $count = $optionCounts[$opt];
                        $percentage = $answers->count() > 0
                            ? round(($count / $answers->count()) * 100, 1)
                            : 0;

                        $optionStats[] = [
                            'option' => $opt,
                            'count' => $count,
                            'percentage' => $percentage,
                        ];
                    }

                    $itemStat['option_stats'] = $optionStats;
                    break;

                case 'checkbox':
                    $options = $this->safeJsonDecode($item->options);
                    $optionCounts = array_fill_keys($options, 0);

                    foreach ($answers as $answer) {
                        $selectedOptions = $this->safeJsonDecode($answer->answer_value);
                        if (is_array($selectedOptions)) {
                            foreach ($selectedOptions as $opt) {
                                if (isset($optionCounts[$opt])) {
                                    $optionCounts[$opt]++;
                                }
                            }
                        }
                    }

                    $totalResponses = $answers->count();
                    $optionStats = [];
                    foreach ($options as $opt) {
                        $count = $optionCounts[$opt];
                        $percentage = $totalResponses > 0
                            ? round(($count / $totalResponses) * 100, 1)
                            : 0;

                        $optionStats[] = [
                            'option' => $opt,
                            'count' => $count,
                            'percentage' => $percentage,
                        ];
                    }

                    $itemStat['option_stats'] = $optionStats;
                    break;

                case 'textarea':
                    $texts = $answers->pluck('example_text')->filter()->values();
                    $itemStat['text_responses'] = $texts;
                    break;
            }

            $stats[] = $itemStat;
        }

        return $stats;
    }

    public function getStatsByUnit(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $userType = $request->getQueryParams()['user_type'] ?? 'all';

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

        $unitStats = [];
        foreach ($targets as $target) {
            $unitName = $target->unit_name ?? '未分配';

            if (!isset($unitStats[$unitName])) {
                $unitStats[$unitName] = [
                    'unit_name' => $unitName,
                    'targets' => [],
                    'total_participants' => 0,
                    'total_completed' => 0,
                ];
            }

            $answeredCount = DB::table('examine_answers')
                ->where('examine_id', $id)
                ->where('target_id', $target->id)
                ->distinct('user_id')
                ->count('user_id');

            $targetStats = $this->getTargetItemStatsWithUserType($id, (int)$target->id, $examine, $userType);

            $unitStats[$unitName]['targets'][] = [
                'target_id' => $target->id,
                'target_name' => $target->target_name,
                'position' => $target->position,
                'answered_users' => $answeredCount,
                'items_stats' => $targetStats,
            ];
        }

        return success_response($response, [
            'examine_info' => $examine,
            'unit_stats' => array_values($unitStats),
        ]);
    }

    private function getTargetItemStatsWithUserType(int $examineId, int $targetId, $examine, string $userType): array
    {
        $items = DB::table('template_items')
            ->where('template_id', $examine->template_id)
            ->orderBy('sort_order', 'asc')
            ->get();

        $stats = [];

        foreach ($items as $item) {
            $query = DB::table('examine_answers')
                ->join('users', 'examine_answers.user_id', '=', 'users.id')
                ->where('examine_answers.examine_id', $examineId)
                ->where('examine_answers.target_id', $targetId)
                ->where('examine_answers.item_id', $item->id);

            if ($userType !== 'all') {
                $query->where('users.user_type', $userType);
            }

            $answers = $query->get();

            $itemStat = [
                'item_id' => $item->id,
                'item_title' => $item->item_title,
                'item_type' => $item->item_type,
                'total_responses' => $answers->count(),
                'option_stats' => [],
                'a_count' => 0,
                'b_count' => 0,
            ];

            switch ($item->item_type) {
                case 'radio':
                case 'checkbox':
                    $options = $this->safeJsonDecode($item->options);
                    $aOptionCounts = array_fill_keys($options, 0);
                    $bOptionCounts = array_fill_keys($options, 0);

                    foreach ($answers as $answer) {
                        $userTypeVal = (string)($answer->user_type ?? 'A');
                        $answerValues = $item->item_type === 'checkbox'
                            ? $this->safeJsonDecode($answer->answer_value)
                            : [$answer->answer_value];

                        foreach ((array)$answerValues as $val) {
                            if (isset($aOptionCounts[$val])) {
                                if ($userTypeVal === 'A') {
                                    $aOptionCounts[$val]++;
                                } else {
                                    $bOptionCounts[$val]++;
                                }
                            }
                        }
                    }

                    $optionStats = [];
                    foreach ($options as $opt) {
                        $aCount = $aOptionCounts[$opt];
                        $bCount = $bOptionCounts[$opt];
                        $total = $aCount + $bCount;
                        $percentage = $total > 0 ? round(($total / $answers->count()) * 100, 1) : 0;

                        $optionStats[] = [
                            'option' => $opt,
                            'a_count' => $aCount,
                            'b_count' => $bCount,
                            'total_count' => $total,
                            'percentage' => $percentage,
                        ];
                    }

                    $itemStat['option_stats'] = $optionStats;
                    $itemStat['a_count'] = array_sum($aOptionCounts);
                    $itemStat['b_count'] = array_sum($bOptionCounts);
                    break;

                case 'textarea':
                    $texts = $answers->pluck('answer_value')->filter()->values();
                    $itemStat['text_responses'] = $texts;
                    break;
            }

            $stats[] = $itemStat;
        }

        return $stats;
    }

    public function voteSummary(Request $request, Response $response, array $args): Response
    {
        try {
        $id = (int)$args['id'];

        $examine = DB::table('examines')
            ->leftJoin('units', 'examines.unit_id', '=', 'units.id')
            ->leftJoin('templates', 'examines.template_id', '=', 'templates.id')
            ->select(
                'examines.*',
                'units.unit_name',
                'templates.template_type'
            )
            ->where('examines.id', $id)
            ->first();

        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        $totalUsers = DB::table('examine_users')->where('examine_id', $id)->count();
        $completedUsers = DB::table('examine_users')
            ->where('examine_id', $id)
            ->where('status', 'completed')
            ->count();

        $targets = DB::table('examine_targets')
            ->where('examine_id', $id)
            ->orderBy('sort_order', 'asc')
            ->get();

        $debug = [
            'examine_id' => $id,
            'template_id' => $examine->template_id,
            'total_users' => $totalUsers,
            'targets_count' => $targets->count(),
            'targets_list' => $targets->map(fn($t) => ['id' => $t->id, 'name' => $t->target_name])->toArray(),
        ];

        $resultTargets = [];
        foreach ($targets as $target) {
            $returnedVotes = DB::table('examine_answers')
                ->where('examine_id', $id)
                ->where('target_id', $target->id)
                ->distinct('user_id')
                ->count('user_id');

            $validVotes = $returnedVotes;
            $pendingVotes = max(0, $totalUsers - $returnedVotes);

            $items = $this->getVoteItemDetails($id, (int)$target->id, $examine);

            // 调试：查看每个 target 的 items
            $debug['target_' . $target->id . '_items_count'] = count($items);

            $resultTargets[] = [
                'target_id' => $target->id,
                'target_name' => $target->target_name,
                'position' => $target->position,
                'returned_votes' => $returnedVotes,
                'valid_votes' => $validVotes,
                'pending_votes' => $pendingVotes,
                'items' => $items,
            ];
        }

        $wa = $examine->weight_a;
        $wb = $examine->weight_b;
        return success_response($response, [
            'debug' => $debug,
            'examine_info' => [
                'id' => $examine->id,
                'examine_name' => $examine->examine_name,
                'unit_name' => $examine->unit_name ?? '',
                'template_type' => $examine->template_type ?? '',
                'start_time' => $examine->start_time,
                'end_time' => $examine->end_time,
                'weight_a' => is_numeric($wa) ? (float)$wa : 1.0,
                'weight_b' => is_numeric($wb) ? (float)$wb : 1.0,
            ],
            'progress' => [
                'total_users' => $totalUsers,
                'completed_users' => $completedUsers,
                'completion_rate' => $totalUsers > 0 ? round(($completedUsers / $totalUsers) * 100, 2) : 0,
            ],
            'targets' => $resultTargets,
        ]);
        } catch (\Throwable $e) {
            error_log("[StatisticsController::voteSummary] Error: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}");
            return error_response($response, 500, '投票汇总计算失败，请稍后重试');
        }
    }

    private function getVoteItemDetails(int $examineId, int $targetId, $examine): array
    {
        $items = DB::table('template_items')
            ->where('template_id', $examine->template_id)
            ->orderBy('sort_order', 'asc')
            ->get();

        $resultItems = [];

        foreach ($items as $item) {
            if ($item->item_type === 'textarea') continue;

            $rawOptions = $item->options;
            $options = $this->safeJsonDecode($rawOptions);
            if (!is_array($options) || empty($options)) continue;

            $answers = DB::table('examine_answers')
                ->join('users', 'examine_answers.user_id', '=', 'users.id')
                ->select('examine_answers.answer_value', 'users.user_type')
                ->where('examine_answers.examine_id', $examineId)
                ->where('examine_answers.target_id', $targetId)
                ->where('examine_answers.item_id', $item->id)
                ->get();

            $optionScores = $this->getOptionScoreMap($options);

            $optionData = [];
            foreach ($options as $opt) {
                $aCount = 0;
                $bCount = 0;

                $optKey = $this->getOptionKey($opt);
                $optLabel = $this->getOptionLabel($opt);

                foreach ($answers as $answer) {
                    if ($item->item_type === 'checkbox') {
                        $selectedOptions = $this->safeJsonDecode($answer->answer_value);
                        $selectedKeys = array_map([$this, 'getOptionKey'], $selectedOptions);
                        if (is_array($selectedOptions) && in_array($optKey, $selectedKeys)) {
                            if ((string)$answer->user_type === 'B') $bCount++;
                            else $aCount++;
                        }
                    } else {
                        $answerValueKey = $this->getOptionKey($answer->answer_value);
                        if ($answerValueKey === $optKey) {
                            if ((string)$answer->user_type === 'B') $bCount++;
                            else $aCount++;
                        }
                    }
                }

                $optionData[] = [
                    'option' => $optLabel,
                    'a_count' => $aCount,
                    'b_count' => $bCount,
                    'score' => $optionScores[$optKey] ?? 0,
                ];
            }

            $weightedScore = $this->calcWeightedScore($optionData, $answers->count(), $examine);

            $resultItems[] = [
                'item_id' => $item->id,
                'item_title' => $item->item_title,
                'item_type' => $item->item_type,
                'options' => $optionData,
                'weighted_score' => round($weightedScore, 2),
            ];
        }

        return $resultItems;
    }

    public function scoreSummary(Request $request, Response $response, array $args): Response
    {
        try {
        $id = (int)$args['id'];

        $examine = DB::table('examines')
            ->leftJoin('units', 'examines.unit_id', '=', 'units.id')
            ->leftJoin('templates', 'examines.template_id', '=', 'templates.id')
            ->select(
                'examines.*',
                'units.unit_name',
                'templates.template_type'
            )
            ->where('examines.id', $id)
            ->first();

        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        $totalUsers = DB::table('examine_users')->where('examine_id', $id)->count();
        $completedUsers = DB::table('examine_users')
            ->where('examine_id', $id)
            ->where('status', 'completed')
            ->count();

        $targets = DB::table('examine_targets')
            ->where('examine_id', $id)
            ->orderBy('sort_order', 'asc')
            ->get();

        $scoreTargets = [];
        foreach ($targets as $target) {
            $items = $this->getVoteItemDetails($id, (int)$target->id, $examine);

            $itemScores = [];
            $totalWeightedScore = 0;
            $validItemCount = 0;

            foreach ($items as $item) {
                $itemScores[] = [
                    'item_title' => $item['item_title'],
                    'a_score' => round($item['weighted_score'], 2),
                    'b_score' => round($item['weighted_score'], 2),
                    'combined_score' => round($item['weighted_score'], 2),
                ];
                $totalWeightedScore += $item['weighted_score'];
                $validItemCount++;
            }

            $avgScore = $validItemCount > 0 ? $totalWeightedScore / $validItemCount : 0;

            $scoreTargets[] = [
                'target_id' => $target->id,
                'target_name' => $target->target_name,
                'position' => $target->position,
                'item_scores' => $itemScores,
                'total_score' => round($avgScore, 2),
            ];
        }

        usort($scoreTargets, fn($a, $b) => $b['total_score'] <=> $a['total_score']);

        foreach ($scoreTargets as $i => &$t) {
            $t['rank'] = $i + 1;
        }
        unset($t);

        return success_response($response, [
            'examine_info' => [
                'id' => $examine->id,
                'examine_name' => $examine->examine_name,
                'unit_name' => $examine->unit_name,
                'start_time' => $examine->start_time,
                'end_time' => $examine->end_time,
            ],
            'progress' => [
                'total_users' => $totalUsers,
                'completed_users' => $completedUsers,
                'completion_rate' => $totalUsers > 0 ? round(($completedUsers / $totalUsers) * 100, 2) : 0,
            ],
            'targets' => $scoreTargets,
        ]);
        } catch (\Throwable $e) {
            error_log("[StatisticsController::scoreSummary] Error: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}");
            return error_response($response, 500, '得分汇总计算失败，请稍后重试');
        }
    }

    private function getOptionScoreMap(array $options): array
    {
        $map = [];
        $count = count($options);
        if ($count <= 0) return $map;

        $scores = [100, 85, 70, 55, 40];
        foreach ($options as $i => $opt) {
            $key = $this->getOptionKey($opt);
            $map[$key] = $scores[min($i, count($scores) - 1)];
        }
        return $map;
    }

    private function getOptionKey($opt): string
    {
        if (is_array($opt)) {
            if (isset($opt['text'])) {
                return (string)$opt['text'];
            } elseif (isset($opt['letter'])) {
                return (string)$opt['letter'];
            } elseif (isset($opt['value'])) {
                return (string)$opt['value'];
            } elseif (isset($opt['label'])) {
                return (string)$opt['label'];
            } elseif (count($opt) === 1) {
                return (string)array_values($opt)[0];
            }
            return (string)json_encode($opt, JSON_UNESCAPED_UNICODE);
        }
        return (string)$opt;
    }

    private function getOptionLabel($opt): string
    {
        if (is_array($opt)) {
            if (isset($opt['text'])) {
                return (string)$opt['text'];
            } elseif (isset($opt['letter'])) {
                return (string)$opt['letter'];
            } elseif (isset($opt['label'])) {
                return (string)$opt['label'];
            } elseif (isset($opt['value'])) {
                return (string)$opt['value'];
            } elseif (count($opt) === 1) {
                return (string)array_values($opt)[0];
            }
            return (string)json_encode($opt, JSON_UNESCAPED_UNICODE);
        }
        return (string)$opt;
    }

    private function calcWeightedScore(array $optionData, int $totalAnswers, $examine): float
    {
        if ($totalAnswers <= 0) return 0;

        $sumScore = 0;
        foreach ($optionData as $opt) {
            $totalCount = $opt['a_count'] + $opt['b_count'];
            $sumScore += ($totalCount / $totalAnswers) * $opt['score'];
        }

        return $sumScore;
    }
}