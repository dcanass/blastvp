<?php

namespace Module\BaseModule\Controllers\IPAM;

use Controllers\Panel;
use Module\BaseModule\BaseModule;
use Module\BaseModule\Controllers\ClusterHelper;
use Objects\Permissions\Roles\AdminRole;

class IPAM {

    public static $DEP_OPTIONS = [
        'dualstack' => [
            'lang' => "ipam_dual_stack"
        ],
        'ip4'       => [
            'lang' => "ipam_4_only"
        ],
        'ip6'       => [
            'lang' => "ipam_6_only"
        ],
        'none'      => [
            'lang' => 'ipam_none'
        ]
    ];

    public const DUALSTACK = 'dualstack';
    public const IP4       = 'ip4';
    public const IP6       = 'ip6';

    public static function adminList() {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }

        $ipv4 = Panel::getDatabase()->custom_query(self::fetchRange(4))->fetchAll(\PDO::FETCH_ASSOC);
        $ipv6 = Panel::getDatabase()->custom_query(self::fetchRange(6))->fetchAll(\PDO::FETCH_ASSOC);

        $nodes = array_map(function ($e) {
            return $e['node'];
        }, ClusterHelper::getNodes());

        $users = Panel::getDatabase()->fetch_all('users');

        Panel::compile('_views/_pages/admin/ipam/landing.html', array_merge(
            [
                'nodes' => $nodes,
                'ipv4'  => $ipv4,
                'ipv6'  => $ipv6,
                'users' => $users
            ],
            Panel::getLanguage()->getPage('global'),
            Panel::getLanguage()->getPage('ipam')
        ));
    }

    /**
     * list all IPAM ranges
     *
     * @return array
     */
    public static function listApi() {
        $user = BaseModule::getUser();
        if ($user->getPermission() < 2) {
            return [
                'error' => true,
                'code'  => 403
            ];
        }
        $ipv4 = Panel::getDatabase()->custom_query(self::fetchRange(4))->fetchAll(\PDO::FETCH_ASSOC);
        $ipv6 = Panel::getDatabase()->custom_query(self::fetchRange(6))->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'ip4' => $ipv4,
            'ip6' => $ipv6,
        ];
    }

    public static function singleRange($type, $id) {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }

        if ($type == 4) {
            $range = Panel::getDatabase()->custom_query(<<<SQL
                SELECT 
                    ipam_4.start, ipam_4.end, ipam_4.subnet, ipam_4_addresses.*
                FROM
                    ipam_4
                        LEFT JOIN
                    ipam_4_addresses ON ipam_4.id = ipam_4_addresses.fk_ipam
                WHERE
                    fk_ipam = ?
            SQL, ['id' => $id])->fetchAll(\PDO::FETCH_ASSOC);
        } else {
            $range = Panel::getDatabase()->custom_query(<<<SQL
                SELECT 
                    ipam_6.network, ipam_6.prefix, ipam_6_addresses.*
                FROM
                    ipam_6
                        LEFT JOIN
                    ipam_6_addresses ON ipam_6.id = ipam_6_addresses.fk_ipam
                WHERE
                    fk_ipam = ?
            SQL, ['id' => $id])->fetchAll(\PDO::FETCH_ASSOC);
        }

        // join servers and/or containers
        $containers = [];
        if (Panel::getModule('LXCModule'))
            $containers = Panel::getDatabase()->custom_query("SELECT * FROM containers WHERE deletedAt IS NULL")->fetchAll(\PDO::FETCH_ASSOC);
        $servers = Panel::getDatabase()->custom_query("SELECT * FROM servers WHERE deletedAt IS NULL")->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($range as &$ip) {
            $app_url      = constant('APP_URL');
            $ip['server'] = Panel::getLanguage()->get('ipam', 'm_ip_unused');
            // servers have priority, so we first check containers to overwrite when we check server
            foreach ($containers as $c) {
                if ($c['ip'] == $ip['id'] && $type == 4 || isset($c['ip6']) && $c['ip6'] == $ip['id'] && $type == 6) {
                    $ip['server'] = "<a target=\"_blank\" href=\"{$app_url}container/{$c['id']}\">{$c['hostname']}</a>";
                }
            }
            foreach ($servers as $s) {
                if ($s['ip'] == $ip['id'] && $type == 4 || $s['ip6'] == $ip['id'] && $type == 6) {
                    $ip['server'] = "<a target=\"_blank\" href=\"{$app_url}server/{$s['id']}\">{$s['hostname']}</a>";
                }
            }
        }

        Panel::compile('_views/_pages/admin/ipam/single_range.html', array_merge([
            'range'      => $range,
            'ipam_title' => $type == 4 ?
                "{$range[0]['start']} - {$range[0]['end']}" :
                "{$range[0]['network']}/{$range[0]['prefix']}",
            'type'       => $type,
            'networkId'  => $id
        ], Panel::getLanguage()->getPage('ipam'), Panel::getLanguage()->getPage('global')));
    }

    public static function updateMac($type, $id) {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }

        $ip = Panel::getDatabase()->fetch_single_row("ipam_{$type}_addresses", 'id', $id);
        if (!$ip)
            return ['success' => false];

        Panel::getDatabase()->update("ipam_{$type}_addresses", [
            'mac' => $_POST['mac']
        ], 'id', $id);

        return ['success' => true];
    }


    public static function deleteIP($type, $id) {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }

        $ip = Panel::getDatabase()->fetch_single_row("ipam_{$type}_addresses", 'id', $id);
        if ($ip->in_use) {
            return [
                'success' => false,
                'message' => Panel::getLanguage()->get('ipam', 'delete_ip_in_use')
            ];
        }

        Panel::getDatabase()->delete("ipam_{$type}_addresses", 'id', $id);

        return ['success' => true];
    }

    public static function deleteRange($type, $id) {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }

        $range = Panel::getDatabase()->custom_query(self::fetchRange($type, "WHERE ipam_{$type}.id = $id"))->fetchAll(\PDO::FETCH_OBJ)[0];

        if ($range->used > 0) {
            return [
                'success' => false,
                'message' => Panel::getLanguage()->get('ipam', 'delete_range_in_use')
            ];
        }

        // range doesn't has any used ips
        Panel::getDatabase()->delete("ipam_{$type}", 'id', $id);
        Panel::getDatabase()->delete("ipam_{$type}_addresses", "fk_ipam", $id);
        return ['success' => true];
    }

    public static function create() {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }

        $scope  = $_POST['scope'];
        $nodes  = $_POST['nodes'] ?? null;
        $userId = $_POST['userId'] ?? null;

        $type = $_POST['type'];
        if ($type == "4") {
            // add a new ipv4 subnet
            $start   = $_POST['start'];
            $end     = $_POST['end'];
            $subnet  = $_POST['subnet'];
            $gateway = $_POST['gateway'];

            $result = Panel::getDatabase()->insert('ipam_4', [
                'start'   => $start,
                'end'     => $end,
                'subnet'  => $subnet,
                'gateway' => $gateway,
                'scope'   => $scope,
                'nodes'   => $nodes,
                'userId'  => $userId
            ]);
            $id     = Panel::getDatabase()->get_last_id();

            // take start address, convert to long, add until it's <= end-address as long
            $start_long = ip2long($start);
            $end_long   = ip2long($end);

            for ($i = $start_long; $i <= $end_long; $i++) {
                $ip = long2ip($i);
                Panel::getDatabase()->insert('ipam_4_addresses', [
                    'ip'      => $ip,
                    'fk_ipam' => $id,
                    'mac'     => null,
                    'in_use'  => 0
                ]);
            }
        }
        if ($type == "6") {
            $network = $_POST['network'];
            $gateway = $_POST['gateway'];
            $prefix  = $_POST['prefix'];
            $target  = $_POST['targetSize'];

            $calculator = new IPv6Calculator();
            $res        = $calculator->getInformation($network . "/" . $prefix, $target);

            $result = Panel::getDatabase()->insert('ipam_6', [
                'network' => $network,
                'prefix'  => $prefix,
                'target'  => $target,
                'gateway' => $gateway,
                'scope'   => $scope,
                'nodes'   => $nodes,
                'userId'  => $userId
            ]);
            $id     = Panel::getDatabase()->get_last_id();

            foreach ($res['networks'] as $network) {
                Panel::getDatabase()->insert('ipam_6_addresses', [
                    'ip'      => $network['network'] . '/' . $network['prefix'],
                    'fk_ipam' => $id,
                    'mac'     => null,
                    'in_use'  => 0
                ]);
            }
        }
        return ['success' => true];
    }


    public static function fetchRange($type, $where = "") {
        $sql = <<<SQL
        SELECT 
            res.*,
            IF(res.`percentage` > 90,
                'danger',
                IF(res.`percentage` > 70,
                    'warning',
                    'success')) AS `color`
        FROM
            (SELECT 
                qu.*, ROUND((qu.used / qu.ips) * 100, 2) AS `percentage`
            FROM
                (SELECT 
                ipam_{$type}.*,
                    COUNT(ipam_{$type}_addresses.id) AS ips,
                    SUM(ipam_{$type}_addresses.in_use) AS used,
                users.username as userName,
                users.email as userEmail
            FROM
                ipam_{$type}
            LEFT JOIN ipam_{$type}_addresses ON ipam_{$type}.id = ipam_{$type}_addresses.fk_ipam
            LEFT JOIN users ON ipam_{$type}.userId = users.id
            {$where}
            GROUP BY ipam_{$type}.id) AS qu
            ORDER BY createdAt DESC) AS res;
        SQL;

        return $sql;
    }
}
