<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

class ImportController
{
    public function downloadTemplate(Request $request, Response $response): Response
    {
        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('导入模板');

            $sheet->setCellValue('A1', '序号');
            $sheet->setCellValue('B1', '单位名称');
            $sheet->setCellValue('C1', '姓名');
            $sheet->setCellValue('D1', '手机号');
            $sheet->setCellValue('E1', '职务');
            $sheet->setCellValue('F1', '类型');

            $sheet->setCellValue('A2', 1);
            $sheet->setCellValue('B2', '示例单位');
            $sheet->setCellValue('C2', '张三');
            $sheet->setCellValue('D2', '13800138000');
            $sheet->setCellValue('E2', '科长');
            $sheet->setCellValue('F2', 'A');

            $sheet->getStyle('A1:F1')->getFont()->setBold(true);
            $sheet->getStyle('A1:F1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $sheet->getColumnDimension('A')->setWidth(8);
            $sheet->getColumnDimension('B')->setWidth(16);
            $sheet->getColumnDimension('C')->setWidth(10);
            $sheet->getColumnDimension('D')->setWidth(18);
            $sheet->getColumnDimension('E')->setWidth(12);
            $sheet->getColumnDimension('F')->setWidth(8);

            $styleArray = [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
            ];
            $sheet->getStyle('A1:F2')->applyFromArray($styleArray);

            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');

            $tempFile = sys_get_temp_dir() . '/' . uniqid('tpl_') . '.xlsx';
            if (!is_dir(dirname($tempFile))) {
                mkdir(dirname($tempFile), 0750, true);
            }

            $writer->save($tempFile);

            if (!file_exists($tempFile) || filesize($tempFile) === 0) {
                @unlink($tempFile);
                return error_response($response, 500, '生成Excel模板失败: 文件为空');
            }

            $content = file_get_contents($tempFile);
            @unlink($tempFile);

            $response->getBody()->write($content);

            return $response
                ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->withHeader('Content-Disposition', 'attachment; filename="' . rawurlencode('单位及用户导入模板') . '.xlsx"')
                ->withHeader('Cache-Control', 'no-cache, must-revalidate')
                ->withHeader('Pragma', 'public')
                ->withHeader('Content-Length', (string) strlen($content));

        } catch (\Throwable $e) {
            error_log('[DEBUG] downloadTemplate error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return error_response($response, 500, '生成Excel模板失败: ' . $e->getMessage());
        }
    }

    public function importUsersUnits(Request $request, Response $response): Response
    {
        $uploadedFiles = $request->getUploadedFiles();

        if (!isset($uploadedFiles['file']) || $uploadedFiles['file']->getError() !== UPLOAD_ERR_OK) {
            return error_response($response, 400, '请上传Excel文件');
        }

        $file = $uploadedFiles['file'];

        $allowedExtensions = ['xlsx', 'xls'];
        $fileExtension = strtolower(pathinfo($file->getClientFilename(), PATHINFO_EXTENSION));

        if (!in_array($fileExtension, $allowedExtensions)) {
            return error_response($response, 400, '仅支持xlsx/xls格式的Excel文件');
        }

        $allowedMimes = [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
        ];
        if ($allowedMimes[$fileExtension] !== null) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detectedMime = $finfo->file($file->getStream()->getMetadata('uri'));
            if ($detectedMime && !str_starts_with($detectedMime, explode('/', $allowedMimes[$fileExtension])[0] . '/')) {
                return error_response($response, 400, '文件类型与扩展名不匹配');
            }
        }

        if ($file->getSize() > 10 * 1024 * 1024) {
            return error_response($response, 400, '文件大小超过限制（最大10MB）');
        }

        try {
            $tempPath = tempnam(sys_get_temp_dir(), 'excel_import_');
            $file->moveTo($tempPath);

            $spreadsheet = IOFactory::load($tempPath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            unlink($tempPath);

            if (count($rows) < 2) {
                return error_response($response, 400, 'Excel文件内容为空或只有表头');
            }

            $headers = array_map('trim', array_shift($rows));
            $requiredColumns = ['序号', '单位名称', '姓名', '手机号', '职务', '类型'];

            foreach ($requiredColumns as $col) {
                if (!in_array($col, $headers)) {
                    return error_response($response, 400, "Excel缺少必需列: {$col}。要求格式：序号、单位名称、姓名、手机号、职务、类型");
                }
            }

            $unitNameIdx = array_search('单位名称', $headers);
            $nameIdx = array_search('姓名', $headers);
            $phoneIdx = array_search('手机号', $headers);
            $positionIdx = array_search('职务', $headers);
            $userTypeIdx = array_search('类型', $headers);

            $successCount = 0;
            $errorCount = 0;
            $errors = [];
            $unitCache = [];
            $phoneSet = [];

            DB::beginTransaction();

            try {
                foreach ($rows as $rowNum => $row) {
                    $lineNo = $rowNum + 2;

                    $unitName = trim($row[$unitNameIdx] ?? '');
                    $name = trim($row[$nameIdx] ?? '');
                    $phone = trim($row[$phoneIdx] ?? '');
                    $position = trim($row[$positionIdx] ?? '');
                    $userType = strtoupper(trim($row[$userTypeIdx] ?? ''));

                    if (empty($unitName) && empty($name) && empty($phone)) {
                        continue;
                    }

                    $unitNameLower = mb_strtolower($unitName, 'UTF-8');
                    if (str_contains($unitNameLower, '示例') || str_contains($unitNameLower, 'sample') || str_contains($unitNameLower, 'example')) {
                        continue;
                    }

                    if (empty($userType)) {
                        $errorCount++;
                        $errors[] = "第{$lineNo}行: 类型不能为空";
                        continue;
                    }

                    if (empty($unitName)) {
                        $errorCount++;
                        $errors[] = "第{$lineNo}行: 单位名称不能为空";
                        continue;
                    }

                    if (empty($name)) {
                        $errorCount++;
                        $errors[] = "第{$lineNo}行: 姓名不能为空";
                        continue;
                    }

                    if (empty($phone)) {
                        $errorCount++;
                        $errors[] = "第{$lineNo}行: 手机号不能为空";
                        continue;
                    }

                    if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
                        $errorCount++;
                        $errors[] = "第{$lineNo}行: 手机号格式错误（{$phone}）";
                        continue;
                    }

                    if (!in_array($userType, ['A', 'B'])) {
                        $errorCount++;
                        $errors[] = "第{$lineNo}行: 类型只能为 A 或 B（当前值：{$userType}）";
                        continue;
                    }

                    if (isset($phoneSet[$phone])) {
                        $errorCount++;
                        $errors[] = "第{$lineNo}行: 手机号重复（{$phone}），已跳过";
                        continue;
                    }
                    $phoneSet[$phone] = true;

                    if (!isset($unitCache[$unitName])) {
                        $unit = DB::table('units')->where('unit_name', $unitName)->first();

                        if (!$unit) {
                            $newUnitId = DB::table('units')->insertGetId([
                                'unit_name' => $unitName,
                                'created_at' => date('Y-m-d H:i:s'),
                            ]);
                            $unitCache[$unitName] = $newUnitId;
                        } else {
                            $unitCache[$unitName] = $unit->id;
                        }
                    }

                    $currentUnitId = $unitCache[$unitName];

                    $existingUser = DB::table('users')->where('phone', $phone)->first();
                    if ($existingUser) {
                        DB::table('users')
                            ->where('id', $existingUser->id)
                            ->update([
                                'unit_id' => $currentUnitId,
                                'name' => $name,
                                'real_name' => $name,
                                'phone' => $phone,
                                'position' => $position,
                                'user_type' => $userType,
                                'source' => 'admin',
                                'status' => 1,
                                'updated_at' => date('Y-m-d H:i:s'),
                            ]);
                    } else {
                        DB::table('users')->insert([
                            'unit_id' => $currentUnitId,
                            'name' => $name,
                            'real_name' => $name,
                            'phone' => $phone,
                            'position' => $position,
                            'user_type' => $userType,
                            'source' => 'admin',
                            'status' => 1,
                            'created_at' => date('Y-m-d H:i:s'),
                        ]);
                    }

                    $successCount++;
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

            log_operation(
                (int)$request->getAttribute('admin_id'),
                'import',
                'import_users_units',
                null,
                null,
                [
                    'success_count' => $successCount,
                    'error_count' => $errorCount,
                    'total_errors' => count($errors),
                ],
                $request
            );

            return success_response($response, [
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'errors' => array_slice($errors, 0, 20),
            ], "导入完成：成功{$successCount}条，失败{$errorCount}条");
        } catch (\Exception $e) {
            return error_response($response, 500, '导入失败: ' . $e->getMessage());
        }
    }
}
