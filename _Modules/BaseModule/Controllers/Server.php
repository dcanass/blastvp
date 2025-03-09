<?php

/**
 * Created by PhpStorm.
 * User: bennet
 * Date: 23.02.19
 * Time: 13:42
 */

namespace Module\BaseModule\Controllers;

use Controllers\Panel;
use Module\BaseModule\BaseModule;
use Module\BaseModule\Controllers\Admin\Settings;
use Module\BaseModule\Controllers\IPAM\IPAM;
use Module\BaseModule\Controllers\IPAM\IPAMHelper;
use Module\BaseModule\Objects\ServerStatus;
use Objects\Constants;
use Objects\Event\EventManager;
use Objects\Formatters;
use Objects\Invoice;
use Module\BaseModule\Controllers\Invoice as ControllersInvoice;
use Objects\Notification;
use Objects\Permissions\ACL;
use Objects\Permissions\Resources\ServerResource;
use Objects\Server as ServerObject;

class Server {

    public static function dashboard($id) {
        $user = BaseModule::getUser();
        // load server to id and check if user has permission
        if (!ACL::can($user)->read(ServerResource::class, $id)) {
            header('Location: ' . Settings::getConfigEntry("APP_URL"));
            die();
        }
        $server = Server::loadServer($id);
        if (!$server) {
            header('Location: ' . Settings::getConfigEntry("APP_URL"));
            die();
        }

        Panel::compile("_views/_pages/server/index.html", array_merge([
            'id'       => $id,
            'hostname' => $server->hostname
        ], Panel::getLanguage()->getPages(['global', 'server', 'ipam'])));
    }

    public static function serverRest($id) {
        $user = BaseModule::getUser();

        if (!ACL::can($user)->read(ServerResource::class, $id)) {
            return [
                'code' => 403
            ];
        }

        // load server to id and check if user has permission
        $server = Server::loadServer($id);
        if (!$server) {
            return [
                'code' => 404
            ];
        }

        $hasNetworkModule = Panel::getModule('NetworkModule');
        $addIps           = [];
        if ($hasNetworkModule) {
            $addIps           = Panel::getDatabase()->custom_query("SELECT network_devices.*, networks.friendlyName FROM network_devices RIGHT JOIN networks ON network_devices.networkId = networks.id WHERE serverId = ? ", ['serverId' => $server->id])->fetchAll(\PDO::FETCH_ASSOC);
            $server->networks = $addIps;
        }

        $ssh_keys = Panel::getDatabase()->custom_query("SELECT * FROM `ssh-keys` WHERE userId=?", ['userId' => $user->getId()])->fetchAll(\PDO::FETCH_ASSOC);

        if ($server->packageId) {
            $package = Panel::getDatabase()->fetch_single_row('packages', ['id'], [$server->packageId]);
            if (!$package) {
                $server->packageId = 0;
            }
            $server->package = $package;
        }

        $node        = $server->node;
        $vmid        = $server->vmid;
        $config      = Panel::getProxmox()->get("/nodes/$node/qemu/$vmid/config")['data'];
        $description = isset($config['description']) ? explode("\n", $config['description']) : [];

        $description = array_values(array_filter($description, function ($ele) {
            return (strpos($ele, "!#") === false) && $ele !== "";
        }));

        $attachedIsos         = array_filter($config, function ($value, $key) use ($server) {
            return preg_match("/ide(\d+)/", $key) && str_contains($value, 'media=cdrom') && !str_contains($value, "vm-{$server->vmid}-cloudinit.qcow2");
        }, ARRAY_FILTER_USE_BOTH);
        $server->attachedIsos = array_filter(array_map(function ($ele) use ($server) {
            $a = explode(',', $ele);
            if ($a[0] == "cdrom" || str_contains($a[0], $server->vmid . '-cloudinit'))
                return false;
            return explode("/", $a[0])[1];
        }, $attachedIsos));


        $date     = new \DateTime($server->nextPayment);
        $interval = \DateInterval::createFromDateString(Settings::getConfigEntry('O_DELETE_SUSPENDED', 3) . ' days');
        $date->add($interval);

        $server->willBeDeletedAt = $date->format('Y-m-d H:i:s');

        // $server->bootOrder = [];
        $bootConfig = $config['boot'];
        $bootConfig = @explode('=', $bootConfig)[1];
        if ($bootConfig) {
            $bootConfig = explode(';', $bootConfig);
        }
        if (isset($config['bootdisk']) && !isset($bootConfig[$config['bootdisk']])) {
            $bootConfig[] = $config['bootdisk'];
        }

        $availableDrives = array_values(array_filter(array_keys($config), function ($e) use ($config, $server) {
            return preg_match("/^(scsi|ide|sata|virtio|net)(\d+)$/", $e) && !str_contains($config[$e], $server->vmid . '-cloudinit');
        }));
        $bootConfig      = array_values(array_unique(array_merge($bootConfig, $availableDrives)));

        $final = [];
        foreach ($bootConfig as $k => &$c) {
            if (preg_match("/^(scsi|sata|virtio)(\d+)$/", $c)) {
                preg_match("/size=(\d+\w+)/", $config[$c], $matches);
                $final[$c] = $matches[1];
            }
            // dump($c, $config[$c]);
            if (preg_match("/^(net)(\d+)$/", $c)) {
                preg_match("/((([0-9]|[A-Z]){2}:?){6}?)(?=,)/", $config[$c], $matches);
                $final[$c] = $matches[1];
            }

            if (preg_match("/^(ide)(\d+)$/", $c)) {
                preg_match("/\/((.+?)(?=,))/", $config[$c], $matches);
                $final[$c] = $matches[1];
            }
        }

        $server->bootOrder = $final;

        return [
            'server'   => [
                ...(array) $server,
                'description'       => implode(PHP_EOL, $description),
                '_status'           => ServerStatus::getTextRepresentation($server->status),
                'guestAgentEnabled' => isset($config['agent']) && explode(',', $config['agent'])[0] == '1',
                'snapshotsEnabled' => Settings::getConfigEntry('SNAPSHOT_ENABLED', false)
            ],
            'ssh_keys' => $ssh_keys,
        ];
    }

