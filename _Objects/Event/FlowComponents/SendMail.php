<?php
namespace Objects\Event\FlowComponents;

use Controllers\MailHelper;
use Controllers\Panel;
use Module\BaseModule\Controllers\Admin\Settings;
use Objects\Event\TemplateReplacer;

class SendMail {

    /**
     * sends a email
     * $data contains:
     * - to
     * - subject
     * - content
     */
    public static function execute($data, $parameters) {
        $to      = TemplateReplacer::replaceAll($data['to'], $parameters);
        $subject = TemplateReplacer::replaceAll($data['subject'], $parameters);
        $content = TemplateReplacer::replaceAll(nl2br($data['content']), $parameters);

        $res = Panel::getEngine()->compile(MailHelper::getCurrentMailTemplate(), [
            "m_title" => $subject,
            "m_desc"  => $content,
            "logo"    => Settings::getConfigEntry("LOGO")
        ]);

        Panel::getMailHelper()->clear();
        Panel::getMailHelper()->setAddress($to);
        Panel::getMailHelper()->setContent($subject, $res);
        Panel::getMailHelper()->send();
        Panel::getMailHelper()->clear();
    }

}