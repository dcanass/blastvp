<?php
namespace Objects\Event\FlowComponents;

use Controllers\Panel;
use Module\BaseModule\Controllers\ClusterHelper;
use Module\BaseModule\Controllers\IPAM\IPAMHelper;
use Objects\Event\TemplateReplacer;

class ModifyServer {


    /**
     * modifies a server
     *
     * data:
     * - serverid = the server to load and update
     * - component = network | ha | general = the component that's being modified
     * - action = the action that's being done to the component
     * 
     * 
     * actions:
     *      network:
     *      - change-rate (targetrate in $data)
     *      - change-vlan (targetvlan in $data)
     *      - assign-v4
     *          - assignv4id
     *      - assign-v6
     *          - assignv6id
     *      ha:
     *      - ha-status ("enabled" or "disabled")
     *          - hamaxrelocate (max_relocate in API)
     *          - hamaxrestart (max_restart in API)
     *          - hagroup (group in API)
     *      general:
     *      - note (change the note of the server)
     *          - generalnote 
     *      - hostname (change the hostname of the server)
     *          - generalhostname 
     *  
     * @param array $data
     * @param array $parameters
     * @return void
     */
    public static function execute($data, $parameters) {
        $serverid = $parameters[$data['serverid']];
        $server   = Panel::getDatabase()->fetch_single_row('servers', 'id', $serverid, \PDO::FETCH_ASSOC);


        $component = $data['component'];
        $action    = $data['action'];

        switch ($component) {
            case 'network':
                self::handleNetwork($server, $action, $data, $parameters);
                break;
            case 'ha':
                self::handleHa($server, $action, $data, $parameters);
                break;
            case 'general':
                self::handleGeneral($server, $action, $data, $parameters);
                break;
        }

    }

    /**
     * handle general server stuff
     *
     * @param $server
     * @param $action
     * @param $data
     * @param $parameters
     * @return void
     */
    private static function handleGeneral($server, $action, $data, $parameters) {
        switch ($action) {
            case 'note':
                $newNote = TemplateReplacer::replaceAll($data['generalnote'], $parameters);

                ClusterHelper::applyPatch($server['node'], $server['vmid'], [
                    'description' => $newNote
                ]);
                break;
            case 'hostname':
                $newHostname = TemplateReplacer::replaceAll($data['generalhostname'], $parameters);

                ClusterHelper::applyPatch($server['node'], $server['vmid'], [
                    'name' => $newHostname
                ]);
                break;
        }
    }

