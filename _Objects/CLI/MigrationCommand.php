<?php
namespace Objects\CLI;

use Ahc\Cli\Input\Command;
use Migration\MigrationHandler;
use Objects\Cronjob;

class MigrationCommand extends Command {
    public function __construct() {
        parent::__construct('migration:run', 'run database migrations');
    }

    public function execute() {
        $io = $this->app()->io();

        $migrationHandler = MigrationHandler::getInstance();
        $migrationHandler->applyMigrations();

        return 0;
    }
}