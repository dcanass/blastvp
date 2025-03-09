<?php

/**
 * Created by PhpStorm.
 * User: bennet
 * Date: 02.11.18
 * Time: 09:47
 */

namespace Module\BaseModule;

use Controllers\Panel;
use Module\BaseModule\Controllers\Admin\Settings;
use Module\Module;
use Objects\Event\Action;
use Objects\Event\Enricher;
use Objects\Event\Event;
use Objects\Event\EventManager;
use Objects\Event\FlowComponents\CreateTicket;
use Objects\Event\FlowComponents\ModifyFirewall;
use Objects\Event\FlowComponents\ModifyServer;
use Objects\Event\FlowComponents\SendMail;
use Objects\Event\FlowComponents\Webhook;
use Objects\User;

class BaseModule extends Module {

    private $name = "BaseModule";
    private $author = "Bennet Gallein <me@bennetgallein.de>";

    /**
     * array of unauthenticated routes coming from other modules
     */
    static $unauthenticatedRoutes = [];

    /**
     * UserModule constructor.
     * @throws \Safe\Exceptions\FilesystemException
     */
    public function __construct() {
        parent::__construct($this->name, $this->author);

        /**
         * server events
         */
        EventManager::registerEvent(
            (new Event())
                ->setName('server::create')
                ->setFriendlyName('Server created')
                ->setDescription('event when a server gets created')
                ->setParameters(['server' => 'the server object'])
        );
        EventManager::registerEvent(
            (new Event())
                ->setName('server::delete')
                ->setFriendlyName('Server removed')
                ->setDescription('event when a server gets deleted')
                ->setParameters(['server' => 'the server object'])
        );
        EventManager::registerEvent(
            (new Event())
                ->setName('server::start')
                ->setFriendlyName('Server started')
                ->setDescription('event when a server gets started')
                ->setParameters(['server' => 'the server object'])
        );
        EventManager::registerEvent(
            (new Event())
                ->setName('server::stop')
                ->setFriendlyName('Server stopped')
                ->setDescription('event when a server gets stopped')
                ->setParameters(['server' => 'the server object'])
        );
        EventManager::registerEvent(
            (new Event())
                ->setName('server::restart')
                ->setFriendlyName('Server restarted')
                ->setDescription('event when a server gets restarted')
                ->setParameters(['server' => 'the server object'])
        );
        EventManager::registerEvent(
            (new Event())
                ->setName('server::extend')
                ->setFriendlyName('Server extended')
                ->setDescription('event when a server is automatically extended')
                ->setParameters(['server' => 'the server object'])
        );
        EventManager::registerEvent(
            (new Event())
                ->setName('server::modified')
                ->setFriendlyName('Server modified')
                ->setDescription('event when a server is modified')
                ->setParameters(['server' => 'the server object'])
        );

        /**
         * user events
         */
        EventManager::registerEvent(
            (new Event())
                ->setName('user::register')
                ->setFriendlyName('User registered')
                ->setDescription('event a user account gets created')
                ->setParameters(['user' => 'the user object'])
        );
        EventManager::registerEvent(
            (new Event())
                ->setName('user::login')
                ->setFriendlyName('User login')
                ->setDescription('event when a user logs in')
                ->setParameters(['user' => 'the user object'])
        );
        EventManager::registerEvent(
            (new Event())
                ->setName('user::update')
                ->setFriendlyName('User data updated')
                ->setDescription('event when userdata gets updated')
                ->setParameters(['user' => 'the user object'])
        );

        /**
         * balance
         */
        EventManager::registerEvent(
            (new Event())
                ->setName('balance::add')
                ->setFriendlyName('Balance added')
                ->setDescription('event when balance changes')
                ->setParameters(['user' => 'the user object'])
        );
        EventManager::registerEvent(
            (new Event())
                ->setName('balance::remove')
                ->setFriendlyName('Balance removed')
                ->setDescription('event when balance changes')
                ->setParameters(['user' => 'the user object'])
        );
        EventManager::registerEvent(
            (new Event())
                ->setName('invoice::created')
                ->setFriendlyName('Invoice created')
                ->setDescription('Event when an invoice is created')
                ->setParameters([
                    'invoice' => [
                        'amount'     => 'the amount of the invoice',
                        'type'       => 'invoice type. BALANCE = 1; PAYMENT = 2; CREDIT  = 3;',
                        'userid'     => 'userid',
                        'done'       => '0 | 1',
                        'descriptor' => 'line item text'
                    ]
                ])
        );

        /**
         * event log
         */
        EventManager::registerEvent(
            (new Event())
                ->setName('event::create')
                ->setFriendlyName('Event created')
                ->setDescription('When a new event-log entry is created')
                ->setParameters(['log' => 'the event log entry'])
        );

        /**
         * ticket
         */
        EventManager::registerEvent(
            (new Event())
                ->setName('ticket::create')
                ->setFriendlyName(('Ticket created'))
                ->setDescription('When a ticket is created')
                ->setParameters((['ticket' => 'the ticket object']))
        );

        /**
         * register enrichers
         */
        EventManager::registerEnrichers(
            new Enricher('servers', 'servers', ["id", "vmid", "userid", "hostname", "cpu", "ram", "disk", "os", "ip", "_ip", "ip6", "_ip6", "node", "createdAt", "deletedAt", "nextPayment", "paymentReminderSent", "packageId", "status", "cancelledAt"]),
            new Enricher('users', 'users', ["id", "username", "email", "password", "register", "permission", "confirmationToken"]),
            new Enricher('balances', 'balances', ["id", "userid", "balance"]),
            new Enricher('charges', 'charges', ["id", "price", "type", "calcType", "calcOnly", "osid", "recurring", "description", "active"]),
            new Enricher('packages', 'packages', ["id", "name", "price", "cpu", "ram", "disk", "decoded_meta" => ["title", "value"], "meta", "type", "templateId"]),
            new Enricher('templates', 'templates', ["id", "vmid", "displayName", "defaultUser", "defaultDrive", "minDisk", "minCpu", "minRAM", "disabled"]),
            new Enricher('IPv4 Adresses', 'ipam_4_addresses', ["id", "ip", "fk_ipam", "mac", "in_use"]),
            new Enricher('IPv4 IPAM Ranges', 'ipam_4', ["id", "start", "end", "subnet", "gateway", "scope", "percentage", "nodes", "createdAt", "userId"]),
            new Enricher('IPv6 Adresses', 'ipam_6_addresses', ["id", "ip", "fk_ipam", "mac", "in_use"]),
            new Enricher('IPv6 IPAM Ranges', 'ipam_6', ["id", "network", "prefix", "target", "gateway", "scope", "percentage", "nodes", "createdAt", "userId"])
        );

        EventManager::registerActions(
            new Action(
                "send-email",
                "SendEmailComponent",
                "js/components/flow/actions/send-email.js",
                "Send E-Mail",
                function ($data, $parameters) {
                    return SendMail::execute($data, $parameters);
                }),
            new Action(
                "send-webhook",
                "SendWebhookComponent",
                "js/components/flow/actions/send-webhook.js",
                "Send Webhook",
                function ($data, $parameters) {
                    return Webhook::execute($data, $parameters);
                }),
            new Action(
                "create-ticket",
                "CreateTicketComponent",
                "js/components/flow/actions/create-ticket.js",
                "Create Ticket",
                function ($data, $parameters) {
                    return CreateTicket::execute($data, $parameters);
                }),
            new Action(
                "modify-server",
                "ModifyServerComponent",
                "js/components/flow/actions/modify-server.js",
                "Modify Server",
                function ($data, $parameters) {
                    return ModifyServer::execute($data, $parameters);
                }),
            new Action(
                "modify-firewall",
                "FirewallComponent",
                "js/components/flow/actions/modify-firewall.js",
                "Modify Firewall",
                function ($data, $parameters) {
                    return ModifyFirewall::execute($data, $parameters);
                })
        );

        self::initUser();
    }

