<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Tests\Console;

use PHPUnit\Framework\TestCase;
use Porta\AssistPlugin\Console\RelayCommand;
use Porta\AssistPlugin\Net\Relay;
use Revolt\EventLoop;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `porta assist-relay`: it listens, says where, and relays until it is
 * stopped — or says why it cannot listen.
 */
final class RelayCommandTest extends TestCase
{
    private function execute(RelayCommand $command, int $port): CommandTester
    {
        $application = new Application();
        $application->addCommand($command);
        $tester = new CommandTester($application->find('assist-relay'));
        $tester->execute(['--bind' => '127.0.0.1', '--port' => (string) $port], ['capture_stderr_separately' => true]);

        return $tester;
    }

    public function testItRelaysUntilStopped(): void
    {
        $answer = '';
        $tester = $this->execute(
            new RelayCommand(static function (Relay $relay, int $port) use (&$answer): void {
                // A stranger, turned away by a relay that is running; then stopped, the command ends.
                $client = \stream_socket_client('tcp://127.0.0.1:'.$port);
                \assert(false !== $client, description: 'the relay listens');
                \stream_set_blocking($client, enable: false);
                \fwrite($client, data: "hello\n");
                EventLoop::onReadable($client, static function (string $watcher) use ($client, $relay, &$answer): void {
                    $answer .= (string) \fread($client, length: 1_024);
                    if (\str_contains($answer, "\n")) {
                        EventLoop::cancel($watcher);
                        \fclose($client);
                        $relay->stop();
                    }
                });
            }),
            port: 0,
        );

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertMatchesRegularExpression('/Relaying on port \d+\./', $tester->getDisplay());
        $this->assertSame("{\"error\":\"not a relay request\"}\n", $answer);
    }

    public function testAPortInUseIsSaid(): void
    {
        $taken = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($taken);
        $port = (int) \substr((string) \stream_socket_get_name($taken, remote: false), offset: 10);

        $tester = $this->execute(new RelayCommand(), $port);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('cannot listen on tcp://127.0.0.1:'.$port, $tester->getErrorOutput());
    }
}