    public static function listAll() {
        $user = BaseModule::getUser();

        return $user->getServers();
    }

    public static function apiPatch($id) {
        $user = BaseModule::getUser();
        if (!ACL::can($user)->update(ServerResource::class, $id)) {
            return [
                'code' => 403
            ];
        }
        $server = self::loadServer($id);
        $body   = Panel::getRequestInput();

        $description = isset($body['description']);

        $detach = $body['detach'] ?? null;
        if ($description)
            Panel::getProxmox()->set("/nodes/" . $server->node . "/qemu/" . $server->vmid . "/config", [
                'description' => $body['description']
            ]);

        if ($detach) {
            // detach drive
            Panel::getProxmox()->set("/nodes/{$server->node}/qemu/{$server->vmid}/config", [
                'delete' => $detach
            ]);
        }

        $attach = $body['attach'] ?? null;
        if ($attach) {
            $bootFirst = filter_var($body['bootFirst'], FILTER_VALIDATE_BOOLEAN);

            $config = Panel::getProxmox()->get("/nodes/{$server->node}/qemu/{$server->vmid}/config")['data'];
            // attach ISO
            $drives = array_map(function ($key) {
                preg_match("/ide(\d+)/", $key, $a);
                return $a[1];
            }, array_keys(array_filter($config, function ($value, $key) {
                return preg_match("/ide(\d+)/", $key);
            }, ARRAY_FILTER_USE_BOTH)));

            $options = array_values(array_diff(range(0, 3), $drives));

            if (sizeof($options) == 0) {
                return [
                    'error'   => true,
                    'message' => Panel::getLanguage()->get('servers', 'm_iso_no_slot')
                ];
            }

            $storage = Settings::getConfigEntry("ISO_STORAGE", "");
            Panel::getProxmox()->set("/nodes/{$server->node}/qemu/{$server->vmid}/config", [
                'ide' . $options[0] => "{$storage}:iso/{$attach},media=cdrom"
            ]);

            // adjust boot order to set this device first // post field "order=scsi0;ide0"
            if ($bootFirst) {
                $bootConfig = $config['boot'];
                $bootConfig = explode('=', $bootConfig)[1];
                // array of scsi0, ide0, ...
                $bootConfig = explode(";", $bootConfig);

                if (!isset($bootConfig[$config['bootdisk']])) {
                    $bootConfig[] = $config['bootdisk'];
                }

                $drive = 'ide' . $options[0];
                if (isset($bootConfig[$drive])) {
                    unset($bootConfig[$drive]);
                }
                array_unshift($bootConfig, $drive);
                $bootConfig = implode(";", $bootConfig);

                Panel::getProxmox()->set("/nodes/{$server->node}/qemu/{$server->vmid}/config", [
                    'boot' => "order=" . $bootConfig
                ]);
            }
        }

        $bootOrder = $body['bootOrder'] ?? null;
        if ($bootOrder) {
            // check if bootOrder has keys, if it has, take those
            if (!array_is_list($bootOrder)) {
                $bootOrder = array_keys($bootOrder);
            }
            Panel::getProxmox()->set("/nodes/{$server->node}/qemu/{$server->vmid}/config", [
                'boot' => "order=" . implode(';', $bootOrder)
            ]);
        }

        return ['error' => false];
    }

