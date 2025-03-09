<?php

namespace Controllers;

use Amp\ByteStream;
use Amp\Cancellation;
use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\ContextFactory;
use Amp\Parallel\Context\ProcessContextFactory;
use Closure;

use function Amp\async;

class StdIoForwardingProcessContextFactory implements ContextFactory {
    public function __construct(
        private $contextFactory = new ProcessContextFactory(),
    ) {
    }

    public function start(array|string $script, ?Cancellation $cancellation = null): Context {
        $process = $this->contextFactory->start($script, $cancellation);

        $pip = Closure::fromCallable("Amp\ByteStream\pipe");
        async($pip, $process->getStdout(), ByteStream\getStdout())->ignore();
        async($pip, $process->getStderr(), ByteStream\getStderr())->ignore();

        return $process;
    }
}
