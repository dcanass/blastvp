<?php

/** @noinspection ALL */

namespace Module\BaseModule\Controllers\Admin;

use Controllers\LanguageLoader;
use Controllers\MailHelper;
use Controllers\Panel;
use Module\BaseModule\Controllers\ClusterHelper;
use Monolog\Handler\StreamHandler;
use Objects\Balance;
use Objects\Constants;
use Objects\Permissions\Roles\AdminRole;
use VisualAppeal\AutoUpdate;
use Composer\Semver\Comparator;
use Controllers\Crontab;
use Exception;
use Module\BaseModule\BaseModule;
use Module\BaseModule\Controllers\Invoice;
use Objects\Formatters;
use RestService\RestService;
use ZipArchive;


/**
 * Created by PhpStorm.
 * User: bennet
 * Date: 03.03.19
 * Time: 15:37
 */

class Settings {

    public static function main() {

        $cronjobs = Crontab::getJobs();
        // check if our job is an entry there.
        // format: cd /var/www/html/shop/proxmox-standard && php cron/cronjob.php > log.txt
        $panelPath         = realpath(__DIR__ . "/../../../../");
        $hasNoCronjobSetup = false;
        if (!isset($cronjobs[0]) || !str_contains($cronjobs[0], "* * * * * cd " . $panelPath . " && php cron/cronjob.php > log.txt")) {
            $hasNoCronjobSetup = true;
        }

        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }

        $hasUpdate = false;
        if (isset($_GET['update']) && $_GET['update'] == 1) {
            $hasUpdate = true;
            $version   = $_GET['version'];
        }

        $pmNotReachable = true;
        try {
            $p = Panel::getProxmox();
        } catch (Exception $e) {
            $pmNotReachable = false;
        }

        $user = BaseModule::getUser();
        if ($user->getPermission() < 3)
            die("Missing permissions!");

        $update = new AutoUpdate(__DIR__ . "/../../../../temp", __DIR__ . "/../../../", 60);
        $update->setCurrentVersion(Panel::$VERSION);
        $update->setUpdateUrl('https://bennetgallein.de/api/update/' . Panel::getModule('BaseModule')->getMeta()->productId);
        try {
            $update->addLogHandler(new StreamHandler(__DIR__ . '/../../../../update.log'));
        } catch (Exception $e) {
            die("Failed to open log: " . $e);
        }

        if ($update->checkUpdate() === false) {
            die('Could not check for updates! See log file for details.');
        }
        $updateAvailable = false;
        if ($update->newVersionAvailable()) {
            $updateAvailable = true;
            $next            = $update->getLatestVersion();
        }

