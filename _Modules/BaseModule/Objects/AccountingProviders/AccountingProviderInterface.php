<?php

namespace Module\BaseModule\Objects\AccountingProviders;

use Controllers\Panel;
use Module\BaseModule\Controllers\Admin\Settings;

abstract class AccountingProviderInterface {

    /**
     * create a new invoice. This function should implement the communication with the API of the designated provider
     *
     * @return boolean
     */
    abstract protected function createInvoice(
        \Objects\User $user,
        \Objects\Address $address,
        \Objects\Invoice $invoice
    ): bool;

    /**
     * test the connection to the provider
     *
     * @return mixed true if the connection was successfull, a message-string if not
     */
    abstract protected function testConnection(): mixed;

    /**
     * test the creation of an invoice. Should create a deletable invoice.
     *
     * @return mixed true if the creation was successfull and the ID of the invoice, a message-string with errors if not
     */
    abstract protected function testCreateInvoice(\Objects\User $user): mixed;

    /**
     * download invoice-pdf to storage
     *
     * @return mixed
     */
    abstract protected function downloadInvoice($result, $targetId): mixed;

    /**
     * returns the enable provider
     *
     * @return LexOfficeProvider|SevDeskProvider|BillomatProvider|false
     */
    public static function getProvider() {
        $a = Settings::getConfigEntry('ACCOUNTING_PROVIDER');

        switch ($a) {
            case "lexoffice":
                return new LexOfficeProvider();
            case "sevdesk":
                return new SevDeskProvider();
            case "billomat":
                return new BillomatProvider();
            default:
                return false;
        }
    }

    public function buildEmailText(\Objects\User $user, \Objects\Invoice $invoice) {
        return str_replace([
            '{{name}}',
            '{{amount}}',
            '{{serverName}}'
        ], [
            $user->getName(),
            $invoice->getFormattedAmount(),
            $invoice->descriptor
        ], Panel::getLanguage()->get('mail_payment_success', 'm_desc'));
    }
}