<?php
namespace Objects\Event\FlowComponents;

use Controllers\Panel;
use Module\BaseModule\Controllers\ClusterHelper;
use Module\BaseModule\Controllers\IPAM\IPAMHelper;
use Objects\Event\TemplateReplacer;

class ModifyFirewall {


    /**
     * modifies the firewall for a server
     *
     * data:
     * - serverid = the server to load and update
     * - component = ipset = the component that's being modified
     * - action = the action that's being done to the component
     * 
     * 
     * actions:
     *      ipset:
     *      - create (targetrate in $data)
     *      - modify (targetvlan in $data)
     *          - ipsetname
     *          - ipsetcomment
     *          - ipsetsetcidr
     *          - ipsetsetcomment
     *          - ipsetsetnomatch
     *  
     * @param array $data
     * @param array $parameters
     * @return void
     */
    public static function execute($data, $parameters) {
        $serverid = $parameters[$data['serverid']];
        $server   = Panel::getDatabase()->fetch_single_row('servers', 'id', $serverid, \PDO::FETCH_OBJ);


        $component = $data['component'];
        $action    = $data['action'];

        switch ($component) {
            case 'ipset':
                self::handleIPSet($server, $action, $data, $parameters);
                break;
        }

    }

    /**
     * handle ipset rules for server
     *
     * @param $server
     * @param $action
     * @param $data
     * @param $parameters
     * @return void
     */
    private static function handleIPSet($server, $action, $data, $parameters) {
        switch ($action) {
            case 'create':
            case 'modify':

                $name = TemplateReplacer::replaceAll($data['ipsetname'], $parameters);
                $comment = TemplateReplacer::replaceAll($data['ipsetcomment'], $parameters);
                $rename = $action == 'modify' ? $name : null;

                $ipsets = json_decode($data['ipsetsets'], true);

                Panel::getProxmox()->create("/nodes/$server->node/qemu/$server->vmid/firewall/ipset", [
                    'name'    => $name,
                    'comment' => $comment,
                    'rename'  => $rename
                ]);

                foreach ($ipsets as $set) {
                    $cidr    = TemplateReplacer::replaceAll($set['ip'], $parameters);
                    $comment = TemplateReplacer::replaceAll($set['comment'], $parameters);
                    $nomatch = boolval($set['nomatch']);

                    Panel::getProxmox()->create("/nodes/$server->node/qemu/$server->vmid/firewall/ipset/$name", [
                        'cidr'    => $cidr,
                        'comment' => $comment,
                        'nomatch' => $nomatch
                    ]);
                }


                break;
        }
    }

}