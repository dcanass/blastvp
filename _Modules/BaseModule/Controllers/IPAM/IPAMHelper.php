<?php

namespace Module\BaseModule\Controllers\IPAM;

use Controllers\Panel;
use Module\BaseModule\Controllers\Admin\Settings;

class IPAMHelper {


    const IP_UNUSED = 0;
    const IP_USED   = 1;

    /**
     * returns a free ip address
     * 
     * sort logic is this:
     * 1. find nets depending on O_IPAM_PRIORITY (default GLOBAL)
     * 2. find nets that have match O_IPAM_BALANCE Setting (default FILL, other option BALANCE)
     * 3. choose lowest ip in that net
     * 
     * @return boolean|array the pk of the ipam_4_addresses table
     */
    public static function getFreeIp($type, $node = "", $userId = "") {
        // filter nets based on O_IPAM_PRIORITY - we can discharg nets that are full.
        $nets = Panel::getDatabase()->custom_query(IPAM::fetchRange($type))->fetchAll(\PDO::FETCH_OBJ);
        // filter out all nets that are full - since we cannot use any ips there anyways
        $nets = array_filter($nets, function ($net) {
            return (int) $net->percentage < 100;
        });

        if (sizeof($nets) == 0) {
            return [
                'error' => true,
                'msg'   => "NO_FREE_{$type}_NETS"
            ];
        }
        // now we can filter out all nets that are NOT matching the O_IPAM_PRIORITY
        $priority           = Settings::getConfigEntry("O_IPAM_PRIORITY", "GLOBAL");
        $failWhenNoUsersNet = Settings::getConfigEntry('O_IPAM_FAIL_NO_USERNET', false);
        $scopeFiltered      = array_filter($nets, function ($net) use ($priority, $node, $userId) {
            if ($priority == "GLOBAL") {
                return $net->scope == "global";
            }
            if ($priority == "NODE")
                return $net->scope == "node" && strpos($net->nodes, $node) !== false;

            if ($priority == "USER")
                return $net->userId == $userId;
            // unreachable
            return false;
        });
        // check if nets-size is 0, otherwise we can revert the previous filter
        if (sizeof($scopeFiltered) == 0) {
            if (!$failWhenNoUsersNet) {
                // there are no more nets that satisfy the priority, so we can take all that are global or node-specific
                $scopeFiltered = array_filter($nets, function ($net) use ($node) {
                    return $net->scope == "global" || ($net->scope == "node" && strpos($net->nodes, $node) !== false);
                });
            }
        }

        // filter for balance property - BALANCE means that all nets are getting filled equally
        // FILL means we fill one and then get the next
        $balance = Settings::getConfigEntry("O_IPAM_BALANCE", "FILL");

        usort($scopeFiltered, function ($a, $b) use ($balance) {
            if ($balance == "FILL") {
                return strcmp($b->percentage, $a->percentage);
            }
            if ($balance == "BALANCE") {
                return strcmp($a->percentage, $b->percentage);
            }
        });

        if (sizeof($scopeFiltered) > 0) {
            // we have at least one ip, so we can return that :)
            // fetch free IP from this range
            $sql = <<<SQL
                SELECT a.* FROM 
                    ipam_{$type}_addresses AS a
                WHERE
                    a.fk_ipam = {$scopeFiltered[0]->id} AND
                    a.in_use = 0
                LIMIT 1
            SQL;
            $res = Panel::getDatabase()->custom_query($sql)->fetchAll(\PDO::FETCH_OBJ);
            if (sizeof($res) == 0) {
                return [
                    'error' => true,
                    'msg'   => "NO_FREE_{$type}_NETS"
                ];
            }
            return [
                'error' => false,
                'fk_ip' => $res[0]->id,
                'ip'    => (object) array_merge((array) $scopeFiltered[0], (array) $res[0])
            ];
        } else {
            return [
                'error' => true,
                'msg'   => "NO_FREE_{$type}_NETS"
            ];
        }
    }

    /**
     * Get a single IPv4 by ID
     *
     * @param number $id
     * @return object
     */
    public static function getIpv4ById($id) {
        $sql = <<<SQL
            SELECT 
            ipam_4_addresses.*,
            ipam_4.start,
            ipam_4.end,
            ipam_4.subnet,
            ipam_4.gateway,
            ipam_4.scope,
            ipam_4.nodes,
            ipam_4.createdAt
        FROM
            ipam_4_addresses
                RIGHT JOIN
            ipam_4 ON ipam_4_addresses.fk_ipam = ipam_4.id
        WHERE
            ipam_4_addresses.id = $id
        LIMIT 1;
        SQL;
        return Panel::getDatabase()->custom_query($sql)->fetchAll(\PDO::FETCH_OBJ)[0];
    }

    /**
     * Get a single IPv6 by ID
     *
     * @param number $id
     * @return object
     */
    public static function getIpv6ById($id) {
        $sql = <<<SQL
            SELECT 
            ipam_6_addresses.*,
            ipam_6.network,
            ipam_6.prefix,
            ipam_6.target,
            ipam_6.gateway,
            ipam_6.scope,
            ipam_6.nodes,
            ipam_6.createdAt
        FROM
            ipam_6_addresses
                RIGHT JOIN
            ipam_6 ON ipam_6_addresses.fk_ipam = ipam_6.id
        WHERE
            ipam_6_addresses.id = $id
        LIMIT 1;
        SQL;
        return Panel::getDatabase()->custom_query($sql)->fetchAll(\PDO::FETCH_OBJ)[0];
    }

    /**
     * set the usage-status of a single IP 
     *
     * @param string $type wether its an ipv4 or ipv6 address
     * @param number $id ID of the IP
     * @param number $status status (IPAMHelper::IP_UNUSED)
     * @return void
     */
    public static function setIPStatus($type, $id, $status) {
        Panel::getDatabase()->update("ipam_{$type}_addresses", ['in_use' => $status], 'id', $id);
    }
}