        Panel::compile("_views/_pages/admin/settings.html", array_merge(
            [
                "hasUpdate"                      => $hasUpdate,
                "version"                        => $version ?? "",
                "paymentMethods"                 => Balance::$availablePaymentMethods,
                "mollieMethods"                  => Balance::$mollieMethods,
                "updateAvailable"                => $updateAvailable,
                "updateNext"                     => $next ?? null,
                "hasNoCronjobSetup"              => $hasNoCronjobSetup,
                "app_url"                        => self::getConfigEntry("APP_URL"),
                "db_host"                        => self::getConfigEntry("DB_HOST"),
                "db_user"                        => self::getConfigEntry("DB_USER"),
                "db_password"                    => self::getConfigEntry("DB_PASSWORD"),
                "language"                       => self::getConfigEntry("LANGUAGE", "de"),
                "pp_client"                      => self::getConfigEntry("PP_CLIENT"),
                "pp_secret"                      => self::getConfigEntry("PP_SECRET"),
                "pp_mode"                        => self::getConfigEntry("PP_MODE"),
                "p_host"                         => self::getConfigEntry("P_HOST"),
                "p_user"                         => self::getConfigEntry("P_USER"),
                "p_password"                     => self::getConfigEntry("P_PASSWORD"),
                "p_node"                         => self::getConfigEntry("P_NODE"),
                "p_auth_mode"                    => self::getConfigEntry("P_AUTH_MODE", "user"),
                "p_token_id"                     => self::getConfigEntry("P_TOKEN_ID", ""),
                "p_token_secret"                 => self::getConfigEntry("P_TOKEN_SECRET", ""),
                "logo"                           => self::getConfigEntry("LOGO"),
                "invoice_1"                      => self::getConfigEntry("INVOICE_1"),
                "invoice_2"                      => self::getConfigEntry("INVOICE_2"),
                "invoice_3"                      => self::getConfigEntry("INVOICE_3"),
                "invoice_4"                      => self::getConfigEntry("INVOICE_4"),
                "invoice_show_vat"               => self::getConfigEntry("INVOICE_SHOW_VAT"),
                "invoice_vat"                    => self::getConfigEntry("INVOICE_VAT"),
                "mail_host"                      => self::getConfigEntry("MAIL_HOST"),
                "mail_port"                      => self::getConfigEntry("MAIL_PORT", "25"),
                "mail_encryption"                => self::getConfigEntry("MAIL_ENCRYPTION", "tls"),
                "mail_user"                      => self::getConfigEntry("MAIL_USER"),
                "mail_password"                  => self::getConfigEntry("MAIL_PASSWORD"),
                "mail_from"                      => self::getConfigEntry("MAIL_FROM"),
                "mail_from_name"                 => self::getConfigEntry("MAIL_FROM_NAME"),
                "mail_reply"                     => self::getConfigEntry("MAIL_REPLY"),
                "mail_reply_name"                => self::getConfigEntry("MAIL_REPLY_NAME"),
                "nodes"                          => defined("NODE_LIMIT") ? unserialize(NODE_LIMIT) : new \stdClass(),
                "mac_support"                    => self::getConfigEntry("MAC_SUPPORT", false),
                "p_bridge"                       => self::getConfigEntry("P_BRIDGE", "vmbr0"),
                "p_storage"                      => self::getConfigEntry("P_STORAGE", "local-lvm"),
                "terms"                          => self::getConfigEntry("TERMS_URL"),
                "support"                        => self::getConfigEntry("HELP_URL"),
                "modules"                        => self::getModules(),
                "enabledMethods"                 => defined('ENABLED_PAYMENT_METHODS') ? ENABLED_PAYMENT_METHODS : [],
                "mollieEnabledMethods"           => defined('MOLLIE_ENABLED_PAYMENT_METHODS') ? MOLLIE_ENABLED_PAYMENT_METHODS : [],
                "stripe_client"                  => self::getConfigEntry("STRIPE_CLIENT"),
                "stripe_secret"                  => self::getConfigEntry("STRIPE_SECRET"),
                "coinbase_webhook_secret"        => self::getConfigEntry("COINBASE_WEBHOOK_SECRET"),
                "coinbase_api_key"               => self::getConfigEntry("COINBASE_API_KEY"),
                "paysafecard_api_key"            => self::getConfigEntry("PAYSAFECARD_API_KEY"),
                "gocardless_api_key"             => self::getConfigEntry("GOCARDLESS_API_KEY"),
                "gocardless_webhook"             => self::getConfigEntry("GOCARDLESS_WEBHOOK", ''),
                "duitku_apikey"                  => self::getConfigEntry("DUITKU_APIKEY", ""),
                "duitku_merchant"                => self::getConfigEntry("DUITKU_MERCHANT", ""),
                "stripe_webhook_secret"          => self::getConfigEntry("STRIPE_WEBHOOK_SECRET"),
                "full_clone"                     => self::getConfigEntry("P_FULL_CLONE", false),
                "skip_lock"                      => self::getConfigEntry("P_SKIP_LOCK", false),
                "page_title"                     => self::getConfigEntry("PAGE_TITLE", "ProxmoxCP"),
                "console_host"                   => self::getConfigEntry("CONSOLE_HOST"),
                "console_port"                   => self::getConfigEntry("CONSOLE_PORT", 6379),
                "console_password"               => self::getConfigEntry("CONSOLE_PASSWORD"),
                "mollie_api_key"                 => self::getConfigEntry("MOLLIE_API_KEY"),
                "registrations_active"           => self::getConfigEntry("REGISTRATIONS_ACTIVE", true),
                "pmNotReachable"                 => !$pmNotReachable,
                "available_currencies"           => Invoice::getCurrenciesAvailable(),
                "currency_enabled"               => self::getConfigEntry("CURRENCY", "EUR"),
                "currency_position"              => self::getConfigEntry("CURRENCY_POSITION", "BEHIND"),
                "captchaProvider"                => self::getConfigEntry('CAPTCHA_PROVIDER', '-'),
                "captchaPublic"                  => self::getConfigEntry('CAPTCHA_PUBLIC'),
                "captchaPrivate"                 => self::getConfigEntry('CAPTCHA_PRIVATE'),
                "register_requires_confirmation" => self::getConfigEntry('REGISTER_REQUIRES_CONFIRMATION', false),
                "custom_style"                   => self::getConfigEntry("CUSTOM_STYLE", ""),
                'accounting_provider'            => self::getConfigEntry('ACCOUNTING_PROVIDER', 'none'),
                'accounting_send_mails'          => self::getConfigEntry('ACCOUNTING_SEND_MAILS', false),
                'invoicing_mode'                 => self::getConfigEntry("ACCOUNTING_INVOICING_MODE", "INV_MODE_DELIVERY"),
                'billomat_settings'              => defined('BILLOMAT_SETTINGS') ? (array) unserialize(BILLOMAT_SETTINGS) : [
                    'customer_id'         => '',
                    'api_key'             => '',
                    'app_id'              => '',
                    'app_secret'          => '',
                    'collection_customer' => ''
                ],
                'lexoffice_settings'             => defined('LEXOFFICE_SETTINGS') ? (array) unserialize(LEXOFFICE_SETTINGS) : [
                    'api_key' => '',
                ],
                'sevdesk_settings'               => defined('SEVDESK_SETTINGS') ? (array) unserialize(SEVDESK_SETTINGS) : [
                    'api_key'             => '',
                    'collection_customer' => ''
                ],
                "availableLanguages"             => Panel::getLanguage()->getListOfLanguages(true),
                'enabledLanguages'               => self::getConfigEntry("ENABLED_LANGUAGES", Panel::getLanguage()->getListOfLanguages(true)),
                "profilePictureProviders"        => ['Static', 'Gravatar', 'Robohash', 'Avatar'],
                "profilePictureProvider"         => self::getConfigEntry('PROFILE_PICTURE_PROVIDER', 'Gravatar'),
                "isoStorage"                     => self::getConfigEntry("ISO_STORAGE", ""),
                "auto_install_minor"             => self::getConfigEntry("AUTO_INSTALL_MINOR", false),
                "forceThemeOptions"              => [
                    "dark"  => Panel::getLanguage()->get("admin_settings", "m_force_theme_dark"),
                    "light" => Panel::getLanguage()->get("admin_settings", "m_force_theme_light"),
                    "dont"  => Panel::getLanguage()->get("admin_settings", "m_foce_theme_dont")
                ],
                "forceTheme"                     => self::getConfigEntry("FORCE_THEME", "dont")
            ],
            Panel::getLanguage()->getPages(["admin_settings", "global"]),
        ));
    }


    public static function order() {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }
        Panel::compile("_views/_pages/admin/order.html", array_merge([
            "O_IPAM_BALANCE"           => self::getConfigEntry("O_IPAM_BALANCE", "FILL"),
            "O_IPAM_PRIORITY"          => self::getConfigEntry("O_IPAM_PRIORITY", "GLOBAL"),
            "O_IPAM_DEPLOYMENT"        => self::getConfigEntry("O_IPAM_DEPLOYMENT", "ip4"),
            "O_INTERFACE_SPEED"        => self::getConfigEntry("O_INTERFACE_SPEED"),
            "O_INTERPOLATE_RAM"        => self::getConfigEntry("O_INTERPOLATE_RAM", false),
            "O_CREATION_TIME"          => self::getConfigEntry("O_CREATION_TIME", false),
            "O_CORES_DEFAULT"          => self::getConfigEntry("O_CORES_DEFAULT"),
            "O_CORES_BASE"             => self::getConfigEntry("O_CORES_BASE"),
            "O_CORES_PRICE_EACH_EXTRA" => self::getConfigEntry("O_CORES_PRICE_EACH_EXTRA"),
            "O_CORES_MAX"              => self::getConfigEntry("O_CORES_MAX"),
            "O_RAM_DEFAULT"            => self::getConfigEntry("O_RAM_DEFAULT"),
            "O_RAM_BASE"               => self::getConfigEntry("O_RAM_BASE"),
            "O_RAM_PRICE_EACH_EXTRA"   => self::getConfigEntry("O_RAM_PRICE_EACH_EXTRA"),
            "O_RAM_MAX"                => self::getConfigEntry("O_RAM_MAX"),
            "O_DISK_DEFAULT"           => self::getConfigEntry("O_DISK_DEFAULT"),
            "O_DISK_BASE"              => self::getConfigEntry("O_DISK_BASE"),
            "O_DISK_PRICE_EACH_EXTRA"  => self::getConfigEntry("O_DISK_PRICE_EACH_EXTRA"),
            "O_DISK_MAX"               => self::getConfigEntry("O_DISK_MAX"),
            "O_DELETE_SUSPENDED"       => self::getConfigEntry("O_DELETE_SUSPENDED", 3),
            "O_IPAM_FAIL_NO_USERNET"   => self::getConfigEntry("O_IPAM_FAIL_NO_USERNET", false),
            "O_DISABLE_FREE_ORDER"     => self::getConfigEntry("O_DISABLE_FREE_ORDER", false),
            "snapshot_enabled"         => self::getConfigEntry("SNAPSHOT_ENABLED", false),
            "snapshot_limit"           => self::getConfigEntry("SNAPSHOT_LIMIT", 10),
            "snapshot_retention"       => self::getConfigEntry("SNAPSHOT_RETENTION", 30)
        ], Panel::getLanguage()->getPage('admin_settings_order'), Panel::getLanguage()->getPage('ipam')));
    }

    public static function api_save() {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }
        $app_url     = $_POST['app_url'];
        $db_host     = $_POST['db_host'];
        $db_user     = $_POST['db_user'];
        $db_password = $_POST['db_password'];
        $language    = $_POST['language'];
        $pp_client   = $_POST['pp_client'];
        $pp_secret   = $_POST['pp_secret'];
        $pp_mode     = $_POST['pp_mode'];
        $p_host      = $_POST['p_host'];
        $p_user      = $_POST['p_user'];
        $p_password  = $_POST['p_password'];
        $p_node      = $_POST['p_node'];
        $logo        = $_POST['logo'];

        $stripe_secret         = $_POST['stripe_secret'];
        $stripe_client         = $_POST['stripe_client'];
        $stripe_webhook_secret = $_POST['stripe_webhook_secret'];

        $invoice_1        = $_POST['invoice_1'];
        $invoice_2        = $_POST['invoice_2'];
        $invoice_3        = $_POST['invoice_3'];
        $invoice_4        = $_POST['invoice_4'];
        $invoice_show_vat = (bool) filter_var($_POST['invoice_show_vat'], FILTER_VALIDATE_BOOLEAN);
        $invoice_vat      = (float) $_POST['invoice_vat'];

        $mail_host       = $_POST['mail_host'];
        $mail_port       = $_POST['mail_port'];
        $mail_encryption = $_POST['mail_encryption'];
        $mail_user       = $_POST['mail_user'];
        $mail_password   = $_POST['mail_password'];
        $mail_from       = $_POST['mail_from'];
        $mail_from_name  = $_POST['mail_from_name'];
        $mail_reply      = $_POST['mail_reply'];
        $mail_reply_name = $_POST['mail_reply_name'];

        $mac_support  = (bool) filter_var($_POST['mac_support'], FILTER_VALIDATE_BOOLEAN);
        $p_bridge     = $_POST['p_bridge'];
        $p_storage    = $_POST['p_storage'];
        $p_full_clone = $_POST['p_full_clone'];
        $p_skip_lock  = $_POST['p_skip_lock'];

        $terms                          = $_POST['terms'];
        $help                           = $_POST['support'];
        $page_title                     = $_POST['page_title'];
        $registrations_active           = filter_var($_POST['registrations_active'], FILTER_VALIDATE_BOOLEAN);
        $register_requires_confirmation = filter_var($_POST['register_requires_confirmation'], FILTER_VALIDATE_BOOLEAN);

        $console_host     = $_POST['console_host'];
        $console_port     = $_POST['console_port'];
        $console_password = $_POST['console_password'];

        $mollie_api_key = $_POST['mollie_api_key'];
        $currency       = $_POST['currency'];

        self::writeToConfig("CONSOLE_HOST", $console_host);
        self::writeToConfig("CONSOLE_PORT", $console_port);
        self::writeToConfig("CONSOLE_PASSWORD", $console_password);

        self::writeToConfig("TERMS_URL", $terms);
        self::writeToConfig("HELP_URL", $help);
        self::writeToConfig("PAGE_TITLE", $page_title);
        self::writeToConfig("REGISTRATIONS_ACTIVE", $registrations_active);
        self::writeToConfig("REGISTER_REQUIRES_CONFIRMATION", $register_requires_confirmation);

        self::writeToConfig("APP_URL", $app_url);
        self::writeToConfig("DB_HOST", $db_host);
        self::writeToConfig("DB_USER", $db_user);
        self::writeToConfig("DB_PASSWORD", $db_password);
        self::writeToConfig("LANGUAGE", $language);
        self::writeToConfig("PP_CLIENT", $pp_client);
        self::writeToConfig("PP_SECRET", $pp_secret);

        self::writeToConfig("PP_MODE", $pp_mode == "sandbox" ? Constants::PAYPAL_MODES["sandbox"]["frontend"] : Constants::PAYPAL_MODES["production"]["frontend"]);
        self::writeToConfig("PP_MODE_BACKEND", $pp_mode == "sandbox" ? Constants::PAYPAL_MODES["sandbox"]["backend"] : Constants::PAYPAL_MODES["production"]["backend"]);

        self::writeToConfig("STRIPE_CLIENT", $stripe_client);
        self::writeToConfig("STRIPE_SECRET", $stripe_secret);
        self::writeToConfig("STRIPE_WEBHOOK_SECRET", $stripe_webhook_secret);

        self::writeToConfig("P_HOST", $p_host);
        self::writeToConfig("P_AUTH_MODE", $_POST['p_auth_mode']);
        self::writeToConfig("P_USER", $p_user);
        self::writeToConfig("P_PASSWORD", $p_password);
        self::writeToConfig("P_TOKEN_ID", $_POST['p_token_id']);
        self::writeToConfig("P_TOKEN_SECRET", $_POST['p_token_secret']);
        self::writeToConfig("P_NODE", $p_node);
        self::writeToConfig("P_FULL_CLONE", (bool) filter_var($p_full_clone, FILTER_VALIDATE_BOOLEAN));
        self::writeToConfig("P_SKIP_LOCK", (bool) filter_var($p_skip_lock, FILTER_VALIDATE_BOOLEAN));

        self::writeToConfig("LOGO", $logo);

        self::writeToConfig("INVOICE_1", $invoice_1);
        self::writeToConfig("INVOICE_2", $invoice_2);
        self::writeToConfig("INVOICE_3", $invoice_3);
        self::writeToConfig("INVOICE_4", $invoice_4);
        self::writeToConfig("INVOICE_SHOW_VAT", $invoice_show_vat);
        self::writeToConfig("INVOICE_VAT", $invoice_vat);

        self::writeToConfig("MAIL_HOST", $mail_host);
        self::writeToConfig("MAIL_PORT", $mail_port);
        self::writeToConfig("MAIL_ENCRYPTION", $mail_encryption);
        self::writeToConfig("MAIL_USER", $mail_user);
        self::writeToConfig("MAIL_PASSWORD", $mail_password);
        self::writeToConfig("MAIL_FROM", $mail_from);
        self::writeToConfig("MAIL_FROM_NAME", $mail_from_name);
        self::writeToConfig("MAIL_REPLY", $mail_reply);
        self::writeToConfig("MAIL_REPLY_NAME", $mail_reply_name);

        self::writeToConfig("MAC_SUPPORT", $mac_support);
        self::writeToConfig("P_BRIDGE", $p_bridge);
        self::writeToConfig("P_STORAGE", $p_storage);

        self::writeToConfig("MOLLIE_API_KEY", $mollie_api_key);
        self::writeToConfig("CURRENCY", $currency);
        self::writeToConfig("CURRENCY_POSITION", $_POST['currency_position']);

        self::writeToConfig('CAPTCHA_PROVIDER', $_POST['captchaProvider']);
        self::writeToConfig('CAPTCHA_PUBLIC', $_POST['captchaPublic']);
        self::writeToConfig('CAPTCHA_PRIVATE', $_POST['captchaPrivate']);

        self::writeToConfig("CUSTOM_STYLE", $_POST['custom_style']);

        // accounting system settings
        self::writeToConfig('ACCOUNTING_PROVIDER', $_POST['accounting_provider']);
        self::writeToConfig('ACCOUNTING_SEND_MAILS', filter_var($_POST['accounting_send_mails'], FILTER_VALIDATE_BOOLEAN));
        self::writeToConfig("ACCOUNTING_INVOICING_MODE", $_POST['invoicing_mode']);

        self::writeToConfig("COINBASE_API_KEY", $_POST['coinbase_api_key']);
        self::writeToConfig("COINBASE_WEBHOOK_SECRET", $_POST['coinbase_webhook_secret']);


        self::writeToConfig("PAYSAFECARD_API_KEY", $_POST['paysafecard_api_key']);
        self::writeToConfig("GOCARDLESS_API_KEY", $_POST['gocardless_api_key']);
        self::writeToConfig("GOCARDLESS_WEBHOOK", $_POST['gocardless_webhook']);

        self::writeToConfig("DUITKU_MERCHANT", $_POST['duitku_merchant']);
        self::writeToConfig("DUITKU_APIKEY", $_POST['duitku_apikey']);

        // billomat
        self::writeToConfig('BILLOMAT_SETTINGS', [
            'customer_id'         => $_POST['billomat_customer_id'],
            'api_key'             => $_POST['billomat_api_key'],
            'app_id'              => $_POST['billomat_app_id'],
            'app_secret'          => $_POST['billomat_app_secret'],
            'collection_customer' => $_POST['billomat_collection_customer']
        ]);

        // lexoffice
        self::writeToConfig('LEXOFFICE_SETTINGS', [
            'api_key' => $_POST['lexoffice_api_key']
        ]);

        // sevdesk
        self::writeToConfig("SEVDESK_SETTINGS", [
            'api_key'             => $_POST['sevdesk_api_key'],
            'collection_customer' => $_POST['sevdesk_collection_customer']
        ]);

        self::writeToConfig("PROFILE_PICTURE_PROVIDER", $_POST['profile_picture_provider']);
        self::writeToConfig("ISO_STORAGE", $_POST['isoStorage']);
        self::writeToConfig("AUTO_INSTALL_MINOR", filter_var($_POST['auto_install_minor'], FILTER_VALIDATE_BOOLEAN));
        self::writeToConfig("FORCE_THEME", $_POST['force_theme']);

        die();
    }

    public static function api_cores() {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }

        self::writeToConfig("O_CORES_DEFAULT", $_POST['o_cores_default']);
        self::writeToConfig("O_CORES_BASE", $_POST['o_cores_base']);
        self::writeToConfig("O_CORES_PRICE_EACH_EXTRA", $_POST['o_cores_price_each_extra']);
        self::writeToConfig("O_CORES_MAX", $_POST['o_cores_max']);

        return [];
    }

    public static function api_ram() {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }

        self::writeToConfig("O_RAM_DEFAULT", $_POST['o_ram_default']);
        self::writeToConfig("O_RAM_BASE", $_POST['o_ram_base']);
        self::writeToConfig("O_RAM_PRICE_EACH_EXTRA", $_POST['o_ram_price_each_extra']);
        self::writeToConfig("O_RAM_MAX", $_POST['o_ram_max']);

        return [];
    }

    public static function api_disk() {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }

        self::writeToConfig("O_DISK_DEFAULT", $_POST['o_disk_default']);
        self::writeToConfig("O_DISK_BASE", $_POST['o_disk_base']);
        self::writeToConfig("O_DISK_PRICE_EACH_EXTRA", $_POST['o_disk_price_each_extra']);
        self::writeToConfig("O_DISK_MAX", $_POST['o_disk_max']);

        return [];
    }

    public static function api_misc() {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }

        self::writeToConfig('O_INTERFACE_SPEED', $_POST['o_interface_speed']);
        self::writeToConfig('O_INTERPOLATE_RAM', (bool) filter_var($_POST['o_interpolate_ram'], FILTER_VALIDATE_BOOLEAN));
        self::writeToConfig('O_CREATION_TIME', (bool) filter_var($_POST['o_creation_time'], FILTER_VALIDATE_BOOLEAN));
        self::writeToConfig('O_IPAM_BALANCE', $_POST['o_ipam_balance']);
        self::writeToConfig('O_IPAM_PRIORITY', $_POST['o_ipam_priority']);
        self::writeToConfig('O_IPAM_DEPLOYMENT', $_POST['o_ipam_deployment']);
        self::writeToConfig('O_DELETE_SUSPENDED', (int) $_POST['o_delete_suspended']);
        self::writeToConfig("O_IPAM_FAIL_NO_USERNET", (bool) filter_var($_POST['o_ipam_fail_no_usernet'], FILTER_VALIDATE_BOOLEAN));
        self::writeToConfig("O_DISABLE_FREE_ORDER", (bool) filter_var($_POST['o_disable_free_order'], FILTER_VALIDATE_BOOLEAN));

        self::writeToConfig("SNAPSHOT_ENABLED", (bool) filter_var($_POST['snapshot_enabled'], FILTER_VALIDATE_BOOLEAN));
        self::writeToConfig("SNAPSHOT_LIMIT", $_POST['snapshot_limit']);
        self::writeToConfig("SNAPSHOT_RETENTION", $_POST['snapshot_retention']);

        return [];
    }

    public static function writeToConfig(string $key, $value): void {
        $config       = json_decode(file_get_contents(__DIR__ . "/../../../../config.json"));
        $config->$key = $value;

        $suc = file_put_contents(__DIR__ . "/../../../../config.json", json_encode($config, JSON_PRETTY_PRINT));
        if (!$suc)
            throw new Exception("Could not write to config file. Maybe permissions are broken?");
    }

    public static function hostOverview() {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }

        // check if the server is in a cluster - if not, redirect to the host overview directly
        $res     = Panel::getProxmox()->get('/cluster/status');
        $cluster = array_filter($res['data'], function ($element) {
            return ($element['type'] == 'cluster');
        });
        if (sizeof($cluster) == 0) {
            self::singleHostOverview(Settings::getConfigEntry('P_NODE'));
        } else {
            Panel::compile('_views/_pages/admin/cluster.html', array_merge([
                'node' => self::getConfigEntry("P_NODE")
            ], Panel::getLanguage()->getPages(['global', 'admin_cluster'])));
        }
    }

    public static function singleHostOverview($id) {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }

        Panel::compile('_views/_pages/admin/host.html', array_merge([
            'node' => $id,
        ], Panel::getLanguage()->getPages(['global', 'admin_host'])));
    }

    public static function hostOverviewGraphs() {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }
        $p = Panel::getProxmox();

        header('Content-Type: application/json');
        die(json_encode($p->get('/cluster/resources')));
    }

    public static function hostStatus() {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }
        $p = Panel::getProxmox();

        header('Content-Type: application/json');
        $res  = $p->get('/cluster/status');
        $load = ClusterHelper::getLoad(true);

        foreach ($res['data'] as $k => &$server) {
            $a = array_filter($load, function ($node) use ($server) {
                return ($node['node'] == $server['name']);
            });
            if (!$a) {
                // unset($res['data'][$k]);
            } else {
                $server['stats'] = array_values($a)[0];
            }
        }

        die(json_encode(($res)));
    }

    public static function hostGraph($node) {
        header('Content-Type: application/json');
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }
        $p = Panel::getProxmox();

        // load node rrd 
        $nodeInfo    = $p->get('/nodes/' . $node . '/rrddata', ['timeframe' => 'hour', 'cf' => 'AVERAGE']);
        $storageInfo = $p->get('/nodes/' . $node . '/storage/' . Settings::getConfigEntry('P_STORAGE', 'local') . '/rrddata', ['timeframe' => 'hour', 'cf' => 'AVERAGE']);

        // merge storageInfo with nodeInfo
        foreach ($nodeInfo['data'] as &$metric) {
            $time                     = $metric['time'];
            $relatedMetric            = array_search($time, array_column($storageInfo['data'], 'time'));
            $metric['storage']        = Settings::getConfigEntry('P_STORAGE', 'local');
            $metric['storageMetrics'] = $storageInfo['data'][$relatedMetric];
        }


        die(json_encode($nodeInfo));
    }

    public static function language() {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }

        $lang = Panel::getLanguage()->getRaw();
        foreach ($lang as $k => $v) {
            foreach ($v as $kk => $vv) {
                if (is_array($vv)) {
                    $lang[$k][$kk] = [
                        't'    => '',
                        'type' => 'disabled',
                    ];
                    foreach ($vv as $kkk => $vvv) {
                        $lang[$k][$kk . "--" . $kkk] = [
                            't'    => $vvv,
                            'type' => strlen($vvv) > 60 ? 'textarea' : 'input',
                            'rows' => ceil(strlen($vvv) / 60)
                        ];
                    }
                } else {
                    $lang[$k][$kk] = [
                        't'    => $vv,
                        'type' => strlen($vv) > 60 ? 'textarea' : 'input',
                        'rows' => ceil(strlen($vv) / 60)
                    ];
                }
            }
        }

        Panel::compile('_views/_pages/admin/language.html', array_merge([
            'raw' => $lang
        ], Panel::getLanguage()->getPage('admin_language')));
    }

    public static function languageSave() {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }
        $res = [];
        foreach ($_POST as $entry => $vvalue) {
            $e     = explode('--', $entry);
            $key   = $e[0];
            $value = $e[1];
            if (isset($e[2])) {
                // we have a third level here
                if (Panel::getLanguage()->getOriginal($key, $value)[$e[2]] != $vvalue) {
                    $res[$key][$value][$e[2]] = $vvalue;
                }
            } else {
                if (Panel::getLanguage()->getOriginal($key, $value) != $vvalue) {
                    $res[$key][$value] = $vvalue;
                }
            }
        }
        file_put_contents(__DIR__ . '/../../../../_languages/' . Panel::getLanguage()->getCurrentLanguage(true) . '/overwrite.json', json_encode($res, JSON_PRETTY_PRINT));
        return $res;
    }

    public static function addNewNodeLimit() {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }
        $res = [];

        $nodeLimit = defined("NODE_LIMIT") ? unserialize(NODE_LIMIT) : new \stdClass();

        $nodeLimit->{$_POST['node']} = $_POST['limit'];

        self::writeToConfig("NODE_LIMIT", (array) $nodeLimit);
        return $res;
    }

    public static function api_addPayment($method) {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }
        $methods = defined('ENABLED_PAYMENT_METHODS') ? ENABLED_PAYMENT_METHODS : [];
        if (array_search($method, $methods) !== false) {
            return ['error' => true, 'msg' => 'Bereits aktiviert'];
        } else {
            $methods[] = $method;
            self::writeToConfig("ENABLED_PAYMENT_METHODS", array_values($methods));
            return ['error' => false, 'msg' => "Aktiviert"];
        }
    }

    public static function api_removePayment($method) {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }

        $methods = defined('ENABLED_PAYMENT_METHODS') ? ENABLED_PAYMENT_METHODS : [];
        if (array_search($method, $methods) === false) {
            return ['error' => true, 'msg' => 'Bereits deaktiviert'];
        } else {
            unset($methods[array_search($method, $methods)]);
            self::writeToConfig("ENABLED_PAYMENT_METHODS", array_values($methods));
            return ['error' => false, 'msg' => "Deaktiviert"];
        }
    }

    public static function api_addLanguage($language) {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }
        $languages = defined('ENABLED_LANGUAGES') ? ENABLED_LANGUAGES : Panel::getLanguage()->getListOfLanguages(true);
        if (array_search($language, $languages) !== false) {
            return ['error' => true, 'msg' => 'Bereits aktiviert'];
        } else {
            $languages[] = $language;
            self::writeToConfig("ENABLED_LANGUAGES", array_values($languages));
            return ['error' => false, 'msg' => "Aktiviert"];
        }
    }

    public static function api_removeLanguage($language) {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }

        $languages = defined('ENABLED_LANGUAGES') ? ENABLED_LANGUAGES : Panel::getLanguage()->getListOfLanguages(true);
        if (array_search($language, $languages) === false) {
            return ['error' => true, 'msg' => 'Bereits deaktiviert'];
        } else {
            unset($languages[array_search($language, $languages)]);
            self::writeToConfig("ENABLED_LANGUAGES", array_values($languages));
            return ['error' => false, 'msg' => "Deaktiviert"];
        }
    }

    public static function api_addMethodToMethod($method, $paymentMethod) {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }

        $methods = defined(strtoupper($method) . '_ENABLED_PAYMENT_METHODS') ? constant(strtoupper($method) . "_ENABLED_PAYMENT_METHODS") : [];
        if (array_search($paymentMethod, $methods) !== false) {
            return ['error' => true, 'msg' => 'Bereits aktiviert'];
        } else {
            $methods[] = $paymentMethod;
            self::writeToConfig(strtoupper($method) . "_ENABLED_PAYMENT_METHODS", array_values($methods));
            return ['error' => false, 'msg' => "Deaktiviert"];
        }
    }

    public static function api_removeMethodFromMethod($method, $paymentMethod) {
        $user = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }

        $methods = defined(strtoupper($method) . '_ENABLED_PAYMENT_METHODS') ? constant(strtoupper($method) . "_ENABLED_PAYMENT_METHODS") : [];
        if (array_search($paymentMethod, $methods) === false) {
            return ['error' => true, 'msg' => 'Bereits deaktiviert'];
        } else {
            unset($methods[array_search($paymentMethod, $methods)]);
            self::writeToConfig(strtoupper($method) . '_ENABLED_PAYMENT_METHODS', array_values($methods));
            return ['error' => false, 'msg' => "Deaktiviert"];
        }
    }

    public static function getModules() {
        $mods = defined('MODULES') ? unserialize(MODULES) : new \stdClass();
        foreach (glob(__DIR__ . '/../../../*[!.php]') as $file) {
            $modname = basename($file);
            if ($modname == "BaseModule")
                continue;
            if (!isset($mods->{$modname})) {
                $mods->{$modname} = false;
            }
        }

        $final = [];
        // read module_meta to get product id for each module
        foreach ($mods as $module => $enabled) {
            $meta    = json_decode(file_get_contents(__DIR__ . "/../../../$module/module_meta.json"), true);
            $final[] = [
                'id'      => $meta['productId'],
                'name'    => $meta['name'],
                'version' => $meta['version'],
                'enabled' => $enabled
            ];
        }

        return $final;
    }

    public static function toggleModule($module) {
        $user = BaseModule::getUser();
        // only admins can enable modules
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }
        $isActivation = false;
        $mods         = defined('MODULES') ? unserialize(MODULES) : new \stdClass();
        if (isset($mods->{$module})) {
            $isActivation    = !$mods->{$module};
            $mods->{$module} = !$mods->{$module};
        } else {
            $mods->{$module} = true;
        }


        // check if mod is being activated. If it is, run the checks to see if it needs something from installation.
        if ($isActivation === true) {
            // the module is being activated
            // check if the module has a module_meta file and do the checks to see if it is installed.
            $meta = @file_get_contents("_Modules/$module/module_meta.json");
            if ($meta) {
                // check if has attr needsInstallation
                $meta = json_decode($meta);
                if ($meta->needsInstallation) {
                    // need it. run check. if check returns false, execute the installation function
                    $res = call_user_func($meta->checkInstallation);
                    //die(json_encode(['isInstalled' => $res]));
                    if (!$res) {
                        // needs installation, function returned true!
                        $res = call_user_func($meta->installInstallation);
                    }
                }
            }
        }

        self::writeToConfig("MODULES", $mods);
        return ['result' => $isActivation];
    }

    public static function mailEditor() {
        Panel::compile('_views/_pages/admin/mail-editor.html', array_merge([
            'content' => file_get_contents(MailHelper::getCurrentMailTemplate())
        ], Panel::getLanguage()->getPage('admin_mail')));
    }

    public static function saveEmailTemplate() {
        $content = $_POST['html'];
        $user    = BaseModule::getUser();
        if ($user->getRole() != AdminRole::class) {
            return ['code' => 403];
        }

        // check if the parameters are in there
        if (!strpos($content, '{ :m_desc }')) {
            return ['error' => true, 'message' => "Der Parameter m_desc ist nicht enthalten."];
        }
        if (!strpos($content, "{ :m_title }")) {
            return ['error' => true, 'message' => "Der Parameter m_title ist nicht enthalten."];
        }


        $saveToFile = file_put_contents('mails/mail_overwrite.php', $content);
        if ($saveToFile === false) {
            return ['error' => true, 'message' => Panel::getLanguage()->get('admin_mail', 'm_save_err')];
        } else {
            return ['error' => false, 'message' => Panel::getLanguage()->get('admin_mail', 'm_save_suc')];
        }
    }

    public static function saveDashboardOrder() {
        $user = BaseModule::getUser();
        $b    = Panel::getRequestInput();

        if ($user->getRole() !== AdminRole::class) {
            return [
                'code' => 403
            ];
        }

        $order        = $b['order'];
        $orderWidgets = $b['orderWidgets'];

        self::writeToConfig('DASHBOARD_SORT', $order);
        self::writeToConfig('DASHBOARD_WIDGET_SORT', $orderWidgets);

        return [
            'code' => 200
        ];

    }

    public static function getModuleUpdates() {
        $modules     = Panel::getModules();
        $mods        = [];
        $restService = new RestService();
        foreach ($modules as $mod) {
            $modName = $mod->getMeta()->name;
            if ($modName != "BaseModule") {
                // check for update if productId is set
                $updateAvailable = false;
                if (isset($mod->getMeta()->productId)) {
                    try {
                        $response        = $restService->setEndpoint('https://bennetgallein.de/api')
                            ->get('/update/' . $mod->getMeta()->productId);
                        $response        = array_keys((array) $response);
                        $updateAvailable = Comparator::greaterThan(end($response), $mod->getMeta()->version);
                    } catch (Exception $e) {
                        $response = [null];
                    }
                    $mods[] = ["name" => $modName, "newest" => end($response), "updateAvailable" => $updateAvailable];
                } else {
                    $mods[] = ["name" => $modName, "newest" => null, "updateAvailable" => $updateAvailable];
                }
            }
        }

        return $mods;
    }

    public static function getModuleChangelog($module) {
        $mod         = json_decode(file_get_contents("_Modules/$module/module_meta.json"));
        $restService = new RestService();
        $response    = $restService->setEndpoint('https://bennetgallein.de/api')
            ->get('/changelog/' . $mod->productId);

        return [
            'changelog'   => $response[0]->content,
            'releaseDate' => Formatters::formatDateAbsolute($response[0]->datum),
            'version'     => $response[0]->version
        ];
    }

    public static function updateModule($module) {
        $mod         = json_decode(file_get_contents(__DIR__ . "/../../../../_Modules/$module/module_meta.json"));
        $restService = new RestService();

        $response = $restService->setEndpoint('https://bennetgallein.de/api')
            ->get('/update/' . $mod->productId);
        $response = array_values((array) $response);
        $response = $response[sizeof($response) - 1];
        $module   = file_get_contents($response);

        if (!$module) {
            return ['error' => true, 'msg' => "Unreachable"];
        }
        $random      = bin2hex(random_bytes(5));
        $writeToTemp = file_put_contents("temp/module-installation/$random", $module);
        if (!$writeToTemp) {
            unlink("temp/module-installation/$random");
            return ['error' => true, 'msg' => "Download failed! Check permissions on the temp-folder."];
        }

        $zip  = new ZipArchive();
        $open = $zip->open("temp/module-installation/$random");
        if (!$open) {
            return ['error' => true, 'msg' => "Opening downloaded file failed."];
        }
        $unzip = $zip->extractTo('_Modules/');
        $zip->close();
        if (!$unzip) {
            return ['error' => true, 'msg' => "File could not be unzipped. Check permissions."];
        }
        unlink("temp/module-installation/$random");

        return [
            'error' => false,
            'msg'   => 'Module updated.',
        ];
    }

    public static function installCronjob() {
        $user = BaseModule::getUser();
        // only admins should be able to do this
        if ($user->getPermission() != 3) {
            return [
                'error' => true,
                'msg'   => Panel::getLanguage()->get('admin_servers', "m_import_no_perm")
            ];
        }
        $panelPath = realpath(__DIR__ . "/../../../../");
        $job       = "* * * * * cd " . $panelPath . " && php cron/cronjob.php > log.txt";
        $result    = Crontab::addJob($job);

        return [
            'error' => $result,
            'msg'   => Panel::getLanguage()->get('admin_settings', $result == null ? 'm_no_crontab_no_error' : 'm_no_crontab_unknown_error')
        ];
    }

    public static function getConfigEntry($name, $fallback = "", $shouldFail = false) {
        if (defined($name)) {
            return constant($name);
        }
        if (!$shouldFail)
            return $fallback;
        die("Failed to read configuration parameter \"$name\" but required.");
    }
}