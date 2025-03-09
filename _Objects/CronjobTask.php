<?php

namespace Objects;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Controllers\ConfigValidator;
use Controllers\Panel;

class CronjobTask implements Task {

    public function __construct(private $module) {
    }

    public function run(Channel $channel, Cancellation $cancellation): mixed {
        echo "Executing Cronjob Task for: " . $this->module->getName() . PHP_EOL;
        new ConfigValidator();
        new Panel();
        // return false;
        return call_user_func($this->module->getMeta()->cronjobTask);
    }
}
