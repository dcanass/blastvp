<?php

namespace Module\BaseModule\Objects\AccountingProviders;

use DateTime;
use Iron1902\BasicSevdeskAPI\BasicSevdeskAPI;
use Iron1902\BasicSevdeskAPI\Options;
use Module\BaseModule\Controllers\Admin\Settings;
use Module\BaseModule\Controllers\Invoice;
use Module\BaseModule\Objects\AccountingProviders\AccountingProviderInterface;
use Objects\Address;
use Objects\Invoice as ObjectsInvoice;

class SevDeskProvider extends AccountingProviderInterface {

    private $api = null;
    private $sevUser = null;
    private $collectionCustomer = null;

    public function __construct() {

        $settings = Settings::getConfigEntry('SEVDESK_SETTINGS', false);
        if ($settings) {
            $settings = (array) unserialize($settings);
        }


        $options = new Options();
        $options->setApiKey($settings['api_key']);
        $options->setGuzzleOptions(['http_errors' => false]);

        // Create the client
        $this->api = new BasicSevdeskAPI($options);

        $call = $this->api->rest('GET', 'SevUser');

        if ($call['status'] == "200") {
            $this->sevUser            = $call['body'][0]->id;
            $res                      = $this->api->rest('GET', 'Contact', [
                'query' => [
                    'depth' => 1,
                    'name'  => $settings['collection_customer']
                ]
            ]);
            $this->collectionCustomer = $res['body'][0]->id;
        }
    }

    public function createInvoice(
        \Objects\User $user,
        Address $address,
        ObjectsInvoice $invoice
    ): bool {
        $result = $this->__createInvoice($user, $address, $invoice);
        $this->downloadInvoice($result, $invoice->id);
        return !$result['errors'];
    }

    public function __createInvoice(
        \Objects\User $user,
        Address $address,
        ObjectsInvoice $invoice,
        $finalize = true
    ) {
        $taxType = $this->determineTaxType($address);

        $taxRate = 0;
        if ($taxType != 'ss') {
            $taxRate = Settings::getConfigEntry('INVOICE_VAT', 0);
        }

        $result = $this->api->rest('POST', 'Invoice/Factory/saveInvoice', [
            'invoice'        => [
                "address"       => implode(PHP_EOL, [$user->getName(), $address->getAddress(), $address->getCity(), $address->getZipcode(), $address->get_country()]),
                "objectName"    => "Invoice",
                "contact"       => [
                    'id'         => $this->collectionCustomer,
                    'objectName' => "Contact"
                ],
                "contactPerson" => [
                    'id'         => $this->sevUser,
                    'objectName' => "SevUser"
                ],
                'invoiceDate'   => (new DateTime())->format('d.m.Y'),
                'discount'      => 0,
                'status'        => $finalize ? 200 : 100,
                'taxRate'       => $taxRate,
                'taxType'       => $taxType,
                'invoiceType'   => 'RE',
                'currency'      => Invoice::getActiveCurrency()['iso'],
                'showNet'       => $taxRate == 'ss',
                'mapAll'        => true,
            ],
            'invoicePosSave' => [[
                "positionNumber" => 0,
                "objectName"     => "InvoicePos",
                'mapAll'         => true,
                'quantity'       => 1,
                'price'          => number_format($invoice->amount, 4),
                'text'           => $invoice->descriptor,
                'unity'          => [
                    'id'         => 1,
                    'objectName' => "Unity"
                ],
                'discount'       => 0,
                'taxRate'        => $taxRate
            ]]
        ]);

        if (Settings::getConfigEntry('ACCOUNTING_SEND_MAILS', false) && $finalize) {
            // send EMAIL
            $invoiceId = $result['body']['invoice']['id'];
            $a         = $this->api->rest('POST', "Invoice/$invoiceId/sendViaEmail", [
                'toEmail' => $user->getEmail(),
                'subject' => "[%DOKUMENTENNUMMER%]",
                'text'    => $this->buildEmailText($user, $invoice)
            ]);
        }

        return $result;
    }

    public function testConnection(): mixed {
        // test if we can call the API for  /Contact
        $res = $this->api->rest('GET', 'SevUser');
        // check for errors
        // and return a string for the message
        if ($res['status'] == 200) {
            return true;
        }

        $b = $res['body'];
        return "{$b['status']} {$b['message']}";
    }

    public function testCreateInvoice(\Objects\User $user): mixed {
        $address = (new Address($user->getId()))->load();
        $invoice = new ObjectsInvoice((object) [
            'id'         => null,
            'amount'     => 1,
            'type'       => '1',
            'userid'     => null,
            'createdAt'  => date('c'),
            'done'       => null,
            'descriptor' => 'Test-Invoice',
        ]);
        $result  = $this->__createInvoice($user, $address, $invoice, false);

        if ($result['status'] !== 201) {
            return [
                'error'   => true,
                'message' => $result['body']->error->message
            ];
        }

        return [
            'error'   => false,
            'message' => $result['body']['invoice']['id']
        ];
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
        // Steuerfrei Kleinunternehmer
        if (!Settings::getConfigEntry('INVOICE_SHOW_VAT', false))
            return 'ss';

        return 'default';
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
            base64_decode($this->api->rest('GET', "Invoice/{$result['body']['invoice']['id']}/getPdf")['body']['content'])
        );
        return $result;
    }
}