<?php

/**
 * Created by PhpStorm.
 * User: bennet
 * Date: 14.02.2019
 * Time: 05:00
 */

namespace Module\BaseModule\Controllers;


use Angle\Engine\Template\Engine;
use Controllers\MailHelper;
use Controllers\Panel;
use Module\BaseModule\BaseModule;
use Module\BaseModule\Controllers\Admin\ServerAPI;
use Module\BaseModule\Controllers\Admin\Settings;
use Objects\Event\EventManager;
use Objects\User;
use RobThree\Auth\TwoFactorAuth;

class Authentication {

    public static function login() {
        $confirm = isset($_GET['confirmed']);

        $isReset = false;
        if (isset($_GET['token'])) {
            // this is a password reset request, we want to prefill and disable the email and only enable the password field
            $user = Panel::getDatabase()->fetch_single_row('users', 'resetToken', $_GET['token']);
            if (!$user)
                die('invalid token');
            $isReset       = true;
            $_GET['email'] = $user->email;

        }

        Panel::compile("_views/_pages/authentication/login.html", array_merge([
            "conditions"           => Settings::getConfigEntry('CONDITIONS_URL', ''),
            "help"                 => Settings::getConfigEntry('HELP_URL', ''),
            "terms"                => Settings::getConfigEntry('TERMS_URL', ''),
            "registration_active"  => Settings::getConfigEntry("REGISTRATIONS_ACTIVE", true),
            "confirmation_message" => $confirm ? Panel::getLanguage()->get('login', "m_confirmation_success") : false,
            "prefill_email"        => isset($_GET['email']) ? $_GET['email'] : "",
            'isReset'              => $isReset,
            "resetToken"           => $_GET['token'] ?? ''
        ], Panel::getLanguage()->getPage('login')));
    }

    public static function register() {
        Panel::compile("_views/_pages/authentication/register.html", array_merge([
            "conditions"       => Settings::getConfigEntry('CONDITIONS_URL', ''),
            "help"             => Settings::getConfigEntry('HELP_URL', ''),
            "terms"            => Settings::getConfigEntry('TERMS_URL', ''),
            "captcha_provider" => Settings::getConfigEntry('CAPTCHA_PROVIDER', false),
            "captcha_public"   => Settings::getConfigEntry("CAPTCHA_PUBLIC", "")
        ], Panel::getLanguage()->getPage('register')));
    }

    /**
     * logs the user in
     *
     * @return array
     */
    public static function loginPost() {
        $b = Panel::getRequestInput();

        $email    = $b['email'] ?? false;
        $password = $b['password'] ?? false;
        $code     = $b['code'] ?? false;

        $m = Panel::getLanguage()->getPage('login');

        if (!$email || !$password) {
            return ["error" => true, "msg" => $m['m_login_failed_fields']];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ["error" => true, "msg" => $m['m_login_failed_invalid_email']];
        }

        $db = Panel::getDatabase();

        $qu = $db->fetch_single_row("users", "email", $email);

        if (!$qu) {
            return ["error" => true, "msg" => $m['m_login_failed_wrong']];
        }

        if (!password_verify($password, $qu->password)) {
            return ["error" => true, "msg" => $m['m_login_failed_wrong']];
        }

        if ($qu->confirmationToken) {
            return ["error" => true, "msg" => $m['m_login_account_not_confirmed']];
        }

        if ($qu->twofaEnabled && !$code) {
            return [
                'error'       => true,
                'twoRequired' => true
            ];
        }

        if ($qu->twofaEnabled && $code) {
            $tfa = new TwoFactorAuth();
            if (!$tfa->verifyCode($qu->twofaSecret, $code)) {
                return [
                    'error' => true,
                    'msg'   => $m['m_login_twofa_failed']
                ];
            }
        }

        $user                  = new User($qu->id);
        $user                  = $user->load();
        $_SESSION['proxmox_p'] = serialize($user);

        EventManager::fire('user::login', $user->toArray());

        return [
            "error" => false,
            "msg"   => $m['m_login_success'],
            "token" => $user->apiToken
        ];
    }

