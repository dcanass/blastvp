<?php

namespace Module\BaseModule\Controllers;

use Controllers\Panel;
use Module\BaseModule\BaseModule;
use Objects\Formatters;
use Objects\Server;

class Packages {

    const STATIC  = 2;
    const DYNAMIC = 1;

    public static function adminList() {
        $user = BaseModule::getUser();
        if ($user->getPermission() < 2) {
            die('401');
        }

        list($cpu_html, $ram_html, $disk_html) = Order::getHtml(false);
        Panel::compile('_views/_pages/admin/packages.html', array_merge([
            "cpu_html"  => $cpu_html,
            "ram_html"  => $ram_html,
            "disk_html" => $disk_html
        ], Panel::getLanguage()->getPages(['global', 'order', 'packages'])));
    }

    public static function get() {
        $res = Panel::getDatabase()->custom_query('SELECT * FROM packages ORDER BY sort ASC', [])->fetchAll(\PDO::FETCH_ASSOC);

        $res = array_map(function ($e) {
            $e['priceFormatted'] = Formatters::formatBalance($e['price']);
            return $e;
        }, $res);

        return $res;
    }

    public static function adminAdd() {
        header('Content-Type: application/json');
        $user = BaseModule::getUser();
        if ($user->getPermission() < 2) {
            die('401');
        }

        $b = Panel::getRequestInput();

        $name       = $b['name'];
        $cores      = $b['cpu'];
        $ram        = $b['ram'];
        $disk       = $b['disk'];
        $price      = $b['price'];
        $type       = $b['type'];
        $templateId = isset($b['templateId']) && $b['templateId'] != '' ? $b['templateId'] : null;

        $metas = $b['meta'];

        $res = Panel::getDatabase()->insert('packages', [
            'name'       => $name,
            'price'      => $price,
            'cpu'        => $cores,
            'ram'        => $ram,
            'disk'       => $disk,
            'meta'       => $metas,
            'type'       => $type,
            'templateId' => $templateId
        ]);

        die(json_encode([
            'success' => $res
        ]));
    }

    public static function adminSave() {
        header('Content-Type: application/json');
        $user = BaseModule::getUser();
        if ($user->getPermission() < 2) {
            die('401');
        }
        $b = Panel::getRequestInput();

        $name       = $b['name'];
        $cores      = $b['cpu'];
        $ram        = $b['ram'];
        $disk       = $b['disk'];
        $price      = $b['price'];
        $type       = $b['type'];
        $templateId = isset($b['templateId']) && $b['templateId'] != '' ? $b['templateId'] : null;

        $metas = $b['meta'];

        $id = isset($b['id']) ? $b['id'] : false;

        if (!$id) {
            self::adminAdd();
        }

        $upd = [];
        $pkg = self::getOne($id);
        if ($id && $b['applyPriceChange'] && filter_var($b['applyPriceChange'], FILTER_VALIDATE_BOOL) && $price != $pkg->price) {
            // update
            $servers = Panel::getDatabase()->custom_query("SELECT * FROM servers WHERE deletedAt IS NULL AND packageId=?", ['packageId' => $id])->fetchAll(\PDO::FETCH_OBJ);
            foreach ($servers as $server) {
                $server = new Server($server);
                // if the server price is not equal to the old package price
                // we can assume that the server price was changed manually
                // we dont want to update those
                if ($server->price == $pkg->price) {
                    Panel::getDatabase()->update('servers', ['price' => $price], 'id', $server->id);
                    $upd[] = $server->id;
                }
            }
        }

        $res = Panel::getDatabase()->update('packages', [
            'name'       => $name,
            'price'      => $price,
            'cpu'        => $cores,
            'ram'        => $ram,
            'disk'       => $disk,
            'meta'       => $metas,
            'type'       => $type,
            'templateId' => $templateId
        ], 'id', $id);

        die(json_encode([
            'success' => $res,
            'upd'     => $upd
        ]));
    }

    public static function adminRemove($id) {
        header('Content-Type: application/json');
        $user = BaseModule::getUser();
        if ($user->getPermission() < 2) {
            die('401');
        }
        $res = Panel::getDatabase()->delete('packages', 'id', $id);
        die(json_encode(['success' => $res]));
    }

    public static function saveOrder() {
        header('Content-Type: application/json');
        $user = BaseModule::getUser();
        if ($user->getPermission() < 2) {
            die('401');
        }

        $order = $_POST['order'];

        foreach ($order as $k => $v) {
            Panel::getDatabase()->update('packages', [
                'sort' => $v
            ], 'id', $k);
        }

        die(json_encode($order));
    }

    /**
     * get a single package
     *
     * @param float $id
     * @return \stdClass
     */
    public static function getOne($id) {
        return Panel::getDatabase()->fetch_single_row('packages', 'id', $id);
    }
}