    public function initUser() {
        if (php_sapi_name() === 'cli') {
            return;
        }

        $headers = array_change_key_case(getallheaders());

        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            header('Content-Type: application/json');
            if (Settings::getConfigEntry('CORS', false)) {
                header('Access-Control-Allow-Origin: ' . Settings::getConfigEntry('CORS_ALLOW_ORIGIN', '*'));
                header('Access-Control-Allow-Methods: ' . Settings::getConfigEntry('CORS_ALLOW_METHODS', 'GET, POST'));
                header("Access-Control-Allow-Headers: " . Settings::getConfigEntry('CORS_ALLOW_HEADERS', '*'));
            }
            die(json_encode([]));
        }

        if (isset($headers['authorization']) && str_starts_with($headers['authorization'], 'Token')) {
            // load user from token
            $token = explode(' ', $headers['authorization'])[1];
            $token = Panel::getDatabase()->fetch_single_row('api-tokens', 'token', $token);
            if (!$token)
                return ['code' => 401];
            $user = (new User($token->userId))->load();
            header('Authorized-By: Token');
        } else {
            session_start([
                'cookie_httponly' => true
            ]);
            $user = isset($_SESSION['proxmox_p']) ? unserialize($_SESSION['proxmox_p']) : false;
        }

        if ($user && !($user instanceof User))
            die("No cheating in the session bro!");
        if ($user) {
            $db = Panel::getDatabase();

            $query = $db->fetch_single_row("users", "id", $user->getId());
            if (!$query) {
                header("Location: " . Settings::getConfigEntry("APP_URL") . "login");
                die();
            }
            $_SESSION['proxmox_p'] = serialize((new User($query->id))->load());
            define("USER", serialize($user));
        } else {
            $x   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $pos = strpos($x, Settings::getConfigEntry("APP_URL"));
            if ($pos !== false) {
                $x = substr_replace($x, "", $pos, strlen(Settings::getConfigEntry("APP_URL")));
            }

            self::addUnauthenticatedRoute("login", "register", "confirm", "confirm-reset");

            // check if path starts with /api, then we allow
            preg_match('/^api\/(.*)/', $x, $matches);
            if (!in_array($x, self::$unauthenticatedRoutes) && sizeof($matches) == 0) {
                header("Location: " . Settings::getConfigEntry("APP_URL") . "login");
                die();
            }
        }