    public static function admin_overview() {
        $user = BaseModule::getUser();

        if ($user->getPermission() < 2) {
            die('401');
        }

        $v4 = Panel::getDatabase()->custom_query(IPAM::fetchRange(4))->fetchAll(\PDO::FETCH_ASSOC);
        $v6 = Panel::getDatabase()->custom_query(IPAM::fetchRange(6))->fetchAll(\PDO::FETCH_ASSOC);

        Panel::compile('_views/_pages/admin/servers.html', array_merge([
            'node_limits' => unserialize(Settings::getConfigEntry('NODE_LIMIT', serialize([]))),
            'ipam_4'      => $v4,
            'ipam_6'      => $v6
        ], Panel::getLanguage()->getPages(['global', 'admin_servers', 'ipam'])));
    }

    public static function startServer($id) {
        $user = BaseModule::getUser();
        if (!ACL::can($user)->update(ServerResource::class, $id)) {
            return [
                'code' => 403
            ];
        }
        $server = Server::loadServer($id);
        if (!$server) {
            return [
                'code' => 404
            ];
        }
        Panel::getDatabase()->update('servers', ['status' => ServerStatus::$STARTING], 'id', $id);

        $status = ClusterHelper::startServer($server->node, $server->vmid);

        if (!$status) {
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('global', 'm_internal_error')
            ];
        } else {
            Panel::getDatabase()->update('servers', ['status' => ServerStatus::$ONLINE], 'id', $id);
            if ($user->hasNotificationsEnabled('servers')) {
                // user has ticket notifications enabled, so we need to send him a message her.
                $notification = (new Notification())
                    ->setUserId($user->getId())
                    ->setType(Notification::TYPE_SERVERS)
                    ->setEmail($user->getEmail())
                    ->setMeta("server_started_" . $id);
                $notification->save();
            }
            EventManager::fire('server::start', (array) $server);
            return [
                'error' => false
            ];
        }
    }

    public static function shutdownServer($id) {
        $user = BaseModule::getUser();
        if (!ACL::can($user)->update(ServerResource::class, $id)) {
            return [
                'code' => 403
            ];
        }
        $server = Server::loadServer($id);
        if (!$server) {
            return [
                'code' => 404
            ];
        }
        Panel::getDatabase()->update('servers', ['status' => ServerStatus::$STOPPING], 'id', $id);

        $status = ClusterHelper::shutdownServer($server->node, $server->vmid);

        if (!$status) {
            Panel::getDatabase()->update('servers', ['status' => ServerStatus::$ONLINE], 'id', $id);
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('global', 'm_internal_error')
            ];
        } else {
            Panel::getDatabase()->update('servers', ['status' => ServerStatus::$OFFLINE], 'id', $id);
            if ($user->hasNotificationsEnabled('servers')) {
                // user has ticket notifications enabled, so we need to send him a message her.
                $notification = (new Notification())
                    ->setUserId($user->getId())
                    ->setType(Notification::TYPE_SERVERS)
                    ->setEmail($user->getEmail())
                    ->setMeta("server_stopped_" . $id);
                $notification->save();
            }
            EventManager::fire('server::stop', (array) $server);
            return [
                'error' => false
            ];
        }
    }

    /**
     * restart a server
     *
     * @param int $id
     * @return array
     */
    public static function restartServer($id) {
        $user = BaseModule::getUser();
        if (!ACL::can($user)->update(ServerResource::class, $id)) {
            return [
                'code' => 403
            ];
        }
        $server = Server::loadServer($id);
        if (!$server) {
            return [
                'code' => 404
            ];
        }
        $status = ClusterHelper::restartServer($server->node, $server->vmid);
        if ($user->hasNotificationsEnabled('servers')) {
            // user has ticket notifications enabled, so we need to send him a message her.
            $notification = (new Notification())
                ->setUserId($user->getId())
                ->setType(Notification::TYPE_SERVERS)
                ->setEmail($user->getEmail())
                ->setMeta("server_restarted_" . $id);
            $notification->save();
        }
        EventManager::fire('server::restart', (array) $server);
        return [
            'error' => false
        ];
    }

    /**
     * reset a server (like pulling the plug and starting again)
     *
     * @param int $id
     * @return array
     */
    public static function resetServer($id) {
        $user = BaseModule::getUser();
        if (!ACL::can($user)->update(ServerResource::class, $id)) {
            return [
                'code' => 403
            ];
        }
        $server = Server::loadServer($id);
        if (!$server) {
            return [
                'code' => 404
            ];
        }
        $status = ClusterHelper::resetServer($server->node, $server->vmid);
        if ($user->hasNotificationsEnabled('servers')) {
            // user has ticket notifications enabled, so we need to send him a message her.
            $notification = (new Notification())
                ->setUserId($user->getId())
                ->setType(Notification::TYPE_SERVERS)
                ->setEmail($user->getEmail())
                ->setMeta("server_restarted_" . $id);
            $notification->save();
        }

        EventManager::fire('server::restart', (array) $server);

        return [
            'error' => false
        ];
    }

    /**
     * stop a server (like pulling the plug and done)
     *
     * @param int $id
     * @return array
     */
    public static function stopServer($id) {
        $user = BaseModule::getUser();
        if (!ACL::can($user)->update(ServerResource::class, $id)) {
            return [
                'code' => 403
            ];
        }
        $server = Server::loadServer($id);
        if (!$server) {
            return [
                'code' => 404
            ];
        }

        $status = ClusterHelper::stopServer($server->node, $server->vmid);
        if ($user->hasNotificationsEnabled('servers')) {
            // user has ticket notifications enabled, so we need to send him a message her.
            $notification = (new Notification())
                ->setUserId($user->getId())
                ->setType(Notification::TYPE_SERVERS)
                ->setEmail($user->getEmail())
                ->setMeta("server_restarted_" . $id);
            $notification->save();
        }
        Panel::getDatabase()->update('servers', ['status' => ServerStatus::$OFFLINE], 'id', $id);
        EventManager::fire('server::stop', (array) $server);
        return [
            'error' => false
        ];
    }

    public static function getGraphs($id) {
        $user = BaseModule::getUser();
        if (!ACL::can($user)->read(ServerResource::class, $id)) {
            return [
                'code' => 403
            ];
        }
        $server = Server::loadServer($id);
        if (!$server) {
            return [
                'code' => 404
            ];
        }
        $result = Panel::getProxmox()
            ->get('/nodes/' . $server->node . '/qemu/' . $server->vmid . '/rrddata', [
                'timeframe' => 'hour',
                'cf'        => 'AVERAGE'
            ]);
        return $result['data'];
    }

    public static function delete($id) {
        $user = BaseModule::getUser();
        if (!ACL::can($user)->delete(ServerResource::class, $id)) {
            return [
                'code' => 403
            ];
        }
        $server = Server::loadServer($id);
        if (!$server) {
            return [
                'code' => 404
            ];
        }

        Panel::executeIfModuleIsInstalled('NetworkModule', 'Module\NetworkModule\Controllers\PublicController::__serverDeletion', [$server->id, false]);
        Panel::executeIfModuleIsInstalled('BlockStorageModule', 'Module\BlockStorageModule\Controllers\PublicController::__serverDeletion', [$server->vmid]);

        if ($server->status == ServerStatus::$ONLINE) {
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('server', 'm_err_online')
            ];
        }
        $res = ClusterHelper::deleteServer($server->node, $server->vmid);
        if (!$res) {
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('order', 'm_err_internal'),
            ];
        }

        Panel::getDatabase()->custom_query("UPDATE servers SET deletedAt=NOW() WHERE id=?", ['id' => $id]);
        // set ip free
        if (isset($server->_ip))
            IPAMHelper::setIPStatus(4, $server->_ip->id, IPAMHelper::IP_UNUSED);
        if (isset($server->_ip6))
            IPAMHelper::setIPStatus(6, $server->_ip6->id, IPAMHelper::IP_UNUSED);


        // delete charges, just in case there are any
        Panel::getDatabase()->custom_query("DELETE FROM monthly_charges WHERE serverId=? AND serverType=?", ['serverId' => $server->id, 'serverType' => 'server']);
        if ($user->hasNotificationsEnabled('servers')) {
            // user has ticket notifications enabled, so we need to send him a message her.
            $notification = (new Notification())
                ->setUserId($user->getId())
                ->setType(Notification::TYPE_SERVERS)
                ->setEmail($user->getEmail())
                ->setMeta("server_deleted_" . $id);
            $notification->save();
        }

        EventManager::fire('server::delete', (array) $server);

        return [
            'error'   => false,
            'message' => Panel::getLanguage()->get('server', 'm_server_deleted'),
            'meta'    => $res
        ];
    }

    public static function rebuild($id) {
        $templateId       = $_POST['templateId'];
        $password         = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $ssh              = isset($_POST['ssh']) ? $_POST['ssh'] : false;
        $hostname         = $_POST['hostname'];
        $user             = BaseModule::getUser();
        if (!ACL::can($user)->delete(ServerResource::class, $id) || !ACL::can($user)->update(ServerResource::class, $id)) {
            return [
                'code' => 403
            ];
        }
        $server = Server::loadServer($id);

        if ($server->status == ServerStatus::$ONLINE) {
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('server', "m_err_online")
            ];
        }

        if ($password != $confirm_password || trim($password) == "") {
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('order', 'm_err_pw')
            ];
        }

        $_ssh = $ssh;
        $ssh  = Panel::getDatabase()->fetch_single_row('ssh-keys', 'id', $ssh);
        if ($_ssh && (!$ssh || $ssh->userId != $user->getId())) {
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('order', 'm_err_ssh')
            ];
        }

        if (!Constants::validatePasswordLength($password)) {
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('order', 'm_password_invalid')
            ];
        }

        if ($ssh && $ssh->content != "" && !Constants::validateKey($ssh->content)) {
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('order', 'm_err_ssh')
            ];
        }

        if (!Constants::validateHostname($hostname)) {
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('order', 'm_err_host')
            ];
        }

        if ($server->packageId) {
            $package = Panel::getDatabase()->fetch_single_row('packages', ['id', 'type'], [$server->packageId, Packages::STATIC]);
            if ($package && $package->templateId)
                $templateId = $package->templateId;
        }
        // check if template has a custom name set
        $template = Panel::getDatabase()->fetch_single_row('templates', 'id', $templateId);
        $osName   = $template->displayName;

        $p = Panel::getProxmox();

        Panel::executeIfModuleIsInstalled('NetworkModule', 'Module\NetworkModule\Controllers\PublicController::__serverDeletion', [$server->id, false]);
        Panel::executeIfModuleIsInstalled('BlockStorageModule', 'Module\BlockStorageModule\Controllers\PublicController::__serverDeletion', [$server->vmid]);

        // aquire new vmid
        $nextId = ClusterHelper::getNextId();

        // create clone of the vm
        $node         = [];
        $node['node'] = $server->node;

        if (!$node) {
            EventLog::log("ORDER_NO_NODE", EventLog::ERROR);
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('order', 'm_err_internal'),
                'node'    => $node
            ];
        }

        $_server = ClusterHelper::createClone($node['node'], $template->vmid, $nextId, $hostname);
        if (!$_server) {
            EventLog::log("ORDER_PROXMOX_CLONE", EventLog::ERROR);
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('order', 'm_err_internal'),
                'errors'  => $_server
            ];
        }

        $ip   = isset($server->_ip) ? IPAMHelper::getIpv4ById($server->_ip->id) : false;
        $ip6  = isset($server->_ip6) ? IPAMHelper::getIpv6ById($server->_ip6->id) : false;
        $cpu  = $server->cpu;
        $ram  = $server->ram;
        $disk = $server->disk;

        // load template config from server
        $templateConfig = $p->get('/nodes/' . Settings::getConfigEntry("P_NODE") . '/qemu/' . $template->vmid . '/config');
        $templateConfig = $templateConfig['data'];

        // update vm to use the parameters and set cloud init
        $update = ClusterHelper::updateClone(
            $node['node'],
            $nextId,
            $password,
            $_ssh ? $ssh->content : "",
            $cpu,
            $ram,
            $ip,
            $ip6,
            $templateConfig,
            $template
        );

        if (!$update) {
            EventLog::log("ORDER_PROXMOX_UPDATE", EventLog::ERROR);
            ClusterHelper::deleteServer($node['node'], $nextId);
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('order', 'm_err_internal'),
                'errors'  => $update['errors'],
                'meta'    => 'ip=' . $ip->ip . '/' . $ip->mask . ',gw=' . $ip->gateway
            ];
        }

        // resize hdd to desired size
        $hddresize = ClusterHelper::resizeDisk($node['node'], $nextId, $disk, $templateConfig, $template);
        if (!$hddresize) {
            EventLog::log("ORDER_PROXMOX_RESIZE_DISK", EventLog::ERROR);
            ClusterHelper::deleteServer($node['node'], $nextId);
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('order', 'm_err_internal'),
                'errors'  => $hddresize['errors']
            ];
        }

        // start the server
        $start = ClusterHelper::startServer($node['node'], $nextId);
        if (isset($start['errors'])) {
            EventLog::log("ORDER_PROXMOX_START", EventLog::ERROR);
            ClusterHelper::deleteServer($node['node'], $nextId);
            return [
                'errors'  => $start['errors'],
                'error'   => true,
                'message' => Panel::getLanguage()->get('order', 'm_err_internal')
            ];
        }

        // delete server
        $res = ClusterHelper::deleteServer($server->node, $server->vmid);
        if (!$res) {
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('order', 'm_err_internal'),
            ];
        }

        Panel::getDatabase()->update('servers', [
            'os'       => $osName,
            'vmid'     => $nextId,
            'node'     => $node['node'],
            'hostname' => $hostname,
            'status'   => ServerStatus::$ONLINE
        ], 'id', $server->id);

        if ($user->hasNotificationsEnabled('servers')) {
            // user has ticket notifications enabled, so we need to send him a message her.
            $notification = (new Notification())
                ->setUserId($user->getId())
                ->setType(Notification::TYPE_SERVERS)
                ->setEmail($user->getEmail())
                ->setMeta("server_rebuilded_" . $id);
            $notification->save();
        }

        EventManager::fire('server::create', [
            ...(array) $server,
            'rebuild'  => true,
            'password' => $password
        ]);

        return [
            'errors'  => [$hddresize, $update, $_server],
            'error'   => false,
            'message' => Panel::getLanguage()->get('server', 'm_rebuild_succ')
        ];
    }

    public static function terminal($id) {
        $user = BaseModule::getUser();
        if (!ACL::can($user)->read(ServerResource::class, $id)) {
            return [
                'code' => 401
            ];
        }
        $server = Server::loadServer($id);
        if (!$server) {
            return [
                'code' => 404
            ];
        }

        try {

            $config = Panel::getProxmox()->create('/nodes/' . $server->node . '/qemu/' . $server->vmid . '/vncproxy', [
                'websocket'         => true, // Start websocket
                'generate-password' => true
            ])['data'];

            $token  = sha1(time() . self::getRandomId());
            $client = new \Predis\Client([
                'password' => Settings::getConfigEntry("CONSOLE_PASSWORD", "", true),
                'host'     => Settings::getConfigEntry("CONSOLE_HOST", "", true),
                'port'     => Settings::getConfigEntry("CONSOLE_PORT", 6379)
            ]);

            $host = Settings::getConfigEntry("P_HOST");
            $host = explode(":", $host);
            $host = $host[0];

            $client->set($token, json_encode(['host' => $host . ':' . $config['port']]));
            Panel::compile('_views/_pages/terminal/vnc.html', [
                'APP_URL'  => constant('APP_URL'),
                'token'    => $token,
                'host'     => Settings::getConfigEntry("CONSOLE_HOST", "", true),
                'password' => urlencode($config['password'])
            ]);
        } catch (\Exception $e) {
            die($e->getMessage());
        }
    }

    public static function agentResetPassword($id) {
        $user = BaseModule::getUser();
        $b    = Panel::getRequestInput();
        if (!ACL::can($user)->delete(ServerResource::class, $id)) {
            return [
                'code' => 403
            ];
        }

        $server = Server::loadServer($id);
        if (!$server) {
            return [
                'code' => 404
            ];
        }

        $node = $server->node;
        $vmid = $server->vmid;

        $password = $b['password'];
        $user     = $b['user'];

        // check if guest-agent is active (aka. reachable via ping)
        try {
            $ping = Panel::getProxmox()->create("/nodes/$node/qemu/$vmid/agent/ping");
        } catch (\Exception $ex) {
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('server', 'm_cannot_ping_agent')
            ];
        }

        try {
            Panel::getProxmox()->create("/nodes/$node/qemu/$vmid/agent/set-user-password", [
                'username' => $user,
                'password' => $password
            ]);

            return [
                'error'   => false,
                'message' => Panel::getLanguage()->get('server', 'm_password_reset_done')
            ];

        } catch (\Exception $e) {
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('server', 'm_password_reset_failed'),
                'e'       => $e->getMessage()
            ];
        }
    }

    /**
     * share a server with another user
     *
     * @param int $id
     * @return array
     */
    public static function share($id) {
        $user = BaseModule::getUser();
        $body = Panel::getRequestInput();
        if (!ACL::can($user)->delete(ServerResource::class, $id)) {
            return [
                'code' => 403
            ];
        }

        $server = Server::loadServer($id);
        if (!$server) {
            return [
                'code' => 404
            ];
        }

        $invitedUser = Panel::getDatabase()->fetch_single_row('users', 'email', $body['email']);
        if (!$invitedUser) {
            return [
                'code'    => 404,
                'message' => "User not found"
            ];
        }
        $perms = match ($body['role']) {
            'reader' => "r",
            'editor' => "ru",
            'admin' => "rud",
            default => null
        };

        $exists = Panel::getDatabase()->check_exist('permissions', [
            'resource'   => ServerResource::getName(),
            'resourceId' => $server->id,
            'userId'     => $invitedUser->id
        ]);
        if ($exists) {
            return [
                'code'    => 400,
                'message' => 'User already has access. Modify existing instead of creating new'
            ];
        }

        Panel::getDatabase()->insert('permissions', [
            'userId'      => $invitedUser->id,
            'permissions' => $perms,
            'resource'    => ServerResource::getName(),
            'resourceId'  => $server->id
        ]);

        return [];

    }

    /**
     * revoke access to a server (can only be done by an admin)
     *
     * @param int $id
     * @return array;
     */
    public static function revokeShare($id) {
        $user = BaseModule::getUser();
        $body = Panel::getRequestInput();
        if (!ACL::can($user)->delete(ServerResource::class, $id)) {
            return [
                'code' => 403
            ];
        }

        $server = Server::loadServer($id);
        if (!$server) {
            return [
                'code' => 404
            ];
        }

        Panel::getDatabase()->delete('permissions', 'id', $body['id']);

        return [];
    }

    /**
     * update a permission on a shared server. Can only be done by admins or owner
     *
     * @param int $id
     * @return array
     */
    public static function updateShare($id) {
        $user = BaseModule::getUser();
        $body = Panel::getRequestInput();
        if (!ACL::can($user)->delete(ServerResource::class, $id)) {
            return [
                'code' => 403
            ];
        }

        $server = Server::loadServer($id);
        if (!$server) {
            return [
                'code' => 404
            ];
        }

        Panel::getDatabase()->update('permissions', [
            'permissions' => $body['permissions']
        ], 'id', $body['id']);

        return [];
    }

    /**
     * mark a server as cancelled
     *
     * @param int $id
     * @return array
     */
    public static function cancelServer($id) {
        $user = BaseModule::getUser();
        if (!ACL::can($user)->delete(ServerResource::class, $id)) {
            return [
                'code' => 403
            ];
        }

        Panel::getDatabase()->update('servers', [
            'cancelledAt' => date('Y-m-d H:i:s')
        ], 'id', $id);

        return [];
    }

    /**
     * revoke the cancallation of a server
     *
     * @param number $id
     * @return array
     */
    public static function revokeCancelServer($id) {
        $user = BaseModule::getUser();
        if (!ACL::can($user)->delete(ServerResource::class, $id)) {
            return [
                'code' => 403
            ];
        }

        Panel::getDatabase()->update('servers', [
            'cancelledAt' => null
        ], 'id', $id);

        return [];
    }

    /**
     * unsuspends a suspended Server, removes balance
     *
     * @param float $id
     * @return array
     */
    public static function unsuspend($id) {
        $user = BaseModule::getUser();
        if (!ACL::can($user)->delete(ServerResource::class, $id)) {
            return [
                'code' => 403
            ];
        }
        $server = self::loadServer($id);
        if (!$server) {
            return [
                'code' => 404
            ];
        }
        $server = Panel::getDatabase()->fetch_single_row('servers', 'id', $id);
        $server = new ServerObject($server);

        // check if the user has enough balance
        if ($user->getBalance()->getBalance() >= $server->price) {
            // he has
            $user->getBalance()->removeBalance($server->price);
            $user->getBalance()->save();
            EventManager::fire('balance::remove', (array) $user);

            // update nextPayment in DB
            Panel::getDatabase()->custom_query("UPDATE servers SET nextPayment = NOW() + INTERVAL 30 DAY WHERE id=?", ['id' => $server->id]);

            // insert invoice
            $user->getBalance()->insertInvoice(
                $server->price,
                Invoice::PAYMENT,
                $user->getId(),
                true,
                "Server: " . $server->hostname
            );

            Panel::getDatabase()->update('servers', [
                'status' => ServerStatus::$OFFLINE
            ], 'id', $id);

            EventManager::fire("server::extend", $server->serialize());

            return [
                'message' => 'Successfully unsuspended Server'
            ];
        } else {
            return [
                'code'    => 400,
                'message' => Panel::getLanguage()->get('order', 'm_err_balance')
            ];
        }
    }

    /**
     * return all isos that are located in the set storage
     *
     * @return array
     */
    public static function getIsos($id) {
        $user = BaseModule::getUser();
        if (!ACL::can($user)->read(ServerResource::class, $id)) {
            return [
                'code' => 403
            ];
        }
        $server = self::loadServer($id);
        if (!$server) {
            return [
                'code' => 404
            ];
        }

        $storage = Settings::getConfigEntry("ISO_STORAGE", "");
        if ($storage == "")
            return [];
        $isos = Panel::getProxmox()->get("/nodes/{$server->node}/storage/{$storage}/content", ['content' => 'iso'])['data'];

        $isos = array_map(function ($ele) {
            return explode('/', $ele['volid'])[1];
        }, $isos);

        return $isos;
    }

    /**
     * loads a server with all the data necessary
     *
     * @param float $id the server id
     */
    public static function loadServer($id) {
        $server = Panel::getDatabase()->fetch_single_row('servers', 'id', $id);
        if (!$server || $server->deletedAt != null) {
            return false;
        }
        // load IPAM ip for server
        if ($server->ip && !str_contains($server->ip, '.')) {
            $ip          = IPAMHelper::getIpv4ById($server->ip);
            $server->ip  = $ip->ip;
            $server->_ip = $ip;
        }

        if ($server->ip6) {
            $ip6          = IPAMHelper::getIpv6ById($server->ip6);
            $server->ip6  = $ip6->ip;
            $server->_ip6 = $ip6;
        }

        $server->priceFormatted = Formatters::formatBalance($server->price);

        $server->shareList = array_map(fn($ele) =>
            [...$ele, "role" => ACL::$ROLES[$ele['permissions']]],
            Panel::getDatabase()->custom_query("SELECT p.*, u.email, u.username FROM permissions p RIGHT JOIN users u ON p.userId = u.id WHERE `resourceId`=? AND `resource`=?",
                [
                    'resourceId' => $server->id,
                    'resource'   => ServerResource::getName()
                ])->fetchAll(\PDO::FETCH_ASSOC));

        $server->charges = Panel::getDatabase()->fetch_multi_row('monthly_charges', ['*'], ['serverId' => $id])->fetchAll(\PDO::FETCH_ASSOC);

        return $server;
    }

    public static function getRandomId($length = 8) {
        $characters       = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString     = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }
}