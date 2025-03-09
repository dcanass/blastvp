<?php

/**
 * Created by PhpStorm.
 * User: bennet
 * Date: 19.12.18
 * Time: 18:40
 */

namespace Controllers;


use Exception;
use Module\BaseModule\Controllers\Admin\Settings;
use PHPMailer\PHPMailer\PHPMailer;

class MailHelper {

    private $mail;
    private $to;

    public function __construct($smtp, $user, $password) {
        Panel::setMailHelper($this);
        $this->mail = new PHPMailer(true);
        $this->mail->CharSet = 'utf-8';
        $this->mail->isSMTP();
        $this->mail->Host = $smtp;  // Specify main and backup SMTP servers
        $this->mail->SMTPAuth = true;                               // Enable SMTP authentication
        $this->mail->Username = $user;          // SMTP username
        $this->mail->Password = $password;                      // SMTP password
        $this->mail->SMTPSecure = Settings::getConfigEntry("MAIL_ENCRYPTION", "tls");                            // Enable TLS encryption, `ssl` also accepted
        $this->mail->Port = Settings::getConfigEntry("MAIL_PORT", 25);                                    // TCP port to connect to
        //Recipients
        try {
            $this->mail->setFrom(Settings::getConfigEntry("MAIL_FROM"), Settings::getConfigEntry("MAIL_FROM_NAME"));
        } catch (Exception $e) {
            echo $e;
        }
        $this->mail->addReplyTo(Settings::getConfigEntry("MAIL_REPLY"), Settings::getConfigEntry("MAIL_REPLY_NAME"));
    }

    public function setAddress($to) {
        $this->to = $to;
        $this->mail->addAddress($to);
    }

    public function setContent($subject, $body) {
        $this->mail->isHTML(true);
        $this->mail->Subject = $subject;
        $this->mail->Body = $body;
    }

    public function addBCC($bcc) {
        $this->mail->addBCC($bcc);
    }

    public function send() {
        $this->mail->send();
    }

    public function addAttachment($a, $filename) {
        $this->mail->addStringAttachment($a, $filename);
    }

    public function clear() {
        $this->mail->clearAddresses();
        $this->mail->clearAllRecipients();
        $this->mail->clearAttachments();
        $this->mail->clearBCCs();
        $this->mail->clearCCs();
        $this->mail->clearCustomHeaders();
        $this->mail->clearReplyTos();
    }

    public static function getCurrentMailTemplate() {
        if (file_exists('mails/mail_overwrite.php')) {
            return "mails/mail_overwrite.php";
        } else {
            return "mails/mail.php";
        }
    }
}
