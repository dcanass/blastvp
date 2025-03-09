<?php

/**
 * Created by PhpStorm.
 * User: bennet
 * Date: 05.03.19
 * Time: 19:58
 */

namespace Module\BaseModule\Controllers;


use Controllers\Panel;
use Module\BaseModule\BaseModule;
use Module\BaseModule\Controllers\Admin\Settings;
use Module\BaseModule\Controllers\Invoice as ControllersInvoice;
use Objects\Event\EventManager;
use Objects\Formatters;
use Objects\Invoice;
use Objects\User;
use Omnipay\Common\Item;
use Omnipay\Omnipay;
use PayPal\Api\Amount;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\Transaction;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Rest\ApiContext;
use Stripe\Stripe;
use Stripe\Webhook;

class Balance {


    public static function render() {

        $user    = BaseModule::getUser();
        $balance = $user->getBalance();
        $invoice = $user->getBalance()->loadInvoices()->getLastInvoice(Invoice::PAYMENT);

        $success = false;
        $abort   = false;

        $accountSetupDone = $user->getAddress()->load()->isValid();

        if (isset($_GET['success'])) {
            $success = true;
            if (isset($_GET['method']) && isset($_GET['orderId'])) {
                switch ($_GET['method']) {
                    case "mollie":
                        $mollie = new \Mollie\Api\MollieApiClient();
                        $mollie->setApiKey(Settings::getConfigEntry("MOLLIE_API_KEY"));
                        // check mollie payment status
                        $payment = Panel::getDatabase()->fetch_single_row('payments', 'id', $_GET['orderId']);
                        $order = $mollie->orders->get($payment->paymentId);
                        if ($order->isPaid() || $order->isAuthorized() || $order->isCompleted()) {
                            $success = true;
                        } else {
                            $abort   = true;
                            $success = false;
                        }
                        break;
                    default:
                        $success = true;
                        break;
                }
            }
        }
        if (isset($_GET['abort'])) {
            $abort = true;
        }

        Panel::compile("_views/_pages/account/balance.html", array_merge([
            'balance'              => $balance->getFormattedBalance(),
            'balanceRaw'           => $balance->getBalance(),
            'lastInvoice'          => $invoice ? $invoice->getFormattedAmount() : Formatters::formatBalance(0),
            'pp_mode'              => Settings::getConfigEntry("PP_MODE"),
            'stripe_enabled'       => self::hasEnabled('stripe'),
            'paypal_enabled'       => self::hasEnabled('paypal'),
            "pp_public"            => Settings::getConfigEntry('PP_CLIENT'),
            'success'              => $success,
            'abort'                => $abort,
            'molliePaymentMethods' => Settings::getConfigEntry('MOLLIE_ENABLED_PAYMENT_METHODS', []),
            'mollieEnabled'        => self::hasEnabled('mollie'),
            'coinbaseEnabled'      => self::hasEnabled("coinbase"),
            'paysafecardEnabled'   => self::hasEnabled('paysafecard'),
            'goCardlessEnabled'    => self::hasEnabled('gocardless'),
            'duitkuEnabled'        => self::hasEnabled("duitku"),
            "currency_symbol"      => ControllersInvoice::getActiveCurrency()['symbol'],
            'format'               => Settings::getConfigEntry('CURRENCY_POSITION', 'BEHIND'),
            'accountSetupNeeded'   => !$accountSetupDone,
            'show_vat'             => Settings::getConfigEntry("INVOICE_SHOW_VAT", false),
            "vat"                  => Settings::getConfigEntry("INVOICE_VAT", 0),
        ], Panel::getLanguage()->getPages(['global', 'dashboard', 'vouchers', 'balance'])));
    }

