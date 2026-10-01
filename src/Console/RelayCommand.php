<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Console;

use Porta\AssistPlugin\Net\Relay;
use Porta\Plugin\Api\PluginCommand;
use Revolt\EventLoop;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `porta assist-relay` — a {@see Relay}, for a host and guests who cannot
 * reach one another, run anywhere both can reach. It holds no secret and
 * sees no file: what it carries is the session's TLS, end to end. It runs
 * until it is interrupted.
 */
#[AsCommand(
    name: 'assist-relay',
    description: 'Join hosts and guests of shared sessions who cannot reach one another',
)]
final class RelayCommand extends PluginCommand
{
    /** @param \Closure(Relay, int): void|null $started told the relay and its port once it listens */
    public function __construct(
        private readonly ?\Closure $started = null,
    ) {
        parent::__construct();
    }

    public function __invoke(
        OutputInterface $output,
        #[Option('The address to listen on')]
        string $bind = '0.0.0.0',
        #[Option('The port to listen on; 0 picks a free one')]
        int $port = 7_420,
    ): int {
        $relay = new Relay();
        $listening = $relay->start($bind, $port);
        if (\is_string($listening)) {
            self::errors($output)->writeln('<error>'.$listening.'</error>');

            return self::FAILURE;
        }
        $output->writeln(\sprintf('Relaying on port <info>%d</info>. Hosts set assist.relay to this machine\'s address and that port.', $listening));
        if (null !== $this->started) {
            ($this->started)($relay, $listening);
        }
        EventLoop::run();

        return self::SUCCESS;
    }
}
