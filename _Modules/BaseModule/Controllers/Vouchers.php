<?php

namespace Module\BaseModule\Controllers;

use Controllers\Panel;
use Module\BaseModule\BaseModule;
use Objects\Formatters;
use Objects\Invoice;

class Vouchers {

    public static function adminList() {
        $user = BaseModule::getUser();
        if ($user->getPermission() < 3) {
            die('401');
        }
        $vouchers = Panel::getDatabase()->fetch_all('vouchers');
        Panel::compile('_views/_pages/admin/vouchers.html', array_merge(
            [
                'vouchers' => $vouchers
            ],
            Panel::getLanguage()->getPage('admin_vouchers')
        ));
    }

    public static function adminCreate() {
        $user = BaseModule::getUser();
        if ($user->getPermission() < 3) {
            die('401');
        }

        // the code itself
        $code = $_POST['code'];
        // how often a single customer should be able to use this code
        $usagePerCustomer = (int) $_POST['usagePerCustomer'];
        /// how often the code can be used in total
        $usageTotal = (int) $_POST['usageTotal'];
        // 1 = Balance, 2 = Product
        $voucherBase = ((int) $_POST['voucherBase'] == 1) ? "balance" : "product";
        // 1 = Percentage, 2 = Fixed
        $voucherType = ((int) $_POST['voucherType'] == 1) ? "percentage" : "fixed";
        // voucherBase = 1 Balance to add
        $voucherBalanceVolume = $_POST['voucherBalanceVolume'];
        // voucherBase = 2 & voucherType = 1 Discount-Percentage
        $voucherTypePercent = $_POST['voucherTypePercent'];
        // voucherBase = 2 && voucherType = 2 Fixed Discount
        $voucherTypeAmount = $_POST['voucherTypeAmount'];
        // voucherBase = 2 Recurring or single-time
        $voucherRecurring = ((int) $_POST['voucherRecurring'] == 1 ? "single" : "recurring");

        $codeExists = Panel::getDatabase()->check_exist('vouchers', ['code' => $code]);
        if ($codeExists) {
            self::respondError(Panel::getLanguage()->get('admin_vouchers', 'm_create_exists'));
        }

        if ($usageTotal == 0)
            $usageTotal = -1;
        $baseInsert = [
            'code'             => $code,
            'usagePerCustomer' => $usagePerCustomer,
            'usageTotal'       => $usageTotal,
            'usageTotalLeft'   => $usageTotal,
            'voucherBase'      => $voucherBase,
        ];

        if ($voucherBase == "balance") {
            // voucher to add balance to a user Account
            $baseInsert = array_merge(
                $baseInsert,
                [
                    'voucherBalanceVolume' => $voucherBalanceVolume
                ]
            );
        }

        if ($voucherBase == "product") {
            // a voucher for a product
            if ($voucherType == "percentage") {
                // voucher for product %-based
                $baseInsert = array_merge(
                    $baseInsert,
                    [
                        'voucherType'        => $voucherType,
                        'voucherTypePercent' => $voucherTypePercent
                    ]
                );
            }

            if ($voucherType == "fixed") {
                $baseInsert = array_merge(
                    $baseInsert,
                    [
                        'voucherType'       => $voucherType,
                        'voucherTypeAmount' => $voucherTypeAmount
                    ]
                );
            }

            $baseInsert = array_merge(
                $baseInsert,
                [
                    'voucherRecurring' => $voucherRecurring
                ]
            );
        }

        Panel::getDatabase()->insert('vouchers', $baseInsert);

        die(json_encode([
            'error'   => false,
            'message' => Panel::getLanguage()->get('admin_vouchers', 'm_create_success')
        ]));
    }

    public static function adminDelete($id) {
        $user = BaseModule::getUser();
        if ($user->getPermission() < 3) {
            die('401');
        }
        Panel::getDatabase()->delete('vouchers', 'id', $id);
        header('Content-Type: application/json');
        die(json_encode([
            'error'   => false,
            'message' => ""
        ]));
    }

    public static function apiBalanceVoucher() {
        $voucher = $_POST['voucher'];
        $user    = BaseModule::getUser();
        $res     = Panel::getDatabase()->custom_query('SELECT * FROM vouchers WHERE code=? AND voucherBase="balance" AND (usageTotalLeft=-1 OR usageTotalLeft > 0)', ['code' => $voucher])->fetchAll(\PDO::FETCH_OBJ);
        if ($res && sizeof($res) > 0) {
            $res = $res[0];
        } else {
            self::respondError(Panel::getLanguage()->get('vouchers', 'voucher_expired'));
        }

        // check if user already used the code the amount of times a user is allowed to use it (usagePerCustomer field)
        $counter = Panel::getDatabase()->custom_query("SELECT * FROM voucher_uses WHERE userId=? AND code=?", ['userId' => $user->getId(), 'code' => $voucher])->rowCount();
        if ($counter >= $res->usagePerCustomer) {
            self::respondError(Panel::getLanguage()->get('vouchers', 'voucher_expired'));
        }

        // seems all good. Let's add the balance to the user and create an invoice
        $b = $user->getBalance();
        $b->addBalance($res->voucherBalanceVolume);
        $b->save();

        $b->insertInvoice(
            $res->voucherBalanceVolume,
            Invoice::BALANCE,
            $user->getId(),
            1,
            Invoice::getNextId()
        );

        // save that the user used the code
        Panel::getDatabase()->insert('voucher_uses', [
            'userId' => $user->getId(),
            'code'   => $voucher
        ]);

        // decrement totalUsageCount if limit is not inifinite
        if ($res->usageTotalLeft != -1) {
            Panel::getDatabase()->custom_query("UPDATE vouchers SET usageTotalLeft = usageTotalLeft - 1 WHERE code=?", ['code' => $voucher]);
        }

        $message = Panel::getLanguage()->get('vouchers', 'voucher_balance_added');
        $message = str_replace('{{code}}', $voucher, $message);
        $message = str_replace('{{balance}}', Formatters::formatBalance($res->voucherBalanceVolume), $message);

        header('Content-Type: application/json');
        die(json_encode([
            'error'   => false,
            'message' => $message
        ]));
    }

    public static function respondError($error) {
        header('Content-Type: application/json');
        die(json_encode((['error' => true, 'message' => $error])));
    }
}