<?php
namespace Objects\CLI;

use Ahc\Cli\Input\Command;
use Controllers\Panel;

class InfoCommand extends Command {
    public function __construct() {
        parent::__construct('info', 'core information useful for debugging');
    }

    public function execute() {
        $io     = $this->app()->io();
        $writer = $io->writer();

        $writer->justify('Environment');
        $writer->justify('PHP Version', PHP_VERSION);
        $writer->justify('Modules:');
        $mods = Panel::getModules();
        foreach ($mods as $mod) {
            $writer->justify($mod->getName(), $mod->getVersion());
        }


        return 0;
    }
}