        $this->routes();
    }

    public function routes() {
        $data = [
            ["/", "BaseModule\Controllers\Dashboard::main", [], "GET"],
            ["/dashboard", "BaseModule\Controllers\Dashboard::main", [], "GET"],
            ["/login", "BaseModule\Controllers\Authentication::login", [], "GET"],
            ["/register", "BaseModule\Controllers\Authentication::register", [], "GET"],
            ["/login", "BaseModule\Controllers\Authentication::loginPost", [], "POST"],
            ["/register", "BaseModule\Controllers\Authentication::registerPost", [], "POST"],
            ["/confirm", "BaseModule\Controllers\Authentication::confirm", [], 'GET'],
            ["/logout", "BaseModule\Controllers\Authentication::logout", [], "GET"],
            ["/server/:id", "BaseModule\Controllers\Server::dashboard", ["id" => '\d+'], "GET"],
            ['/server/terminal/:id', "BaseModule\Controllers\Server::terminal", ['id' => "\d+"], 'GET'],
            ["/support", "BaseModule\Controllers\Support::overview", [], "GET"],
            ["/support/:id", "BaseModule\Controllers\Support::ticket", ["id" => "\d+"], "GET"],
            ["/messages", "BaseModule\Controllers\Messages::inbox", [], "GET"],
            ["/settings", "BaseModule\Controllers\Settings::render", [], "GET"],
            ['/api/settings/language/:language', "BaseModule\Controllers\Settings::setLanguage", ['language' => '\w+'], 'GET'],
            ["/invoices", "BaseModule\Controllers\Invoice::listAll", [], "GET"],
            ["/invoices/:id", "BaseModule\Controllers\Invoice::single", ["id" => "\d+"], "GET"],
            ["/order", "BaseModule\Controllers\Order::render", [], "GET"],
            ["/balance", "BaseModule\Controllers\Balance::render", [], "GET"],
            ["/admin/settings", "BaseModule\Controllers\Admin\Settings::main", [], "GET"],
            ["/admin/settings/order", "BaseModule\Controllers\Admin\Settings::order", [], "GET"],

            ['/admin/settings/language', "BaseModule\Controllers\Admin\Settings::language", [], 'GET'],
            ['/admin/host', "BaseModule\Controllers\Admin\Settings::hostOverview", [], 'GET'],
            ['/admin/host/:id', "BaseModule\Controllers\Admin\Settings::singleHostOverview", ['id' => '\w+'], 'GET'],
            ['/admin/tickets', "BaseModule\Controllers\Support::admin_overview", [], "GET"],
            ['/admin/server', "BaseModule\Controllers\Server::admin_overview", [], 'GET'],
            ['/admin/users', 'BaseModule\Controllers\Admin::admin_users', [], 'GET'],
            ['/admin/users/:id', "BaseModule\Controllers\Admin::admin_user", ['id' => "\d+"], 'GET'],
            ['/admin/users', 'BaseModule\Controllers\Admin::createUser', [], 'POST'],
            ['/admin/invoices', 'BaseModule\Controllers\Admin::adminInvoices', [], 'GET'],


            ['/api/tickets/:id', 'BaseModule\Controllers\Support::apiSingleTicket', ['id' => '\d+'], 'GET'],
            ['/api/support/templates', 'BaseModule\Controllers\Support::getTemplates', [], 'GET'],
            ['/api/support/templates', 'BaseModule\Controllers\Support::createTemplate', [], 'POST'],
            ['/api/support/templates/:id', 'BaseModule\Controllers\Support::updateTemplate', ['id' => '\d+'], 'PATCH'],
            ['/api/support', 'BaseModule\Controllers\Support::createForUser', [], 'POST'],

            ['/api/admin/update', "BaseModule\Controllers\Admin::update", [], 'GET']
        ];

        $server = [
            ['/api/servers', 'BaseModule\Controllers\Server::listAll', [], 'GET'],
            ['/api/server/:id', 'BaseModule\Controllers\Server::serverRest', ['id' => '\d+'], 'GET'],
            ['/api/server/:id', 'BaseModule\Controllers\Server::apiPatch', ['id' => '\d+'], 'PATCH'],
            ['/api/server/shutdown/:id', "BaseModule\Controllers\Server::shutdownServer", ['id' => '\d+'], 'GET'],
            ['/api/server/start/:id', "BaseModule\Controllers\Server::startServer", ['id' => '\d+'], 'GET'],
            ['/api/server/restart/:id', "BaseModule\Controllers\Server::restartServer", ['id' => '\d+'], 'GET'],
            ['/api/server/reset/:id', "BaseModule\Controllers\Server::resetServer", ['id' => '\d+'], 'GET'],
            ['/api/server/stop/:id', "BaseModule\Controllers\Server::stopServer", ['id' => '\d+'], 'GET'],
            ['/api/server/graph/:id', "BaseModule\Controllers\Server::getGraphs", ['id' => '\d+'], 'GET'],
            ['/api/server/delete/:id', "BaseModule\Controllers\Server::delete", ['id' => "\d+"], 'GET'],
            ['/api/server/rebuild/:id', "BaseModule\Controllers\Server::rebuild", ['id' => '\d+'], 'POST'],
            ['/api/server/share/:id', "BaseModule\Controllers\Server::share", ['id' => '\d+'], 'POST'],
            ['/api/server/revoke-share/:id', "BaseModule\Controllers\Server::revokeShare", ['id' => '\d+'], 'POST'],
            ['/api/server/update-share/:id', "BaseModule\Controllers\Server::updateShare", ['id' => '\d+'], 'POST'],
            ['/api/server/cancel/:id', "BaseModule\Controllers\Server::cancelServer", ['id' => '\d+'], 'GET'],
            ['/api/server/revoke-cancel/:id', "BaseModule\Controllers\Server::revokeCancelServer", ['id' => '\d+'], 'GET'],
            ['/api/server/unsuspend/:id', "BaseModule\Controllers\Server::unsuspend", ['id' => '\d+'], 'GET'],
            ['/api/server/rescale/:id', 'BaseModule\Controllers\Order::purchaseResize', ['id' => '\d+'], 'PATCH'],
            ['/api/server/reset-password/:id', 'BaseModule\Controllers\Server::agentResetPassword', ['id' => '\d+'], 'POST'],
            ['/api/server/snapshots/:id', 'BaseModule\Controllers\Server\Snapshots::listSnapshots', ['id' => '\d+'], 'GET'],
            ['/api/server/snapshots/:id', 'BaseModule\Controllers\Server\Snapshots::createSnapshot', ['id' => '\d+'], 'POST'],
            ['/api/server/snapshots/:id', 'BaseModule\Controllers\Server\Snapshots::deleteSnapshot', ['id' => '\d+'], 'DELETE'],
            ['/api/server/snapshots/:id/restore', 'BaseModule\Controllers\Server\Snapshots::restoreSnapshot', ['id' => '\d+'], 'POST'],

            ['/api/server/isos/:id', 'BaseModule\Controllers\Server::getIsos', ['id' => '\d+'], 'GET'],
            ['/api/order-info/:id', 'BaseModule\Controllers\Order::getOrderParameters', ['id' => '\d+'], 'GET'],
            ['/api/server/recalc-price/:id', 'BaseModule\Controllers\Order::getNewServerPrice', ['id' => '\d+'], "POST"],
        ];

        $api_routes = [
            ["/api/admin/order/save/cores", "BaseModule\Controllers\Admin\Settings::api_cores", [], "POST"],
            ["/api/admin/order/save/ram", "BaseModule\Controllers\Admin\Settings::api_ram", [], "POST"],
            ["/api/admin/order/save/disk", "BaseModule\Controllers\Admin\Settings::api_disk", [], "POST"],
            ['/api/admin/order/save/misc', "BaseModule\Controllers\Admin\Settings::api_misc", [], 'POST'],
            ["/api/admin/settings/save", "BaseModule\Controllers\Admin\Settings::api_save", [], "POST"],
            ['/api/admin/settings/createNoteLimit', "BaseModule\Controllers\Admin\Settings::addNewNodeLimit", [], 'POST'],
            ["/api/order/calc", "BaseModule\Controllers\Order::api_calc", [], "POST"],
            ["/api/order/get-specs", "BaseModule\Controllers\Order::api_get_specs", [], "POST"],
            ['/api/admin/save-language', "BaseModule\Controllers\Admin\Settings::languageSave", [], "POST"],
            ['/api/admin/save-dashboard', "BaseModule\Controllers\Admin\Settings::saveDashboardOrder", [], 'POST'],
            ['/api/admin/toggle-module/:module', "BaseModule\Controllers\Admin\Settings::toggleModule", ['module' => '\w+'], 'GET'],
            ['/api/admin/host/graph', "BaseModule\Controllers\Admin\Settings::hostOverviewGraphs", [], 'GET'],
            ['/api/admin/host/status', "BaseModule\Controllers\Admin\Settings::hostStatus", [], 'GET'],
            ['/api/admin/host/usage/:node', 'BaseModule\Controllers\Admin\Settings::hostGraph', ['node' => '\w+'], 'GET'],
            ['/api/host/load-isos', 'BaseModule\Controllers\Order::loadIsos', [], 'GET'],
            ['/api/host/load-vm-info', "BaseModule\Controllers\Order::loadVmInfo", [], 'POST'],
            ['/api/host/load-server-info/:id', "BaseModule\Controllers\Order::loadServerInfo", ['id' => '\d+'], "GET"],
            ['/api/host/update-server-info/:id', "BaseModule\Controllers\Order::updateServerInfo", ['id' => '\d+'], "POST"],
            ['/api/host/import-server', "BaseModule\Controllers\Order::importServer", [], 'POST'],
            ['/api/order/purchase', "BaseModule\Controllers\Order::purchase", [], 'POST'],

            ['/api/address/save', "BaseModule\Controllers\Settings::postSave", [], 'POST'],
            ['/api/change-password', "BaseModule\Controllers\Settings::changePassword", [], 'POST'],
            ['/api/request-2fa', 'BaseModule\Controllers\Settings::apiRequest2FA', [], 'GET'],
            ['/api/save-2fa', 'BaseModule\Controllers\Settings::apiSave2FA', [], 'POST'],

            ['/api/forgot-password', 'BaseModule\Controllers\Settings::apiPasswordResetRequest', [], 'POST'],
            ['/api/reset-password', 'BaseModule\Controllers\Settings::apiResetPassword', [], 'POST'],
            ['/confirm-reset', 'BaseModule\Controllers\Authentication::login', [], 'GET'],

            ['/api/admin/set-rank/:userid/:rank', "BaseModule\Controllers\Admin::setRank", ['userid' => '\d+', 'rank' => '\d+'], 'GET'],
            ['/api/admin/add-balance/:userid', "BaseModule\Controllers\Admin::addBalance", ['userid' => '\d+'], 'POST'],
            ['/api/admin/remove-balance/:userid', "BaseModule\Controllers\Admin::removeBalance", ['userid' => '\d+'], 'POST'],
            ['/api/admin/disable-twofa/:userid', "BaseModule\Controllers\Admin\UserAPI::disable2FA", ['userid' => '\d+'], "PATCH"],
            ['/api/admin/delete-user/:id', "BaseModule\Controllers\Admin::deleteUser", ['id' => '\d+'], 'DELETE'],
            ['/api/admin/password-reset/:id', "BaseModule\Controllers\Admin::changePassword", ['id' => '\d+'], 'POST'],
            ['/api/admin/login-as/:id', "BaseModule\Controllers\Authentication::loginAsUser", ['id' => '\d+'], "POST"],
            ['/api/admin/restore-session', "BaseModule\Controllers\Authentication::restoreSession", [], "POST"],
            ['/api/admin/import-module', "BaseModule\Controllers\Admin::importModule", [], 'POST'],
            ['/api/admin/test-mail', "BaseModule\Controllers\Admin::testMailServer", [], 'GET'],
            ['/api/admin/test-paypal', "BaseModule\Controllers\Admin::testPayPal", [], 'POST'],
            ['/api/admin/test-stripe', "BaseModule\Controllers\Admin::testStripe", [], 'POST'],
            ['/api/admin/test-proxmox', "BaseModule\Controllers\Admin::testProxmox", [], 'POST'],
            ['/api/admin/discover-email', "BaseModule\Controllers\Admin::discoverEmail", [], 'POST'],
            ['/api/admin/add-payment-method/:method', "BaseModule\Controllers\Admin\Settings::api_addPayment", ['method' => '\w+'], 'GET'],
            ['/api/admin/remove-payment-method/:method', "BaseModule\Controllers\Admin\Settings::api_removePayment", ['method' => '\w+'], 'GET'],
            ['/api/admin/add-language/:language', "BaseModule\Controllers\Admin\Settings::api_addLanguage", ['language' => '\w+'], 'GET'],
            ['/api/admin/remove-language/:language', "BaseModule\Controllers\Admin\Settings::api_removeLanguage", ['language' => '\w+'], 'GET'],
            ['/api/admin/users', 'BaseModule\Controllers\Admin\UserAPI::apiUsers', [], 'GET'],
            ['/api/admin/users/:id', 'BaseModule\Controllers\Admin\UserAPI::apiUser', ['id' => '\d+'], 'GET'],
            ['/api/admin/invoice', 'BaseModule\Controllers\Admin\InvoiceAPI::createInvoice', [], 'POST'],
            ['/api/admin/invoices', 'BaseModule\Controllers\Admin\InvoiceAPI::getInvoices', [], 'POST'],
            ['/api/admin/tickets', 'BaseModule\Controllers\Admin\TicketAPI::getTickets', [], 'POST'],
            ['/api/admin/servers', 'BaseModule\Controllers\Admin\ServerAPI::getServers', [], 'POST'],

            ['/api/admin/add-payment-method-to-method/:method/:paymentMethod', "BaseModule\Controllers\Admin\Settings::api_addMethodToMethod", ['method' => '\w+', 'paymentMethod' => '\w+'], 'GET'],
            ['/api/admin/remove-payment-method-from-method/:method/:paymentMethod', "BaseModule\Controllers\Admin\Settings::api_removeMethodFromMethod", ['method' => '\w+', 'paymentMethod' => '\w+'], 'GET'],
            ['/api/admin/install-cronjob', "BaseModule\Controllers\Admin\Settings::installCronjob", [], 'GET'],

            ['/api/admin/get-module-updates', "BaseModule\Controllers\Admin\Settings::getModuleUpdates", [], 'GET'],
            ['/api/admin/update-module/:module', "BaseModule\Controllers\Admin\Settings::updateModule", ["module" => '\w+'], 'GET'],
            ['/api/changelog/:module', "BaseModule\Controllers\Admin\Settings::getModuleChangelog", ['module' => '\w+'], 'GET'],

            ['/api/admin/servers-by-node/:node', "BaseModule\Controllers\Admin::getServersByNode", ['node' => '\w+'], 'GET'],
            ['/api/admin/server-migration', "BaseModule\Controllers\Admin::serverMigration", [], 'POST'],

            ['/api/dashboard-meta', "BaseModule\Controllers\Dashboard::metaData", [], "GET"],
        ];

        $ticket_api = [
            ['/api/tickets', 'BaseModule\Controllers\Support::listAll', [], 'GET'],
            ['/api/ticket/create', "BaseModule\Controllers\Support::createTicket", [], 'POST'],
            ['/api/ticket/answer', "BaseModule\Controllers\Support::answerTicket", [], 'POST'],
            ['/api/ticket/close/:id', 'BaseModule\Controllers\Support::closeTicket', ['id' => '\d+'], 'GET'],
            ['/api/ticket/reopen/:id', 'BaseModule\Controllers\Support::reopenTicket', ['id' => '\d+'], 'GET']
        ];

        $payment = [
            ['/api/create-payment', "BaseModule\Controllers\Balance::createOmniPayment", [], "POST"],
            ['/api/stripe-webhook', "BaseModule\Controllers\Balance::stripeWebhook", [], 'POST'],
            ['/api/payment-webhook', "BaseModule\Controllers\Balance::paymentWebhook", [], 'POST'],
            ['/api/balance-prediction', 'BaseModule\Controllers\Balance::prediction', [], 'GET'],
        ];

        $extras = [
            ['/admin/settings/extra-charges', "BaseModule\Controllers\SetupCosts::renderSettings", [], 'GET'],
            ['/admin/settings/extra-charges/delete/:id', "BaseModule\Controllers\SetupCosts::deleteExtra", ['id' => '\d+'], 'GET'],
            ['/admin/settings/extra-charges/edit/:id', "BaseModule\Controllers\SetupCosts::editExtra", ['id' => '\d+'], 'POST'],
            ['/admin/settings/extra-charges/create', "BaseModule\Controllers\SetupCosts::createExtra", [], 'POST'],
            ['/admin/settings/extra-charges/toggle', "BaseModule\Controllers\SetupCosts::toggleStatus", [], "POST"]
        ];

        $mail_admin = [
            ['/admin/email', "BaseModule\Controllers\Admin\Settings::mailEditor", [], 'GET'],
            ['/api/email-content/update', "BaseModule\Controllers\Admin\Settings::saveEmailTemplate", [], 'POST']
        ];

        $test = [
            ['/test', "BaseModule\Controllers\Test::pr", [], "GET"],
            ['/admin/import-database-changes', "BaseModule\Controllers\Admin::importDatabaseChanges", [], 'GET'],
            ['/api/admin/import-database-changes-rest', "BaseModule\Controllers\Admin::importDatabaseChangesApi", [], 'GET']
        ];

        $vouchers = [
            ['/admin/vouchers', "BaseModule\Controllers\Vouchers::adminList", [], 'GET'],
            ['/admin/vouchers/create', "BaseModule\Controllers\Vouchers::adminCreate", [], 'POST'],
            ['/admin/vouchers/delete/:id', "BaseModule\Controllers\Vouchers::adminDelete", ['id' => '\d+'], 'GET'],
            ['/api/vouchers/validate-balance', "BaseModule\Controllers\Vouchers::apiBalanceVoucher", [], 'POST'],
        ];

        $news = [
            ['/news/archive', "BaseModule\Controllers\News::archive", [], 'GET'],
            ['/admin/news', "BaseModule\Controllers\News::adminList", [], 'GET'],
            ['/admin/news', "BaseModule\Controllers\News::add", [], 'POST'],
            ['/api/news/get-latest', "BaseModule\Controllers\News::apiGetLast", [], 'GET'],
            ['/api/news/toggle/:id', "BaseModule\Controllers\News::togglePublic", ['id' => '\d+'], 'GET'],
            ['/api/news/single/:id', "BaseModule\Controllers\News::getSingleNews", ['id' => '\d+'], 'GET'],
            ['/api/news/delete/:id', "BaseModule\Controllers\News::deleteEntry", ['id' => '\d+'], 'GET']
        ];

        $notifications = [
            ['/api/notifications/toggle', "BaseModule\Controllers\Settings::toggleNotificationSetting", [], 'POST'],
            ['/api/notifications/last/:amount', "BaseModule\Controllers\Settings::getLatestNotifications", ['amount' => '\d+'], 'GET'],
            ['/api/notifications/mark-read/:id', "BaseModule\Controllers\Settings::markNotificationAsRead", ['id' => '\d+'], 'GET'],
            ['/api/notifications/mark-unread/:id', "BaseModule\Controllers\Settings::markNotificationAsUnread", ['id' => '\d+'], 'GET']
        ];

        $themes = [
            ['/toggle-dark', "BaseModule\Controllers\Settings::setDarkTheme", [], 'GET']
        ];

        $templateManager = [
            ['/admin/settings/templates', "BaseModule\Controllers\TemplateManager::listAll", [], 'GET'],
            ['/api/admin/disable-template/:vmid', "BaseModule\Controllers\TemplateManager::disableTemplate", ['vmid' => '\d+'], 'GET'],
            ['/api/admin/enable-template/:vmid', "BaseModule\Controllers\TemplateManager::enableTemplate", ['vmid' => '\d+'], 'GET'],
            ['/api/admin/get-templates', "BaseModule\Controllers\TemplateManager::get", [], 'GET'],
            ['/api/admin/load-template-information/:vmid', "BaseModule\Controllers\TemplateManager::getDetailedConfig", ['vmid' => '\d+'], 'GET'],
            ['/api/admin/save-template/:id', "BaseModule\Controllers\TemplateManager::saveTemplate", ['id' => '\d+'], 'POST'],
            ['/api/admin/delete-template/:id', "BaseModule\Controllers\TemplateManager::deleteTemplate", ['id' => '\d+'], 'DELETE'],
            ['/api/templates/save-order', 'BaseModule\Controllers\TemplateManager::saveOrder', [], 'POST']
        ];

        $packages = [
            ['/admin/settings/packages', "BaseModule\Controllers\Packages::adminList", [], 'GET'],
            ['/api/packages/add', "BaseModule\Controllers\Packages::adminAdd", [], "POST"],
            ['/api/packages/delete/:id', 'BaseModule\Controllers\Packages::adminRemove', ['id' => '\d+'], 'GET'],
            ['/api/packages/get', "BaseModule\Controllers\Packages::get", [], "GET"],
            ['/api/package/save', "BaseModule\Controllers\Packages::adminSave", [], 'POST'],
            ['/api/package/save-order', 'BaseModule\Controllers\Packages::saveOrder', [], 'POST']
        ];

        $ssh_keys = [
            ['/ssh-keys', 'BaseModule\Controllers\SSHKeys::list', [], 'GET'],
            ['/ssh-keys', 'BaseModule\Controllers\SSHKeys::create', [], 'POST'],
            ['/api/ssh-keys/calc-fingerprint', 'BaseModule\Controllers\SSHKeys::apiFingerprint', [], 'POST'],
            ['/ssh-keys/delete', 'BaseModule\Controllers\SSHKeys::deleteKey', [], 'POST']
        ];

        $ipam = [
            ['/admin/ipam', 'BaseModule\Controllers\IPAM\IPAM::adminList', [], 'GET'],
            ['/admin/ipam/4/:id', 'BaseModule\Controllers\IPAM\IPAM::singleRange', ['type' => 4, 'id' => '\d+'], 'GET'],
            ['/admin/ipam/6/:id', 'BaseModule\Controllers\IPAM\IPAM::singleRange', ['type' => 6, 'id' => '\d+'], 'GET'],
            ['/api/admin/ipam-create', 'BaseModule\Controllers\IPAM\IPAM::create', [], 'POST'],
            ['/api/admin/ipam/update-mac/:type/:id', 'BaseModule\Controllers\IPAM\IPAM::updateMac', ['type' => '\d+', 'id' => '\d+'], 'POST'],
            ['/api/admin/ipam/delete-ip/:type/:id', 'BaseModule\Controllers\IPAM\IPAM::deleteIP', ['type' => '\d+', 'id' => '\d+'], 'GET'],
            ['/api/admin/ipam/delete-network/:type/:id', 'BaseModule\Controllers\IPAM\IPAM::deleteRange', ['type' => '\d+', 'id' => '\d+'], 'GET'],
        ];

        $ipam_api = [
            ['/api/ipam', 'BaseModule\Controllers\IPAM\IPAM::listApi', [], 'GET'],
        ];

        $invoicing = [
            ['/admin/invoicing/test-connection', 'BaseModule\Controllers\Invoice::testConnection', [], 'GET'],
            ['/admin/invoicing/create-test-invoice', 'BaseModule\Controllers\Invoice::testCreateInvoice', [], 'GET'],
        ];

        $setup = [
            ['/setup', "BaseModule\Controllers\Setup::startOnboarding", [], 'GET']
        ];

        $eventLog = [
            ['/admin/event-log', 'BaseModule\Controllers\EventLog::render', [], 'GET']
        ];

        $events = [
            ['/admin/events', 'BaseModule\Controllers\Events::render', [], 'GET'],
            ['/api/admin/events', 'BaseModule\Controllers\Events::apiGet', [], 'GET'],
            ['/api/admin/events', 'BaseModule\Controllers\Events::apiCreate', [], 'POST'],
            ['/api/admin/events/events', 'BaseModule\Controllers\Events::apiGetEvents', [], 'GET'],
            ['/api/admin/events/enrichers', 'BaseModule\Controllers\Events::apiGetEnrichers', [], 'GET'],
            ['/api/admin/events/actions', 'BaseModule\Controllers\Events::apiGetActions', [], 'GET'],
            ['/api/admin/events/test-action', 'BaseModule\Controllers\Events::apiPostTestAction', [], 'POST'],
            ['/api/admin/events/:id', 'BaseModule\Controllers\Events::apiGetSingle', ['id' => '\d+'], 'GET'],
            ['/api/admin/events/:id', 'BaseModule\Controllers\Events::apiPatch', ['id' => '\d+'], 'PATCH'],
            ['/api/admin/events/:id', 'BaseModule\Controllers\Events::apiDelete', ['id' => '\d+'], 'DELETE'],
        ];

        $tags = [
            ['/api/tags/:resource', 'BaseModule\Controllers\Tags::apiGet', ['resource' => '\w+'], 'GET'],
            ['/api/tags/:resource', 'BaseModule\Controllers\Tags::apiPost', ['resource' => '\w+'], 'POST']
        ];

        $internal = [];
        if ($_SERVER['HTTP_HOST'] == "localhost:8888") {
            $internal = [
                ['/internal/compare-database', 'BaseModule\Controllers\Internal::compareDatabaseSchema', [], 'GET']
            ];
        }

        $data = array_merge(
            $data,
            $api_routes,
            $ticket_api,
            $payment,
            $test,
            $extras,
            $mail_admin,
            $vouchers,
            $news,
            $notifications,
            $themes,
            $templateManager,
            $packages,
            $ssh_keys,
            $ipam,
            $ipam_api,
            $setup,
            $eventLog,
            $invoicing,
            $server,
            $internal,
            $events,
            $tags
        );

        $this->_registerRoutes($data);
    }

    public static function buildRequestDomain() {
        $s   = $_SERVER;
        $ssl = (!empty($s['HTTPS']) && $s['HTTPS'] == 'on') ||
            (isset($s['HTTP_X_FORWARDED_PROTO']) && $s['HTTP_X_FORWARDED_PROTO'] == 'https');

        // Get the server protocol
        $serverProtocol = strtolower($s['SERVER_PROTOCOL']);
        $protocolBase   = substr($serverProtocol, 0, strpos($serverProtocol, '/'));

        // Set the full protocol (http or https)
        $protocol = $ssl ? $protocolBase . 's' : $protocolBase;

        // Determine if we need to include the port in the host
        $port           = $s['SERVER_PORT'];
        $isStandardPort = (!$ssl && $port == '80') || ($ssl && $port == '443');
        $portString     = $isStandardPort ? '' : ':' . $port;

        // Determine the host
        $host = isset($s['HTTP_X_FORWARDED_HOST']) ? $s['HTTP_X_FORWARDED_HOST'] :
            (isset($s['HTTP_HOST']) ? $s['HTTP_HOST'] : null);

        // If no host is set, use SERVER_NAME with port
        if (!isset($host)) {
            $host = $s['SERVER_NAME'] . $portString;
        }
        return $protocol . '://' . $host;
    }

    /**
     * return the logged in user
     *
     * @return \Objects\User|false $user the user
     */
    public static function getUser() {
        if (defined("USER"))
            return unserialize(USER);

        return false;
    }

    /**
     * add to the unauthenticated routes
     *
     * @param string $route
     * @return void
     */
    public static function addUnauthenticatedRoute(string ...$route) {
        array_push(self::$unauthenticatedRoutes, ...$route);
    }
}