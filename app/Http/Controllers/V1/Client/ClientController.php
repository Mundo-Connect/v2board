<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Protocols\ClashMeta;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $flag = $request->input('flag')
            ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);
        $user = $request->user;
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            if ($flag) {
                if (strpos($flag, 'sing') === false) {
                    foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                        $file = 'App\\Protocols\\' . basename($file, '.php');
                        $class = new $file($user, $servers);
                        if (strpos($flag, $class->flag) !== false) {
                            $protocolServers = $this->filterServersForFlag($servers, $class->flag);
                            $this->setSubscribeInfoToServers($protocolServers, $user);
                            $class = new $file($user, $protocolServers);
                            return $class->handle();
                        }
                    }
                }
                if (strpos($flag, 'sing') !== false) {
                    $protocolServers = $this->filterServersForFlag($servers, 'sing');
                    $version = null;
                    if (preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches)) {
                        $version = $matches[1];
                    }
                    if (!is_null($version) && $version >= '1.12.0') {
                        $class = new Singbox($user, $protocolServers);
                    } else {
                        $class = new SingboxOld($user, $protocolServers);
                    }
                    return $class->handle();
                }
            }
            $class = new General($user, $servers);
            return $class->handle();
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
