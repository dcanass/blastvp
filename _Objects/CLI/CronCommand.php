<?php
namespace Objects\CLI;

use Ahc\Cli\Input\Command;
use Objects\Cronjob;

class CronCommand extends Command {
    public function __construct() {
        parent::__construct('cron:run', 'run the cronjob');
    }

    public function execute() {
        $io = $this->app()->io();

        $cron = new Cronjob();
        $cron->executeModuleFunctions();

        return 0;
    }
}