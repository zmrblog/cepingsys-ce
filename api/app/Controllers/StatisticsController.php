<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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

    public function exportExcel(Request $request, Response $response, array $args): Response
    {
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

        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('得分汇总');

            $targets = DB::table('examine_targets')
                ->where('examine_id', $id)
                ->orderBy('sort_order', 'asc')
                ->get();

            if ($targets->isEmpty()) {
                return error_response($response, 404, '暂无测评对象');
            }

            $firstTargetId = (int)$targets[0]->id;
            $firstItems = $this->getVoteItemDetails($id, $firstTargetId, $examine);

            if (empty($firstItems)) {
                return error_response($response, 404, '暂无测评数据');
            }

            $counts = $this->getExamineAnswerCounts($id);
            $isPerson = ($examine->template_type === 'person');
            $firstCols = $isPerson ? 2 : 1;

            // 计算每 item 的列数 (options + 得分)
            $itemColsList = [];
            $totalItemCols = 0;
            foreach ($firstItems as $item) {
                $opts = $item['options'] ?? [];
                $cols = count($opts) + 1;
                $itemColsList[] = ['title' => $item['item_title'], 'options' => $opts, 'cols' => $cols];
                $totalItemCols += $cols;
            }
            $totalCols = $firstCols + $totalItemCols;
            $endCol = $this->colFromNumber($totalCols);

            // Row 1: 标题
            $sheet->setCellValue('A1', $examine->examine_name . '测评结果');
            $sheet->mergeCells("A1:{$endCol}1");
            $this->styleTitleRow($sheet, 1, $endCol);

            // Row 2: 测评日期
            $dateStr = '测评日期:' . date('Y-m-d', strtotime($examine->start_time)) . ' - ' . date('Y-m-d', strtotime($examine->end_time));
            $sheet->setCellValue('A2', $dateStr);
            $dateEnd = $this->colFromNumber(min($totalCols, 6));
            $sheet->mergeCells("A2:{$dateEnd}2");

            // Row 4: 类别头行 (item标题，合并跨列)
            $colNum = 1;
            $dataRow = 4;
            $firstLabel = $isPerson ? '姓名' : '单位名称';
            $sheet->setCellValue([$colNum, $dataRow], $firstLabel);
            $sheet->mergeCells("{$this->colFromNumber($colNum)}4:{$this->colFromNumber($colNum)}5");
            $colNum++;
            if ($isPerson) {
                $sheet->setCellValue([$colNum, $dataRow], '职务');
                $sheet->mergeCells("{$this->colFromNumber($colNum)}4:{$this->colFromNumber($colNum)}5");
                $colNum++;
            }
            foreach ($itemColsList as $ic) {
                $startCol = $this->colFromNumber($colNum);
                $endItemCol = $this->colFromNumber($colNum + $ic['cols'] - 1);
                $sheet->setCellValue([$colNum, 4], $ic['title']);
                if ($ic['cols'] > 1) {
                    $sheet->mergeCells("{$startCol}4:{$endItemCol}4");
                }
                $colNum += $ic['cols'];
            }

            // Row 5: 子选项行 (各选项 + 得分)
            $colNum = $firstCols + 1;
            foreach ($itemColsList as $ic) {
                foreach ($ic['options'] as $opt) {
                    $sheet->setCellValue([$colNum, 5], $opt['option']);
                    $colNum++;
                }
                $sheet->setCellValue([$colNum, 5], '得分');
                $colNum++;
            }

            $this->styleHeaderRow($sheet, 4, $endCol);
            $this->styleHeaderRow($sheet, 5, $endCol);
            $this->styleHeaderRow($sheet, 6, $endCol);
            $this->styleABSubHeaderRow($sheet, 6, $endCol);

            // Row 7+: 数据行
            $dataStartRow = 7;
            $cr = $dataStartRow;
            foreach ($targets as $target) {
                $items = $this->getVoteItemDetails($id, (int)$target->id, $examine);

                $colNum = 1;
                $sheet->setCellValue([$colNum, $cr], $target->target_name ?? '');
                $colNum++;
                if ($isPerson) {
                    $sheet->setCellValue([$colNum, $cr], $target->position ?? '');
                    $colNum++;
                }
                foreach ($items as $item) {
                    foreach ($item['options'] as $opt) {
                        $count = ($opt['a_count'] ?? 0) + ($opt['b_count'] ?? 0);
                        $sheet->setCellValue([$colNum, $cr], $count > 0 ? $count : '');
                        $colNum++;
                    }
                    $ws = round($item['weighted_score'] ?? 0, 2);
                    $sheet->setCellValue([$colNum, $cr], $ws);
                    $colNum++;
                }
                $cr++;
            }

            // 底部汇总行
            $summaryRow = $cr;
            $sheet->setCellValue("A{$summaryRow}", "发出数:{$counts['total']}    收回数:{$counts['returned']}");
            $summaryEnd = $this->colFromNumber(min($totalCols, 8));
            $sheet->mergeCells("A{$summaryRow}:{$summaryEnd}{$summaryRow}");
            $sheet->getStyle("A{$summaryRow}")->getFont()->setBold(true);

            // 格式化
            $dataEndRow = $cr - 1;
            $this->applyTableBorders($sheet, 4, $dataEndRow, $endCol);
            if ($dataStartRow <= $dataEndRow) {
                $dataColStart = $this->colFromNumber($firstCols + 1);
                $sheet->getStyle("{$dataColStart}{$dataStartRow}:{$endCol}{$dataEndRow}")
                    ->getAlignment()
                    ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
                    ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
                $nameColEnd = $this->colFromNumber($firstCols);
                $sheet->getStyle("A{$dataStartRow}:{$nameColEnd}{$dataEndRow}")
                    ->getAlignment()
                    ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            }
            $sheet->getStyle("A4:{$endCol}6")
                ->getAlignment()
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

            // 固定列宽
            $sheet->getColumnDimension('A')->setWidth(14);
            if ($isPerson) {
                $sheet->getColumnDimension('B')->setWidth(12);
            }
            $firstDataCol = $firstCols + 1;
            for ($i = $firstDataCol; $i <= $totalCols; $i++) {
                $sheet->getColumnDimension($this->colFromNumber($i))->setWidth(10);
            }

            // 表头自动换行
            $sheet->getStyle("4:6")->getAlignment()->setWrapText(true);
            $sheet->getRowDimension(4)->setRowHeight(-1);
            $sheet->getRowDimension(5)->setRowHeight(-1);
            $sheet->getRowDimension(6)->setRowHeight(20);

            // 冻结窗格
            $freezeCell = $isPerson ? 'C7' : 'B7';
            $sheet->freezePane($freezeCell);

            // 添加反向测评内容 sheet（如有）
            $this->addReverseEvalSheet($spreadsheet, $id, $targets);

            $fileName = "得分汇总_{$examine->examine_name}_" . date('YmdHis') . ".xlsx";
            $tempPath = sys_get_temp_dir() . '/' . $fileName;
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempPath);
            $fileContent = file_get_contents($tempPath);
            unlink($tempPath);

            $response->getBody()->write($fileContent);
            return $response
                ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->withHeader('Content-Disposition', "attachment; filename=\"{$fileName}\"")
                ->withHeader('Content-Length', strlen($fileContent));

        } catch (\Throwable $e) {
            return error_response($response, 500, 'Excel导出失败: ' . $e->getMessage());
        }
    }

    private function getItemTypeText(string $type): string
    {
        return match ($type) {
            'radio' => '单选',
            'checkbox' => '多选',
            'textarea' => '文本',
            default => '未知',
        };
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

    public function exportByUnit(Request $request, Response $response, array $args): Response
    {
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

        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('得票汇总');

            $targets = DB::table('examine_targets')
                ->where('examine_id', $id)
                ->orderBy('sort_order', 'asc')
                ->get();

            if ($targets->isEmpty()) {
                return error_response($response, 404, '暂无测评对象');
            }

            $firstTargetId = (int)$targets[0]->id;
            $firstItems = $this->getVoteItemDetails($id, $firstTargetId, $examine);

            if (empty($firstItems)) {
                return error_response($response, 404, '暂无测评数据');
            }

            $counts = $this->getExamineAnswerCounts($id);
            $isPerson = ($examine->template_type === 'person');
            $firstCols = $isPerson ? 2 : 1;

            // 计算每 item 的列数 (options only, no score column)
            $itemColsList = [];
            $totalItemCols = 0;
            foreach ($firstItems as $item) {
                $opts = $item['options'] ?? [];
                $cols = count($opts) * 2;
                $itemColsList[] = ['title' => $item['item_title'], 'options' => $opts, 'cols' => $cols];
                $totalItemCols += $cols;
            }
            $totalCols = $firstCols + $totalItemCols;
            $endCol = $this->colFromNumber($totalCols);

            // Row 1: 标题
            $sheet->setCellValue('A1', $examine->examine_name . '测评得票汇总');
            $sheet->mergeCells("A1:{$endCol}1");
            $this->styleTitleRow($sheet, 1, $endCol);

            // Row 2: 测评日期
            $dateStr = '测评日期:' . date('Y-m-d', strtotime($examine->start_time)) . ' - ' . date('Y-m-d', strtotime($examine->end_time));
            $sheet->setCellValue('A2', $dateStr);
            $dateEnd = $this->colFromNumber(min($totalCols, 8));
            $sheet->mergeCells("A2:{$dateEnd}2");

            // Row 4: 类别头行 (item标题，合并跨列)
            $colNum = 1;
            $dataRow = 4;
            $firstLabel = $isPerson ? '姓名' : '单位名称';
            $sheet->setCellValue([$colNum, $dataRow], $firstLabel);
            $sheet->mergeCells("{$this->colFromNumber($colNum)}4:{$this->colFromNumber($colNum)}5");
            $colNum++;
            if ($isPerson) {
                $sheet->setCellValue([$colNum, $dataRow], '职务');
                $sheet->mergeCells("{$this->colFromNumber($colNum)}4:{$this->colFromNumber($colNum)}5");
                $colNum++;
            }
            foreach ($itemColsList as $ic) {
                $startCol = $this->colFromNumber($colNum);
                $endItemCol = $this->colFromNumber($colNum + $ic['cols'] - 1);
                $sheet->setCellValue([$colNum, 4], $ic['title']);
                if ($ic['cols'] > 1) {
                    $sheet->mergeCells("{$startCol}4:{$endItemCol}4");
                }
                $colNum += $ic['cols'];
            }

            // Row 5: 子选项行 (每个选项名跨AB两列)
            $colNum = $firstCols + 1;
            foreach ($itemColsList as $ic) {
                foreach ($ic['options'] as $opt) {
                    $startCol = $this->colFromNumber($colNum);
                    $endOptCol = $this->colFromNumber($colNum + 1);
                    $sheet->setCellValue([$colNum, 5], $opt['option']);
                    if ($ic['cols'] > 2) {
                        $sheet->mergeCells("{$startCol}5:{$endOptCol}5");
                    }
                    $colNum += 2;
                }
            }

            // Row 6: A/B 子头
            $colNum = $firstCols + 1;
            foreach ($itemColsList as $ic) {
                foreach ($ic['options'] as $opt) {
                    $sheet->setCellValue([$colNum, 6], 'A');
                    $colNum++;
                    $sheet->setCellValue([$colNum, 6], 'B');
                    $colNum++;
                }
            }

            $this->styleHeaderRow($sheet, 4, $endCol);
            $this->styleHeaderRow($sheet, 5, $endCol);
            $this->styleHeaderRow($sheet, 6, $endCol);

            // Row 7+: 数据行
            $dataStartRow = 7;
            $cr = $dataStartRow;
            foreach ($targets as $target) {
                $items = $this->getVoteItemDetails($id, (int)$target->id, $examine);

                $colNum = 1;
                $sheet->setCellValue([$colNum, $cr], $target->target_name ?? '');
                $colNum++;
                if ($isPerson) {
                    $sheet->setCellValue([$colNum, $cr], $target->position ?? '');
                    $colNum++;
                }
                foreach ($items as $item) {
                    foreach ($item['options'] as $opt) {
                        $sheet->setCellValue([$colNum, $cr], $opt['a_count'] ?? '');
                        $colNum++;
                        $sheet->setCellValue([$colNum, $cr], $opt['b_count'] ?? '');
                        $colNum++;
                    }
                }
                $cr++;
            }

            // 底部汇总行
            $summaryRow = $cr;
            $sheet->setCellValue("A{$summaryRow}", "发出数:{$counts['total']}    收回数:{$counts['returned']}");
            $summaryEnd = $this->colFromNumber(min($totalCols, 8));
            $sheet->mergeCells("A{$summaryRow}:{$summaryEnd}{$summaryRow}");
            $sheet->getStyle("A{$summaryRow}")->getFont()->setBold(true);

            // 格式化
            $dataEndRow = $cr - 1;
            $this->applyTableBorders($sheet, 4, $dataEndRow, $endCol);
            if ($dataStartRow <= $dataEndRow) {
                $dataColStart = $this->colFromNumber($firstCols + 1);
                $sheet->getStyle("{$dataColStart}{$dataStartRow}:{$endCol}{$dataEndRow}")
                    ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            }

            // 固定列宽
            $sheet->getColumnDimension('A')->setWidth(14);
            if ($isPerson) {
                $sheet->getColumnDimension('B')->setWidth(12);
            }
            $firstDataCol = $firstCols + 1;
            for ($i = $firstDataCol; $i <= $totalCols; $i++) {
                $sheet->getColumnDimension($this->colFromNumber($i))->setWidth(10);
            }

            // 表头自动换行
            $sheet->getStyle("4:6")->getAlignment()->setWrapText(true);
            $sheet->getRowDimension(4)->setRowHeight(-1);
            $sheet->getRowDimension(5)->setRowHeight(-1);
            $sheet->getRowDimension(6)->setRowHeight(-1);

            // 冻结窗格
            $freezeCell = $isPerson ? 'C7' : 'B7';
            $sheet->freezePane($freezeCell);

            $this->addReverseEvalSheet($spreadsheet, $id, $targets);

            $fileName = "得票汇总_{$examine->examine_name}_" . date('YmdHis') . ".xlsx";
            $tempPath = sys_get_temp_dir() . '/' . $fileName;
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempPath);
            $fileContent = file_get_contents($tempPath);
            unlink($tempPath);

            $response->getBody()->write($fileContent);
            return $response
                ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->withHeader('Content-Disposition', "attachment; filename=\"{$fileName}\"")
                ->withHeader('Content-Length', strlen($fileContent));

        } catch (\Throwable $e) {
            return error_response($response, 500, 'Excel导出失败: ' . $e->getMessage());
        }
    }

    public function exportTarget(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $targetId = (int)$args['targetId'];

        $examine = DB::table('examines')
            ->leftJoin('units', 'examines.unit_id', '=', 'units.id')
            ->select('examines.*', 'units.unit_name')
            ->where('examines.id', $id)
            ->first();

        if (!$examine) {
            return error_response($response, 404, '测评任务不存在');
        }

        $target = DB::table('examine_targets')
            ->where('id', $targetId)
            ->where('examine_id', $id)
            ->first();

        if (!$target) {
            return error_response($response, 404, '测评对象不存在');
        }

        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('测评结果');

            $sheet->setCellValue('A1', '测评对象: ' . $target->target_name . ($target->position ? " ({$target->position})" : ''));
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

            $sheet->setCellValue('A2', '所属任务: ' . $examine->examine_name);
            $sheet->setCellValue('A3', '所属部门: ' . ($target->unit_name ?? '未分配'));
            $sheet->setCellValue('A4', '导出时间: ' . date('Y-m-d H:i:s'));

            $row = 6;

            $headers = ['指标项', '题型', '选项', 'A类得票数', 'B类得票数', 'A类占比(%)', 'B类占比(%)', 'A类加权得分', 'B类加权得分', '综合得分'];
            foreach ($headers as $colIndex => $header) {
                $sheet->setCellValue([$colIndex + 1, $row], $header);
            }
            $sheet->getStyle("A{$row}:J{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}:J{$row}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('E0E0E0');
            $row++;

            $itemStats = $this->getTargetItemStatsWithUserType($id, $targetId, $examine, 'all');

            foreach ($itemStats as $stat) {
                if (!empty($stat['option_stats'])) {
                    foreach ($stat['option_stats'] as $optIdx => $optStat) {
                        $sheet->setCellValue("A{$row}", $optIdx === 0 ? $stat['item_title'] : '');
                        $sheet->setCellValue("B{$row}", $optIdx === 0 ? $this->getItemTypeText($stat['item_type']) : '');
                        $sheet->setCellValue("C{$row}", $optStat['option']);
                        $sheet->setCellValue("D{$row}", $optStat['a_count']);
                        $sheet->setCellValue("E{$row}", $optStat['b_count']);
                        
                        $aPercentage = $stat['a_count'] > 0 ? round(($optStat['a_count'] / $stat['a_count']) * 100, 1) : 0;
                        $bPercentage = $stat['b_count'] > 0 ? round(($optStat['b_count'] / $stat['b_count']) * 100, 1) : 0;
                        
                        $sheet->setCellValue("F{$row}", $aPercentage);
                        $sheet->setCellValue("G{$row}", $bPercentage);
                        
                        $weightA = (float)$examine->weight_a;
                        $weightB = (float)$examine->weight_b;
                        $scoreA = round($aPercentage * $weightA / 100, 2);
                        $scoreB = round($bPercentage * $weightB / 100, 2);
                        $compositeScore = round(($scoreA + $scoreB) / 2, 2);
                        
                        $sheet->setCellValue("H{$row}", $scoreA);
                        $sheet->setCellValue("I{$row}", $scoreB);
                        $sheet->setCellValue("J{$row}", $compositeScore);
                        
                        $row++;
                    }
                } elseif (!empty($stat['text_responses'])) {
                    $sheet->setCellValue("A{$row}", $stat['item_title']);
                    $sheet->setCellValue("B{$row}", $this->getItemTypeText($stat['item_type']));
                    $sheet->setCellValue("C{$row}", implode('; ', array_slice($stat['text_responses']->toArray(), 0, 3)));
                    $row++;
                }
            }

            $sheet->getColumnDimension('A')->setWidth(30);
            $sheet->getColumnDimension('B')->setWidth(10);
            $sheet->getColumnDimension('C')->setWidth(20);
            for ($col = 'D'; $col <= 'J'; $col++) {
                $sheet->getColumnDimension($col)->setWidth(15);
            }

            $fileName = "测评结果_{$target->target_name}_" . date('YmdHis') . ".xlsx";
            $tempPath = sys_get_temp_dir() . '/' . $fileName;

            $writer = new Xlsx($spreadsheet);
            $writer->save($tempPath);

            $fileContent = file_get_contents($tempPath);
            unlink($tempPath);

            $response->getBody()->write($fileContent);
            return $response
                ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->withHeader('Content-Disposition', "attachment; filename=\"{$fileName}\"")
                ->withHeader('Content-Length', strlen($fileContent));

        } catch (\Throwable $e) {
            return error_response($response, 500, 'Excel导出失败: ' . $e->getMessage());
        }
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

    private function applyTableBorders($sheet, int $startRow, int $endRow, string $endCol): void
    {
        $styleArray = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ];
        $sheet->getStyle("A{$startRow}:{$endCol}{$endRow}")->applyFromArray($styleArray);
    }

    private function styleHeaderRow($sheet, int $row, string $endCol): void
    {
        $sheet->getStyle("A{$row}:{$endCol}{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:{$endCol}{$row}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('D9E2F3');
        $sheet->getStyle("A{$row}:{$endCol}{$row}")->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    }

    private function styleABSubHeaderRow($sheet, int $row, string $endCol): void
    {
        $sheet->getStyle("A{$row}:{$endCol}{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:{$endCol}{$row}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E8F0FE');
        $sheet->getStyle("A{$row}:{$endCol}{$row}")->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    }

    private function styleTitleRow($sheet, int $row, string $endCol): void
    {
        $sheet->getStyle("A{$row}:{$endCol}{$row}")->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle("A{$row}:{$endCol}{$row}")->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    }

    private function autoFitColumnWidths($sheet, string $startCol, string $endCol): void
    {
        $col = $startCol;
        while ($col !== $this->nextCol($endCol)) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
            $col = $this->nextCol($col);
        }
    }

    private function colFromNumber(int $num): string
    {
        $result = '';
        while ($num > 0) {
            $mod = ($num - 1) % 26;
            $result = chr(65 + $mod) . $result;
            $num = intdiv(($num - 1), 26);
        }
        return $result;
    }

    private function nextCol(string $col): string
    {
        return ++$col;
    }

    private function getExamineInfo(int $id): ?array
    {
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

        if (!$examine) return null;

        return (array)$examine;
    }

    private function buildScoreSummarySheet(Spreadsheet $spreadsheet, array $examine, $targets, array $firstItems): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('得分汇总');

        $counts = $this->getExamineAnswerCounts((int)$examine['id']);
        $isPerson = ($examine['template_type'] === 'person');
        $firstCols = $isPerson ? 2 : 1;

        $itemColsList = [];
        $totalItemCols = 0;
        foreach ($firstItems as $item) {
            $opts = $item['options'] ?? [];
            $cols = count($opts) + 1;
            $itemColsList[] = ['title' => $item['item_title'], 'options' => $opts, 'cols' => $cols];
            $totalItemCols += $cols;
        }
        $totalCols = $firstCols + $totalItemCols;
        $endCol = $this->colFromNumber($totalCols);

        // Row 1: 标题
        $sheet->setCellValue('A1', $examine['examine_name'] . '测评结果');
        $sheet->mergeCells("A1:{$endCol}1");
        $this->styleTitleRow($sheet, 1, $endCol);

        // Row 2: 测评日期
        $dateStr = '测评日期:' . date('Y-m-d', strtotime($examine['start_time'])) . ' - ' . date('Y-m-d', strtotime($examine['end_time']));
        $sheet->setCellValue('A2', $dateStr);
        $dateEnd = $this->colFromNumber(min($totalCols, 6));
        $sheet->mergeCells("A2:{$dateEnd}2");

        $colNum = 1;
        $dataRow = 4;
        $firstLabel = $isPerson ? '姓名' : '单位名称';
        $sheet->setCellValue([$colNum, $dataRow], $firstLabel);
        $sheet->mergeCells("{$this->colFromNumber($colNum)}4:{$this->colFromNumber($colNum)}5");
        $colNum++;
        if ($isPerson) {
            $sheet->setCellValue([$colNum, $dataRow], '职务');
            $sheet->mergeCells("{$this->colFromNumber($colNum)}4:{$this->colFromNumber($colNum)}5");
            $colNum++;
        }
        foreach ($itemColsList as $ic) {
            $startCol = $this->colFromNumber($colNum);
            $endItemCol = $this->colFromNumber($colNum + $ic['cols'] - 1);
            $sheet->setCellValue([$colNum, 4], $ic['title']);
            if ($ic['cols'] > 1) {
                $sheet->mergeCells("{$startCol}4:{$endItemCol}4");
            }
            $colNum += $ic['cols'];
        }

        $colNum = $firstCols + 1;
        foreach ($itemColsList as $ic) {
            foreach ($ic['options'] as $opt) {
                $sheet->setCellValue([$colNum, 5], $opt['option']);
                $colNum++;
            }
            $sheet->setCellValue([$colNum, 5], '得分');
            $colNum++;
        }

        $this->styleHeaderRow($sheet, 4, $endCol);
        $this->styleHeaderRow($sheet, 5, $endCol);

        $dataStartRow = 6;
        $cr = $dataStartRow;
        foreach ($targets as $target) {
            $items = $this->getVoteItemDetails((int)$examine['id'], (int)$target->id, (object)$examine);

            $colNum = 1;
            $sheet->setCellValue([$colNum, $cr], $target->target_name ?? '');
            $colNum++;
            if ($isPerson) {
                $sheet->setCellValue([$colNum, $cr], $target->position ?? '');
                $colNum++;
            }
            foreach ($items as $item) {
                foreach ($item['options'] as $opt) {
                    $count = ($opt['a_count'] ?? 0) + ($opt['b_count'] ?? 0);
                    $sheet->setCellValue([$colNum, $cr], $count > 0 ? $count : '');
                    $colNum++;
                }
                $ws = round($item['weighted_score'] ?? 0, 2);
                $sheet->setCellValue([$colNum, $cr], $ws);
                $colNum++;
            }
            $cr++;
        }

        $summaryRow = $cr;
        $sheet->setCellValue("A{$summaryRow}", "发出数:{$counts['total']}    收回数:{$counts['returned']}");
        $summaryEnd = $this->colFromNumber(min($totalCols, 8));
        $sheet->mergeCells("A{$summaryRow}:{$summaryEnd}{$summaryRow}");
        $sheet->getStyle("A{$summaryRow}")->getFont()->setBold(true);

        $dataEndRow = $cr - 1;
        $this->applyTableBorders($sheet, 4, $dataEndRow, $endCol);
        if ($dataStartRow <= $dataEndRow) {
            $dataColStart = $this->colFromNumber($firstCols + 1);
            $sheet->getStyle("{$dataColStart}{$dataStartRow}:{$endCol}{$dataEndRow}")
                ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        }

        $sheet->getColumnDimension('A')->setWidth(14);
        if ($isPerson) {
            $sheet->getColumnDimension('B')->setWidth(12);
        }
        $firstDataCol = $firstCols + 1;
        for ($i = $firstDataCol; $i <= $totalCols; $i++) {
            $sheet->getColumnDimension($this->colFromNumber($i))->setWidth(12);
        }

        $sheet->getStyle("4:5")->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(4)->setRowHeight(-1);
        $sheet->getRowDimension(5)->setRowHeight(-1);

        $freezeCell = $isPerson ? 'C6' : 'B6';
        $sheet->freezePane($freezeCell);
    }

    private function buildVoteSummarySheet(Spreadsheet $spreadsheet, array $examine, $targets, array $firstItems): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('得票汇总');

        $counts = $this->getExamineAnswerCounts((int)$examine['id']);
        $isPerson = ($examine['template_type'] === 'person');
        $firstCols = $isPerson ? 2 : 1;

        $itemColsList = [];
        $totalItemCols = 0;
        foreach ($firstItems as $item) {
            $opts = $item['options'] ?? [];
            $cols = count($opts);
            $itemColsList[] = ['title' => $item['item_title'], 'options' => $opts, 'cols' => $cols];
            $totalItemCols += $cols;
        }
        $totalCols = $firstCols + $totalItemCols;
        $endCol = $this->colFromNumber($totalCols);

        $sheet->setCellValue('A1', $examine['examine_name'] . '测评得票汇总');
        $sheet->mergeCells("A1:{$endCol}1");
        $this->styleTitleRow($sheet, 1, $endCol);

        $dateStr = '测评日期:' . date('Y-m-d', strtotime($examine['start_time'])) . ' - ' . date('Y-m-d', strtotime($examine['end_time']));
        $sheet->setCellValue('A2', $dateStr);
        $dateEnd = $this->colFromNumber(min($totalCols, 6));
        $sheet->mergeCells("A2:{$dateEnd}2");

        $colNum = 1;
        $dataRow = 4;
        $firstLabel = $isPerson ? '姓名' : '单位名称';
        $sheet->setCellValue([$colNum, $dataRow], $firstLabel);
        $sheet->mergeCells("{$this->colFromNumber($colNum)}4:{$this->colFromNumber($colNum)}5");
        $colNum++;
        if ($isPerson) {
            $sheet->setCellValue([$colNum, $dataRow], '职务');
            $sheet->mergeCells("{$this->colFromNumber($colNum)}4:{$this->colFromNumber($colNum)}5");
            $colNum++;
        }
        foreach ($itemColsList as $ic) {
            $startCol = $this->colFromNumber($colNum);
            $endItemCol = $this->colFromNumber($colNum + $ic['cols'] - 1);
            $sheet->setCellValue([$colNum, 4], $ic['title']);
            if ($ic['cols'] > 1) {
                $sheet->mergeCells("{$startCol}4:{$endItemCol}4");
            }
            $colNum += $ic['cols'];
        }

        $colNum = $firstCols + 1;
        foreach ($itemColsList as $ic) {
            foreach ($ic['options'] as $opt) {
                $sheet->setCellValue([$colNum, 5], $opt['option']);
                $colNum++;
            }
        }

        $this->styleHeaderRow($sheet, 4, $endCol);
        $this->styleHeaderRow($sheet, 5, $endCol);

        $dataStartRow = 6;
        $cr = $dataStartRow;
        foreach ($targets as $target) {
            $items = $this->getVoteItemDetails((int)$examine['id'], (int)$target->id, (object)$examine);

            $colNum = 1;
            $sheet->setCellValue([$colNum, $cr], $target->target_name ?? '');
            $colNum++;
            if ($isPerson) {
                $sheet->setCellValue([$colNum, $cr], $target->position ?? '');
                $colNum++;
            }
            foreach ($items as $item) {
                foreach ($item['options'] as $opt) {
                    $count = ($opt['a_count'] ?? 0) + ($opt['b_count'] ?? 0);
                    $sheet->setCellValue([$colNum, $cr], $count > 0 ? $count : '');
                    $colNum++;
                }
            }
            $cr++;
        }

        $summaryRow = $cr;
        $sheet->setCellValue("A{$summaryRow}", "发出数:{$counts['total']}    收回数:{$counts['returned']}");
        $summaryEnd = $this->colFromNumber(min($totalCols, 8));
        $sheet->mergeCells("A{$summaryRow}:{$summaryEnd}{$summaryRow}");
        $sheet->getStyle("A{$summaryRow}")->getFont()->setBold(true);

        $dataEndRow = $cr - 1;
        $this->applyTableBorders($sheet, 4, $dataEndRow, $endCol);
        if ($dataStartRow <= $dataEndRow) {
            $dataColStart = $this->colFromNumber($firstCols + 1);
            $sheet->getStyle("{$dataColStart}{$dataStartRow}:{$endCol}{$dataEndRow}")
                ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        }

        $sheet->getColumnDimension('A')->setWidth(14);
        if ($isPerson) {
            $sheet->getColumnDimension('B')->setWidth(12);
        }
        $firstDataCol = $firstCols + 1;
        for ($i = $firstDataCol; $i <= $totalCols; $i++) {
            $sheet->getColumnDimension($this->colFromNumber($i))->setWidth(12);
        }

        $sheet->getStyle("4:5")->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(4)->setRowHeight(-1);
        $sheet->getRowDimension(5)->setRowHeight(-1);

        $freezeCell = $isPerson ? 'C6' : 'B6';
        $sheet->freezePane($freezeCell);
    }

    public function batchExportTasks(Request $request, Response $response): Response
    {
        $input = json_decode((string)$request->getBody(), true);
        $taskIds = $input['task_ids'] ?? [];
        $type = $input['type'] ?? 'vote';

        if (empty($taskIds) || !is_array($taskIds)) {
            return error_response($response, 400, '请选择要导出的测评任务');
        }

        $zip = new \ZipArchive();
        $zipFileName = tempnam(sys_get_temp_dir(), 'batch_export_') . '.zip';

        if ($zip->open($zipFileName, \ZipArchive::CREATE) !== true) {
            return error_response($response, 500, '无法创建临时文件');
        }

        $generatedFiles = [];

        try {
            foreach ($taskIds as $taskId) {
                $taskId = (int)$taskId;
                $examine = $this->getExamineInfo($taskId);
                if (!$examine) continue;

                $targets = DB::table('examine_targets')
                    ->where('examine_id', $taskId)
                    ->orderBy('sort_order', 'asc')
                    ->get();

                if ($targets->isEmpty()) continue;

                $firstTargetId = (int)$targets[0]->id;
                $firstItems = $this->getVoteItemDetails($taskId, $firstTargetId, (object)$examine);
                if (empty($firstItems)) continue;

                $spreadsheet = new Spreadsheet();

                if ($type === 'vote') {
                    $this->buildVoteSummarySheet($spreadsheet, $examine, $targets, $firstItems);
                } else {
                    $this->buildScoreSummarySheet($spreadsheet, $examine, $targets, $firstItems);
                }

                $this->addReverseEvalSheet($spreadsheet, $taskId, $targets);

                $prefix = $type === 'vote' ? '得票汇总' : '得分汇总';
                $safeName = preg_replace('/[\\\\\/:*?"<>|]/', '_', $examine['examine_name']);
                $tempFile = sys_get_temp_dir() . "/{$prefix}_{$safeName}.xlsx";
                $writer = new Xlsx($spreadsheet);
                $writer->save($tempFile);
                $spreadsheet->disconnectWorksheets();

                $displayName = "{$prefix}_{$safeName}.xlsx";
                $zip->addFile($tempFile, $displayName);
                $generatedFiles[] = $tempFile;
            }

            $zip->close();

            foreach ($generatedFiles as $f) {
                if (file_exists($f)) @unlink($f);
            }

            $zipContent = file_get_contents($zipFileName);
            @unlink($zipFileName);

            $dateStr = date('Y-m-d');
            $response->getBody()->write($zipContent);
            return $response
                ->withHeader('Content-Type', 'application/zip')
                ->withHeader('Content-Disposition', "attachment; filename=\"批量导出_{$dateStr}.zip\"");

        } catch (\Throwable $e) {
            $zip->close();
            foreach ($generatedFiles as $f) {
                if (file_exists($f)) @unlink($f);
            }
            if (file_exists($zipFileName)) @unlink($zipFileName);
            return error_response($response, 500, '批量导出失败: ' . $e->getMessage());
        }
    }

    private function getExamineAnswerCounts(int $examineId): array
    {
        $total = DB::table('examine_users')->where('examine_id', $examineId)->count();
        $returned = DB::table('examine_answers')
            ->where('examine_id', $examineId)
            ->distinct('user_id')
            ->count('user_id');
        $validAnswers = DB::table('examine_answers')
            ->where('examine_id', $examineId)
            ->whereNotNull('answer_value')
            ->where('answer_value', '!=', '')
            ->distinct('user_id')
            ->count('user_id');
        return [
            'total' => $total,
            'returned' => $returned,
            'valid' => $validAnswers,
            'invalid' => $returned - $validAnswers,
        ];
    }

    private function addReverseEvalSheet($spreadsheet, int $examineId, $targets): void
    {
        $responses = DB::table('examine_answers')
            ->join('template_items', 'examine_answers.item_id', '=', 'template_items.id')
            ->join('users', 'examine_answers.user_id', '=', 'users.id')
            ->select(
                'examine_answers.target_id',
                'examine_answers.example_text',
                'template_items.item_title',
                'users.name as user_name'
            )
            ->where('examine_answers.examine_id', $examineId)
            ->where('template_items.is_reverse', 1)
            ->whereNotNull('examine_answers.example_text')
            ->get();

        if ($responses->isEmpty()) {
            return;
        }

        $targetNames = [];
        foreach ($targets as $target) {
            $targetNames[$target->id] = $target->target_name;
        }

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('反向测评内容');

        $sheet->setCellValue('A1', '测评对象');
        $sheet->setCellValue('B1', '测评项目');
        $sheet->setCellValue('C1', '评价人');
        $sheet->setCellValue('D1', '评价内容');

        $this->styleHeaderRow($sheet, 1, 'D');

        $row = 2;
        foreach ($responses as $resp) {
            $targetName = $targetNames[$resp->target_id] ?? '未知';
            $sheet->setCellValue("A{$row}", $targetName);
            $sheet->setCellValue("B{$row}", $resp->item_title);
            $sheet->setCellValue("C{$row}", $resp->user_name);
            $sheet->setCellValue("D{$row}", $resp->example_text);
            $row++;
        }

        $dataEndRow = $row - 1;
        $this->applyTableBorders($sheet, 1, $dataEndRow, 'D');

        $sheet->getColumnDimension('A')->setWidth(14);
        $sheet->getColumnDimension('B')->setWidth(24);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(60);

        $sheet->getStyle("A1:D{$dataEndRow}")
            ->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        $sheet->getStyle("A2:C{$dataEndRow}")
            ->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        if ($dataEndRow >= 2) {
            $sheet->getStyle("D2:D{$dataEndRow}")
                ->getAlignment()
                ->setWrapText(true)
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
        }

        for ($r = 2; $r <= $dataEndRow; $r++) {
            $sheet->getRowDimension($r)->setRowHeight(-1);
        }
    }
}
