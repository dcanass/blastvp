<?php

namespace Module\BaseModule\Objects\AccountingProviders;

use Module\BaseModule\Controllers\Admin\Settings;
use Objects\Address;
use Objects\Invoice;
use Objects\User;
use Phobetor\Billomat\Client\BillomatClient;

class BillomatProvider extends AccountingProviderInterface {

    private $api = null;
    private $collectionCustomer = null;

    /**
     * @suppress PHP0436
     */
    public function __construct() {
        $settings = Settings::getConfigEntry('BILLOMAT_SETTINGS', false);
        if ($settings) {
            $settings = (array) unserialize($settings);
        }
        $this->api = new BillomatClient($settings['customer_id'], $settings['api_key'], $settings['app_id'], $settings['app_secret']);
        $this->api->setConfig('http_errors', false);

        try {
            $res = $this->api->getClients([
                'client_number' => $settings['collection_customer']
            ]);

            $this->collectionCustomer = $res['clients']['client'][0]['id'];
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    public function createInvoice(
        User $user,
        Address $address,
        Invoice $invoice
    ): bool {
        try {
            $result = $this->__createInvoice($user, $address, $invoice);
            $this->downloadInvoice($result, $invoice->id);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function __createInvoice(
        User $user,
        Address $address,
        Invoice $invoice,
        $finalize = true
    ) {

        $taxRate = $this->determineTaxType($address);

        $item = [
            'tax_rate' => $taxRate == 'vatfree' ? 0 : Settings::getConfigEntry('INVOICE_VAT', 0),
        ];

        $result    = $this->api->createInvoice([
            'invoice' => [
                'client_id'     => (int) $this->collectionCustomer,
                'net_gross'     => "GROSS",
                "address"       => implode(PHP_EOL, [$user->getName(), $address->getAddress(), $address->getCity(), $address->getZipcode(), $address->get_country()]),
                'invoice-items' => [
                    'items' => array_merge($item, [
                        'unit'       => 'Piece',
                        'unit_price' => $invoice->amount,
                        'quantity'   => 1,
                        'title'      => $invoice->descriptor,
                        'type'       => 'SERVICE',
                    ])
                ]
            ]
        ]);
        $invoiceId = $result['invoice']['id'];
        // invoice als complete makieren
        try {
            if ($finalize) {
                $this->api->completeInvoice([
                    'id'       => (int) $invoiceId,
                    'complete' => []
                ]);
                // send via EMAIL if enabled
                if (Settings::getConfigEntry('ACCOUNTING_SEND_MAILS', false)) {
                    $this->api->sendInvoiceEmail([
                        'id'    => (int) $invoiceId,
                        'email' => [
                            'recipients' => [
                                'to' => $user->getEmail()
                            ]
                        ]
                    ]);
                }
                return $result;
            }
            return $result;
        } catch (\Exception $e) {
            echo $e->getMessage();
            return $e;
        }
    }

    public function testConnection(): mixed {
        try {
            $res = $this->api->getClients([]);
            if (isset($res['clients']['client']))
                return true;
        } catch (\Exception $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function testCreateInvoice(User $user): mixed {

        $address = (new Address($user->getId()))->load();
        $invoice = new Invoice((object) [
            'id'         => null,
            'amount'     => 1,
            'type'       => '1',
            'userid'     => null,
            'createdAt'  => date('c'),
            'done'       => null,
            'descriptor' => 'Test-Invoice',
        ]);
        try {
            $result    = $this->__createInvoice($user, $address, $invoice, false);
            $invoiceId = $result->toArray();
            $this->downloadInvoice($result, '12');
            return [
                'error'   => false,
                'message' => $invoiceId['invoice']['id']
            ];
        } catch (\Exception $e) {
            return [
                'error'   => true,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * determine the TAX type for the customer. This is 0% if the seller is a "Kleinunternehmer", which means
     * that he is not allowed to charge MwSt on his invoices.
     * 
     * If the person is a company, which is allowed to charge tax, it's always the local TAX of the seller.
     * This is only for B2C. B2B is not supported by the Panel.
     * 
     * @return string
     */
    private function determineTaxType(Address $address) {
        if (!Settings::getConfigEntry('INVOICE_SHOW_VAT', false))
            return 'vatfree';

        if (!$address->isInEu())
            return 'net';

        return 'gross';
    }

    /**
     * downloads an invoice to local storage
     *
     * @param [type] $result
     * @return void
     */
    public function downloadInvoice($result, $id): mixed {
        @mkdir(__DIR__ . '/../../../../_invoices', );

        file_put_contents(
            __DIR__ . '/../../../../_invoices/' . $id . '.pdf',
            base64_decode($this->api->getInvoicePdf([
                'id' => (int) $result['invoice']['id']]
            )->toArray()['pdf']['base64file'])
        );
        return $result;
    }
}