<?php

namespace Module\BaseModule\Controllers;


use Controllers\Panel;
use Module\BaseModule\BaseModule;
use Module\BaseModule\Controllers\Admin\Settings;
use Module\BaseModule\Controllers\Invoice as ControllersInvoice;
use Module\BaseModule\Controllers\IPAM\IPAM;
use Module\BaseModule\Controllers\IPAM\IPAMHelper;
use Module\BaseModule\Objects\ServerStatus;
use Objects\Constants;
use Objects\Event\EventManager;
use Objects\Formatters;
use Objects\Invoice;
use Objects\Permissions\Roles\AdminRole;
use Objects\Permissions\Roles\CustomerRole;
use Objects\Server as ObjectServer;

class Order {

    public static function render() {
        $user = BaseModule::getUser();

        list($c_html, $r_html, $d_html) = self::getHtml();


        $packages = Panel::getDatabase()->custom_query('SELECT packages.*, templates.displayName FROM packages LEFT JOIN templates ON packages.templateId = templates.id ORDER BY `sort` ASC', [])->fetchAll(\PDO::FETCH_ASSOC);
        $packages = array_map(function ($ele) {
            $ele['meta'] = json_decode($ele['meta'], true);
            $ele['ram'] = number_format($ele['ram'] / 1024, 2, ',', '');
            return $ele;
        }, $packages);

        $ssh_keys = Panel::getDatabase()->custom_query("SELECT * FROM `ssh-keys` WHERE userId=?", ['userId' => $user->getId()])->fetchAll(\PDO::FETCH_ASSOC);

        $createSSHModal = Panel::getEngine()->compile('_views/partials/_create-ssh-key.html', array_merge(
            Panel::getLanguage()->getPage('order'),
            Panel::getLanguage()->getPage('ssh-keys')
        ));

        Panel::compile("_views/_pages/server/order.html", array_merge([
            "showCreationTime"  => Settings::getConfigEntry("O_CREATION_TIME", false),
            "c_html"            => $c_html,
            "r_html"            => $r_html,
            "d_html"            => $d_html,
            "default_price"     => "...",
            'packages'          => $packages,
            'ssh_keys'          => $ssh_keys,
            'createSSHModal'    => $createSSHModal,
            'show_configurator' => !Settings::getConfigEntry("O_DISABLE_FREE_ORDER", false)
        ], Panel::getLanguage()->getPages(['vouchers', 'global', 'packages', 'order'])));
    }

    public static function calc($cpu, $ram, $disk) {
        $disk    = intval($disk);
        $cpu     = intval($cpu);
        $ram     = intval($ram);
        $price   = [];
        $price[] =
            Settings::getConfigEntry("O_DISK_BASE", 0) + ((($disk / Settings::getConfigEntry("O_DISK_DEFAULT", 1)) - 1) * Settings::getConfigEntry("O_DISK_PRICE_EACH_EXTRA", 0));
        $price[] =
            Settings::getConfigEntry("O_RAM_BASE", 0) + ((($ram / Settings::getConfigEntry("O_RAM_DEFAULT", 1)) - 1) * Settings::getConfigEntry("O_RAM_PRICE_EACH_EXTRA", 0));
        $price[] =
            Settings::getConfigEntry("O_CORES_BASE", 0) + ((($cpu - Settings::getConfigEntry("O_CORES_DEFAULT", 1))) * Settings::getConfigEntry("O_CORES_PRICE_EACH_EXTRA", 0));

        return [
            'cpu'  => $price[0],
            'ram'  => $price[1],
            'disk' => $price[2]
        ];
    }

    public static function api_calc() {
        $user    = BaseModule::getUser();
        $cpu     = $_POST['cpu'];
        $ram     = $_POST['ram'];
        $disk    = $_POST['disk'];
        $os      = $_POST['os'];
        $voucher = $_POST['voucher'];
        $options = json_decode($_POST['options']);

        $calc = self::calc($cpu, $ram, $disk);

        $price = $calc['cpu'] + $calc['ram'] + $calc['disk'];

        $chargeCalculation = SetupCosts::calculateCharges($price, $os, $options);

        $_rawPrice = $chargeCalculation['rawPrice'];
        $price     = $chargeCalculation['price'];
        $charges   = $chargeCalculation['charges'];

        // check for discount
        if ($voucher && trim($voucher) != "") {
            $check = Panel::getDatabase()->custom_query("SELECT * FROM vouchers WHERE code=? AND (usageTotalLeft=-1 OR usageTotalLeft > 0) AND voucherBase='product'", ['code' => $voucher])->fetchAll(\PDO::FETCH_OBJ);
            if ($check && sizeof($check) > 0) {
                $code = $check[0];
                // check if user has used this code
                $userUsed = Panel::getDatabase()->custom_query("SELECT * FROM voucher_uses WHERE code=? AND userId=?", ['code' => $voucher, 'userId' => $user->getId()])->rowCount();
                if ($userUsed < $code->usagePerCustomer) {
                    // determine if code is type is percent or fixed.
                    switch ($code->voucherType) {
                        case "percentage":
                            // one-time voucher percentage based. So 10% of the first month
                            $price = $price - (($price * $code->voucherTypePercent) / 100);
                            if ($code->voucherRecurring == "recurring") {
                                $_rawPrice = $_rawPrice - (($_rawPrice * $code->voucherTypePercent) / 100);
                            }
                            $charges[] = [
                                'description' => Panel::getLanguage()->get('vouchers', 'm_voucher') . ': ' . $code->code,
                                'negative'    => true,
                                'price'       => $code->voucherTypePercent . '%'
                            ];
                            break;
                        case "fixed":
                            $price = $price - $code->voucherTypeAmount;
                            if ($code->voucherRecurring == "recurring") {
                                $_rawPrice = $_rawPrice - $code->voucherTypeAmount;
                            }
                            $charges[] = [
                                'description' => Panel::getLanguage()->get('vouchers', 'm_voucher') . ': ' . $code->code,
                                'negative'    => true,
                                'price'       => Formatters::formatBalance($code->voucherTypeAmount)
                            ];
                            break;
                    }
                }
            }
        }

        $hasCreationInfo = false;
        $time            = false;
        $_time           = false;
        $creationTime    = Panel::getDatabase()->fetch_single_row('creation_times', 'templateId', $os);
        if ($creationTime) {
            $hasCreationInfo = true;
            $_time           = $creationTime->creationTime;
            $time            = Formatters::formatTimeInSeconds($creationTime->creationTime, 1);
        }

        // configurable charges
        $configurableCharges = Panel::getDatabase()->custom_query("SELECT * FROM `charges` WHERE `active` = 1 AND (`type`=3 OR (`type`=4 AND `osid`=?))", ['osid' => $os])->fetchAll(\PDO::FETCH_ASSOC);

        $configurableCharges = array_map(function ($charge) use ($_rawPrice) {
            $toAdd = 0;
            // charge with a fixed price, so we can just add that to the price
            switch ($charge['calcType']) {
                case SetupCosts::FIXED_PRICE:
                    $toAdd = $charge['price'];
                    break;
                case SetupCosts::PERCENTAGE:
                    $toAdd = ($_rawPrice * $charge['price']) / 100;
                    break;
            }

            $a = [
                '$description' => $charge['description'],
                '$price'       => Formatters::formatBalance($toAdd),
                '$recurring'   => $charge['recurring'] == 1 ? '/' . Panel::getLanguage()->get('order', 'm_monthly') : ''
            ];

            return [
                ...$charge,
                'price'          => Formatters::formatBalance($toAdd),
                'description'    => strtr('$description (+$price$recurring)', $a),
                'descriptionRaw' => $charge['description']
            ];
        }, $configurableCharges);


        return [
            "rawprice"            => Formatters::formatBalance($_rawPrice),
            "price"               => Formatters::formatBalance($price),
            'charges'             => array_values(array_filter($charges, function ($ele) {
                return $ele['calcOnly'] == 0;
            })),
            'hasCreationTimes'    => $hasCreationInfo,
            'creationTime'        => $time,
            '_creationTime'       => $_time,
            'calc'                => $calc,
            'configurableCharges' => $configurableCharges
        ];
    }

