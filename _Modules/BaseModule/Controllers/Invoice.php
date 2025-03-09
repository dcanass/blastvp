<?php

/**
 * Created by PhpStorm.
 * User: bennet
 * Date: 01.03.19
 * Time: 20:53
 */

namespace Module\BaseModule\Controllers;


use Angle\Engine\Template\Engine;
use Controllers\Panel;
use Module\BaseModule\BaseModule;
use Module\BaseModule\Controllers\Admin\Settings;
use Module\BaseModule\Objects\AccountingProviders\AccountingProviderInterface;
use Objects\Invoice as ObjectsInvoice;
use Objects\User;

class Invoice {

    public static $currencies = [
        'EUR' => [
            'iso'                => "EUR",
            'display'            => "Euro",
            'symbol'             => "€",
            'symbol_html'        => "&euro;",
            'decimal_separator'  => ',',
            'thousand_separator' => '.',
            'decimals'           => 2
        ],
        'GBP' => [
            'iso'                => "GBP",
            'display'            => "Pound",
            'symbol'             => "£",
            'symbol_html'        => "&pound;",
            'decimal_separator'  => '.',
            'thousand_separator' => ',',
            'decimals'           => 2
        ],
        'USD' => [
            'iso'                => "USD",
            'display'            => "Dollar",
            'symbol'             => "$",
            'symbol_html'        => "&usd;",
            'decimal_separator'  => '.',
            'thousand_separator' => ',',
            'decimals'           => 2
        ],
        'CHF' => [
            'iso'                => "CHF",
            'display'            => 'Schweizer Franken',
            'symbol'             => "CHF",
            'symbol_html'        => 'CHF',
            'decimal_separator'  => '.',
            'thousand_separator' => ',',
            'decimals'           => 2
        ],
        'CZK' => [
            'iso'                => 'CZK',
            'display'            => 'Koruna česká',
            'symbol'             => 'Kč',
            'symbol_html'        => 'CZK',
            'decimal_separator'  => '.',
            'thousand_separator' => ',',
            'decimals'           => 2
        ],
        'IDR' => [
            'iso'                => 'IDR',
            'display'            => 'Indonesian Rupiah',
            'symbol'             => 'Rp.',
            'symbol_html'        => 'Rp.',
            'decimal_separator'  => '.',
            'thousand_separator' => ',',
            'decimals'           => 0
        ]
    ];

    public static function listAll() {
        $user = BaseModule::getUser();

        $user->getBalance()->loadInvoices();
        $invoices = $user->getBalance()->getInvoices();
        Panel::compile("_views/_pages/account/invoices.html", array_merge([
            'invoices' => $invoices
        ], Panel::getLanguage()->getPage('invoices')));
    }

    public static function single($id) {

        $data = Panel::getDatabase()->custom_query("SELECT invoices.*, users.id AS useruserId FROM invoices LEFT JOIN users ON invoices.userid=users.id WHERE invoices.id=?", ['id' => $id])->fetchAll()[0];
        $user = (new User($data->useruserId))->load();

        // check if file exists and render that instead
        if (file_exists(__DIR__ . "/../../../_invoices/" . $id . '.pdf')) {
            //
            header("Content-type: application/pdf");
            header("Content-Disposition: inline; filename=filename.pdf");
            @readfile(__DIR__ . "/../../../_invoices/" . $id . '.pdf');
        }

        Panel::compile("_views/_pages/account/invoice.html", array_merge([
            "address"          => (array) $user->getAddress()->load(),
            "user"             => $user->toArray(),
            "invoice"          => (array) new ObjectsInvoice($data),
            "logo"             => Settings::getConfigEntry("LOGO"),
            "invoice_1"        => Settings::getConfigEntry("INVOICE_1"),
            "invoice_2"        => Settings::getConfigEntry("INVOICE_2"),
            "invoice_3"        => Settings::getConfigEntry("INVOICE_3"),
            "invoice_4"        => Settings::getConfigEntry("INVOICE_4"),
            "invoice_show_vat" => Settings::getConfigEntry("INVOICE_SHOW_VAT"),
            "invoice_vat"      => Settings::getConfigEntry("INVOICE_VAT")
        ], Panel::getLanguage()->getPage('invoice')));
    }

    /**
     * will return a list of all the available currencies
     *
     * @return array
     */
    public static function getCurrenciesAvailable() {
        return self::$currencies;
    }

    /**
     * get the current active currency
     *
     * @return array
     */
    public static function getActiveCurrency() {
        $cur = Settings::getConfigEntry("CURRENCY", "EUR");
        return self::$currencies[$cur];
    }

    public static function testConnection() {
        $user = BaseModule::getUser();
        if ($user->getPermission() < 2) {
            return [
                'error'   => true,
                'message' => "401 Forbidden"
            ];
        }

        $prov   = AccountingProviderInterface::getProvider();
        $result = $prov->testConnection();

        return [
            'error'   => $result !== true,
            'message' => $result
        ];
    }

    public static function testCreateInvoice() {
        $user = BaseModule::getUser();
        if ($user->getPermission() < 2) {
            return [
                'error'   => true,
                'message' => '401 Forbidden'
            ];
        }

        $prov   = AccountingProviderInterface::getProvider();
        $result = $prov->testCreateInvoice($user);

        return $result;
    }
}
