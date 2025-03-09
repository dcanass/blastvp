<?php
namespace Module\BaseModule\Cron;

use Controllers\Panel;

class DetectServerMigrations {

    /**
     * should detect if a server has been moved from one node to another
     * 
     * -> get all servers from proxmox and compare against non-deleted servers in the DB. update node if necessary
     *
     * @return void
     */
    public static function execute() {
        $proxmox = Panel::getProxmox();

        $serversInProxmox = $proxmox->get("/cluster/resources")['data'];

        $serversInProxmox = array_filter($serversInProxmox, function ($ele) {
            return $ele['type'] == 'qemu' && $ele['template'] == 0;
        });

        foreach (Panel::getDatabase()->custom_query("SELECT * FROM servers WHERE deletedAt IS NULL")->fetchAll(\PDO::FETCH_OBJ) as $server) {
            // find server in proxmox array
            $f = array_filter($serversInProxmox, function ($e) use ($server) {
                return $e['vmid'] == $server->vmid;
            });
            $f = reset($f);
            if (isset($f)) {
                // server is no longer on the appropriate node, update in db
                $upd = [];
                // check if hostname changed
                if ($f['name'] != $server->hostname) {
                    $upd['hostname'] = $f['name'];
                }

                // check if on node
                if ($f['node'] !== $server->node) {
                    $upd['node'] = $f['node'];
                }

                // check if status changed
                if (($f['status'] == 'stopped' && $server->status == 'online') || ($f['status'] == 'running' && $server->status == 'offline')) {
                    $upd['status'] = $f['status'] == 'stopped' ? 'offline' : 'online';
                }

                if (sizeof($upd) > 0)
                    Panel::getDatabase()->update('servers', $upd, 'id', $server->id);
            }
        }
    }
}