    public static function loadIsos() {
        header('Content-Type: application/json');
        $templates = Panel::getDatabase()->custom_query("SELECT * FROM templates WHERE `disabled` = 0 ORDER BY `sort` ASC")->fetchAll(\PDO::FETCH_ASSOC);
        die(json_encode(
            array_values($templates)
        ));
    }

    public static function _loadIsos() {
        try {
            $p = Panel::getProxmox();

            $res = $p->get('nodes/' . Settings::getConfigEntry("P_NODE") . '/qemu');

            $res = array_filter($res['data'], function ($ele) {
                return (isset($ele['template']) && $ele['template']) == 1;
            });
            $res = array_map(function ($ele) {

                $ele['name'] = str_replace('-', ' ', $ele['name']);

                return $ele;
            }, $res);
        } catch (\Exception $e) {
            return [];
        }

        return $res;
    }

    /**
     * actual buying function. Check user balance, validate inputs, calc price etc
     *
     * @return array
     */
    public static function purchase() {
        $eta  = -hrtime(true);
        $user = BaseModule::getUser();

        // get price
        $cpu  = $_POST['cpu'];
        $ram  = $_POST['ram'];
        $disk = $_POST['disk'];

        $os               = isset($_POST['os']) ? $_POST['os'] : false;
        $hostname         = $_POST['hostname'];
        $password         = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        $ssh              = $_POST['ssh'];
        $voucher          = $_POST['voucher'];
        $package          = $_POST['package'] ?? false;
        $options          = json_decode($_POST['options']);

        $dnsDomain     = $_POST['dns_domain'] ?? null;
        $dnsNameserver = $_POST['dns_nameserver'] ?? null;

        $price = array_sum(self::calc($cpu, $ram, $disk));

        $monthly_charges = [];

        if ($os) {
            $template = Panel::getDatabase()->fetch_single_row('templates', 'vmid', $os);
        }

        if ($package) {
            // calculate diff between package-price and "normal" price to insert as a monthly-charge
            $package = Panel::getDatabase()->fetch_single_row('packages', 'id', $package);

            $cpu  = $package->cpu;
            $ram  = $package->ram;
            $disk = $package->disk;

            // recalc price with new specs
            $price = array_sum(self::calc($cpu, $ram, $disk));

            $diffPackagePrice = $package->price - $price;
            if ($diffPackagePrice != 0) {
                $monthly_charges[] = [
                    'description' => "Diff package-price",
                    'charge'      => $diffPackagePrice
                ];
            }

            $price = $package->price;

            if ($package->type == '2') {
                // fixed os
                $template = Panel::getDatabase()->fetch_single_row('templates', 'id', $package->templateId);
                $os       = $template->vmid;
            }
        }

        $vmid   = $template->vmid;
        $osName = $template->displayName;

        // load SSH key
        $_ssh = $ssh;
        $ssh  = Panel::getDatabase()->fetch_single_row('ssh-keys', 'id', $ssh);
        if ($_ssh != 0 && (!$ssh || $ssh->userId != $user->getId())) {
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('order', 'm_err_ssh')
            ];
        }

        $chargeCalculation = SetupCosts::calculateCharges($price, $vmid, $options);
        $charges           = $chargeCalculation['charges'];

        if (!$package) {
            $extras = array_filter($charges, function ($ele) {
                return $ele['type'] == SetupCosts::GLOBAL_CHARGE;
            });
            // add extras to price
            // load global extras
            foreach ($extras as $e) {
                if ($e['recurring']) {
                    $monthly_charges[] = [
                        'description' => $e['description'],
                        'charge'      => $e['rawPrice'],
                        'id'          => $e['id']
                    ];
                }
                $price += $e['rawPrice'];
            }
        }
        // os specific costs
        $extras = array_filter($charges, function ($ele) {
            return $ele['type'] == SetupCosts::OS_CHARGE || $ele['type'] == SetupCosts::CONFIGURABLE || $ele['type'] == SetupCosts::CONFIGURABLE_OS;
        });
        foreach ($extras as $e) {
            $price += $e['rawPrice'];
            if ($e['recurring']) {
                $monthly_charges[] = [
                    'description' => $e['description'],
                    'charge'      => $e['rawPrice'],
                    'id'          => $e['id']
                ];
            }
        }