    /**
     * create a new omnipay payment
     *
     * @return array
     */
    public static function createOmniPayment() {
        $user     = BaseModule::getUser();
        $b        = Panel::getRequestInput();
        $method   = $b['method'];
        $submethod = $b['submethod'];
        $amount   = $b['amount'];
        $currency = ControllersInvoice::getActiveCurrency()['iso'];
        $baseUrl  = BaseModule::buildRequestDomain() . Settings::getConfigEntry("APP_URL") . 'balance';

        $vatRate = Settings::getConfigEntry("INVOICE_VAT", 0);

        $gw = self::getGateway($method);
        // create empty payments entry to update with ID later
        $id = self::createPayment($user->getId(), $amount);

        switch ($method) {
            case "paysafecard":
                $response = $gw->authorize([
                    'amount'           => $amount,
                    'currency'         => $currency,
                    'success_url'      => $baseUrl . "?success=1",
                    'failure_url'      => $baseUrl . "?abort=1",
                    'notification_url' => str_replace('/balance', '/api/payment-webhook', $baseUrl) . '?method=paysafecard&paymentId={payment_id}&orderId=' . $id,
                ])->send();

                if (!$response->isSuccessful()) {
                    return [
                        'm'    => $response->getMessage(),
                        'code' => 400
                    ];
                }

                $paymentId = $response->getPaymentId();
                self::updatePaymentId($id, $paymentId);

                return [
                    'url' => $response->getRedirectUrl()
                ];

            case "paypal":
                $response = $gw->purchase([
                    'amount'    => $amount,
                    'currency'  => $currency,
                    'returnUrl' => $baseUrl . "?success=1",
                    'cancelUrl' => $baseUrl . "?abort=1",
                ])->send();

                $data = $response->getData();

                if ($response->isSuccessful()) {

                    self::updatePaymentId($id, $data['id']);

                    return [
                        'url' => $response->getRedirectUrl()
                    ];
                }
                break;
            case "mollie":
                $userAddress = $user->getAddress()->load();
                $response = $gw->createOrder([
                    'amount'      => $amount,
                    'currency'    => $currency,
                    'returnUrl'   => $baseUrl . "?success=1",
                    'description' => Panel::getLanguage()->get('global', 'm_balance'),
                    "redirectUrl" => $baseUrl . '?success=1&method=mollie&orderId=' . $id,
                    "notifyUrl"   => str_replace('/balance', '/api/payment-webhook', $baseUrl) . '?method=mollie&orderId=' . $id,
                    "locale"      => "de_DE",
                    'card'        => [
                        'email'            => $user->getEmail(),
                        'billingFirstName' => $user->getName(),
                        'billingLastName'  => $user->getName(),
                        'billingAddress1'  => $userAddress->address1 . " " . $userAddress->address2,
                        'billingCity'      => $userAddress->getCity(),
                        'billingPostcode'  => $userAddress->getZipcode(),
                        'billingCountry'   => $userAddress->getCountryAlpha(),
                    ],
                    "items"       => [
                        [
                            "name"        => "Guthaben",
                            "vatRate"     => number_format($vatRate, 2, ".", ""),
                            'vatAmount'   => number_format($amount * ($vatRate / (100 + $vatRate)), 2, ".", ""),
                            "totalAmount" => $amount,
                            "unitPrice"   => $amount,
                            "quantity"    => 1,
                        ]
                    ],
                    "orderNumber" => $id,
                    "payment_method" => $submethod
                ])->send();

                $data = $response->getData();

                self::updatePaymentId($id, $data['id']);

                return [
                    'data' => $data,
                    'url'  => $response->getRedirectUrl()
                ];
            case "stripe":

                $item = new Item();
                $item->setName(Panel::getLanguage()->get('global', 'm_balance'));
                $item->setPrice($amount);
                $item->setQuantity(1);

                $transaction = $gw->purchase([
                    'items'                => [$item],
                    'customer'             => [
                        'email' => $user->getEmail()
                    ],
                    'transactionId'        => $id,
                    'payment_method_types' => ['card'],
                    'currency'             => $currency,
                    'mode'                 => 'payment',
                    'returnUrl'            => $baseUrl . '?success=1',
                    'cancelUrl'            => $baseUrl . '?abort=1'
                ])->send()->getData();

                self::updatePaymentId($id, $transaction['session']['payment_intent']);

                return ['url' => $transaction['session']['url']];
            case "coinbase":
                $metaData = [
                    'orderId' => $id
                ];

                $transaction = $gw->purchase([
                    'name'        => Panel::getLanguage()->get('global', 'm_balance'),
                    'description' => Panel::getLanguage()->get('global', 'm_balance'),
                    'amount'      => $amount,
                    'currency'    => $currency,
                    'customData'  => $metaData,
                    'redirectUrl' => $baseUrl . '?success=1&method=coinbase&orderId=' . $id,
                    'cancelUrl'   => $baseUrl . '?cancel=1',
                ])->send()->getData();

                self::updatePaymentId($id, $transaction['data']['id']);

                return ['url' => $transaction['data']['hosted_url']];
            case "gocardless":
                $transaction = $gw->createBillingRequest([
                    "description" => Panel::getLanguage()->get('global', 'm_balance'),
                    "amount"      => $amount,
                    "currency"    => $currency,
                ])->send();
                $transactionId = $transaction->getData()->id;
                $billingRequestFlow = $gw->authoriseRequest([
                    "returnUrl"            => $baseUrl . '?success=1&method=gocardless&orderId=' . $id,
                    "cancelUrl"            => $baseUrl . '?cancel=1',
                    "transactionReference" => $transactionId
                ])->send();
                self::updatePaymentId($id, $transactionId);
                return [
                    'url' => $billingRequestFlow->getData()->authorisation_url,
                    'id'  => $transactionId
                ];
            case "duitku":
                $transaction = $gw->purchase([
                    'description'   => Panel::getLanguage()->get('global', 'm_balance'),
                    'amount'        => $amount,
                    'email'         => $user->getEmail(),
                    'transactionId' => $id,
                    'returnUrl'     => $baseUrl . '?success=1&method=duitku&orderId=' . $id,
                    "notifyUrl"     => str_replace('/balance', '/api/payment-webhook', $baseUrl) . '?method=duitku&orderId=' . $id,
                ])->send();

                self::updatePaymentId($id, $transaction->getData()['reference']);

                return ['url' => $transaction->getData()['paymentUrl']];
        }
        return [];
    }

