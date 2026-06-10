<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

class TemplateController
{
    public function index(Request $request, Response $response): Response
    {
        $page = (int)($request->getQueryParams()['page'] ?? 1);
        $perPage = (int)($request->getQueryParams()['per_page'] ?? 20);
        $templateType = $request->getQueryParams()['type'] ?? null;
        $keyword = trim($request->getQueryParams()['keyword'] ?? '');

        $query = DB::table('templates')
            ->leftJoin('admins', 'templates.created_by', '=', 'admins.id')
            ->select(
                'templates.*',
                'admins.real_name as creator_name'
            );

        if ($templateType && in_array($templateType, ['leader', 'team'])) {
            $query->where('templates.template_type', $templateType);
        }

        if ($keyword) {
            $query->where('template_name', 'like', '%' . like_escape($keyword) . '%');
        }

        $total = $query->count();
        $templates = $query->orderBy('templates.id', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return success_response($response, [
            'data' => $templates,
            'pagination' => paginate($page, $perPage, $total),
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        
        $template = DB::table('templates')->where('id', $id)->first();
        
        if (!$template) {
            return error_response($response, 404, '模板不存在');
        }

        $items = DB::table('template_items')
            ->where('template_id', $id)
            ->orderBy('sort_order', 'asc')
            ->get()
            ->map(function ($item) {
                $item->options = json_decode($item->options ?? '[]', true);
                $item->reverse_options = json_decode($item->reverse_options ?? '[]', true);
                return $item;
            });

        $template->items = $items;

        return success_response($response, $template);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        if (empty($data) || !is_array($data)) {
            $rawBody = (string) $request->getBody();
            if (!empty($rawBody)) {
                $data = json_decode($rawBody, true) ?? [];
            }
        }

        $templateName = trim($data['template_name'] ?? '');
        $templateType = $data['template_type'] ?? '';
        $items = $data['items'] ?? [];

        if (empty($templateName)) {
            return error_response($response, 400, '模板名称不能为空');
        }

        if (!in_array($templateType, ['leader', 'team'])) {
            return error_response($response, 400, '模板类型无效（必须是leader或team）');
        }

        if (empty($items) || !is_array($items)) {
            return error_response($response, 400, '至少需要添加一个指标项');
        }

        foreach ($items as $index => $item) {
            $itemTitle = trim($item['item_title'] ?? '');
            $itemType = $item['item_type'] ?? '';

            if (empty($itemTitle)) {
                return error_response($response, 400, "第" . ($index + 1) . "个指标项标题不能为空");
            }

            if (!in_array($itemType, ['radio', 'checkbox', 'textarea'])) {
                return error_response($response, 400, "第" . ($index + 1) . "个指标项类型无效");
            }

            if (in_array($itemType, ['radio', 'checkbox'])) {
                $options = $item['options'] ?? [];
                if (empty($options) || !is_array($options)) {
                    return error_response($response, 400, "第" . ($index + 1) . "个指标项需要配置选项");
                }
                
                foreach ($options as $optIdx => $option) {
                    $optText = is_array($option) ? ($option['text'] ?? '') : $option;
                    if (empty(trim($optText))) {
                        return error_response($response, 400, "第" . ($index + 1) . "个指标项的第" . ($optIdx + 1) . "个选项不能为空");
                    }
                }
            }

            if ($itemType === 'checkbox') {
                $minSelect = isset($item['min_select']) ? (int)$item['min_select'] : 0;
                $maxSelect = isset($item['max_select']) ? (int)$item['max_select'] : count($item['options']);
                
                if ($minSelect < 0 || $minSelect > count($item['options'])) {
                    return error_response($response, 400, "第" . ($index + 1) . "个指标项的最少选择数无效");
                }
                
                if ($maxSelect < $minSelect || $maxSelect > count($item['options'])) {
                    return error_response($response, 400, "第" . ($index + 1) . "个指标项的最多选择数无效");
                }
            }

            if (!empty($item['is_reverse']) && $itemType === 'radio') {
                $reverseOptions = $item['reverse_options'] ?? [];
                if (empty($reverseOptions) || !is_array($reverseOptions)) {
                    return error_response($response, 400, "反向测评标记了，但未指定哪些选项为负面选项");
                }
            }
        }

        DB::beginTransaction();

        try {
            $templateId = DB::table('templates')->insertGetId([
                'template_name' => $templateName,
                'template_type' => $templateType,
                'description' => trim($data['description'] ?? ''),
                'is_default' => (int)($data['is_default'] ?? 0),
                'status' => 1,
                'created_by' => (int)$request->getAttribute('admin_id'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            foreach ($items as $index => $item) {
                DB::table('template_items')->insert([
                    'template_id' => $templateId,
                    'item_title' => trim($item['item_title']),
                    'item_description' => trim($item['item_description'] ?? ''),
                    'short_name' => trim($item['short_name'] ?? ''),
                    'item_type' => $item['item_type'],
                    'options' => json_encode($item['options'] ?? [], JSON_UNESCAPED_UNICODE),
                    'min_select' => isset($item['min_select']) ? (int)$item['min_select'] : null,
                    'max_select' => isset($item['max_select']) ? (int)$item['max_select'] : null,
                    'is_reverse' => !empty($item['is_reverse']) ? 1 : 0,
                    'reverse_options' => !empty($item['reverse_options']) 
                        ? json_encode($item['reverse_options'], JSON_UNESCAPED_UNICODE) 
                        : null,
                    'required_example' => !empty($item['required_example']) ? 1 : 0,
                    'weight' => isset($item['weight']) ? (int)$item['weight'] : 1,
                    'is_scoring' => !isset($item['is_scoring']) || !empty($item['is_scoring']) ? 1 : 0,
                    'sort_order' => $index,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        log_operation((int)$request->getAttribute('admin_id'), 'templates', 'create', 'template', $templateId, null, $request);

        return success_response($response, ['id' => $templateId], '模板创建成功');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();

        if (empty($data) || !is_array($data)) {
            $rawBody = (string) $request->getBody();
            if (!empty($rawBody)) {
                $data = json_decode($rawBody, true) ?? [];
            }
        }

        $template = DB::table('templates')->where('id', $id)->first();
        if (!$template) {
            return error_response($response, 404, '模板不存在');
        }

        $updateData = [];
        if (isset($data['template_name'])) {
            $updateData['template_name'] = trim($data['template_name']);
        }
        if (isset($data['description'])) {
            $updateData['description'] = trim($data['description']);
        }
        if (isset($data['is_default'])) {
            $updateData['is_default'] = (int)$data['is_default'];
        }
        if (isset($data['status'])) {
            $updateData['status'] = (int)$data['status'];
        }

        if (!empty($updateData)) {
            DB::table('templates')->where('id', $id)->update($updateData);
        }

        if (isset($data['items']) && is_array($data['items'])) {
            DB::transaction(function () use ($id, $data) {
                DB::table('template_items')->where('template_id', $id)->delete();

                foreach ($data['items'] as $index => $item) {
                    DB::table('template_items')->insert([
                        'template_id' => $id,
                        'item_title' => trim($item['item_title']),
                        'item_description' => trim($item['item_description'] ?? ''),
                        'short_name' => trim($item['short_name'] ?? ''),
                        'item_type' => $item['item_type'],
                        'options' => json_encode($item['options'] ?? [], JSON_UNESCAPED_UNICODE),
                        'min_select' => isset($item['min_select']) ? (int)$item['min_select'] : null,
                        'max_select' => isset($item['max_select']) ? (int)$item['max_select'] : null,
                        'is_reverse' => !empty($item['is_reverse']) ? 1 : 0,
                        'reverse_options' => !empty($item['reverse_options'])
                            ? json_encode($item['reverse_options'], JSON_UNESCAPED_UNICODE)
                            : null,
                        'required_example' => !empty($item['required_example']) ? 1 : 0,
                        'weight' => isset($item['weight']) ? (int)$item['weight'] : 1,
                        'is_scoring' => !isset($item['is_scoring']) || !empty($item['is_scoring']) ? 1 : 0,
                        'sort_order' => $index,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            });
        }

        log_operation((int)$request->getAttribute('admin_id'), 'templates', 'update', 'template', $id, null, $request);

        return success_response($response, null, '模板更新成功');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        $template = DB::table('templates')->where('id', $id)->first();
        if (!$template) {
            return error_response($response, 404, '模板不存在');
        }

        $usedInExamines = DB::table('examines')->where('template_id', $id)->exists();
        if ($usedInExamines) {
            return error_response($response, 400, '该模板已被使用，无法删除');
        }

        DB::table('template_items')->where('template_id', $id)->delete();
        DB::table('templates')->where('id', $id)->delete();

        log_operation((int)$request->getAttribute('admin_id'), 'templates', 'delete', 'template', $id, null, $request);

        return success_response($response, null, '模板删除成功');
    }

    public function duplicate(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        $template = DB::table('templates')->where('id', $id)->first();
        if (!$template) {
            return error_response($response, 404, '原模板不存在');
        }

        $items = DB::table('template_items')
            ->where('template_id', $id)
            ->orderBy('sort_order', 'asc')
            ->get();

        DB::beginTransaction();

        try {
            $newTemplateId = DB::table('templates')->insertGetId([
                'template_name' => $template->template_name . ' (副本)',
                'template_type' => $template->template_type,
                'description' => $template->description,
                'is_default' => 0,
                'status' => 1,
                'created_by' => (int)$request->getAttribute('admin_id'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            foreach ($items as $item) {
                DB::table('template_items')->insert([
                    'template_id' => $newTemplateId,
                    'item_title' => $item->item_title,
                    'item_description' => $item->item_description,
                    'item_type' => $item->item_type,
                    'options' => $item->options,
                    'min_select' => $item->min_select,
                    'max_select' => $item->max_select,
                    'is_reverse' => $item->is_reverse,
                    'reverse_options' => $item->reverse_options,
                    'required_example' => $item->required_example,
                    'weight' => $item->weight ?? 1,
                    'sort_order' => $item->sort_order,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        log_operation((int)$request->getAttribute('admin_id'), 'templates', 'duplicate', 'template', $newTemplateId, [
            'from_template_id' => $id,
        ], $request);

        return success_response($response, ['id' => $newTemplateId], '模板复制成功');
    }

}