        // check for discount
        if ($voucher && trim($voucher) != "") {
            $check = Panel::getDatabase()->custom_query("SELECT * FROM vouchers WHERE code=? AND (usageTotalLeft=-1 OR usageTotalLeft > 0) AND voucherBase='product'", ['code' => $voucher])->fetchAll(\PDO::FETCH_OBJ);
            if ($check && sizeof($check) > 0) {
                $code = $check[0];
                // check if user has used this code
                $userUsed = Panel::getDatabase()->custom_query("SELECT * FROM voucher_uses WHERE code=? AND userId=?", ['code' => $voucher, 'userId' => $user->getId()])->rowCount();
                if ($userUsed < $code->usagePerCustomer) {
                    // determine if code is type is percent or fixed.
                    switch ($code->voucherType) {
                        case "percentage":
                            // one-time voucher percentage based. So 10% of the first month
                            if ($code->voucherRecurring == "recurring") {
                                $monthly_charges[] = [
                                    'description' => Panel::getLanguage()->get('vouchers', 'm_voucher') . ': ' . $code->code,
                                    'charge'      => (($price * $code->voucherTypePercent) / 100) * -1
                                ];
                            }
                            $price = $price - (($price * $code->voucherTypePercent) / 100);
                            break;
                        case "fixed":
                            if ($code->voucherRecurring == "recurring") {
                                $monthly_charges[] = [
                                    'description' => Panel::getLanguage()->get('vouchers', 'm_voucher') . ': ' . $code->code,
                                    'charge'      => $code->voucherTypeAmount * -1
                                ];
                            }
                            $price = $price - $code->voucherTypeAmount;
                            break;
                    }
                }
            }
        }

