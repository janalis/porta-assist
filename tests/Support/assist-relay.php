<?php

declare(strict_types=1);

use Porta\AssistPlugin\Net\Relay;
use Revolt\EventLoop;

/*
 * A relay as a separate process, for the commands that block: on a port of
 * its own on loopback, printed on the first line, until it is stopped.
 */

require __DIR__.'/../../../../vendor/autoload.php';
require __DIR__.'/../../../../tests/plugin-autoload.php';
$relay = new Relay();
fwrite(STDOUT, $relay->start('127.0.0.1', 0)."\n");
fflush(STDOUT);
EventLoop::run();
