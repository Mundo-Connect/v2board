<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $flag = $request->input('flag');
        $flag = strtolower(is_string($flag) ? $flag : ($request->userAgent() ?? ''));
        $user = $request->user;
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            if (strpos($flag, 'sing') !== false) {
                $isModern = preg_match('/sing-box[\/\s]+v?(\d+\.\d+(?:\.\d+)?)/', $flag, $matches)
                    && version_compare($matches[1], '1.12.0', '>=');
                $protocol = $isModern ? Singbox::class : SingboxOld::class;
                $servers = $this->filterServersForFlag($servers, 'sing');
                return (new $protocol($user, $servers))->handle();
            }

            if ($flag !== '') {
                foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                    $protocol = 'App\\Protocols\\' . basename($file, '.php');
                    $protocolFlag = get_class_vars($protocol)['flag'];
                    if (strpos($flag, $protocolFlag) !== false) {
                        $servers = $this->filterServersForFlag($servers, $protocolFlag);
                        $this->setSubscribeInfoToServers($servers, $user);
                        return (new $protocol($user, $servers))->handle();
                    }
                }
            }

            return (new General($user, $servers))->handle();
        }
    }

    private function filterServersForFlag(array $servers, string $flag): array
    {
        if ($this->shouldExposeMxAndMc1($flag)) {
            return $servers;
        }

        return array_values(array_filter($servers, function ($server) {
            if (($server['type'] ?? null) === 'mx') {
                return false;
            }
            if (in_array(($server['network'] ?? null), ['mc1', 'mundordp'], true)) {
                return false;
            }
            if (($server['type'] ?? null) !== 'v2node') {
                return true;
            }
            if (($server['protocol'] ?? null) === 'mx') {
                return false;
            }
            return ($server['network'] ?? null) !== 'mc1';
        }));
    }

    private function shouldExposeMxAndMc1(string $flag): bool
    {
        return in_array($flag, [
            'general',
            'passwall',
            'sagernet',
            'ssrplus',
            'v2rayn',
            'v2rayng',
            'v2raytun',
        ], true);
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic}",
        ]));
    }
}
