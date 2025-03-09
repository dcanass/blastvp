<?php

namespace Objects;

use Amp\CompositeException;
use Amp\Parallel\Worker\Execution;
use Controllers\Panel;
use Controllers\StdIoForwardingProcessContextFactory;
use Exception;

use function Amp\Future\awaitAnyN;
use function Amp\Parallel\Context\contextFactory;
use function Amp\Parallel\Worker\submit;

class Cronjob {

    private $p;

    public function __construct() {
        $this->p = new Panel();
    }

    public function executeModuleFunctions() {
        contextFactory(new StdIoForwardingProcessContextFactory());
        $calls = [];
        foreach ($this->p->getModules() as $module) {
            if (isset($module->getMeta()->cronjobTask)) {
                $calls[] = submit(new CronjobTask($module));
            }
        }
        try {
            awaitAnyN(count($calls), array_map(
                fn (Execution $e) => $e->getFuture(),
                $calls,
            ));
        } catch (CompositeException $e) {
            foreach ($e->getReasons() as $er) {
                echo "Error during the execution of a module cronjob. Turn on debug mode to see error mesages." . PHP_EOL;
                dump($er->getMessage());
            }
        }
    }
}
