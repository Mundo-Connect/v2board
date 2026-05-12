<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerMx;
use Illuminate\Http\Request;
use ParagonIE_Sodium_Compat as SodiumCompat;
use App\Utils\Helper;

class MxController extends Controller
{
    public function save(Request $request)
    {
        $params = $request->validate([
            'group_id' => 'required|array',
            'route_id' => 'nullable|array',
            'name' => 'required',
            'parent_id' => 'nullable|integer',
            'host' => 'required',
            'listen_ip' => 'nullable',
            'port' => 'required',
            'server_port' => 'required',
            'tls' => 'required|in:0,1,2',
            'tls_settings' => 'nullable|array',
            'network' => 'required|in:tcp,ws,grpc,httpupgrade,xhttp,mc1,mundordp',
            'network_settings' => 'nullable|array',
            'server_name' => 'nullable',
            'allow_insecure' => 'nullable|in:0,1',
            'tags' => 'nullable|array',
            'rate' => 'required|numeric',
            'show' => 'nullable|in:0,1',
            'sort' => 'nullable'
        ]);

        if (isset($params['tls']) && (int)$params['tls'] === 2) {
            $keyPair = SodiumCompat::crypto_box_keypair();
            $params['tls_settings'] = $params['tls_settings'] ?? [];
            if (!isset($params['tls_settings']['public_key'])) {
                $params['tls_settings']['public_key'] = Helper::base64EncodeUrlSafe(SodiumCompat::crypto_box_publickey($keyPair));
            }
            if (!isset($params['tls_settings']['private_key'])) {
                $params['tls_settings']['private_key'] = Helper::base64EncodeUrlSafe(SodiumCompat::crypto_box_secretkey($keyPair));
            }
            if (!isset($params['tls_settings']['short_id'])) {
                $params['tls_settings']['short_id'] = substr(sha1($params['tls_settings']['private_key']), 0, 8);
            }
            if (!isset($params['tls_settings']['server_port'])) {
                $params['tls_settings']['server_port'] = "443";
            }
        }

        if (isset($params['network_settings'])) {
            $ns = $params['network_settings'];
            foreach (['acceptProxyProtocol', 'useTLSCertificate'] as $field) {
                if (isset($ns[$field])) {
                    $ns[$field] = filter_var($ns[$field], FILTER_VALIDATE_BOOLEAN);
                }
            }
            if (($params['network'] ?? null) === 'xhttp' && isset($ns['extra']) && is_array($ns['extra'])) {
                $extra = $ns['extra'];
                foreach (['xPaddingObfsMode', 'noGRPCHeader', 'noSSEHeader'] as $field) {
                    if (isset($extra[$field])) {
                        $extra[$field] = filter_var($extra[$field], FILTER_VALIDATE_BOOLEAN);
                    }
                }
                if (isset($extra['scMaxBufferedPosts'])) {
                    $extra['scMaxBufferedPosts'] = (int)$extra['scMaxBufferedPosts'];
                }
                if (isset($extra['xmux']) && is_array($extra['xmux'])) {
                    $xmux = $extra['xmux'];
                    if (isset($xmux['hKeepAlivePeriod'])) {
                        $xmux['hKeepAlivePeriod'] = (int)$xmux['hKeepAlivePeriod'];
                    }
                    $extra['xmux'] = $xmux;
                }
                if (isset($extra['downloadSettings']) && is_array($extra['downloadSettings'])) {
                    $downloadSettings = $extra['downloadSettings'];
                    if (isset($downloadSettings['port'])) {
                        $downloadSettings['port'] = (int)$downloadSettings['port'];
                    }
                    $extra['downloadSettings'] = $downloadSettings;
                }
                $ns['extra'] = $extra;
            }
            $params['network_settings'] = $ns;
        }

        if ($request->input('id')) {
            $server = ServerMx::find($request->input('id'));
            if (!$server) {
                abort(500, '服务器不存在');
            }
            try {
                $server->update($params);
            } catch (\Exception $e) {
                abort(500, '保存失败');
            }
            return response([
                'data' => true
            ]);
        }

        if (!ServerMx::create($params)) {
            abort(500, '创建失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        if ($request->input('id')) {
            $server = ServerMx::find($request->input('id'));
            if (!$server) {
                abort(500, '节点ID不存在');
            }
        }
        return response([
            'data' => $server->delete()
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'show' => 'in:0,1'
        ], [
            'show.in' => '显示状态格式不正确'
        ]);
        $params = $request->only([
            'show',
        ]);

        $server = ServerMx::find($request->input('id'));

        if (!$server) {
            abort(500, '该服务器不存在');
        }
        try {
            $server->update($params);
        } catch (\Exception $e) {
            abort(500, '保存失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function copy(Request $request)
    {
        $server = ServerMx::find($request->input('id'));
        $server->show = 0;
        if (!$server) {
            abort(500, '服务器不存在');
        }
        if (!ServerMx::create($server->toArray())) {
            abort(500, '复制失败');
        }

        return response([
            'data' => true
        ]);
    }
}