        if ($password != $confirm_password) {
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('order', 'm_err_pw')
            ];
        }

        if ($ssh && $ssh->content != "" && !Constants::validateKey($ssh->content)) {
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('order', 'm_err_ssh')
            ];
        }

        if (!$ssh && !Constants::validatePasswordLength($password)) {
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('order', 'm_password_invalid')
            ];
        }


        if (!Constants::validateHostname($hostname)) {
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('order', 'm_err_host')
            ];
        }

        $user = BaseModule::getUser();
        $b    = $user->getBalance();

        if ($b->getBalance() < $price) {
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('order', 'm_err_balance')
            ];
        }
        // get nextId from cluster
        $nextId = ClusterHelper::getNextId();

        $node = ClusterHelper::getNodeToCreate();

        if (!$node) {
            EventLog::log("ORDER_NO_NODE", EventLog::ERROR);
            return [
                'error'   => true,
                'message' => $user->getRole() == CustomerRole::class ? Panel::getLanguage()->get('order', 'm_err_internal') : EventLog::getMessage("ORDER_NO_NODE"),
                'node'    => $node
            ];
        }


        // IPAM code
        $deploymentType = Settings::getConfigEntry("O_IPAM_DEPLOYMENT", "ip4"); // deployment type with fallback for original ipv4

        $ipv4 = false;
        $ipv6 = false;

        // v4 or v6 deployment
        if ($deploymentType === IPAM::IP4 || $deploymentType === IPAM::DUALSTACK) {
            $v4 = IPAMHelper::getFreeIp(4, $node['node'], $user->getId());
            if ($v4['error']) {
                EventLog::log("ORDER_NO_FREE_IP_4", EventLog::ERROR);
                // no ip found or something, die here
                return [
                    'error'   => true,
                    'message' => $user->getRole() == CustomerRole::class ? Panel::getLanguage()->get('order', 'm_err_internal') : EventLog::getMessage("ORDER_NO_FREE_IP_4")
                ];
            }
            $ipv4 = $v4['ip'];
        }

        if ($deploymentType === IPAM::IP6 || $deploymentType === IPAM::DUALSTACK) {
            $v6 = IPAMHelper::getFreeIp(6, $node['node'], $user->getId());
            if ($v6['error']) {
                EventLog::log("ORDER_NO_FREE_IP_6", EventLog::ERROR);
                // no ip found or something, die here
                return [
                    'error'   => true,
                    'message' => $user->getRole() == CustomerRole::class ? Panel::getLanguage()->get('order', 'm_err_internal') : EventLog::getMessage("ORDER_NO_FREE_IP_6")
                ];
            }
            $ipv6 = $v6['ip'];
        }

        $p = Panel::getProxmox();


        // load template config from server
        $templateConfig = $p->get('/nodes/' . Settings::getConfigEntry("P_NODE") . '/qemu/' . $vmid . '/config');
        $templateConfig = $templateConfig['data'];

        // create clone of vm
        $server = ClusterHelper::createClone($node['node'], $vmid, $nextId, $hostname);
        if (!$server) {
            EventLog::log("ORDER_PROXMOX_CLONE", EventLog::ERROR);
            return [
                'error'   => true,
                'message' => $user->getRole() == CustomerRole::class ? Panel::getLanguage()->get('order', 'm_err_internal') : EventLog::getMessage("ORDER_PROXMOX_CLONE"),
                'errors'  => $server
            ];
        }
        // update vm to use the parameters and set cloud init
        $update = ClusterHelper::updateClone(
            $node['node'],
            $nextId,
            $password,
            $_ssh != 0 ? $ssh->content : "",
            $cpu,
            $ram,
            $ipv4,
            $ipv6,
            $templateConfig,
            $template,
            $dnsDomain,
            $dnsNameserver
        );

        if (!$update) {
            EventLog::log("ORDER_PROXMOX_UPDATE", EventLog::ERROR);
            // delete server if update failed
            ClusterHelper::deleteServer($node['node'], $nextId);
            return [
                'error'   => true,
                'message' => $user->getRole() == CustomerRole::class ? Panel::getLanguage()->get('order', 'm_err_internal') : EventLog::getMessage("ORDER_PROXMOX_UPDATE"),
                'errors'  => $update['errors'],
                'meta'    => [$ipv4, $ipv6]
            ];
        }

        // resize hdd to desired size
        $hddresize = ClusterHelper::resizeDisk($node['node'], $nextId, $disk, $templateConfig, $template);
        if (!$hddresize) {
            EventLog::log("ORDER_PROXMOX_RESIZE_DISK", EventLog::ERROR);
            // delete if resize failed
            ClusterHelper::deleteServer($node['node'], $nextId);
            return [
                'error'   => true,
                'message' => $user->getRole() == CustomerRole::class ? Panel::getLanguage()->get('order', 'm_err_internal') : EventLog::getMessage("ORDER_PROXMOX_RESIZE_DISK"),
                'errors'  => $hddresize['errors']
            ];
        }

        // start the server
        $start = ClusterHelper::startServer($node['node'], $nextId);
        if (isset($start['errors']) || !$start) {
            EventLog::log("ORDER_PROXMOX_START", EventLog::ERROR);
            ClusterHelper::deleteServer($node['node'], $nextId);
            return [
                'errors'  => $start['errors'],
                'error'   => true,
                'message' => $user->getRole() == CustomerRole::class ? Panel::getLanguage()->get('order', 'm_err_internal') : EventLog::getMessage("ORDER_PROXMOX_START")
            ];
        }

        $b->removeBalance($price);
        $b->save();
        $b->insertInvoice(
            $price,
            Invoice::PAYMENT,
            $user->getId(),
            true,
            "Server: " . $hostname
        );

        EventManager::fire('balance::remove', (array) $user);

        // save server in db
        $l        = Panel::getDatabase()->insert(
            'servers',
            [
                'userid'      => $user->getId(),
                'vmid'        => $nextId,
                'hostname'    => $hostname,
                'cpu'         => $cpu,
                'ram'         => $ram,
                'disk'        => $disk,
                'os'          => $osName,
                'ip'          => $ipv4 ? $ipv4->id : null,
                'ip6'         => $ipv6 ? $ipv6->id : null,
                'nextPayment' => date('Y-m-d H:i:s', strtotime('+30 days')),
                'node'        => $node['node'],
                'packageId'   => $package ? $package->id : null,
                'status'      => ServerStatus::$ONLINE,
                'price'       => $price
            ]
        );
        $serverId = Panel::getDatabase()->get_last_id();

        // mark ips as used
        if ($ipv4)
            IPAMHelper::setIPStatus(4, $ipv4->id, IPAMHelper::IP_USED);
        if ($ipv6)
            IPAMHelper::setIPStatus(6, $ipv6->id, IPAMHelper::IP_USED);


        if (!$l) {
            return [
                'errors'  => $start['errors'],
                'error'   => true,
                'message' => Panel::getLanguage()->get('order', 'm_err_internal')
            ];
        }
        // save extras in DB
        if (sizeof($monthly_charges) > 0) {
            foreach ($monthly_charges as $charge) {
                Panel::getDatabase()->insert('monthly_charges', [
                    'serverId'    => $serverId,
                    'amount'      => $charge['charge'],
                    'description' => $charge['description'],
                    'chargeId'    => $charge['id'],
                    'serverType'  => 'server'
                ]);
            }
        }
        if ($voucher && trim($voucher) != "") {
            Panel::getDatabase()->insert('voucher_uses', [
                'userId' => $user->getId(),
                'code'   => $voucher
            ]);
        }

        $eta += hrtime(true);
        // recalc nanoseconds to seconds
        $eta = $eta / 1000000000;

        // check if record is already there, otherwise insert new one
        $creationTimeExists = Panel::getDatabase()->check_exist('creation_times', ['templateId' => $os]);
        if ($creationTimeExists) {
            // insert creation time record and recalc new average
            Panel::getDatabase()->custom_query(<<<SQL
                UPDATE creation_times SET
                    creationTime = (creationTime * totalCount + $eta) / (totalCount + 1),
                    totalCount = totalCount + 1
                WHERE templateId=$vmid
            
            SQL);
        } else {
            Panel::getDatabase()->insert('creation_times', [
                'creationTime' => $eta,
                'totalCount'   => 1,
                'templateId'   => $vmid
            ]);
        }
        $server = Server::loadServer($serverId);

        EventManager::fire('server::create', [
            ...((array) $server),
            'password' => $password
        ]);

        return [
            'error'    => false,
            'message'  => Panel::getLanguage()->get('order', 'm_no_err'),
            'redirect' => Settings::getConfigEntry("APP_URL") . 'server/' . $serverId
        ];
    }

    public static function loadVmInfo() {
        header('Content-Type: application/json');
        $user = BaseModule::getUser();
        if ($user->getPermission() < 2) {
            die(json_encode(['error' => true, 'msg' => 'no_perm']));
        }


        $vmid = $_POST['vmid'];
        $node = $_POST['node'];

        $exists = Panel::getDatabase()->custom_query('SELECT * FROM servers WHERE deletedAt IS NULL AND vmid=?', ['vmid' => $vmid])->fetchAll();
        if ($exists) {
            die(json_encode(['error' => true, 'msg' => 'exists']));
        }

        $p = Panel::getProxmox();
        try {
            $s = $p->get('/nodes/' . $node . '/qemu/' . $vmid . '/config');
            $d = $p->get('/nodes/' . $node . '/qemu/' . $vmid . '/status/current');
        } catch (\Exception $e) {
            return [
                'error' => true,
                'msg'   => 'no_found'
            ];
        }
        die(json_encode([
            'error'  => false,
            'config' => $s['data'],
            'status' => $d['data']
        ]));
    }

    public static function loadServerInfo($id) {
        header('Content-Type: application/json');
        $user = BaseModule::getUser();
        if ($user->getPermission() < 2) {
            die(json_encode(['error' => true, 'msg' => 'no_perm']));
        }
        die(json_encode(['server' => Panel::getDatabase()->fetch_single_row('servers', 'id', $id)]));
    }

    public static function updateServerInfo($id) {
        $b    = Panel::getRequestInput();
        $user = BaseModule::getUser();
        if ($user->getPermission() < 2) {
            return ['error' => true, 'code' => 403];
        }
        $server = Panel::getDatabase()->fetch_single_row('servers', 'id', $id);
        $server = new ObjectServer($server);

        if (isset($b['unlink']) && filter_var($b['unlink'], FILTER_VALIDATE_BOOL)) {
            // unlink the server
            Panel::executeIfModuleIsInstalled('NetworkModule', 'Module\NetworkModule\Controllers\PublicController::__serverDeletion', [$server->id, false]);

            Panel::getDatabase()->custom_query("UPDATE servers SET deletedAt=NOW() WHERE id=?", ['id' => $id]);
            // set ip free
            if (isset($server->_ip))
                IPAMHelper::setIPStatus(4, $server->_ip->id, IPAMHelper::IP_UNUSED);
            if (isset($server->_ip6))
                IPAMHelper::setIPStatus(6, $server->_ip6->id, IPAMHelper::IP_UNUSED);


            // delete charges, just in case there are any
            Panel::getDatabase()->custom_query("DELETE FROM monthly_charges WHERE serverId=? AND serverType=?", ['serverId' => $server->id, 'serverType' => 'server']);

            EventManager::fire('server::delete', (array) $server);

            return [
                'success' => true
            ];
        }

        Panel::getDatabase()->update('servers', [
            'cpu'    => $b['cpu'],
            'ram'    => $b['ram'],
            'disk'   => $b['disk'],
            'node'   => $b['node'],
            'userid' => $b['userid'],
            'price'  => (float) $b['price']
        ], 'id', $id);


        if (filter_var($b['applyChanges'], FILTER_VALIDATE_BOOLEAN)) {
            // apply changes inside of Proxmox - don't move between nodes
            $res = ClusterHelper::applyPatch($b['node'], $server->vmid, [
                'cores'  => $b['cpu'],
                'memory' => $b['ram']
            ]);



            if ($server->disk < $b['disk']) {
                // resize disk
                $node   = $b['node'];
                $config = Panel::getProxmox()->get("/nodes/$node/qemu/$server->vmid/config")['data'];

                $availableDrives = array_filter(array_keys($config), function ($e) {
                    return preg_match("/^(scsi|ide|sata|virtio)(\d+)$/", $e);
                });

                // isci, sata, virtio 
                $drive = "";
                if (in_array('scsi0', $availableDrives)) {
                    $drive = "scsi0";
                } else if (in_array('sata0', $availableDrives)) {
                    $drive = "sata0";
                } else if (in_array('virtio0', $availableDrives)) {
                    $drive = 'virtio0';
                }

                $res = Panel::getProxmox()->set("/nodes/$node/qemu/$server->vmid/resize", [
                    'disk' => $drive,
                    'size' => $b['disk'] . 'G'
                ]);
            }
        }

        $server = Panel::getDatabase()->fetch_single_row('servers', 'id', $server->id, \PDO::FETCH_ASSOC);
        $server = new ObjectServer($server);
        EventManager::fire("server::modified", $server->serialize());

        return $server->serialize();
    }

    public static function importServer() {
        $b     = Panel::getRequestInput();
        $_user = BaseModule::getUser();

        $node           = $b['node'];
        $vmid           = $b['vmid'];
        $user           = $b['user'];
        $name           = $b['name'];
        $cpu            = $b['cores'];
        $ram            = $b['ram'];
        $disk           = $b['disk'];
        $deploymentType = $b['deploymentType'];
        $ipv4Info       = $b['ipv4Info'];
        $ipv6Info       = $b['ipv6Info'];
        $isLXC          = isset($b['isLXC']);
        $price          = array_sum(Order::calc($cpu, $ram, $disk));


        $ipv4Id   = null;
        $saveIpv4 = $deploymentType === IPAM::DUALSTACK || $deploymentType === IPAM::IP4;
        $ipv6Id   = null;
        $saveIpv6 = $deploymentType === IPAM::DUALSTACK || $deploymentType === IPAM::IP6;


        if ($_user->getRole() != AdminRole::class) {
            return [
                'error' => true,
                'code'  => 403
            ];
        }

        if ($saveIpv4) {
            $ip4 = explode('/', $ipv4Info['ip'])[0]; // ip without subnet
            // check if IP already exists in the provided IPAM range. If not, create it
            $ipamExists = Panel::getDatabase()->custom_query(
                "SELECT * FROM ipam_4_addresses WHERE ip=? AND fk_ipam=? LIMIT 1",
                [
                    'ip'   => $ip4,
                    'ipam' => $ipv4Info['ipam']
                ]
            )->fetchAll(\PDO::FETCH_OBJ);
            if (!$ipamExists || sizeof($ipamExists) == 0) {
                // IP does not exist in that range, so we create it. We don't care if it's in a different range already - for reasons.
                Panel::getDatabase()->insert('ipam_4_addresses', [
                    'ip'      => $ip4,
                    'fk_ipam' => $ipv4Info['ipam'],
                    'mac'     => $ipv4Info['mac'] ?? null,
                    'in_use'  => 1
                ]);
                // get last inserted ID, since we need to save that in the servers-table
                $ipv4Id = Panel::getDatabase()->get_last_id();
            } else {
                $ipv4Id = $ipamExists[0]->id;
                IPAMHelper::setIPStatus('4', $ipv4Id, IPAMHelper::IP_USED);
            }
        }

        if ($saveIpv6) {
            $ipamExists = Panel::getDatabase()->custom_query(
                "SELECT * FROM ipam_6_addresses WHERE ip=? AND fk_ipam=? LIMIT 1",
                [
                    'ip'      => $ipv6Info['ip'],
                    'fk_ipam' => $ipv6Info['ipam']
                ]
            )->fetchAll(\PDO::FETCH_OBJ);
            if (!$ipamExists || sizeof($ipamExists) == 0) {
                // insert "new" IP into the ipam range
                Panel::getDatabase()->insert('ipam_6_addresses', [
                    'ip'      => $ipv6Info['ip'],
                    'fk_ipam' => $ipv6Info['ipam'],
                    'in_use'  => 1
                ]);
                $ipv6Id = Panel::getDatabase()->get_last_id();
            } else {
                $ipv6Id = $ipamExists[0]->id;
                IPAMHelper::setIPStatus('6', $ipv6Id, IPAMHelper::IP_USED);
            }
        }

        $meta   = Panel::getProxmox()->get('/nodes/' . $node . ($isLXC ? '/lxc/' : '/qemu/') . $vmid . '/status/current');
        $status = $meta['data']['status'];
        $status = $status == 'stopped' ? ServerStatus::$OFFLINE : ServerStatus::$ONLINE;

        Panel::getDatabase()->insert($isLXC ? 'containers' : 'servers', [
            'vmid'                => $vmid,
            'userid'              => $user,
            'hostname'            => $name,
            'cpu'                 => $cpu,
            'ram'                 => $ram,
            'disk'                => $disk,
            'ip'                  => $ipv4Id,
            'ip6'                 => $ipv6Id,
            'os'                  => $name,
            'node'                => $node,
            'deletedAt'           => null,
            'nextPayment'         => date('Y-m-d H:i:s', strtotime('+30 days')),
            'paymentReminderSent' => null,
            'status'              => $status,
            'price'               => $price
        ]);

        return [
            'error' => false
        ];
    }

    public static function api_get_specs() {
        header('Content-Type: application/json');
        $os       = $_POST['os'];
        $template = Panel::getDatabase()->fetch_single_row("templates", 'vmid', $os);
        if (
            !$template ||
            ($template->minCpu == 0 &&
                $template->minRAM == 0 &&
                $template->minDisk == 0)
        ) {
            die(json_encode(self::getOrderOptions()));
        }
        die(json_encode(self::getOrderOptions(true, $template->minCpu, $template->minRAM, $template->minDisk)));
    }

    public static function getOrderOptions($withPrices = true, $minCpu = 0, $minRam = 0, $minDisk = 0) {

        $currency = ControllersInvoice::getActiveCurrency()['symbol'];
        $position = Settings::getConfigEntry('CURRENCY_POSITION', 'BEHIND');

        /**
         * "O_CORES_DEFAULT": "2",
         * "O_CORES_BASE": "2",
         * "O_CORES_PRICE_EACH_EXTRA": "2",
         * "O_CORES_MAX": "2",
         */
        $i      = (int) (Settings::getConfigEntry("O_CORES_DEFAULT", 1));
        $c_html = [];
        while ($i <= Settings::getConfigEntry("O_CORES_MAX")) {
            if ($i >= $minCpu) {
                $price = number_format(($i - Settings::getConfigEntry("O_CORES_DEFAULT")) * Settings::getConfigEntry("O_CORES_PRICE_EACH_EXTRA"), 2);

                $pD       = $position === "BEHIND" ? "$price $currency" : "$currency $price";
                $c_html[] = [
                    'value'    => $i,
                    'text'     => $i . ($withPrices && $i != Settings::getConfigEntry("O_CORES_DEFAULT") ? " (" . ($price > 0 ? "+" : " ") . $pD . ")" : ''),
                    'selected' => $i == Settings::getConfigEntry("O_CORES_DEFAULT")
                ];
            }
            $i++;
        }
        /**
         * 
         * "O_RAM_DEFAULT": 512,
         * "O_RAM_BASE": 2.32,
         * "O_RAM_PRICE_EACH_EXTRA": 1,
         * "O_RAM_MAX": 16384,
         */

        $i              = (int) Settings::getConfigEntry("O_RAM_DEFAULT", 1);
        $counter        = 1;
        $r_html         = [];
        $onlyUseSquares = defined("O_INTERPOLATE_RAM") ? O_INTERPOLATE_RAM : false;
        while ($i <= Settings::getConfigEntry("O_RAM_MAX")) {
            if ($i >= $minRam) {
                $price    = number_format((($i / Settings::getConfigEntry("O_RAM_DEFAULT")) - 1) * Settings::getConfigEntry("O_RAM_PRICE_EACH_EXTRA"), 2);
                $pD       = $position === "BEHIND" ? "$price $currency" : "$currency $price";
                $r_html[] = [
                    'value'    => $i,
                    'text'     => $i . " MB (" . $i / 1024 . " GB) " . ($withPrices && $i != Settings::getConfigEntry("O_RAM_DEFAULT") ? "(" . ($price > 0 ? "+" : " ") . $pD . ")" : ""),
                    'selected' => $i == Settings::getConfigEntry("O_RAM_DEFAULT")
                ];
            }
            $i = $onlyUseSquares ? $i * 2 : $i + Settings::getConfigEntry("O_RAM_DEFAULT");
            $counter++;
        }

        /**
         * "O_DISK_DEFAULT": 10,
         * "O_DISK_BASE": 1,
         * "O_DISK_PRICE_EACH_EXTRA": 1,
         * "O_DISK_MAX": 100
         */
        $i      = (int) Settings::getConfigEntry("O_DISK_DEFAULT", 1);
        $d_html = [];
        while ($i <= Settings::getConfigEntry("O_DISK_MAX")) {
            if ($i >= $minDisk) {
                $price    = number_format((($i / Settings::getConfigEntry("O_DISK_DEFAULT")) - 1) * Settings::getConfigEntry("O_DISK_PRICE_EACH_EXTRA"), 2);
                $pD       = $position === "BEHIND" ? "$price $currency" : "$currency $price";
                $d_html[] = [
                    'value'    => $i,
                    'text'     => $i . " GB" . ($withPrices && $i != Settings::getConfigEntry("O_DISK_DEFAULT") ? " (" . ($price > 0 ? "+" : " -") . $pD . ")" : ""),
                    'selected' => $i == Settings::getConfigEntry("O_DISK_DEFAULT")
                ];
            }
            $i += Settings::getConfigEntry("O_DISK_DEFAULT");
        }

        return [
            'cpu'  => $c_html,
            'ram'  => $r_html,
            'disk' => $d_html
        ];
    }

    public static function getHtml($withPrices = true) {
        $currency = ControllersInvoice::getActiveCurrency()['symbol'];
        $position = Settings::getConfigEntry('CURRENCY_POSITION', 'BEHIND');

        /**
         * "O_CORES_DEFAULT": "2",
         * "O_CORES_BASE": "2",
         * "O_CORES_PRICE_EACH_EXTRA": "2",
         * "O_CORES_MAX": "2",
         */
        $i      = Settings::getConfigEntry("O_CORES_DEFAULT", 1);
        $c_html = "";
        while ($i <= Settings::getConfigEntry("O_CORES_MAX")) {
            if ($i == Settings::getConfigEntry("O_CORES_DEFAULT")) {
                $c_html .= "<option selected value='" . $i . "'>" . $i . "</option>";
            } else {
                $price  = number_format(($i - Settings::getConfigEntry("O_CORES_DEFAULT")) * Settings::getConfigEntry("O_CORES_PRICE_EACH_EXTRA"), 2);
                $pD     = $position === "BEHIND" ? "$price $currency" : "$currency $price";
                $c_html .= "<option value='" . $i . "'>" . $i . ($withPrices ? " (" . ($price > 0 ? " +" : " ") . $pD . ")" : '') . "</option>";
            }
            $i++;
        }
        /**
         * 
         * "O_RAM_DEFAULT": 512,
         * "O_RAM_BASE": 2.32,
         * "O_RAM_PRICE_EACH_EXTRA": 1,
         * "O_RAM_MAX": 16384,
         */

        $i              = Settings::getConfigEntry("O_RAM_DEFAULT", 1);
        $counter        = 1;
        $r_html         = "";
        $onlyUseSquares = defined("O_INTERPOLATE_RAM") ? O_INTERPOLATE_RAM : false;
        while ($i <= Settings::getConfigEntry("O_RAM_MAX")) {
            if ($i == Settings::getConfigEntry("O_RAM_DEFAULT")) {
                $r_html .= "<option selected value='" . $i . "'>" . $i . " MB (" . $i / 1024 . " GB)</option>";
            } else {
                $price  = number_format((($i / Settings::getConfigEntry("O_RAM_DEFAULT")) - 1) * Settings::getConfigEntry("O_RAM_PRICE_EACH_EXTRA"), 2);
                $pD     = $position === "BEHIND" ? "$price $currency" : "$currency $price";
                $r_html .= "<option value='" . $i . "'>" . $i . " MB (" . $i / 1024 . " GB) " . ($withPrices ? "(" . ($price > 0 ? " +" : " ") . $pD . ")" : "") . "</option>";
            }
            $i = $onlyUseSquares ? $i * 2 : $i + Settings::getConfigEntry("O_RAM_DEFAULT");
            $counter++;
        }

        /**
         * "O_DISK_DEFAULT": 10,
         * "O_DISK_BASE": 1,
         * "O_DISK_PRICE_EACH_EXTRA": 1,
         * "O_DISK_MAX": 100
         */
        $i      = Settings::getConfigEntry("O_DISK_DEFAULT", 1);
        $d_html = "";
        while ($i <= Settings::getConfigEntry("O_DISK_MAX")) {
            if ($i == Settings::getConfigEntry("O_DISK_DEFAULT")) {
                $d_html .= "<option selected value='" . $i . "'>" . $i . " GB</option>";
            } else {
                $price  = number_format((($i / Settings::getConfigEntry("O_DISK_DEFAULT")) - 1) * Settings::getConfigEntry("O_DISK_PRICE_EACH_EXTRA"), 2);
                $pD     = $position === "BEHIND" ? "$price $currency" : "$currency $price";
                $d_html .= "<option value='" . $i . "'>" . $i . " GB" . ($withPrices ? " (" . ($price > 0 ? " +" : " -") . $pD . ")" : "") . "</option>";
            }
            $i += Settings::getConfigEntry("O_DISK_DEFAULT");
        }

        return [
            $c_html, $r_html, $d_html
        ];
    }

    /**
     * returns configuration options for the order page / resize dialog
     *
     * @return array
     */
    public static function getOrderParameters($id) {
        $server = Server::loadServer($id);

        $cpu    = [];
        $memory = [];
        $disk   = [];


        // cpu
        $i = Settings::getConfigEntry("O_CORES_DEFAULT", 1);
        while ($i <= Settings::getConfigEntry("O_CORES_MAX")) {
            if ($i == $server->cpu) {
                $cpu[$i] = $i;
            } else {
                $price   = number_format((($i - $server->cpu + 1) - Settings::getConfigEntry("O_CORES_DEFAULT")) * Settings::getConfigEntry("O_CORES_PRICE_EACH_EXTRA", 0), 2);
                $cpu[$i] = "$i (" . ($price > 0 ? "+" : "") . Formatters::formatBalance($price) . ")";
            }
            $i++;
        }


        // memory
        $i              = Settings::getConfigEntry("O_RAM_DEFAULT", 1);
        $counter        = 1;
        $onlyUseSquares = Settings::getConfigEntry("O_INTERPOLATE_RAM", false);
        while ($i <= Settings::getConfigEntry("O_RAM_MAX")) {
            if ($i == $server->ram) {
                $memory[$i] = $i . " MB (" . $i / 1024 . " GB)";
            } else {
                $priceDefault   = Settings::getConfigEntry("O_RAM_DEFAULT");
                $priceEachExtra = Settings::getConfigEntry("O_RAM_PRICE_EACH_EXTRA");

                $price = number_format(((($i - $server->ram) / $priceDefault)) * $priceEachExtra, 2);

                $pa         = "(" . ($price > 0 ? "+" : "") . Formatters::formatBalance($price) . ")";
                $memory[$i] = $i . " MB (" . $i / 1024 . " GB) " . $pa;

            }
            $i = $onlyUseSquares ? $i * 2 : $i + Settings::getConfigEntry("O_RAM_DEFAULT");
            $counter++;
        }

        // disk
        $i = Settings::getConfigEntry("O_DISK_DEFAULT", 1);
        while ($i <= Settings::getConfigEntry("O_DISK_MAX")) {
            if ($i >= $server->disk) {
                if ($i == $server->disk) {
                    $disk[$i] = "$i GB";
                } else {
                    $priceDefault   = Settings::getConfigEntry("O_DISK_DEFAULT");
                    $priceEachExtra = Settings::getConfigEntry("O_DISK_PRICE_EACH_EXTRA");
                    $price          = number_format(((($i - $server->disk) / $priceDefault)) * $priceEachExtra, 2);

                    $disk[$i] = "$i GB (" . ($price > 0 ? "+" : "") . Formatters::formatBalance($price) . ")";

                }
            }
            $i += Settings::getConfigEntry("O_DISK_DEFAULT");
        }

        return [
            'cpu'    => $cpu,
            'memory' => $memory,
            'disk'   => $disk
        ];
    }

    /**
     * purchase a server upgrade (rescale)
     * 
     * if the server is a package, we can switch to a different package.
     * The specs of the server need to be adjusted in the database 
     * AND rescaled on Proxmox
     *
     * @param float $id
     * @return array
     */
    public static function purchaseResize($id) {
        $server = Server::loadServer($id);
        $user   = BaseModule::getUser();
        $b      = Panel::getRequestInput();

        $cpu = $b['cpu'];
        $mem = $b['memory'];
        // disk needs to be set differently
        $disk = $b['disk'];
        // leave disk => leave disk untouched during the rescale to be able to scale down again
        $leaveDisk = filter_var($b['leaveDisk'], FILTER_VALIDATE_BOOLEAN);
        // if the context is a package, this will be the ID
        $packageId = $b['packageId'];

        $price    = self::getNewServerPrice($id);
        $payToday = $price['payTodayRaw'];

        // check if user has enough balance
        if ($user->getBalance()->getBalance() <= $payToday) {
            return [
                'code'    => 400,
                'message' => Panel::getLanguage()->get('order', 'm_err_balance')
            ];
        }

        $balance = $user->getBalance();
        // create invoice / gutschrift if negative
        if ($payToday > 0) {
            $balance->removeBalance($payToday);
        } else {
            $balance->addBalance($payToday * -1);
        }
        $balance->insertInvoice(
            $payToday > 0 ? $payToday : $payToday * -1,
            $payToday > 0 ? Invoice::PAYMENT : Invoice::CREDIT,
            $user->getId(),
            true,
            "Rescale: " . $server->hostname);

        $balance->save();

        EventManager::fire('balance::remove', (array) $user);

        // packageID to test with = 8
        if ($server->packageId && $packageId) {
            $package = Packages::getOne($packageId);

            $cpu  = $package->cpu;
            $mem  = $package->ram;
            $disk = $package->disk;
        }

        // use ClusterHelper::applyPatch to modify server specs
        ClusterHelper::applyPatch($server->node, $server->vmid, [
            'cores'  => $cpu,
            'memory' => $mem
        ]);

        if (($packageId == "" && $server->disk != $disk) || $packageId != "" && !$leaveDisk) {
            $config = Panel::getProxmox()->get("/nodes/$server->node/qemu/$server->vmid/config")['data'];

            $availableDrives = array_filter(array_keys($config), function ($e) {
                return preg_match("/^(scsi|ide|sata|virtio)(\d+)$/", $e);
            });

            // isci, sata, virtio 
            $drive = "";
            if (in_array('scsi0', $availableDrives)) {
                $drive = "scsi0";
            } else if (in_array('sata0', $availableDrives)) {
                $drive = "sata0";
            } else if (in_array('virtio0', $availableDrives)) {
                $drive = 'virtio0';
            }

            $res = Panel::getProxmox()->set("/nodes/$server->node/qemu/$server->vmid/resize", [
                'disk' => $drive,
                'size' => $disk . 'G'
            ]);
        } else {
            $disk = $server->disk;
        }

        Panel::getDatabase()->update('servers', [
            'cpu'       => $cpu,
            'ram'       => $mem,
            'disk'      => $disk,
            'packageId' => $server->packageId && $packageId != "" ? $packageId : null,
            'price'     => $price['newPriceRaw']
        ], 'id', $server->id);

        // reload entry
        $server = Panel::getDatabase()->fetch_single_row('servers', 'id', $server->id, \PDO::FETCH_ASSOC);
        $server = new ObjectServer($server);
        EventManager::fire("server::modified", $server->serialize());

        return [];
    }

    public static function getNewServerPrice($id) {
        $server = Server::loadServer($id);
        $user   = BaseModule::getUser();
        $b      = Panel::getRequestInput();

        $cpu       = $b['cpu'];
        $memory    = $b['memory'];
        $disk      = $b['disk'];
        $packageId = $b['packageId'] ?? null;

        $newPrice = array_sum(self::calc($cpu, $memory, $disk));
        $oldPrice = $server->price;

        $charges = Panel::getDatabase()->custom_query("SELECT * FROM monthly_charges WHERE serverId=? AND serverType='server'", ['serverId' => $server->id]);
        if ($charges->rowCount() > 0) {
            $newPrice += array_sum(array_map(function ($c) {
                return $c->amount;
            }, $charges->fetchAll(\PDO::FETCH_OBJ)));
        }

        if ($packageId) {
            $package  = Packages::getOne($packageId);
            $oldPrice = $server->price;
            $newPrice = $package->price;
        }

        // hier noch einbauen, dass die Restlaufzeit des Servers beachtet wird, sprich wie weit sind
        // wir in der aktuellen Abrechnungsperiode, wenn ich einen Server fuer 1 Monat habe und nach 2 Wochen
        // ein upgrade durchfuehre, sollen nur die restlichen 2 Wochen berechnet werden
        // bevor dann zum naechsten Abrechnungszeitpunkt sowieso das normale Modell greift
        $billingStart   = strtotime('-' . 1 . ' months', strtotime($server->nextPayment));
        $billingEnd     = strtotime($server->nextPayment);
        $billingCurrent = time();

        $billingCompleted = (($billingCurrent - $billingStart) / ($billingEnd - $billingStart));

        $diff = ($newPrice - $oldPrice) * (1 - $billingCompleted);

        // return diff in price
        return [
            'newPrice'             => Formatters::formatBalance($newPrice),
            'oldPrice'             => Formatters::formatBalance($oldPrice),
            'monthlyDiff'          => Formatters::formatBalance($newPrice - $server->price),
            'payToday'             => Formatters::formatBalance($diff),
            'payTodayRaw'          => $diff,
            'billingPeriodCovered' => number_format($billingCompleted * 100, 2),
            'newPriceRaw'          => $newPrice
        ];

    }
}
