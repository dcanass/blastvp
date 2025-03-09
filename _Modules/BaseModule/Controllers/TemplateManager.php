<?php

namespace Module\BaseModule\Controllers;

use Controllers\Panel;
use Module\BaseModule\BaseModule;
use Module\BaseModule\Controllers\Admin\Settings;
use Objects\Permissions\Roles\AdminRole;

class TemplateManager {

    public static function listAll() {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }

        Panel::compile("_views/_pages/admin/templates.html", array_merge([], Panel::getLanguage()->getPages(['global', 'admin_templates'])));
    }

    public static function get() {
        // load all templates from proxmox
        $proxmox   = Order::_loadIsos();
        $templates = [];

        $dbTemplates = Panel::getDatabase()->custom_query("SELECT * FROM templates ORDER BY `sort` ASC")->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($proxmox as $template) {
            $entry = array_filter($dbTemplates, function ($e) use ($template) {
                return $e['vmid'] == $template['vmid'];
            });
            if (sizeof($entry) > 0) {
                $entry = array_shift($entry);
            } else {
                $entry = Panel::getDatabase()->insert('templates', ['vmid' => $template['vmid'], 'displayName' => $template['name']]);
                $entry = Panel::getDatabase()->fetch_single_row('templates', 'vmid', $template['vmid'], \PDO::FETCH_ASSOC);
            }
            $entry['orphaned'] = false;
            $templates[]       = $entry;
        }

        foreach ($dbTemplates as $dbTemplate) {
            $entry = array_filter($templates, function ($e) use ($dbTemplate) {
                return $e['vmid'] == $dbTemplate['vmid'];
            });
            if (sizeof($entry) == 0) {
                $dbTemplate['orphaned'] = true;
                $templates[]            = $dbTemplate;
            }
        }


        $sort = array_column($templates, 'sort');
        array_multisort($sort, SORT_ASC, $templates);

        return ['templates' => $templates];
    }

    public static function getDetailedConfig($vmid) {
        header('Content-Type: application/json');
        $user = BaseModule::getUser();
        if ($user->getPermission() < 2) {
            die('401');
        }

        $config = Panel::getProxmox()->get('/nodes/' . Settings::getConfigEntry('P_NODE') . "/qemu/$vmid/config")['data'];


        die(json_encode([
            'config' => $config,
        ]));
    }

    public static function saveTemplate($id) {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return [
                'code' => 403
            ];
        }

        $displayName  = $_POST['displayName'];
        $defaultUser  = $_POST['defaultUser'];
        $defaultDrive = $_POST['defaultDrive'];
        $minDisk      = (int) $_POST['minDisk'];
        $minCpu       = (int) $_POST['minCpu'];
        $minRAM       = (int) $_POST['minRAM'];

        $template = Panel::getDatabase()->fetch_single_row('templates', 'id', $id);
        if (!$template) {
            return [
                'code' => 404
            ];
        }

        Panel::getDatabase()->update('templates', [
            'displayName'  => $displayName,
            'defaultUser'  => $defaultUser,
            'defaultDrive' => $defaultDrive,
            'minDisk'      => $minDisk,
            'minCpu'       => $minCpu,
            'minRAM'       => $minRAM
        ], 'id', $id);
        return [
            $displayName,
            $defaultUser,
            $defaultDrive,
            $minDisk,
            $minCpu,
            $minRAM
        ];
    }

    /**
     * disable a template
     *
     * @param int $vmid
     * @return array
     */
    public static function disableTemplate($vmid) {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return [
                'code' => 403
            ];
        }

        $exists = Panel::getDatabase()->fetch_single_row('templates', 'vmid', $vmid, \PDO::FETCH_OBJ);
        if (!$exists) {
            $exists = Panel::getDatabase()->insert('templates', ['vmid' => $vmid, 'disabled' => 1]);
        } else {
            Panel::getDatabase()->update('templates', [
                'disabled' => 1,
            ], 'id', $exists->id);
        }

        return [
            'error' => false
        ];
    }

    /**
     * enable a template
     *
     * @param int $vmid
     * @return array
     */
    public static function enableTemplate($vmid) {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return [
                'code' => 403
            ];
        }

        $exists = Panel::getDatabase()->fetch_single_row('templates', 'vmid', $vmid, \PDO::FETCH_OBJ);
        if (!$exists) {
            $exists = Panel::getDatabase()->insert('templates', ['vmid' => $vmid, 'disabled' => 0]);
        } else {
            Panel::getDatabase()->update('templates', [
                'disabled' => 0,
            ], 'id', $exists->id);
        }

        return [
            'error' => false
        ];
    }

    /**
     * delete a template from the database.
     * 
     * Searches a template in the database, then in proxmox and removes both if not orphaned
     *
     * @param int $id
     * @return array
     */
    public static function deleteTemplate($id) {
        $user = BaseModule::getUser();
        $b    = Panel::getRequestInput();

        if ($user->getRole() != AdminRole::class) {
            return [
                'code' => 403
            ];
        }

        $template = Panel::getDatabase()->fetch_single_row('templates', 'id', $id);
        if (!$template)
            return ['code' => 404];

        if (filter_var($b['deleteInProxmox'], FILTER_VALIDATE_BOOLEAN)) {
            // check if template exists in proxmox
            $node = Settings::getConfigEntry('P_NODE');
            try {
                $pTemplate = Panel::getProxmox()->get("/nodes/{$node}/qemu/{$template->vmid}/config");
                ClusterHelper::deleteServer($node, $template->vmid);
            } catch (\Exception $e) {
                return [
                    "code"    => 404,
                    "message" => Panel::getLanguage()->get('admin_templates', 'm_error_cannot_find_in_proxmox'),
                    'e'       => $e->getMessage()
                ];
            }
        }

        Panel::getDatabase()->delete('templates', 'id', $id);

        return [
            'error' => false,
        ];
    }

    public static function saveOrder() {
        header('Content-Type: application/json');
        $user = BaseModule::getUser();
        if ($user->getPermission() < 2) {
            die('401');
        }

        $order = $_POST['order'];

        foreach ($order as $k => $v) {
            Panel::getDatabase()->update('templates', [
                'sort' => $v
            ], 'id', $k);
        }

        die(json_encode($order));
    }
}
