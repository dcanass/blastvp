<?php

namespace Module\BaseModule\Objects\AccountingProviders;

use Clicksports\LexOffice\Api;
use Controllers\MailHelper;
use Controllers\Panel;
use DateTime;
use Module\BaseModule\Controllers\Admin\Settings;
use Module\BaseModule\Controllers\Invoice;
use Objects\Address;
use Objects\Invoice as ObjectsInvoice;

class LexOfficeProvider extends AccountingProviderInterface {

    private $api = null;
    public function __construct() {
        $settings = Settings::getConfigEntry('LEXOFFICE_SETTINGS', false);
        if ($settings) {
            $settings = (array) unserialize($settings);
        }

        $this->api = new Api(
            $settings['api_key'],
            new \GuzzleHttp\Client(['http_errors' => false])
        );
    }

    public function createInvoice(
        \Objects\User $user,
        Address $address,
        ObjectsInvoice $invoice
    ): bool {
        $res = $this->__createInvoice($user, $address, $invoice);
        $this->downloadInvoice($res, $invoice->id);
        return isset($res->version);
    }

    public function testConnection(): mixed {
        //
        $res = $this->api->contact()->getPage(1);
        $res = $this->api->contact()->getAsJson($res);

        if (isset($res->number)) { // count of results
            return true;
        }
        return $res->message;
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

        $res = $this->__createInvoice($user, $address, $invoice, false);
        if (isset($res->message)) {
            return [
                'error'   => true,
                'message' => $res->message
            ];
        }
        return [
            'error'   => false,
            'message' => $res->id
        ];
    }

    private function __createInvoice(
        \Objects\User $user,
        Address $address,
        ObjectsInvoice $invoice,
        $finalize = true
    ) {
        $datetime = new DateTime();

        $invoice->amount = number_format($invoice->amount, 4);

        $tax = $this->determineTaxType($address);

        $_address = [
            'name'        => $user->getName(),
            "street"      => $address->getAddress(),
            "city"        => $address->getCity(),
            'zip'         => $address->getZipcode(),
            "countryCode" => $address->getCountryAlpha()
        ];
        if ($address->getCountryAlpha() != "DE") {
            // we need a reference customer for these 2 types.
            $_address['contactId'] = $this->findOrCreateCustomer($user, $address);
        }

        $line = [
            "currency" => Invoice::getActiveCurrency()['iso'],
        ];

        if ($tax == 'vatfree' || $tax == 'net') {
            $line["netAmount"]         = $invoice->amount;
            $line['taxRatePercentage'] = 0;
        } else {
            // calc netto price
            $line["grossAmount"]       = $invoice->amount;
            $line['taxRatePercentage'] = Settings::getConfigEntry('INVOICE_VAT', 0);
        }

        $response = $this->api->invoice()->create([
            'voucherDate'        => $datetime->format("Y-m-d\TH:i:s.vP"),
            'address'            => $_address,
            "lineItems"          => [
                [
                    "type"      => "custom",
                    "name"      => $invoice->descriptor,
                    "quantity"  => 1,
                    "unitName"  => "x",
                    "unitPrice" => $line
                ]
            ],
            "totalPrice"         => [
                "currency" => Invoice::getActiveCurrency()['iso']
            ],
            "taxConditions"      => [
                "taxType" => $tax
            ],
            "shippingConditions" => [
                "shippingType" => 'none'
            ],
        ], $finalize);

        $inv = $this->api->invoice()->getAsJson($response);

        if (Settings::getConfigEntry('ACCOUNTING_SEND_MAILS', false) && $finalize) {
            // send EMAIL
            $invoiceId = $inv->id;

            $res  = Panel::getEngine()->compile(MailHelper::getCurrentMailTemplate(), [
                "m_title" => Panel::getLanguage()->get('mail_payment_success', "m_title"),
                "m_desc"  => $this->buildEmailText($user, $invoice),
                "logo"    => Settings::getConfigEntry("LOGO")
            ]);
            $mail = Panel::getMailHelper();
            $mail->setAddress($user->getEmail());
            $mail->setContent(Panel::getLanguage()->get('mail_payment_success', 'm_subject'), $res);
            $mail->addAttachment(base64_decode($this->api->invoice()->document($invoiceId, true)->getBody()), "invoice-$invoiceId.pdf");
            $mail->send();
            $mail->clear();
        }

        return $inv;
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
     * find or create a new user depending on the information we are given.
     * returns the ID of the customer
     *
     * @param \Objects\User $user
     * @param Address $address
     * @return mixed
     */
    private function findOrCreateCustomer(\Objects\User $user, Address $address) {
        $customers = $this->api->contact()->setFilters(['email' => $user->getEmail()])->getAll();
        $customers = $this->api->contact()->getAsJson($customers);

        if (sizeof($customers->content) == 0) {
            // no customers, create new one
            $create = $this->api->contact()->create([
                'version'        => 0,
                'roles'          => [
                    'customer' => new \stdClass()
                ],
                'person'         => [
                    'lastName' => $user->getName()
                ],
                'addresses'      => [
                    'billing' => [
                        [
                            'street'      => $address->getAddress(),
                            'zip'         => $address->getZipcode(),
                            'city'        => $address->getCity(),
                            'countryCode' => $address->getCountryAlpha()
                        ]
                    ]
                ],
                'emailAddresses' => [
                    'business' => [$user->getEmail()]
                ]
            ]);
            $create = $this->api->contact()->getAsJson($create);
            return $create->id;
        } else {
            return $customers->content['0']->id;
        }
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
            base64_decode($this->api->invoice()->document($result->id, true)->getBody())
        );
        return $result;
    }
}