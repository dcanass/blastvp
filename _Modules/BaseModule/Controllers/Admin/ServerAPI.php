<?php
namespace Module\BaseModule\Controllers\Admin;

use Controllers\Panel;
use Module\BaseModule\BaseModule;
use Objects\Permissions\Roles\CustomerRole;
use Objects\Server;
use Objects\User;

class ServerAPI {
    public static function getServers() {
        $user = BaseModule::getUser();
        if ($user->getRole() == CustomerRole::class) {
            return [
                'code' => 403
            ];
        }
        $b      = Panel::getRequestInput();
        $page   = (int) $b['page'];
        $search = $b['search'] ?? null;
        $size   = 10;

        if (isset($b['userId'])) {
            $user        = new User($b['userId']);
            $serverCount = count($user->getServers());
            return ['data' => array_slice($user->getServers(), ($page - 1) * $size, $size, false),
                'paging' => [
                    'count'       => $serverCount,
                    'pageSize'    => $size,
                    'currentPage' => $page
                ]];
        }

        $addLike = "WHERE s.deletedAt IS NULL";
        $params  = [];
        if ($search != "") {
            $searchFields = [
                's.hostname', 's.vmid', 'u.username', 'ip4.ip', 'ip6.ip', 'ip4.mac', 'ip6.mac'
            ];
            $addLike      = "WHERE (" . implode(" LIKE ? OR ", $searchFields) . " LIKE ?) AND s.deletedAt IS NULL";
            $params       = array_fill(0, count($searchFields), "%{$search}%");
        }
        $qry         = <<<SQL
            SELECT 
                s.* 
            FROM servers s 
            LEFT OUTER JOIN ipam_4_addresses ip4 
                ON s.ip = ip4.id 
            LEFT OUTER JOIN ipam_6_addresses ip6 
                ON s.ip6 = ip6.id 
            LEFT OUTER JOIN users u 
                ON s.userid = u.id $addLike
        SQL;
        $servers     = Panel::getDatabase()->custom_query($qry, $params)->fetchAll(\PDO::FETCH_ASSOC);
        $serverCount = count($servers);
        return [
            'data'   => array_map(function ($e) {
                $s         = (new Server((object) $e))->serialize();
                $user      = (new User($s['userid']))->load();
                $s['user'] = $user;
                return $s;
            }, array_slice($servers, ($page - 1) * $size, $size, false)),
            'paging' => [
                'count'       => $serverCount,
                'pageSize'    => $size,
                'currentPage' => $page
            ]
        ];

    }
}