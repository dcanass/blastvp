<?php

namespace Objects;

use Controllers\Panel;
use Objects\Event\EventManager;

class Balance {
    private $balance;
    private $userId;
    private $invoices;

    public static $availablePaymentMethods = [
        'paypal'      => "PayPal",
        'stripe'      => "Stripe",
        'mollie'      => "Mollie",
        "coinbase"    => "Coinbase",
        'paysafecard' => "Paysafecard",
        'gocardless'  => "GoCardless",
        'duitku'      => "Duitku"
    ];

    public static $mollieMethods = [
        'alma'           => "Alma",
        'applepay'       => "ApplePay",
        'bacs'           => "Bacs",
        'bancomatpay'    => 'BANCOMAT Pay',
        'bancontact'     => "Bancontact",
        'banktransfer'   => [
            "de" => "&Uuml;berweisung",
            "en" => "Banktransfer",
            "fr" => "Virement"
        ],
        'belfius'        => "Belfius Direct Net",
        'blik'           => 'Blik',
        'creditcard'     => [
            "de" => "Kreditkarte",
            "en" => "Creditcard",
            "fr" => "Carte de crédit"
        ],
        'directdebit'    => [
            "de" => 'Lastschrift',
            "en" => "direct debit",
            "fr" => "Frais de débit"
        ],
        'eps'            => "EPS",
        'giftcard'       => [
            "de" => "Geschenkgutschein",
            "en" => "Gift-Vouchers",
            "fr" => "carte cadeau"
        ],
        'giropay'        => "Giropay",
        'ideal'          => "iDEAL",
        'kbc'            => "KBC/CBC",
        'klarnapaylater' => [
            "de" => "Klarna Rechnungskauf",
            "en" => "Klarna Invoice",
            "fr" => "Klarna achat sur facture"
        ],
        'klarnasliceit'  => [
            "de" => "Klarna Ratenkauf",
            "en" => "Klarna Multi-Installments",
            "fr" => "Klarna contrat de crédit"
        ],
        'mybank'         => "MyBank",
        'paysafecard'    => "Paysafecard",
        'sofort'         => [
            "de" => "SOFORT Überweisung",
            "en" => "SOFORT direct debit",
            "fr" => "Virement bancaire instantané"
        ],
        'voucher'        => [
            "de" => "Gutschein",
            "en" => "Voucher",
            "fr" => "coupon"
        ],
        'przelewy24'     => "Przelewy24",
        'twint'          => "Twint",
        'satispay'       => 'Satispay',
        'trustly'        => 'Trustly',
        'klarna'         => 'Klarna',
    ];

    public function __construct($balance, $userId) {
        $this->balance = $balance ?? 0;
        $this->userId  = $userId;
    }

    public function addBalance($toAdd) {
        $this->balance += $toAdd;
    }

    public function removeBalance($toRemove) {
        $this->balance -= $toRemove;
    }

    public function save() {
        $bal = Panel::getDatabase()->fetch_single_row('balances', 'userid', $this->userId);
        if ($bal) {
            Panel::getDatabase()->update('balances', [
                'balance' => $this->balance
            ], 'userid', $this->userId);
        } else {
            Panel::getDatabase()->insert('balances', [
                'balance' => $this->balance,
                'userid'  => $this->userId
            ]);
        }
    }

    public function getBalance() {
        return $this->balance;
    }

    public function getFormattedBalance() {
        return Formatters::formatBalance($this->balance);
    }

    public function loadInvoices() {
        $this->invoices = Panel::getDatabase()->custom_query(<<<SQL
            SELECT * FROM invoices WHERE userid=? AND done=1 ORDER BY createdAt DESC
        SQL, ['id' => $this->userId])->fetchAll();
        return $this;
    }

    public function getInvoices() {
        return array_map(function ($ele) {
            return (array) new Invoice($ele);
        }, $this->invoices);
    }

    public function getLastInvoice($type) {
        foreach ($this->invoices as $invoice) {
            if ($invoice->type == $type) {
                return new Invoice($invoice);
            }
        }
        return null;
    }

    /**
     * insert a new invoice
     *
     * @param float $amount
     * @param string $type
     * @param number $userid
     * @param number|boolean $done
     * @param string $descriptor the text to show on the invoice
     * @return void
     */
    public function insertInvoice($amount, $type, $userid, $done, $descriptor) {
        EventManager::fire('invoice::created', [
            'amount'     => $amount,
            'type'       => $type,
            'userid'     => $userid,
            'done'       => $done,
            'descriptor' => $descriptor,
        ]);
        Panel::getDatabase()->insert('invoices', [
            'amount'     => number_format($amount, 2, '.', ''),
            'type'       => $type,
            'userid'     => $userid,
            'done'       => $done,
            'descriptor' => $descriptor,
            'imported'   => 0
        ]);
    }
}
