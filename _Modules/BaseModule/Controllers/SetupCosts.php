<?php

namespace Module\BaseModule\Controllers;

use Controllers\Panel;
use Module\BaseModule\BaseModule;
use Objects\Formatters;

class SetupCosts {

    const GLOBAL_CHARGE   = 1;
    const OS_CHARGE       = 2;
    const CONFIGURABLE    = 3;
    const CONFIGURABLE_OS = 4;

    const FIXED_PRICE = 1;
    const PERCENTAGE  = 2;

    public static function renderSettings() {
        $user = self::checkPermission();
        Panel::compile("_views/_pages/admin/extra-charges.html", array_merge([
            'charges' => Panel::getDatabase()->fetch_all('charges')
        ], Panel::getLanguage()->getPages(['admin_charges', 'global'])));
    }

    public static function deleteExtra($id) {
        $user = self::checkPermission();
        Panel::getDatabase()->delete('charges', 'id', $id);
        die();
    }

    public static function editExtra($id) {
        $user = self::checkPermission();

        header('Content-Type: application/json');

        $description = $_POST['description'];
        $price       = $_POST['price'];
        $calcOnly    = filter_var($_POST['calcOnly'], FILTER_VALIDATE_BOOLEAN);
        $type        = intval($_POST['type']);
        $calcType    = intval($_POST['calcType']);
        $vmid        = intval($_POST['vmid']);
        $recurring   = $_POST['recurring'];

        Panel::getDatabase()->update('charges', [
            'description' => $description,
            'price'       => $price,
            'calcOnly'    => (int) $calcOnly,
            'type'        => $type,
            'calcType'    => $calcType,
            'osid'        => $vmid ?? null,
            'recurring'   => $recurring
        ], 'id', $id);

        die(json_encode([
            'id'       => $id,
            'desc'     => $description,
            'price'    => $price,
            'calcOnly' => $calcOnly,
            'type'     => $type,
            'vmid'     => $vmid
        ]));
    }

    public static function createExtra() {
        $user = self::checkPermission();

        header('Content-Type: application/json');

        $description = $_POST['description'];
        $price       = $_POST['price'];
        $calcOnly    = filter_var($_POST['calcOnly'], FILTER_VALIDATE_BOOLEAN);
        $type        = intval($_POST['type']);
        $calcType    = intval($_POST['calcType']);
        $vmid        = $_POST['vmid'];
        $recurring   = $_POST['recurring'];

        if (!$vmid) {
            $vmid = null;
        }

        Panel::getDatabase()->insert('charges', [
            'description' => $description,
            'price'       => preg_replace('/,/', '.', $price),
            'calcOnly'    => (int) $calcOnly,
            'type'        => $type,
            'calcType'    => $calcType,
            'osid'        => $vmid,
            'recurring'   => $recurring
        ]);

        die(json_encode([
            $description, $price, $calcOnly, $type, $vmid, $calcOnly
        ]));
    }

    public static function toggleStatus() {
        $user = self::checkPermission();
        header("Content-Type: application/json");

        $id        = $_POST['chargeId'];
        $currently = $_POST['currently'];

        Panel::getDatabase()->update('charges', [
            'active' => (int) !$currently
        ], 'id', $id);
        die(json_encode(['success' => true]));
    }

    public static function checkPermission() {
        $user = BaseModule::getUser();
        if ($user->getPermission() < 2) {
            die('401');
        }
        return $user;
    }


    /**
     * returns an array of charges
     *
     * @return array
     */
    public static function calculateCharges(
        $price,
        $vmid = 0,
        $options = []
    ) {
        $rawPrice = $price;
        $return   = [];
        $charges  = Panel::getDatabase()->custom_query(<<<SQL
            SELECT 
                * 
            FROM 
                `charges` 
            WHERE 
                `active` = 1 AND 
                (
                    (`type` = 1) OR 
                    (`type` = 2 AND `osid` = '$vmid') OR
                    id IN (?)
                )
            ORDER BY 
                `calcType` DESC; 
        SQL, [
            'id' => $options
        ])->fetchAll(\PDO::FETCH_OBJ);


        foreach ($charges as $charge) {
            $toAdd = 0;
            // charge with a fixed price, so we can just add that to the price
            switch ($charge->calcType) {
                case self::FIXED_PRICE:
                    $toAdd = $charge->price;
                    break;
                case self::PERCENTAGE:
                    $toAdd = ($price * $charge->price) / 100;
                    break;
            }
            $price += $toAdd;

            if ($charge->recurring) {
                $rawPrice += $toAdd;
            }

            $return[] = [
                'description' => $charge->description,
                'negative'    => $toAdd < 0,
                'price'       => Formatters::formatBalance($toAdd),
                'rawPrice'    => $toAdd,
                'type'        => $charge->type,
                'recurring'   => $charge->recurring,
                'calcOnly'    => $charge->calcOnly,
                'id'          => $charge->id
            ];
        }

        return [
            'charges'  => $return,
            'price'    => $price,
            'rawPrice' => $rawPrice
        ];
    }
}