    public static function paymentWebhook() {
        $method = $_REQUEST['method'];

        switch ($method) {
            case "paysafecard":
                $paymentId = $_REQUEST['paymentId'];
                $gw = self::getGateway('paysafecard');
                $response = $gw->fetchTransaction([
                    'payment_id' => $paymentId
                ])->send();

                $payment = Panel::getDatabase()->fetch_single_row('payments', 'id', $_REQUEST['orderId']);
                if ($payment->status == "done") {
                    return [
                        'code'    => 200,
                        'message' => 'Payment already done'
                    ];
                }

                if ($response->getStatus() === 'AUTHORIZED') {
                    $captureResponse = $gw->capture([
                        'payment_id' => $paymentId
                    ])->send();

                    // set user balance
                    $user = (new User($payment->userId))->load();
                    $b    = $user->getBalance();

                    $b->addBalance($payment->amount);
                    $b->save();
                    $b->insertInvoice(
                        $payment->amount,
                        Invoice::BALANCE,
                        $user->getId(),
                        1,
                        $paymentId
                    );
                    $b->save();

                    EventManager::fire('balance::add', $user->toArray());

                    self::markPaymentDone($paymentId);

                }
                break;
            case "mollie":
                $mollie = new \Mollie\Api\MollieApiClient();
                $mollie->setApiKey(Settings::getConfigEntry("MOLLIE_API_KEY"));
                // check mollie payment status
                $payment = Panel::getDatabase()->fetch_single_row('payments', 'id', $_REQUEST['orderId']);
                if ($payment->status == "done") {
                    return [
                        'code'    => 200,
                        'message' => 'Payment already done'
                    ];
                }
                $order = $mollie->orders->get($payment->paymentId);
                if ($order->isPaid() || $order->isAuthorized()) {
                    // send
                    $order->shipAll();
                } else if ($order->isCompleted()) {
                    // set user balance
                    $user = (new User($payment->userId))->load();
                    $b    = $user->getBalance();

                    $b->addBalance($payment->amount);
                    $b->save();
                    $b->insertInvoice(
                        $payment->amount,
                        Invoice::BALANCE,
                        $user->getId(),
                        1,
                        $order->id
                    );
                    $b->save();

                    EventManager::fire('balance::add', $user->toArray());

                    self::markPaymentDone($order->id);
                } else {
                    return ['error' => true, 'message' => 'Come back later'];
                }
                break;
            case "stripe":
                Stripe::setApiKey(Settings::getConfigEntry("STRIPE_SECRET"));

                $payload = @file_get_contents('php://input');
                $secret = Settings::getConfigEntry("STRIPE_WEBHOOK_SECRET");
                $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
                $event = null;

                try {

                    $event = Webhook::constructEvent($payload, $sig_header, $secret);

                    if ($event->type == 'checkout.session.completed') {
                        $session = $event->data->object;

                        if ($session->payment_status == "paid") {
                            // update user Balance
                            $payment = Panel::getDatabase()->fetch_single_row('payments', 'paymentId', $session->payment_intent);
                            if ($payment->status == "done") {
                                return [
                                    'code'    => 200,
                                    'message' => 'Payment already done'
                                ];
                            }
                            $user = (new User($payment->userId))->load();
                            $b    = $user->getBalance();

                            $b->addBalance($payment->amount);
                            $b->save();
                            $b->insertInvoice(
                                $payment->amount,
                                Invoice::BALANCE,
                                $user->getId(),
                                1,
                                $session->payment_intent
                            );
                            $b->save();

                            EventManager::fire('balance::add', $user->toArray());

                            self::markPaymentDone($session->payment_intent);
                            return [
                                'code' => 200
                            ];
                        } else {
                            return [
                                'code' => 400
                            ];
                        }
                    }
                } catch (\UnexpectedValueException $e) {
                    // Invalid payload
                    return [
                        'code' => 400
                    ];
                } catch (\Stripe\Exception\SignatureVerificationException $e) {
                    // Invalid signature
                    return [
                        'code' => 400
                    ];
                }
                return [
                    'code' => 400
                ];
            case "paypal":
                $user = BaseModule::getUser();
                $db = Panel::getDatabase();
                $currency = ControllersInvoice::getActiveCurrency()['iso'];

                $apiContext = new ApiContext(
                    new OAuthTokenCredential(
                        Settings::getConfigEntry("PP_CLIENT"),
                        Settings::getConfigEntry("PP_SECRET")
                    )
                );

                $apiContext->setConfig([
                    "mode" => Settings::getConfigEntry("PP_MODE_BACKEND"),
                ]);

                $paymentID = $_POST['paymentID'];
                $payerID = $_POST['payerID'];

                $payment = Payment::get($paymentID, $apiContext);

                $p = Panel::getDatabase()->fetch_single_row('payments', 'paymentId', $paymentID);
                if (!$p || $p->status != "created") {
                    return ['error' => true, 'message' => "not in created state"];
                }
                $execution = new PaymentExecution();
                $execution->setPayerId($payerID);

                $transaction = new Transaction();
                $amount = new Amount();

                $amount->setCurrency($currency)
                    ->setTotal($p->amount);

                $transaction->setAmount($amount);

                $execution->addTransaction($transaction);

                try {
                    $result = $payment->execute($execution, $apiContext);
                    try {
                        $user    = (new User($p->userId))->load();
                        $balance = $user->getBalance();
                        $balance->insertInvoice(
                            $p->amount,
                            Invoice::BALANCE,
                            $user->getId(),
                            1,
                            $payment->getId()
                        );
                        $balance = $user->getBalance();
                        $balance->addBalance($p->amount);
                        $balance->save();

                        self::markPaymentDone($payment->getId());
                        EventManager::fire('balance::add', $user->toArray());

                        return [
                            'code'   => 200,
                            'status' => 'success'
                        ];
                    } catch (\Exception $ex) {
                        return [
                            'code'   => 500,
                            'status' => 'failed',
                            'ex'     => $ex->getMessage()
                        ];
                    }
                } catch (\Exception $ex) {
                    echo $ex;
                    return [
                        'code' => 400
                    ];
                }
            case "coinbase":
                $sig = getallheaders()['X-Cc-Webhook-Signature'];
                $input = file_get_contents("php://input");
                $hash = hash_hmac('sha256', $input, Settings::getConfigEntry("COINBASE_WEBHOOK_SECRET"));

                $body = Panel::getRequestInput();
                if ($hash !== $sig)
                    return [
                        'code'    => 400,
                        'message' => "Signature invalid"
                    ];

                $event = $body['event'];
                if ($event['type'] !== "charge:confirmed") {
                    return [
                        'code'    => 200,
                        'message' => 'invalid event type'
                    ];
                }
                $paymentId = $event['data']['id'];

                // update user Balance
                $payment = Panel::getDatabase()->fetch_single_row('payments', 'paymentId', $paymentId);

                if (!$payment) {
                    return [
                        'code'    => 200,
                        'message' => "payment with id $paymentId not found"
                    ];
                }

                $user = (new User($payment->userId))->load();
                $b = $user->getBalance();

                $b->addBalance($payment->amount);
                $b->save();
                $b->insertInvoice(
                    $payment->amount,
                    Invoice::BALANCE,
                    $user->getId(),
                    1,
                    $paymentId
                );
                $b->save();

                EventManager::fire('balance::add', $user->toArray());

                self::markPaymentDone($paymentId);
                return [
                    'code'    => 200,
                    'message' => 'payment completed'
                ];
            case "gocardless":
                $request_body = @file_get_contents('php://input');
                $headers = array_change_key_case(getallheaders());
                $signature_header = $headers["webhook-signature"];
                $webhook_endpoint_secret = Settings::getConfigEntry("GOCARDLESS_WEBHOOK", "");
                $events = \GoCardlessPro\Webhook::parse($request_body,
                    $signature_header,
                    $webhook_endpoint_secret);
                foreach ($events as $event) {
                    print ("Processing event " . $event->id . "\n");

                    switch ($event->resource_type) {
                        case "mandates":
                            return [
                                'code'    => 204,
                                'message' => 'Ignoring'
                            ];
                        case "payments":
                            if ($event->action !== "confirmed") {
                                return [
                                    'code'    => 204,
                                    'message' => 'Ignoring'
                                ];
                            }
                            $paymentId = $event->links->billing_request;

                            // update user Balance
                            $payment = Panel::getDatabase()->fetch_single_row('payments', 'paymentId', $paymentId);

                            if (!$payment || $payment->done) {
                                return [
                                    'code'    => 400,
                                    'message' => "payment with id $paymentId not found"
                                ];
                            }

                            $user = (new User($payment->userId))->load();
                            $b = $user->getBalance();

                            $b->addBalance($payment->amount);
                            $b->save();
                            $b->insertInvoice(
                                $payment->amount,
                                Invoice::BALANCE,
                                $user->getId(),
                                1,
                                $paymentId
                            );
                            $b->save();

                            EventManager::fire('balance::add', $user->toArray());

                            self::markPaymentDone($paymentId);
                            return [
                                'code'    => 200,
                                'message' => 'payment completed'
                            ];
                        default:
                            break;
                    }
                }

                return ['status' => 200];

            case "duitku":
                $body = Panel::getRequestInput();

                if ($body['resultCode'] != '00') {
                    return [
                        'code'    => 200,
                        'message' => "Payment not completed. Accepting"
                    ];
                }

                $paymentId = $body['reference'];

                // update user Balance
                $payment = Panel::getDatabase()->fetch_single_row('payments', 'paymentId', $paymentId);

                if (!$payment || $payment->status != 'created') {
                    return [
                        'code'    => 400,
                        'message' => "payment with id $paymentId in created state not found"
                    ];
                }

                $user = (new User($payment->userId))->load();
                $b = $user->getBalance();

                $b->addBalance($payment->amount);
                $b->save();
                $b->insertInvoice(
                    $payment->amount,
                    Invoice::BALANCE,
                    $user->getId(),
                    1,
                    $paymentId
                );
                $b->save();

                EventManager::fire('balance::add', $user->toArray());

                self::markPaymentDone($paymentId);

                return [
                    'code'    => 200,
                    'message' => 'payment completed'
                ];
        }




        return [
            'code' => 200
        ];
    }


