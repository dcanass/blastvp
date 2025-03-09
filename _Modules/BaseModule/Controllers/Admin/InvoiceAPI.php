<?php
namespace Module\BaseModule\Controllers\Admin;

use Controllers\MailHelper;
use Controllers\Panel;
use Doctrine\DBAL\ParameterType;
use Module\BaseModule\BaseModule;
use Module\BaseModule\Objects\AccountingProviders\AccountingProviderInterface;
use Objects\Event\EventManager;
use Objects\Formatters;
use Objects\Invoice;
use Objects\User;

class InvoiceAPI {
    public static function getInvoices() {
        $user = BaseModule::getUser();
        if ($user->getPermission() <= 2) {
            return [
                'code' => 401
            ];
        }

        $b    = Panel::getRequestInput();
        $page = $b['page'];
        $size = $b['size'] ?? 10;

        if (isset($b['userId'])) {
            $invoiceCount = Panel::getDatabase()->custom_query("SELECT * FROM invoices WHERE userid=?", ['userid' => $b['userId']])->rowCount();
            return ['data' => array_map(function ($e) {
                return new Invoice($e);
            },
                Panel::getDatabase()->custom_query("SELECT * FROM invoices WHERE userid=? ORDER BY createdAt DESC LIMIT ?, ?", [
                    'userid' => $b['userId'],
                    'skip'   => ($page - 1) * $size,
                    'limit'  => $size,
                ], ['userid' => ParameterType::STRING,
                    'skip'   => ParameterType::INTEGER,
                    'limit'  => ParameterType::INTEGER
                ])->fetchAll()),
                'paging' => [
                    'count'       => $invoiceCount,
                    'pageSize'    => $size,
                    'currentPage' => $page
                ]];
        } else {
            $invoiceCount = Panel::getDatabase()->custom_query("SELECT * FROM invoices", [])->rowCount();
            return ['data' => array_map(function ($e) {
                return [
                    ...(array) new Invoice($e),
                    'username' => $e->username,
                    'email'    => $e->email
                ];
            },
                Panel::getDatabase()->custom_query("SELECT i.*, u.username, u.email FROM invoices i LEFT JOIN users u ON u.id = i.userid ORDER BY i.createdAt DESC LIMIT ?, ?", [
                    'skip'  => ($page - 1) * $size,
                    'limit' => $size,
                ], [
                    'skip'  => ParameterType::INTEGER,
                    'limit' => ParameterType::INTEGER
                ])->fetchAll()),
                'paging' => [
                    'count'       => $invoiceCount,
                    'pageSize'    => $size,
                    'currentPage' => $page
                ]];
        }
    }

    public static function createInvoice() {
        $b    = Panel::getRequestInput();
        $user = BaseModule::getUser();

        if ($user->getPermission() < 2) {
            return [
                'code' => 403
            ];
        }

        ['user' => $forUser, 'usage' => $usage, 'amount' => $amount, 'deduct' => $deduct] = $b;

        $forUser = (new User($forUser))->load();
        $deduct  = filter_var($deduct, FILTER_VALIDATE_BOOL);
        $mail    = Panel::getMailHelper();

        if ($deduct) {
            $forUser->getBalance()->removeBalance($amount);
            $forUser->getBalance()->save();
            EventManager::fire('balance::remove', (array) $forUser);

        }
        // insert invoice
        $forUser->getBalance()->insertInvoice(
            $amount,
            Invoice::PAYMENT,
            $forUser->getId(),
            true,
            $usage
        );

        /**
         * only send these email when there is not accounting provider enabled AND the sending via provider is disabled
         */
        if (!Settings::getConfigEntry('ACCOUNTING_SEND_MAILS', false) && !AccountingProviderInterface::getProvider()) {
            // send email
            $desc = Panel::getLanguage()->get('mail_payment_success', "m_desc");

            $desc = str_replace('{{name}}', $forUser->getName(), $desc);
            $desc = str_replace('{{amount}}', Formatters::formatBalance($amount), $desc);
            $desc = str_replace('{{serverName}}', $usage, $desc);

            $res = Panel::getEngine()->compile(MailHelper::getCurrentMailTemplate(), [
                "m_title" => Panel::getLanguage()->get('mail_payment_success', "m_title"),
                "m_desc"  => $desc,
                "logo"    => Settings::getConfigEntry("LOGO")
            ]);
            $mail->setAddress($forUser->getEmail());
            $mail->setContent(Panel::getLanguage()->get('mail_payment_success', 'm_subject'), $res);
            $mail->send();
            $mail->clear();
        }

        return [
            'code' => 200
        ];
    }
}