    /**
     * handle network actions
     *
     * @param $server
     * @param $action
     * @param $data
     * @param $parameters
     * @return void
     */
    private static function handleNetwork($server, $action, $data, $parameters) {
        $config = ClusterHelper::getConfig($server['node'], $server['vmid']);
        switch ($action) {
            case 'change-rate':
                $newRate = TemplateReplacer::replaceAll($data['targetrate'], $parameters);
                $newConfig = self::addToNetworkConfig($config, 'rate', $newRate);
                break;
            case 'change-vlan':
                $newVlan = TemplateReplacer::replaceAll($data['targetvlan'], $parameters);
                $newConfig = self::addToNetworkConfig($config, 'tag', $newVlan);
                break;
            case 'assign-v4':
            case 'assign-v6':
                $type = $action == "assign-v4" ? '4' : '6';
                $ip = (int) TemplateReplacer::replaceAll($data['assignv' . $type . 'id'], $parameters);
                $ip = $type == '4' ? IPAMHelper::getIpv4ById($ip) : IPAMHelper::getIpv6ById($ip);

                $newConfig = self::addToNetworkConfig(
                    $config,
                    'ip' . ($type == '4' ? '' : '6'),
                    $type == '4' ? ($ip->ip . '/' . $ip->subnet) : $ip->ip,
                    'ipconfig0'
                );
                $newConfig = self::addToNetworkConfig(
                    $newConfig,
                    'gw' . ($type == '4' ? '' : '6'),
                    $ip->gateway,
                    'ipconfig0'
                );

                // mark ip used
                IPAMHelper::setIPStatus($type, $ip->id, IPAMHelper::IP_USED);
                // update server
                Panel::getDatabase()->update('servers', [
                    'ip' . ($type == '4' ? '' : '6') => $ip->id
                ], 'id', $server['id']);
                break;
            case 'remove-v4':
            case 'remove-v6':
                $type = $action == "remove-v4" ? '4' : '6';
                $ip = (int) TemplateReplacer::replaceAll($data['removev' . $type . 'id'], $parameters);
                $ip = $type == '4' ? IPAMHelper::getIpv4ById($ip) : IPAMHelper::getIpv6ById($ip);

                $newConfig = self::removeFromNetworkConfig(
                    $config,
                    'ip' . ($type == '4' ? '' : '6'),
                    'ipconfig0'
                );
                $newConfig = self::removeFromNetworkConfig(
                    $newConfig,
                    'gw' . ($type == '4' ? '' : '6'),
                    'ipconfig0'
                );

                // mark ip used
                IPAMHelper::setIPStatus($type, $ip->id, IPAMHelper::IP_UNUSED);
                // update server
                Panel::getDatabase()->update('servers', [
                    'ip' . ($type == '4' ? '' : '6') => null
                ], 'id', $server['id']);
                break;
        }

        ClusterHelper::applyPatch($server['node'], $server['vmid'], $newConfig);
        ClusterHelper::regenerateCloudInit($server['node'], $server['vmid']);
    }

    /**
     * handle High Availability actions
     *
     * @param $server
     * @param $action
     * @param $data
     * @param $parameters
     * @return void
     */
    private static function handleHa($server, $action, $data, $parameters) {
        switch ($action) {
            case "ha-status":
                $status = $data['hastatus'];
                $vmid = "vm:" . $server['vmid'];
                if ($status == "disabled") {
                    // disable HA
                    $result = Panel::getProxmox()->delete(`/cluster/ha/resources/$vmid`);
                } else {
                    // enable HA
                    $group        = $data['hagroup'] ?: null;
                    $max_relocate = $data['hamaxrelocate'] ?: 1;
                    $max_restart  = $data['hamaxrestart'] ?: 1;

                    $result = Panel::getProxmox()->create("/cluster/ha/resources", [
                        'sid'          => $vmid,
                        'group'        => $group,
                        'max_relocate' => $max_relocate,
                        'max_restart'  => $max_restart
                    ]);
                }
                break;
        }
    }

    /**
     * add/update a parameter in the net0 config
     *
     * @param array $config
     * @param string $key
     * @param mixed $value
     * @return array
     */
    public static function addToNetworkConfig($config, $key, $value, $configKey = 'net0') {
        // split at ",", split at "=" to get k => v
        $vmNetConfig = explode(',', $config[$configKey]);

        $form        = [];
        $vmNetConfig = array_map(function ($ele) {
            return explode('=', $ele);
        }, $vmNetConfig);

        foreach ($vmNetConfig as $v) {
            $form[$v[0]] = $v[1];
        }

        $form[$key] = $value;

        array_walk($form, function (&$v, $k) {
            $v = implode('=', array_filter([$k, $v], function ($e) {
                return !!$e;
            }));
        });

        return [$configKey => implode(',', $form)];
    }

    public static function removeFromNetworkConfig($config, $key, $configKey = 'net0') {
        // split at ",", split at "=" to get k => v
        $vmNetConfig = explode(',', $config[$configKey]);

        $form        = [];
        $vmNetConfig = array_map(function ($ele) {
            return explode('=', $ele);
        }, $vmNetConfig);

        foreach ($vmNetConfig as $v) {
            $form[$v[0]] = $v[1];
        }

        unset($form[$key]);

        array_walk($form, function (&$v, $k) {
            $v = implode('=', array_filter([$k, $v], function ($e) {
                return !!$e;
            }));
        });

        return [$configKey => implode(',', $form)];
    }

}