<?php
namespace Objects\CLI;

use Ahc\Cli\Input\Command;
use Ahc\Cli\IO\Interactor;
use Ahc\Cli\Output\Color;
use Controllers\Panel;
use Migration\MigrationHandler;
use Module\BaseModule\Controllers\Admin;
use Module\BaseModule\Controllers\Admin\Settings;

class UpdateCommand extends Command {
    public function __construct() {
        parent::__construct('update', 'update the panel to the latest version');
    }

    public function execute() {
        $io = $this->app()->io();

        $io->ok('Starting update', true);

        $updates = Settings::getModuleUpdates();

        $updateInfo = Settings::getModuleChangelog("BaseModule");
        $io->info('Installed version: ' . Panel::getModule('BaseModule')->getMeta()->version, true);
        $io->info('Newest version: ' . $updateInfo['version'], true);
        if ($updateInfo['version'] !== Panel::getModule('BaseModule')->getMeta()->version) {
            $result = Admin::update(true);
            $io->ok('Core updates:', true);
            $io->table([$result]);
        }

        $updates = array_filter($updates, function ($upd) {
            return $upd['updateAvailable'];
        });

        $results = [];
        foreach ($updates as $k => $v) {
            $results[$k] = Settings::updateModule($k);
        }
        if (sizeof($results) > 0) {
            $io->ok('Module updates:', true);
            $io->table($results);
            MigrationHandler::getInstance()->applyMigrations();
        }

        $io->ok('Updates finished', true);
        return 0;
    }
}