<?php

namespace Module\BaseModule\Cron;

use Controllers\Panel;
use Module\BaseModule\Controllers\Admin\Settings;
use Module\BaseModule\Objects\AccountingProviders\AccountingProviderInterface;
use Objects\Address;
use Objects\Invoice as ObjectsInvoice;
use Objects\User;

class InvoiceImport {

    public static function __execute() {
        $mode     = Settings::getConfigEntry("ACCOUNTING_INVOICING_MODE", "INV_MODE_DELIVERY");
        $invoices = match ($mode) {
            'INV_MODE_DELIVERY' => Panel::getDatabase()->custom_query("SELECT * FROM `invoices` WHERE `type`= 2 AND `done` = 1 AND `imported` = 0")->fetchAll(\PDO::FETCH_OBJ),
            'INV_MODE_BALANCE' => Panel::getDatabase()->custom_query("SELECT * FROM `invoices` WHERE `type`= 1 AND `done` = 1 AND `imported` = 0")->fetchAll(\PDO::FETCH_OBJ),
        };
        // find invoices where the status is done and paymentId is not empty

        $provider = AccountingProviderInterface::getProvider();
        if (!$provider)
            return;

        foreach ($invoices as $invoice) {
            // we need to load each user for the payment
            $invoice = new ObjectsInvoice($invoice);

            if ($mode === "INV_MODE_BALANCE") {
                $invoice->setDescriptor(Panel::getLanguage()->get('global', 'm_balance'));
            }

            $user    = (new User($invoice->userid))->load();
            $address = (new Address($invoice->userid))->load();

            $importSuccess = true;
            if ($invoice->amount != 0) {
                $importSuccess = $provider->createInvoice($user, $address, $invoice);
            }

            if ($importSuccess) {
                Panel::getDatabase()->update("invoices", [
                    'imported' => true
                ], 'id', $invoice->id);
            }
        }
    }
}
