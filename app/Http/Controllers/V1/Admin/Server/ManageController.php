<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ManageController extends Controller
{
    public function getNodes(Request $request)
    {
        $serverService = new ServerService();
        return response([
            'data' => $serverService->getAllServers()
        ]);
    }

    public function sort(Request $request)
    {
        ini_set('post_max_size', '5m');
        $types = [
            'shadowsocks',
            'vmess',
            'vless',
            'trojan',
            'tuic',
            'hysteria',
            'anytls',
            'mx',
            'v2node'
        ];
        $params = $request->only($types);
        if (empty($params)) {
            $params = array_intersect_key($_POST, array_flip($types));
        }
        Validator::make($params, [
            '*' => 'array',
            '*.*' => 'required|integer|min:0'
        ])->validate();
        DB::transaction(function () use ($params) {
            foreach ($params as $k => $v) {
                $model = 'App\\Models\\Server' . ucfirst($k);
                foreach ($v as $id => $sort) {
                    if (!ctype_digit((string) $id) || (int) $id < 1) {
                        abort(422, '节点ID无效');
                    }
                    $server = $model::find($id);
                    if (!$server) {
                        abort(422, '节点不存在，请刷新后重试');
                    }
                    if (!$server->update(['sort' => $sort])) {
                        abort(500, '保存失败');
                    }
                }
            }
        });
        return response([
            'data' => true
        ]);
    }
}
