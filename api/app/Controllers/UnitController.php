<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

class UnitController
{
    public function index(Request $request, Response $response): Response
    {
        $page = (int)($request->getQueryParams()['page'] ?? 1);
        $perPage = (int)($request->getQueryParams()['per_page'] ?? 20);
        $keyword = trim($request->getQueryParams()['keyword'] ?? '');

        $query = DB::table('units');

        if ($keyword) {
            $query->where('unit_name', 'like', '%' . like_escape($keyword) . '%');
        }

        $total = $query->count();
        $units = $query->orderBy('sort_order', 'asc')
            ->orderBy('id', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return success_response($response, [
            'data' => $units,
            'pagination' => paginate($page, $perPage, $total),
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        
        $unit = DB::table('units')->where('id', $id)->first();
        
        if (!$unit) {
            return error_response($response, 404, '单位不存在');
        }

        return success_response($response, $unit);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $unitName = trim($data['unit_name'] ?? '');
        if (empty($unitName)) {
            return error_response($response, 400, '单位名称不能为空');
        }

        $exists = DB::table('units')->where('unit_name', $unitName)->exists();
        if ($exists) {
            return error_response($response, 400, '单位名称已存在');
        }

        $id = DB::table('units')->insertGetId([
            'unit_name' => $unitName,
            'unit_code' => trim($data['unit_code'] ?? ''),
            'sort_order' => (int)($data['sort_order'] ?? 0),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        log_operation(
            (int)$request->getAttribute('admin_id'),
            'units',
            'create',
            'unit',
            $id,
            ['unit_name' => $unitName],
            $request
        );

        return success_response($response, ['id' => $id], '单位创建成功');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();

        $unit = DB::table('units')->where('id', $id)->first();
        if (!$unit) {
            return error_response($response, 404, '单位不存在');
        }

        $unitName = trim($data['unit_name'] ?? '');
        if (!empty($unitName) && $unitName !== $unit->unit_name) {
            $exists = DB::table('units')
                ->where('unit_name', $unitName)
                ->where('id', '!=', $id)
                ->exists();
            
            if ($exists) {
                return error_response($response, 400, '单位名称已存在');
            }
        }

        $updateData = [];
        if (isset($data['unit_name'])) $updateData['unit_name'] = $unitName;
        if (isset($data['unit_code'])) $updateData['unit_code'] = trim($data['unit_code']);
        if (isset($data['sort_order'])) $updateData['sort_order'] = (int)$data['sort_order'];

        DB::table('units')->where('id', $id)->update($updateData);

        log_operation(
            (int)$request->getAttribute('admin_id'),
            'units',
            'update',
            'unit',
            $id,
            $updateData,
            $request
        );

        return success_response($response, null, '单位更新成功');
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];

        $unit = DB::table('units')->where('id', $id)->first();
        if (!$unit) {
            return error_response($response, 404, '单位不存在');
        }

        $hasUsers = DB::table('users')->where('unit_id', $id)->exists();
        if ($hasUsers) {
            return error_response($response, 400, '该单位下还有用户，无法删除');
        }

        DB::table('units')->delete($id);

        log_operation(
            (int)$request->getAttribute('admin_id'),
            'units',
            'delete',
            'unit',
            $id,
            ['deleted_unit_name' => $unit->unit_name],
            $request
        );

        return success_response($response, null, '单位删除成功');
    }

}
