<?php

/**
 * Created by PhpStorm.
 * User: bennet
 * Date: 27.02.19
 * Time: 17:19
 */

namespace Module\BaseModule\Controllers;

use Controllers\MailHelper;
use Controllers\Panel;
use Module\BaseModule\BaseModule;
use Objects\Event\EventManager;
use Objects\Formatters;
use Objects\Notification;
use RobThree\Auth\Algorithm;
use RobThree\Auth\Providers\Qr\EndroidQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;

class Settings {

    public static function render() {
        $user = BaseModule::getUser();
        // load notification settings
        $_settings = Panel::getDatabase()->custom_query("SELECT * FROM notifications_settings WHERE userId=?", ['userId' => $user->getId()])->fetchAll(\PDO::FETCH_ASSOC);

        $settings = [];
        foreach ($_settings as $ele) {
            $settings = array_merge($settings, ["notifications_" . $ele['type'] => $ele['enabled']]);
        }

        Panel::compile("_views/_pages/account/settings.html", array_merge(
            [
                'user'                  => (array) Panel::getDatabase()->fetch_single_row('users', 'id', $user->getId(), \PDO::FETCH_ASSOC),
                'address'               => (array) $user->getAddress()->load(),
                'notifications_tickets' => $settings['notifications_tickets'] ?? false,
                'notifications_servers' => $settings['notifications_servers'] ?? false,
                'notifications_account' => $settings['notifications_account'] ?? false
            ],
            Panel::getLanguage()->getPages(['settings', 'global'])
        ));
    }

