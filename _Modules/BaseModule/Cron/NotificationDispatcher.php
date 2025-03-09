<?php

namespace Module\BaseModule\Cron;

use Controllers\MailHelper;
use Controllers\Panel;
use Module\BaseModule\Controllers\Admin\Settings;
use Objects\Notification;

class NotificationDispatcher {


    public static function __execute() {
        $notifications = self::loadNotifications();
        self::sendNotifications($notifications);
    }

    public static function sendNotifications($notifications) {
        $mail = Panel::getMailHelper();
        foreach ($notifications as $notification) {

            // send email
            $content = Panel::getLanguage()->get("mail_notification", "m_description");
            $content = str_replace("{{content}}", $notification->getText(), $content);

            $res = Panel::getEngine()->compile(MailHelper::getCurrentMailTemplate(), [
                "m_title" => Panel::getLanguage()->get('mail_notification', "m_title"),
                "m_desc" => $content,
                "logo" => Settings::getConfigEntry("LOGO")
            ]);

            $mail->setAddress($notification->getEmail());
            $mail->setContent(Panel::getLanguage()->get('mail_notification', 'm_subject'), $res);

            try {
                $mail->send();
                Panel::getDatabase()->update('notifications', ['emailTries' => 0], 'id', $notification->getId());
                $mail->clear();
                echo "Send Notification to {$notification->getEmail()} with ID: {$notification->getId()}" . PHP_EOL;
            } catch (\Exception $e) {
                // reduce counter by one
                echo "Failed to send notification to {$notification->getEmail()} with ID: {$notification->getId()}" . PHP_EOL;
                Panel::getDatabase()->update('notifications', ['emailTries' => $notification->getEmailTries() - 1], 'id', $notification->getId());
            }
        }
    }

    public static function loadNotifications() {
        $nots = Panel::getDatabase()->custom_query("SELECT * FROM notifications WHERE emailTries > 0 AND hasRead = 0")->fetchAll();
        $notifications = [];
        foreach ($nots as $not) {
            $notifications[] = Notification::loadFromRes($not);
        }
        return $notifications;
    }
}
