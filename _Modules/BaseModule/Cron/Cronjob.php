<?php

namespace Module\BaseModule\Cron;

use Controllers\MailHelper;
use Controllers\Panel;
use DateInterval;
use DateTime;
use Module\BaseModule\Controllers\Admin\Settings;
use Module\BaseModule\Controllers\IPAM\IPAMHelper;
use Module\BaseModule\Controllers\Order;
use Module\BaseModule\Objects\AccountingProviders\AccountingProviderInterface;
use Module\BaseModule\Objects\ServerStatus;
use Objects\Event\EventManager;
use Objects\Formatters;
use Objects\Invoice as ObjectsInvoice;
use Objects\Server;
use Objects\User;

class Cronjob {
    public static function __execute() {
        // check for servers that are not deleted and will be deleted within the next 2 days
        self::doPaymentReminders();
        self::doInvoices();
        NotificationDispatcher::__execute();
        InvoiceImport::__execute();
        DetectServerMigrations::execute();
        InstallUpdate::execute();
        SnapshotDispatcher::execute();
    }

    private static function doInvoices() {
        $servers = self::searchInvoiceables();

        $mail = Panel::getMailHelper();
        $p    = Panel::getProxmox();

        foreach ($servers as $server) {
            $user   = (new User($server->userid))->load();
            $server = new Server($server);


            $email = $user->getEmail();
            $name  = $user->getName();

            // check if the user has enough balance
            if ($user->getBalance()->getBalance() >= $server->price && $server->cancelledAt === null && $server->status !== ServerStatus::$SUSPENDED) {
                // he has
                $user->getBalance()->removeBalance($server->price);
                $user->getBalance()->save();
                EventManager::fire('balance::remove', (array) $user);
                // update nextPayment in DB
                Panel::getDatabase()->custom_query("UPDATE servers SET nextPayment = nextPayment + INTERVAL 30 DAY WHERE id=?", ['id' => $server->id]);

                // insert invoice
                $user->getBalance()->insertInvoice(
                    $server->price,
                    ObjectsInvoice::PAYMENT,
                    $user->getId(),
                    true,
                    "Server: " . $server->hostname
                );

                /**
                 * only send these email when there is not accounting provider enabled AND the sending via provider is disabled
                 */
                if (!Settings::getConfigEntry('ACCOUNTING_SEND_MAILS', false) && !AccountingProviderInterface::getProvider() && $server->price > 0) {
                    // send email
                    $desc = Panel::getLanguage()->get('mail_payment_success', "m_desc");

                    $desc = str_replace('{{name}}', $name, $desc);
                    $desc = str_replace('{{amount}}', Formatters::formatBalance($server->price), $desc);
                    $desc = str_replace('{{serverName}}', $server->hostname, $desc);

                    $res = Panel::getEngine()->compile(MailHelper::getCurrentMailTemplate(), [
                        "m_title" => Panel::getLanguage()->get('mail_payment_success', "m_title"),
                        "m_desc"  => $desc,
                        "logo"    => Settings::getConfigEntry("LOGO")
                    ]);
                    $mail->setAddress($email);
                    $mail->setContent(Panel::getLanguage()->get('mail_payment_success', 'm_subject'), $res);
                    $mail->send();
                    $mail->clear();
                }

                EventManager::fire("server::extend", (array) $server);
                echo ("Extended Server: " . $server->id) . PHP_EOL;
            } else {
                if ($server->status === ServerStatus::$SUSPENDED || $server->cancelledAt !== null) {
                    // here we need to check if the nextPayment date is X days in the past, X representing the tolerance set in O_DELETE_SUSPENDED
                    // server->nextPayment + O_DELETE_SUSPENDED < now() => delete
                    $date     = new DateTime($server->nextPayment);
                    $interval = DateInterval::createFromDateString(Settings::getConfigEntry('O_DELETE_SUSPENDED', 3) . ' days');
                    $date->add($interval);
                    $now = new DateTime();

                    if ($date->getTimestamp() < $now->getTimestamp() || $server->cancelledAt !== null) {
                        $res = $p->create('/nodes/' . $server->node . '/qemu/' . $server->vmid . '/status/stop');
                        // delete server
                        $res = $p->delete('/nodes/' . $server->node . '/qemu/' . $server->vmid . '?purge=1');

                        Panel::getDatabase()->custom_query("UPDATE servers SET deletedAt=NOW() WHERE id=?", ['id' => $server->id]);

                        Panel::executeIfModuleIsInstalled('NetworkModule', 'Module\NetworkModule\Controllers\PublicController::__serverDeletion', [$server->id, false]);

                        // set ip free
                        if (isset($server->ip)) {
                            IPAMHelper::setIPStatus(4, $server->_ip->id, IPAMHelper::IP_UNUSED);
                        }
                        if (isset($server->ip6)) {
                            IPAMHelper::setIPStatus(6, $server->_ip6->id, IPAMHelper::IP_UNUSED);
                        }
                        echo ("Deleted Server: " . $server->id) . PHP_EOL;
                    }
                    continue;
                }

                // kill server
                $res = $p->create('/nodes/' . $server->node . '/qemu/' . $server->vmid . '/status/stop');

                // suspend server
                $update = Panel::getDatabase()->update('servers', ['status' => ServerStatus::$SUSPENDED], 'id', $server->id);

                // send notification email
                // send email
                $desc = Panel::getLanguage()->get('mail_payment_failed', "m_desc");

                $desc = str_replace('{{name}}', $name, $desc);
                $desc = str_replace('{{serverName}}', $server->hostname, $desc);

                $res = Panel::getEngine()->compile(MailHelper::getCurrentMailTemplate(), [
                    "m_title" => Panel::getLanguage()->get('mail_payment_failed', "m_title"),
                    "m_desc"  => $desc,
                    "logo"    => Settings::getConfigEntry("LOGO")
                ]);
                $mail->setAddress($email);
                $mail->setContent(Panel::getLanguage()->get('mail_payment_failed', 'm_subject'), $res);
                $mail->send();
                $mail->clear();

                echo ("Suspended Server: " . $server->id) . PHP_EOL;
            }
        }
    }