    /**
     * endpoint for stripe webhook, cannot use different function because URL in docs
     *
     * @return array
     */
    public static function stripeWebhook() {
        $_REQUEST['method'] = "stripe";
        return self::paymentWebhook();
    }

    /**
     * return a PaymentGateway instance of the selected provider
     *
     * @param string $provider
     * @return null|\Omnipay\Common\GatewayInterface
     */
    private static function getGateway($provider) {
        $gw = null;
        switch ($provider) {
            case "paypal":
                $gw = Omnipay::create("PayPal_Rest");

                $gw->initialize([
                    'clientId' => Settings::getConfigEntry("PP_CLIENT"),
                    'secret'   => Settings::getConfigEntry("PP_SECRET"),
                    'testMode' => Settings::getConfigEntry("PP_MODE_BACKEND") === "sandbox"
                ]);
                break;
            case "stripe":
                $gw = Omnipay::create("StripeCheckout");

                $gw->initialize([
                    'apiKey'     => Settings::getConfigEntry("STRIPE_SECRET"),
                    'apiVersion' => '2020-08-27',
                ]);
                break;
            case "mollie":
                $gw = Omnipay::create("Mollie");

                $gw->initialize([
                    'apiKey' => Settings::getConfigEntry("MOLLIE_API_KEY")
                ]);
                break;
            case "coinbase":
                $gw = Omnipay::create("CoinbaseCommerce");

                $gw->initialize([
                    'accessToken' => Settings::getConfigEntry("COINBASE_API_KEY")
                ]);

                $gw->setApiVersion('2018-03-22');
                break;
            case "paysafecard":
                $gw = Omnipay::create('Paysafecard');

                $gw->initialize([
                    'apiKey'   => Settings::getConfigEntry("PAYSAFECARD_API_KEY"),
                    'testMode' => false
                ]);
                break;
            case "gocardless":
                $gw = Omnipay::create('GoCardlessV2\Redirect');
                $gw->initialize([
                    'testMode'     => false,
                    'access_token' => Settings::getConfigEntry("GOCARDLESS_API_KEY")
                ]);
                break;
            case "duitku":
                $gw = Omnipay::create("Duitku");

                $gw->initialize([
                    'apiKey'       => Settings::getConfigEntry("DUITKU_APIKEY"),
                    'merchantCode' => Settings::getConfigEntry("DUITKU_MERCHANT"),
                    'sandbox'      => false
                ]);
                break;
        }
        return $gw;
    }