    public static function registerPost() {
        header("Content-Type: application/json");

        $mLogin    = Panel::getLanguage()->getPage('login');
        $mRegister = Panel::getLanguage()->getPage('register');

        if (!Settings::getConfigEntry("REGISTRATIONS_ACTIVE", true)) {
            return ["error" => true, 'msg' => $mRegister['m_registrations_disabled']];
        }

        $email            = $_POST['email'] ?? false;
        $password         = $_POST['password'] ?? false;
        $password_confirm = $_POST['confirm'] ?? false;
        $first_name       = $_POST['firstname'] ?? false;
        $last_name        = $_POST['lastname'] ?? false;
        $tos              = $_POST['tos'] ? filter_var($_POST['tos'], FILTER_VALIDATE_BOOLEAN) : false;

        $token = $_POST['token'] ?? false;
        if ($token) {
            $cap = Captcha::verify($token);
            if (!$cap) {
                return [
                    'error' => true,
                    'msg'   => $mRegister['m_captcha_error'],
                    'score' => $cap
                ];
            }
        }

        if (!$email || !$password || !$password_confirm || !$first_name || !$last_name || !$tos) {
            return ["error" => true, "msg" => $mRegister['m_fill_fields']];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ["error" => true, "msg" => $mLogin['m_login_failed_invalid_email']];
        }
        if ($password_confirm !== $password) {
            return ["error" => true, "msg" => $mRegister['m_password_invalid']];
        }

        $db = Panel::getDatabase();

        $qu = $db->fetch_single_row("users", "email", $email);

        if ($qu) {
            return ["error" => true, "msg" => $mRegister['m_already_taken']];
        }

        // check if there is already a user in the db
        $rowCount = $db->countRows('users');

        if ($rowCount == 0) {
            $permission = 3;
        } else {
            $permission = 1;
        }

        $insert         = [
            "username"   => $first_name . " " . $last_name,
            "email"      => $email,
            "password"   => password_hash($password, PASSWORD_DEFAULT),
            "permission" => $permission
        ];
        $successMessage = $mRegister['m_register_success'];

        // check if registration requires confirmation.
        if (Settings::getConfigEntry('REGISTER_REQUIRES_CONFIRMATION', false)) {
            // get current domain
            $random          = Server::getRandomId();
            $app             = Settings::getConfigEntry('APP_URL', '/');
            $app             = $app != "/" ? $app : "";
            $confirmationUrl = BaseModule::buildRequestDomain() . "{$app}/confirm?token={$random}";

            // we need to send a message to the user.
            $mail = Panel::getMailHelper();
            $res  = Panel::getEngine()->compile(MailHelper::getCurrentMailTemplate(), [
                "m_title" => Panel::getLanguage()->get('mail_confirm_registration', "m_title"),
                "m_desc"  => str_replace(
                    ['{{name}}', '{{link}}'],
                    ["{$first_name} {$last_name}", $confirmationUrl],
                    Panel::getLanguage()->get('mail_confirm_registration', 'm_confirmation_email_text')
                ),
                "logo"    => Settings::getConfigEntry("LOGO")
            ]);
            $mail->setAddress($email);
            $mail->setContent(Panel::getLanguage()->get('mail_confirm_registration', 'm_subject'), $res);
            $mail->send();
            $mail->clear();

            $insert['confirmationToken'] = $random;
            $successMessage              = $mRegister['m_success_verification_required'];
        }

        $ins  = $db->insert("users", $insert);
        $user = Panel::getDatabase()->fetch_single_row('users', 'email', $email);

        Panel::getDatabase()->insert('api-tokens', [
            'userId'      => $user->id,
            'token'       => Server::getRandomId(125),
            'description' => "SYSTEM"
        ]);

        if (!$ins) {
            return ["error" => true, "msg" => "Internal Error."];
        }
        $user = (new User($user->id))->load();
        EventManager::fire('user::register', $user->toArray());

        return ["error" => false, "msg" => $successMessage];
    }

    public static function confirm() {
        $token = $_GET['token'];
        // search for user in the database
        $user = Panel::getDatabase()->fetch_single_row('users', 'confirmationToken', $token);
        if (!$user) {
            // invalid token
            die('invalid token');
        }
        $upd  = Panel::getDatabase()->update('users', ['confirmationToken' => null], 'id', $user->id);
        $user = (new User($user->id))->load();
        EventManager::fire('user::register', $user->toArray());

        header('Location: ' . Settings::getConfigEntry('APP_URL', '/') . 'login?confirmed&email=' . $user->email);
        die();
    }

    /**
     * login as a user
     *
     * @param int $id
     * @return array
     */
    public static function loginAsUser($id) {
        $user                                 = new User($id);
        $user                                 = $user->load();
        $_SESSION['proxmox_p']                = serialize($user);
        $_SESSION['proxmox_cp_loggedin_as']   = true;
        $_SESSION['proxmox_cp_original_user'] = serialize(BaseModule::getUser());

        return [
            'error' => false
        ];
    }

    public static function restoreSession() {
        $_SESSION['proxmox_p']              = serialize(unserialize($_SESSION['proxmox_cp_original_user']));
        $_SESSION['proxmox_cp_loggedin_as'] = false;
        unset($_SESSION['proxmox_cp_original_user']);

        return [
            'error' => false
        ];
    }

    public static function logout() {
        session_destroy();
        unset($_SESSION);
        header("Location: " . Settings::getConfigEntry("APP_URL"));
        die();
    }
}