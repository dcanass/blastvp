<?php
namespace Objects\CLI;

use Ahc\Cli\Input\Command;
use Ahc\Cli\Output\Cursor;
use Controllers\Panel;

class BetaCommand extends Command {
    public function __construct() {
        parent::__construct('beta', 'enable/disable debug mode');

        $this
            ->argument('[action]', 'status|enable|disable');
    }

    public function execute($action) {
        $io     = $this->app()->io();
        $writer = $io->writer();

        $module = Panel::getModule('BaseModule');

        if ($action == "status") {
            // show status
            $io->ok('Current Status: ');
            $io->bold($module->getMeta()->productId === 34 ? 'Disabled' : 'Enabled', true);

            return 0;
        }

        if ($action == "enable") {
            // enable - write productId 100.000
            $i            = json_decode(file_get_contents(dirname(__FILE__) . "/../../_Modules/BaseModule/module_meta.json"));
            $i->productId = 100000;
            @file_put_contents(dirname(__FILE__) . "/../../_Modules/BaseModule/module_meta.json", json_encode($i, JSON_PRETTY_PRINT));

            $io->ok('Enabled beta mode', true);
            return 0;
        }

        if ($action == "disable") {
            // dsiable - write productId 34
            $i            = json_decode(file_get_contents(dirname(__FILE__) . "/../../_Modules/BaseModule/module_meta.json"));
            $i->productId = 34;
            @file_put_contents(dirname(__FILE__) . "/../../_Modules/BaseModule/module_meta.json", json_encode($i, JSON_PRETTY_PRINT));

            $io->ok('Disabled beta mode', true);
            return 0;
        }

        return 0;
    }
}