    public static function toggleNotificationSetting() {
        $user   = BaseModule::getUser();
        $type   = $_POST['type'];
        $status = (int) filter_var($_POST['status'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // check if entry already exists
        $ex = Panel::getDatabase()->custom_query("SELECT * FROM notifications_settings WHERE `userId`=? AND `type`=?", [
            'userId' => $user->getId(),
            'type'   => $type
        ])->fetchAll(\PDO::FETCH_ASSOC);

        if ($ex && sizeof($ex) > 0) {
            Panel::getDatabase()->update('notifications_settings', ['enabled' => $status], 'id', $ex[0]['id']);
        } else {
            Panel::getDatabase()->insert('notifications_settings', [
                'userId'  => $user->getId(),
                'type'    => $type,
                'enabled' => $status
            ]);
        }
        EventManager::fire('user::update', $user->toArray());
        die();
    }

    public static function getLatestNotifications($amount) {
        $user = BaseModule::getUser();
        if (!$user) {
            return ['status' => 'not_authenticated'];
        }

        $nots = Panel::getDatabase()->custom_query("SELECT * FROM notifications WHERE userId=? ORDER BY id DESC LIMIT " . $amount, [$user->getId()]);
        $nots = $nots->fetchAll();

        $res = [];
        foreach ($nots as $not) {
            $not   = Notification::loadFromRes($not);
            $res[] = [
                'id'   => $not->getId(),
                'text' => $not->getText(),
                'date' => Formatters::formatDateAbsolute($not->getCreatedAt()),
                'read' => filter_var($not->getHasRead(), FILTER_VALIDATE_BOOLEAN)
            ];
        }
        return $res;
    }

    public static function markNotificationAsRead($id) {
        Panel::getDatabase()->update('notifications', ['hasRead' => 1], 'id', $id);
        die('ok');
    }

    public static function markNotificationAsUnread($id) {
        Panel::getDatabase()->update('notifications', ['hasRead' => 0], 'id', $id);
        die('ok');
    }

    public static function postSave() {
        $b             = Panel::getRequestInput();
        $user             = BaseModule::getUser();
        $b['userid']   = $user->getId();
        $b['birthday'] = date('Y-m-d', strtotime($b['birthday']));
        $ad               = $user->getAddress();
        $ad->save($b);
        if ($user->hasNotificationsEnabled('account')) {
            // user has account notifications enabled, so we need to send him a message her.
            $notification = (new Notification())
                ->setUserId($user->getId())
                ->setType(Notification::TYPE_ACCOUNT)
                ->setEmail($user->getEmail())
                ->setMeta("account_information");
            $notification->save();
        }
        EventManager::fire('user::update', $user->toArray());
        return [
            'error' => false
        ];
    }

    public static function changePassword() {
        $user = BaseModule::getUser();
        $old  = $_POST['old'];
        $new  = $_POST['new'];

        header('Content-Type: application/json');

        $_user = Panel::getDatabase()->fetch_single_row("users", 'id', $user->getId());
        if (password_verify($old, $_user->password)) {
            Panel::getDatabase()->update('users', ['password' => password_hash($new, PASSWORD_DEFAULT)], 'id', $user->getId());
            if ($user->hasNotificationsEnabled('account')) {
                // user has account notifications enabled, so we need to send him a message her.
                $notification = (new Notification())
                    ->setUserId($user->getId())
                    ->setType(Notification::TYPE_ACCOUNT)
                    ->setEmail($user->getEmail())
                    ->setMeta("password_reset");
                $notification->save();
            }
            EventManager::fire('user::update', $user->toArray());
            die(json_encode(['error' => false]));
        } else {
            die(json_encode(['error' => true]));
        }
    }

    public static function setDarkTheme() {
        if (isset($_COOKIE['theme']) && $_COOKIE['theme'] == "dark") {
            setcookie('theme', 'style', 0, '/', false);
        } else {
            setcookie('theme', 'dark', 0, '/', false);
        }
        header("Location: " . $_SERVER['HTTP_REFERER']);
        die();
    }

    public static function setLanguage($language) {
        // set the language
        if ($language == "gb")
            $language = "en";
        setcookie('language', $language, 0, '/', false);
        die();
    }

    /**
     * request 2FA starting process
     *
     * @return array
     */
    public static function apiRequest2FA() {
        $user           = BaseModule::getUser();
        $qrCodeProvider = new EndroidQrCodeProvider();

        $tfa    = new TwoFactorAuth(
            \Module\BaseModule\Controllers\Admin\Settings::getConfigEntry('PAGE_TITLE', 'ProxmoxCP'),
            6,
            30,
            Algorithm::Sha1,
            $qrCodeProvider
        );
        $secret = $tfa->createSecret();

        $_SESSION['2fa_setup_secret'] = $secret;

        return [
            'error'  => false,
            'image'  => $tfa->getQRCodeImageAsDataUri($user->getName(), $secret),
            'secret' => chunk_split($secret, 4, ' ')
        ];
    }

    /**
     * save two factor authentication settings after validating token
     *
     * @return array
     */
    public static function apiSave2FA() {
        $user = BaseModule::getUser();
        $b    = Panel::getRequestInput();
        $code = $b['code'];

        $tfa = new TwoFactorAuth(
            \Module\BaseModule\Controllers\Admin\Settings::getConfigEntry('PAGE_TITLE', 'ProxmoxCP'),
        );

        $tempSecret = $_SESSION['2fa_setup_secret'];
        $res        = $tfa->verifyCode($tempSecret, $code);

        if ($res === true) {
            Panel::getDatabase()->update('users', [
                'twofaSecret'  => $tempSecret,
                'twofaEnabled' => date('Y-m-d H:i:s')
            ], 'id', $user->getId());
        }

        return [
            'success' => $res
        ];
    }

    public static function apiPasswordResetRequest() {
        $b     = Panel::getRequestInput();
        $email = $b['email'];

        $user = Panel::getDatabase()->fetch_single_row('users', 'email', $email);

        if (!$user) {
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('login', 'm_login_failed_wrong')
            ];
        }

        if ($user->resetToken) {
            // error, already in reset-process
            return [
                'error'   => true,
                'message' => Panel::getLanguage()->get('login', 'm_reset_already_in_progress')
            ];
        }

        if ($user->confirmationToken) {
            return [
                "error" => true,
                "msg"   => Panel::getLanguage()->get('login', 'm_login_account_not_confirmed')
            ];
        }

        $random          = Server::getRandomId(15);
        $app             = \Module\BaseModule\Controllers\Admin\Settings::getConfigEntry('APP_URL', '/');
        $app             = $app != "/" ? $app : "";
        $confirmationUrl = BaseModule::buildRequestDomain() . "{$app}/confirm-reset?token={$random}";

        $name = $user->username;

        // we need to send a message to the user.
        $mail = Panel::getMailHelper();
        $res  = Panel::getEngine()->compile(MailHelper::getCurrentMailTemplate(), [
            "m_title" => Panel::getLanguage()->get('mail_password_reset', "m_title"),
            "m_desc"  => str_replace(
                ['{{name}}', '{{link}}'],
                ["$name", $confirmationUrl],
                Panel::getLanguage()->get('mail_password_reset', 'm_confirmation_email_text')
            ),
            "logo"    => \Module\BaseModule\Controllers\Admin\Settings::getConfigEntry("LOGO")
        ]);
        $mail->setAddress($email);
        $mail->setContent(Panel::getLanguage()->get('mail_password_reset', 'm_subject'), $res);
        $mail->send();
        $mail->clear();


        Panel::getDatabase()->update('users', [
            'resetToken' => $random
        ], 'id', $user->id);

        return [
            'error'   => false,
            'message' => Panel::getLanguage()->get('login', 'm_reset_request_successfull')
        ];
    }

    public static function apiResetPassword() {
        $b = Panel::getRequestInput();

        $password = $b['password'];
        $token    = $b['token'];

        // validate token;
        $user = Panel::getDatabase()->fetch_single_row('users', 'resetToken', $token);
        if (!$user)
            die('invalid token');

        Panel::getDatabase()->update('users', [
            'password'   => password_hash($password, PASSWORD_DEFAULT),
            'resetToken' => null
        ], 'id', $user->id);


        return [
            'error'   => false,
            'message' => Panel::getLanguage()->get('login', 'm_reset_done')
        ];
    }
}