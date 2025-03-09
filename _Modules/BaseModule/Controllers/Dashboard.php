<?php

/**
 * Created by PhpStorm.
 * User: bennet
 * Date: 02.11.18
 * Time: 09:53
 */

namespace Module\BaseModule\Controllers;

use Controllers\Panel;
use GuzzleHttp\Client;
use Module\BaseModule\BaseModule;
use Module\BaseModule\Controllers\Admin\Settings;
use Monolog\Handler\StreamHandler;
use Objects\Formatters;
use Objects\Invoice;
use Objects\Ticket;
use VisualAppeal\AutoUpdate;

class Dashboard {

    public static function main() {
        Panel::getRequestInput();
        $user = BaseModule::getUser();

        session_regenerate_id(true);

        $accountSetupDone = $user->getAddress()->load()->isValid();


        $pmReachable         = true;
        $licenseInvalid      = false;
        $updateAvailable     = false;
        $reachingUpdateError = false;
        $next                = '';
        $nextMessage         = Panel::getLanguage()->get('dashboard', 'm_new_version_available');
        if ($user->getPermission() > 2) {
            try {
                $p = Panel::getProxmox();
            } catch (\Exception $e) {
                $pmReachable = false;
            }
            // check for update
            $productId = Panel::getModule('BaseModule')->getMeta()->productId;
            $client    = new Client();
            // license check
            try {
                $result = $client->get("https://bennetgallein.de/api/license-check/" . $productId);
                if (!$result->getBody()) {
                    $licenseInvalid = "Failed to reach https://bennetgallein.de - please check the server connectivity";
                }
                $result = json_decode($result->getBody()->getContents());

                if ($result->error) {
                    $licenseInvalid = str_replace('{{ip}}', $result->ip, Panel::getLanguage()->get('global', 'm_license_invalid'));
                }
            } catch (\Exception $e) {
                $licenseInvalid = "Failed to reach https://bennetgallein.de. Please contact support, error message: " . $e->getMessage();
            }

            try {
                $update = new AutoUpdate(__DIR__ . "/../../../temp", __DIR__ . "/../../", 60);
                $update->setCurrentVersion(Panel::$VERSION);
                $update->setUpdateUrl('https://bennetgallein.de/api/update/' . $productId);
                $update->addLogHandler(new StreamHandler(__DIR__ . '/../../../update.log'));
                $update->checkUpdate();
                if ($update->newVersionAvailable()) {
                    $updateAvailable = true;
                    $next            = $update->getLatestVersion();
                    $nextMessage     = str_replace(['{{version}}'], [$next], $nextMessage);
                }

            } catch (\Exception $e) {
                $reachingUpdateError = "Failed to reach https://bennetgallein.de to check for updates - please check the server connectivity. Error message: " . $e->getMessage();
            }

        }
        $serverDeleted        = isset($_GET['serverDeleted']);
        $serverDeletedMessage = Panel::getLanguage()->get('server', 'm_server_deleted');

        Panel::compile("_views/_pages/index.html", array_merge([
            "pmNotReachable"       => !$pmReachable,
            "serverDeleted"        => $serverDeleted,
            "serverDeletedMessage" => $serverDeletedMessage,
            "accountSetupNeeded"   => !$accountSetupDone,
            "updateAvailable"      => $updateAvailable,
            "nextVersion"          => $next,
            "nextMessage"          => $nextMessage,
            "licenseInvalid"       => $licenseInvalid,
            'reachingUpdateError'  => $reachingUpdateError
        ], Panel::getLanguage()->getPages(['dashboard', 'global'])));
    }

    public static function metaData() {
        header('Content-Type: application/json');

        $user = BaseModule::getUser();

        $servers              = $user->getServers();
        $user->activeProducts += count($servers ?? []);

        $invoice = $user->getBalance()->loadInvoices()->getLastInvoice(Invoice::PAYMENT);

        $tickets     = Ticket::loadAllTickets($user->getId());
        $openTickets = Ticket::filterOpenTickets($tickets);


        // load dashboard-sorting
        $sort       = Settings::getConfigEntry('DASHBOARD_SORT', []);
        $widgetSort = Settings::getConfigEntry('DASHBOARD_WIDGET_SORT', []);

        Panel::compile('_views/api.json', [
            "res" => [
                'activeProducts' => $user->getActiveProducts(),
                'openTickets'    => count($openTickets ?? 0),
                "balance"        => $user->getBalance()->getFormattedBalance(),
                "lastInvoice"    => $invoice ? $invoice->getFormattedAmount() : Formatters::formatBalance(0),
                "sort"           => [
                    "items"   => $sort,
                    "widgets" => $widgetSort
                ]
            ]
        ]);
    }
}