    /**
     * insert a new payment
     *
     * @param int $userId
     * @param int $amount
     * @param string paymentId
     * @return int
     */
    public static function createPayment($userId, $amount, $paymentId = "") {
        Panel::getDatabase()->insert('payments', [
            'amount'    => $amount,
            'userId'    => $userId,
            'paymentId' => $paymentId,
            'status'    => "created"
        ]);
        return Panel::getDatabase()->get_last_id();
    }

    /**
     * update the paymentId to a payments entry
     *
     * @param int $dbId
     * @param string $paymentId
     * @return void
     */
    public static function updatePaymentId($dbId, $paymentId) {
        Panel::getDatabase()->update('payments', [
            'paymentId' => $paymentId
        ], 'id', $dbId);
    }

    /**
     * mark a payment as done
     *
     * @param [type] $paymentId
     * @return int
     */
    public static function markPaymentDone($paymentId) {
        return Panel::getDatabase()->update('payments', [
            'status' => "done"
        ], 'paymentId', $paymentId);
    }

    /**
     * balance prediction API, will be handled by other interceptors to add their own values.
     * 
     *
     * @return void
     */
    public static function prediction() {
        header('Content-Type: application/json');
        $user = BaseModule::getUser();

        // load all servers that are due for payment in the next 30 days
        $servers = $user->getServers();
        $res     = [];
        // convert nextPayment into unix timestamp
        foreach ($servers as $server) {
            if (strtotime($server['nextPayment']) < strtotime('+30 days')) {
                $res[] = [
                    'timestamp' => strtotime($server['nextPayment']),
                    'price'     => $server['price'],
                    'label'     => 'Server: ' . $server['hostname']
                ];
            }
        }

        Panel::compile("_views/api.json", [
            'res' => $res
        ]);
    }

    public static function getEnabledPaymentMethods() {
        return defined("ENABLED_PAYMENT_METHODS") ? ENABLED_PAYMENT_METHODS : [];
    }

    public static function hasEnabled($m) {
        return in_array($m, self::getEnabledPaymentMethods());
    }
}