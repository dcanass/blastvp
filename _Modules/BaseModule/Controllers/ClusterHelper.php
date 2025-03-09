<?php

namespace Module\BaseModule\Controllers;

use Controllers\Panel;
use Module\BaseModule\Controllers\Admin\Settings;
use ProxmoxVE\Proxmox;

class ClusterHelper {


    /**
     * this function returns all nodes that are in side of the proxmox cluster
     *
     * @return array
     */
    public static function getNodes() {
        try {
            $p = Panel::getProxmox();
            return $p->get('nodes')['data'];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * this function returns a node that will be used for quemu creation
     *
     * @return array
     */
    public static function getLoad($includeOverflow = false) {
        $p = Panel::getProxmox();


        $response = $p->get('/cluster/resources')['data'];

        $limits = defined("NODE_LIMIT") ? unserialize(NODE_LIMIT) : new \stdClass();

        $nodes = [];
        foreach ($response as $resource) {
            if ($resource['type'] == "node" && $resource['status'] == "online") {

                $nodes[$resource['node']]           = [];
                $nodes[$resource['node']]['maxmem'] = $resource['maxmem'] ?? 0;
                $nodes[$resource['node']]['maxcpu'] = $resource['maxcpu'] ?? 0;

                $nodes[$resource['node']]['usedmem']  = 0;
                $nodes[$resource['node']]['useddisk'] = 0;
            }
        }
        foreach ($response as $resource) {
            if ($resource['type'] == "storage" && $resource['storage'] == Settings::getConfigEntry("P_STORAGE")) {
                $nodes[$resource['node']]['maxdisk'] = $resource['maxdisk'];
            }
        }

        foreach ($response as $resource) {
            if ($resource['type'] == "qemu" && $resource['template'] == 0) {
                $nodes[$resource['node']]['usedmem'] += $resource['maxmem'];
                $nodes[$resource['node']]['useddisk'] += $resource['maxdisk'];
            }
        }
        foreach ($nodes as $k => $node) {
            $nodes[$k]['usage_mem']  = (($node['usedmem'] ?? 1) / ($node['maxmem'] ?? 1)) * 100;
            $nodes[$k]['usage_disk'] = (($node['useddisk'] ?? 1) / ($node['maxdisk'] ?? 1)) * 100;
        }

        $res = [];

        foreach ($nodes as $k => $node) {
            if (!isset($limits->{$k}) || $limits->{$k} == 0) {
                $limits->{$k} = 0;
            }
            if (($node['usage_mem'] < $limits->{$k} && $node['usage_disk'] < $limits->{$k}) || $includeOverflow) {
                array_push($res, [
                    'node'      => $k,
                    'mem_used'  => $limits->{$k} == 0 ? 0 : ($node['usage_mem'] / $limits->{$k}) * 100,
                    'disk_used' => $limits->{$k} == 0 ? 0 : ($node['usage_disk'] / $limits->{$k}) * 100,
                    'limit'     => $limits->{$k}
                ]);
            }
        }
        return $res;
    }

    public static function getNodeToCreate() {
        $data = self::getLoad();
        if (count($data) == 0) {
            return false;
        }
        // get random from array and return the name only
        return $data[array_rand($data, 1)];
    }

    /**
     * creates a new clonse
     *
     * @param [type] $node
     * @param [type] $os
     * @param [type] $id
     * @param [type] $hostname
     * @return boolean
     */
    public static function createClone($node, $os, $id, $hostname) {
        $p             = Panel::getProxmox();
        $fullClone     = Settings::getConfigEntry("P_FULL_CLONE", false) ? 1 : 0;
        $targetStorage = Settings::getConfigEntry("P_STORAGE", "local");
        $adds          = [];
        if ($fullClone) {
            $adds['full']    = 1;
            $adds['storage'] = $targetStorage;
        }
        try {
            $res = $p->create('/nodes/' . Settings::getConfigEntry("P_NODE") . '/qemu/' . $os . '/clone', array_merge([
                'newid'  => $id,
                'name'   => $hostname,
                'target' => $node,
            ], $adds));
        } catch (\Exception $e) {
            self::log("FAILED to clone from: " . Settings::getConfigEntry("P_NODE") . ' to ' . $node . '; ID: ' . $id . "; res: " . print_r($e->getMessage(), true));
            return false;
        }
        $taskId = $res['data'];
        if (!$taskId) {
            self::log("FAILED to clone from: " . Settings::getConfigEntry("P_NODE") . ' to ' . $node . '; ID: ' . $id . "; res: " . print_r($res, true));
            return false;
        }
        self::log($taskId . '; clone from: ' . Settings::getConfigEntry("P_NODE") . ' ID: ' . $id);
        if (self::getTaskStatus(Settings::getConfigEntry("P_NODE"), $taskId)) {
            return true;
        } else {
            return false;
        }
    }

    public static function updateClone(
        $node,
        $id,
        $password,
        $ssh,
        $cores,
        $ram,
        $ipv4,
        $ipv6,
        $templateConfig = "",
        $template = null,
        $dnsDomain = null,
        $dnsNameserver = null
    ) {
        $p         = Panel::getProxmox();
        $netconfig = "";

        $form = [];
        if (isset($templateConfig['net0'])) {
            // split at ",", split at "=" to get k => v
            $vmNetConfig = explode(',', $templateConfig['net0']);
            // virtio empty generates new mac-address
            $vmNetConfig = array_map(function ($ele) {
                return explode('=', $ele);
            }, $vmNetConfig);

            foreach ($vmNetConfig as $v) {
                $form[$v[0]] = $v[1];
            }

            // we have key -> value array of all the net-config settings for the template.
            // we want to detect which model is used, so lets' search for all available
            $av        = ['virtio', 'e1000', 'rtl8139', 'vmxnet3'];
            $modelUsed = array_diff($av, array_diff($av, array_keys($form)))[0];

            // set explicit MAC if enabled, leave empty to auto-generate
            $form[$modelUsed] = Settings::getConfigEntry('MAC_SUPPORT', false) ? $ipv4->mac : '';

            // interface speed if set, otherwise take from template
            if (Settings::getConfigEntry('O_INTERFACE_SPEED', "")) {
                $form['rate'] = Settings::getConfigEntry("O_INTERFACE_SPEED");
            }

            // overwrite bridge
            $form['bridge'] = Settings::getConfigEntry('P_BRIDGE');

            array_walk($form, function (&$v, $k) {
                $v = implode('=', array_filter([$k, $v], function ($e) {
                    return !!$e;
                }));
            });

            $netconfig = implode(',', $form);
        }


        self::log("Updated: " . $id);
        self::log("Netconfig: " . $netconfig);

        $ipconfig = [];
        if ($ipv4) {
            $ipconfig['ip'] = $ipv4->ip . '/' . $ipv4->subnet;
            $ipconfig['gw'] = $ipv4->gateway;
        }
        if ($ipv6) {
            $ipconfig['ip6'] = $ipv6->ip;
            $ipconfig['gw6'] = $ipv6->gateway;
        }

        array_walk($ipconfig, function (&$v, $k) {
            $v = implode('=', array_filter([$k, $v], function ($e) {
                return !!$e;
            }));
        });

        if ($template && $template->defaultUser) {
            $ciuser = $template->defaultUser;
        } else {
            // no template
            $ciuser = isset($templateConfig['ciuser']) ? $templateConfig['ciuser'] : 'root';
        }

        $payload = [
            'ciuser'    => $ciuser,
            'sshkeys'   => rawurlencode($ssh),
            'cores'     => $cores,
            'memory'    => $ram,
            'ipconfig0' => implode(',', $ipconfig)
        ];
        if ($password) {
            $payload['cipassword'] = $password;
        }

        if (sizeof($form) > 0) {
            $payload['net0'] = $netconfig;
        }

        if ($dnsDomain) {
            $payload['searchdomain'] = $dnsDomain;
        }

        if ($dnsNameserver) {
            $payload['nameserver'] = $dnsNameserver;
        }

        try {
            return $p->set('/nodes/' . $node . '/qemu/' . $id . '/config', $payload);
        } catch (\Exception $e) {
            self::log("FAILED to update server. Response: " . print_r($e->getMessage(), true));
            return false;
        }
    }

    public static function resizeDisk($node, $id, $size, $templateConfig, $template) {
        $drive = 'scsi0';
        if ($template && $template->defaultDrive != null) {
            $drive = $template->defaultDrive;
        }

        $fullClone = Settings::getConfigEntry("P_FULL_CLONE", false) ? 1 : 0;
        // check if $templateConfig[$drive] is in the same storage as the target storage
        $ogStorage     = explode(":", $templateConfig[$drive])[0];
        $targetStorage = Settings::getConfigEntry('P_STORAGE', 'local');
        if (!$fullClone && $ogStorage != $targetStorage) {
            // move storage after cloning
            self::log("Start move disk: $drive ON VMID: " . $id);
            $task = Panel::getProxmox()->create("/nodes/$node/qemu/$id/move_disk", [
                'disk'    => $drive,
                'storage' => $targetStorage,
                'delete'  => true
            ])['data'];
            if (self::getTaskStatus($node, $task)) {
                // wait for moving of disk before starting resize
                self::log("Finish moved disk: $drive on VMID: $id");
            }
        }

        self::log("disk resize: $drive on VMID: $id");

        try {
            return Panel::getProxmox()->set('/nodes/' . $node . '/qemu/' . $id . '/resize', [
                'disk' => $drive,
                'size' => $size . 'G'
            ]);
        } catch (\Exception $e) {
            self::log("FAILED to resize disk. Response: " . print_r($e->getMessage(), true));
            return false;
        }
    }

    public static function startServer($node, $id) {
        $p      = Panel::getProxmox();
        $res    = $p->create('/nodes/' . $node . '/qemu/' . $id . '/status/start');
        $taskId = $res['data'];
        self::log($taskId . "; Start server: " . $id);
        if (self::getTaskStatus($node, $taskId)) {
            // check status of vm to ensure it is running. If not, reexecute this
            if (self::getStatus($node, $id)['status'] === 'running') {
                return $res;
            } else {
                return false;
            }
        }
    }

    public static function shutdownServer($node, $id) {
        $p      = Panel::getProxmox();
        $res    = $p->create('/nodes/' . $node . '/qemu/' . $id . '/status/shutdown');
        $taskId = $res['data'];
        if (!$taskId)
            return false;
        self::log($taskId . "; Stop server: " . $id);
        if (self::getTaskStatus($node, $taskId)) {
            // check status of vm to ensure it is stopped. If not, reexecute this
            if (self::getStatus($node, $id)['status'] === "stopped") {
                return $res;
            } else {
                return false;
            }
        }
    }

    public static function restartServer($node, $id) {
        $p      = Panel::getProxmox();
        $res    = $p->create('/nodes/' . $node . '/qemu/' . $id . '/status/reboot');
        $taskId = $res['data'];
        self::log($taskId . "; Restart server: " . $id);
        if (self::getTaskStatus($node, $taskId)) {
            return $res;
        }
    }

    public static function resetServer($node, $id) {
        $p      = Panel::getProxmox();
        $res    = $p->create('/nodes/' . $node . '/qemu/' . $id . '/status/reset');
        $taskId = $res['data'];
        self::log($taskId . "; Restart server: " . $id);
        if (self::getTaskStatus($node, $taskId)) {
            return $res;
        }
    }

    public static function stopServer($node, $id) {
        $p      = Panel::getProxmox();
        $res    = $p->create('/nodes/' . $node . '/qemu/' . $id . '/status/stop');
        $taskId = $res['data'];
        self::log($taskId . "; Restart server: " . $id);
        if (self::getTaskStatus($node, $taskId)) {
            return $res;
        }
    }


    public static function deleteServer($node, $id) {
        self::log("[INFO] Startin deletion of VMID: $id on node $node");
        $p   = Panel::getProxmox();
        $url = '/nodes/' . $node . '/qemu/' . $id . '?purge=1';
        if (defined("P_SKIP_LOCK") && P_SKIP_LOCK) {
            $url .= "&skiplock=1";
        }
        $res    = $p->delete($url);
        $taskId = $res['data'];

        // return false in case of error and log it.
        if (!$taskId) {
            self::log('[ERROR] Error deleting VM ID: ' . $id . " with: " . print_r($res, true));
            return false;
        }

        self::log($taskId . "; Delete server: " . $id);
        if (self::getTaskStatus($node, $taskId)) {
            return $res;
        }
    }

    public static function migrateVM($source, $target, $vmid, $withlocaldisks = 1, $restartMode = 0, $cloudInitMove = false) {
        $p = Panel::getProxmox();

        $hasCloudInit = false;
        $drive        = null;
        $driveValue   = null;
        if ($cloudInitMove) {
            // check if vm has cloudinit drive, remove if - reattach after migration
            // get config from server
            $config = $p->get('/nodes/' . $source . '/qemu/' . $vmid . '/config');
            // search for "cloudinit,media=cdrom" string in values and save the key, since that is what we need to remove
            foreach ($config['data'] as $k => $v) {
                if (preg_match("/cloudinit\,media\=cdrom/", $v)) {
                    $drive        = $k;
                    $driveValue   = $v;
                    $hasCloudInit = true;
                }
            }

            if ($hasCloudInit && $drive) {
                // remove $drive
                $p->set('/nodes/' . $source . '/qemu/' . $vmid . '/config', ['delete' => $k]);
                $p->create('/nodes/' . $source . '/qemu/' . $vmid . '/status/stop');
            }
        }

        $url    = '/nodes/' . $source . '/qemu/' . $vmid . '/migrate';
        $res    = $p->create($url, [
            'target'           => $target,
            'targetstorage'    => Settings::getConfigEntry("P_STORAGE"),
            'with-local-disks' => (int) $withlocaldisks,
            'online'           => (int) $restartMode,
        ]);
        $taskId = $res['data'];
        if (!$taskId) {
            // reattach cloudinit image if existent
            if ($hasCloudInit && $drive) {
                $p->create("/nodes/$target/qemu/$vmid/config", [
                    $drive => explode(",", $driveValue)[0]
                ]);
            }

            self::log('[ERROR] Error migrating VMID: ' . $vmid . ' to target node: ' . $target . ' from node: ' . $source . ' storage: ' . Settings::getConfigEntry("P_STORAGE") . ' local-disks: ' . (int) $withlocaldisks . ' online: ' . (int) $restartMode . ' with error: ' . print_r($res, true));
            return false;
        }

        self::log($taskId . '; Migrate server: ' . $vmid);
        if (self::getTaskStatus($source, $taskId, 1, true) == "OK") {
            // reattach cloudinit if existet
            if ($hasCloudInit && $drive) {
                $p->create("/nodes/$target/qemu/$vmid/config", [
                    $drive => $driveValue
                ]);
                $p->create("/nodes/$target/qemu/$vmid/status/start");
            }
            return ['success' => true, 'log' => self::getTaskOutput($source, $taskId)];
        } else {
            return ['success' => false, 'log' => self::getTaskOutput($source, $taskId)];
        }
    }

    public static function createSnapshot(string $node, float $vmid, string $name, bool $includeMemory, bool $instantReturn) {
        $p      = Panel::getProxmox();
        $res    = $p->create("/nodes/$node/qemu/$vmid/snapshot", [
            'snapname' => $name,
            'vmstate'  => $includeMemory
        ]);
        $taskId = $res['data'];
        self::log($taskId . "; Create snapshot: " . $vmid);
        if ($instantReturn) {
            return $taskId;
        }
        if (self::getTaskStatus($node, $taskId)) {
            return $res;
        }
        return false;
    }

    public static function deleteSnapshot(string $node, float $vmid, string $name) {
        $p   = Panel::getProxmox();
        $res = $p->delete("/nodes/$node/qemu/$vmid/snapshot/$name?force=1");
        self::log(print_r($res, true));
        $taskId = $res['data'];
        self::log($taskId . "; Delete snapshot: " . $vmid);
        if (self::getTaskStatus($node, $taskId)) {
            return $res;
        }
        return false;
    }

    public static function restoreSnapshot(string $node, float $vmid, string $name) {
        $p      = Panel::getProxmox();
        $res    = $p->create("/nodes/$node/qemu/$vmid/snapshot/$name/rollback", [
            'start' => 1,
        ]);
        $taskId = $res['data'];
        self::log($taskId . "; Restore snapshot: " . $vmid);
        if (self::getTaskStatus($node, $taskId)) {
            return $res;
        }
        return false;
    }

    /**
     * get the config of a VM
     *
     * @param string $node
     * @param string $vmid
     * @return array
     */
    public static function getConfig($node, $vmid) {
        return Panel::getProxmox()->get("/nodes/$node/qemu/$vmid/config")['data'];
    }

    /**
     * apply (part of) a new config to a vm
     *
     * @param string $node
     * @param string $vmid
     * @param array $newConfig
     * @return array
     */
    public static function applyPatch(string $node, string $vmid, array $newConfig) {
        self::log(print_r($newConfig, true));
        $result = Panel::getProxmox()->set("/nodes/$node/qemu/$vmid/config", $newConfig);
        return $result;
    }

    /**
     * regenerate the cloudinit config
     * @param string $node
     * @param mixed $vmid
     * @return array
     */
    public static function regenerateCloudInit($node, $vmid) {
        return Panel::getProxmox()->set("/nodes/$node/qemu/$vmid/cloudinit");
    }

    public static function getNextId() {
        $p = Panel::getProxmox();

        $nextId = $p->get('/cluster/nextid');
        // get nextId from cluster
        return $nextId['data'];
    }

    public static function getTaskStatus($node, $task, $wait = 1, $getRawMessage = false) {
        $p      = Panel::getProxmox();
        $status = $p->get('/nodes/' . $node . '/tasks/' . $task . '/status');
        self::log($task . "; Still waiting on: " . $node);
        if ($status['data']['status'] == "stopped") {
            self::log($task . " Done");
            if ($getRawMessage) {
                return $task['status'];
            }
            return true;
        } else {
            sleep($wait);
            return self::getTaskStatus($node, $task, $wait);
        }
    }

    public static function getTaskOutput($node, $task) {
        $p      = Panel::getProxmox();
        $status = $p->get('/nodes/' . $node . '/tasks/' . $task . '/log', ['limit' => 9999]);

        return $status;
    }

    public static function getStatus($node, $id) {
        $p      = Panel::getProxmox();
        $status = $p->get('/nodes/' . $node . '/qemu/' . $id . '/status/current');
        return $status['data'];
    }

    public static function log($log) {
        $file = @fopen('proxmox.log', 'a+');
        if ($file) {
            @fwrite($file, '[' . date('Y-m-d H:i:s') . '] ' . $log . "\n");
            @fclose($file);
        }
    }
}