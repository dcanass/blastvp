<?php
namespace Module\BaseModule\Cron;

use Controllers\MailHelper;
use Controllers\Panel;
use Migration\MigrationHandler;
use Module\BaseModule\Controllers\Admin;
use Module\BaseModule\Controllers\Admin\Settings;


class InstallUpdate {


    public static function execute() {

        // only execute every 10 minutes
        if (date('i') % 10 === 3) {
            if (!Settings::getConfigEntry("AUTO_INSTALL_MINOR", false)) {
                return false;
            }

            $admins  = Panel::getDatabase()->custom_query("SELECT * FROM users WHERE permission = 3")->fetchAll(\PDO::FETCH_OBJ);
            $updates = Settings::getModuleUpdates();

            $updateInfo = Settings::getModuleChangelog("BaseModule");
            if ($updateInfo['version'] !== Panel::getModule('BaseModule')->getMeta()->version) {
                $result = Admin::update();
                dump($result);
                foreach ($admins as $admin) {
                    $m = Panel::getMailHelper();
                    $m->clear();
                    $m->setAddress($admin->email);

                    $content = "Hello {$admin->username},<br /><br />updated the panel to version {$updateInfo['version']}.<br /><br />";

                    $res = Panel::getEngine()->compile(MailHelper::getCurrentMailTemplate(), [
                        "m_title" => "Update installed",
                        "m_desc"  => $content,
                        "logo"    => Settings::getConfigEntry("LOGO")
                    ]);

                    $m->setContent('Update installed', $res);
                    $m->send();
                    $m->clear();
                }
            }


            $updates = array_filter($updates, function ($upd) {
                return $upd['updateAvailable'];
            });

            $results = [];
            foreach ($updates as $k => $v) {
                $results[$k] = Settings::updateModule($k);
            }
            dump($results);
            if (sizeof($results) > 0) {
                foreach ($admins as $admin) {
                    $m = Panel::getMailHelper();
                    $m->clear();
                    $m->setAddress($admin->email);

                    $content = "Hello {$admin->username},<br /><br />the following modules have been updated:<br /><br />";
                    foreach ($results as $mod => $result) {
                        $content .= $mod . ': ' . $updates[$mod]['newest'] . '<br />';
                    }

                    $res = Panel::getEngine()->compile(MailHelper::getCurrentMailTemplate(), [
                        "m_title" => "Updates installed",
                        "m_desc"  => $content,
                        "logo"    => Settings::getConfigEntry("LOGO")
                    ]);

                    $m->setContent('Updates installed', $res);
                    $m->send();
                    $m->clear();
                }
                MigrationHandler::getInstance()->applyMigrations();
            }
        }
    }

}