    private static function doPaymentReminders() {
        $servers = self::searchPaymentReminders();

        $mail = Panel::getMailHelper();

        foreach ($servers as $server) {
            // load user to server
            $user   = (new User($server->userid))->load();
            $server = new Server($server);

            $email           = $user->getEmail();
            $name            = $user->getName();
            $nextInvoiceDate = Formatters::formatDateAbsolute($server->nextPayment);

            $desc = Panel::getLanguage()->get('mail_payment_reminder', "m_desc");

            $desc = str_replace('{{name}}', $name, $desc);
            $desc = str_replace('{{nextInvoiceDate}}', $nextInvoiceDate, $desc);
            $desc = str_replace('{{amount}}', $server->priceFormatted, $desc);
            $desc = str_replace('{{serverName}}', $server->hostname, $desc);

            $res = Panel::getEngine()->compile(MailHelper::getCurrentMailTemplate(), [
                "m_title" => Panel::getLanguage()->get('mail_payment_reminder', "m_title"),
                "m_desc"  => $desc,
                "logo"    => Settings::getConfigEntry("LOGO")
            ]);
            $mail->setAddress($email);
            $mail->setContent(Panel::getLanguage()->get('mail_payment_reminder', 'm_subject'), $res);
            if ($server->price > 0) {
                $mail->send();
                $mail->clear();
            }
            // update paymentReminderSent in database
            Panel::getDatabase()->custom_query("
                UPDATE servers SET paymentReminderSent = CURRENT_TIMESTAMP WHERE id=?
            ", ['id' => $server->id]);

            echo "Payment Reminder sent: " . $server->id . PHP_EOL;
        }
    }

    /**
     *self::unction searches all servers that will be invoiced in the next 2 Days
     * and where a paymentReminder has not been send within the last day or not at all.
     *self::ould mean that at least one payment reminder is sent for each server.
     *
     * @return array
     */
    private static function searchPaymentReminders() {
        $db    = Panel::getDatabase();
        $query = <<<SQL
            SELECT 
                * 
            FROM 
                servers
            WHERE 
                deletedAt IS NULL
            AND 
                cancelledAt IS NULL
            AND
                nextPayment <= CURRENT_TIMESTAMP + INTERVAL 2 DAY
            AND
                (
                    paymentReminderSent IS NULL
                OR
                    paymentReminderSent <= CURRENT_TIMESTAMP - INTERVAL 1 DAY
                ) 

        SQL;
        return $db->custom_query($query)->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     *self::unction returns all servers where the nextPayment date is in the
     * past, thus the server needs to be paid
     *
     * @return array
     */
    public static function searchInvoiceables() {
        $db    = Panel::getDatabase();
        $query = <<<SQL

            SELECT 
                *
            FROM 
                servers
            WHERE 
                deletedAt IS NULL
            AND
                nextPayment <= CURRENT_TIMESTAMP
        SQL;
        return $db->custom_query($query)->fetchAll(\PDO::FETCH_OBJ);
    }
}
