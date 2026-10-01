<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Console;

use Porta\AssistPlugin\Net\Address;
use Porta\AssistPlugin\Net\Connection;
use Porta\AssistPlugin\Net\SecureClient;
use Porta\AssistPlugin\Net\Wire;
use Porta\AssistPlugin\Session\GuestSession;
use Porta\AssistPlugin\Workspace\Mirror;
use Porta\Config\IdeConfig;
use Porta\Config\PortaHome;
use Porta\Install\PortaBinary;
use Porta\Onboarding\OnboardingState;
use Porta\Plugin\Api\PluginCommand;
use Porta\Support\LineFramer;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `porta assist <invite>` — join a session someone shares.
 *
 * Asks the host in (the host answers in their IDE), brings this machine's copy
 * of the host's project in line with it — only what changed since the last
 * time is fetched — and opens Porta on that copy, which takes the session
 * from there. A plain blocking loop: until the IDE opens, this process has
 * nothing else to do.
 */
#[AsCommand(
    name: 'assist',
    description: 'Join a project someone shares with the assist plugin',
)]
final class JoinCommand extends PluginCommand
{
    /** Files asked for at once. */
    private const int BATCH = 100;

    /** @var resource|null */
    private $socket;

    private readonly LineFramer $framer;

    /** @var list<array<string, mixed>> */
    private array $received = [];

    /**
     * @param (\Closure(string): int)|null $launch opens the IDE on a directory and waits for it; null runs this Porta
     * @param string|null                  $home   where copies are kept; null is the user's home
     */
    public function __construct(
        private readonly ?\Closure $launch = null,
        private readonly ?string $home = null,
        private readonly float $timeout = 10.0,
        private readonly float $answerTimeout = 180.0,
    ) {
        parent::__construct();
        $this->framer = new LineFramer(static function (string $why): void {}, Connection::MAX_LINE);
        $this->setHelp(<<<'HELP'
            Joins a project shared with the <info>assist</info> plugin. The host runs
            <info>Assist: Share This Project</info> and sends you the invite it copies:

              <info>porta assist 192.168.1.20:52814#4f….9c…</info>

            The host is asked to let you in. Then a copy of the project is kept in sync
            under <info>~/.porta/assist/</info> — a second join fetches only what changed —
            and Porta opens on it, connected: what you type goes to the host, and what
            everyone types comes back.
            HELP);
    }

    public function __invoke(
        OutputInterface $output,
        #[Argument('The invite the host shared: host:port#token')]
        string $invite,
        #[Option('The name the others see')]
        ?string $name = null,
    ): int {
        $errors = self::errors($output);
        try {
            $address = Address::parse($invite);
        } catch (\InvalidArgumentException $e) {
            $errors->writeln('<error>'.OutputFormatter::escape($e->getMessage()).'</error>');

            return self::FAILURE;
        }
        $who = '' !== \trim((string) $name) ? \trim((string) $name) : self::user();
        $failure = $this->join($address, $who, $output);
        if (null !== $failure) {
            $errors->writeln('<error>'.OutputFormatter::escape($failure).'</error>');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** Ask in, sync the copy, open the IDE on it: null once done, else why not. */
    private function join(Address $address, string $name, OutputInterface $output): ?string
    {
        $joined = $this->enter($address, $name, $output);
        if (\is_string($joined)) {
            return $joined;
        }
        [$mirror, $ticket, $languages] = $joined;
        GuestSession::store($mirror->root, [
            'host' => $address->host,
            'port' => $address->port,
            'fingerprint' => $address->fingerprint,
            'ticket' => $ticket,
            'name' => $name,
            'languages' => $languages,
            'relay' => $address->relay,
        ]);
        self::enable($mirror->root);
        // The first-run card walks through setting a project up; this one is
        // the host's, set up already.
        new OnboardingState($mirror->root)->markWelcomeSeen();
        $output->writeln(\sprintf('Opening <info>%s</info>…', OutputFormatter::escape($mirror->root)));
        $code = ($this->launch ?? self::launch(...))($mirror->root);

        return 0 === $code ? null : \sprintf('Porta exited with %d', $code);
    }

    /**
     * Be let in and bring the copy in line, on a connection of this
     * command's own — the IDE makes another with the ticket.
     *
     * @return array{Mirror, string, list<string>}|string the copy, the ticket and the host's languages, or why not
     */
    private function enter(Address $address, string $name, OutputInterface $output): array|string
    {
        $socket = SecureClient::connect($address->host, $address->port, $address->fingerprint, $this->timeout, $address->relay);
        if (\is_string($socket)) {
            return $socket;
        }
        $this->socket = $socket;
        try {
            $this->send(['t' => 'hello', 'v' => Wire::VERSION, 'name' => $name, 'token' => $address->token]);
            $welcome = $this->welcome($output);
            if (\is_string($welcome)) {
                return $welcome;
            }
            $mirror = Mirror::under($this->home ?? (string) PortaHome::base(), $welcome['project'], $welcome['name']);
            $synced = $this->sync($mirror, $output);
            $this->send(['t' => 'bye']);

            return $synced ?? [$mirror, $welcome['ticket'], $welcome['languages']];
        } finally {
            \fclose($socket);
            $this->socket = null;
        }
    }

    /**
     * Wait to be let in.
     *
     * @return array{project: string, name: string, ticket: string, languages: list<string>}|string the session, or why there is none
     */
    private function welcome(OutputInterface $output): array|string
    {
        while (true) {
            $message = $this->next($this->answerTimeout);
            $kind = null === $message ? 'timeout' : Wire::string($message, 't');
            if ('pending' === $kind) {
                $output->writeln('Waiting for the host to let you in…');

                continue;
            }
            if ('welcome' === $kind) {
                $project = \is_array($message['project'] ?? null) ? $message['project'] : [];
                $id = \is_string($project['id'] ?? null) && 1 === \preg_match('/^[0-9a-f]{16}$/', $project['id']) ? $project['id'] : null;
                $ticket = Wire::string($message ?? [], 'ticket');
                if (null === $id || '' === $ticket) {
                    return 'The host sent a welcome this Porta does not understand';
                }
                $output->writeln(\sprintf('In, %s.', Wire::string($message ?? [], 'role') === 'edit' ? 'editing' : 'reading along'));

                return [
                    'project' => $id,
                    'name' => \is_string($project['name'] ?? null) ? Wire::clean($project['name']) : '',
                    'ticket' => $ticket,
                    'languages' => Wire::strings($message ?? [], 'languages'),
                ];
            }

            return match (null === $message ? 'timeout' : Wire::string($message, 'reason')) {
                'host' => 'The host turned you away',
                'full' => 'The session is full',
                'unanswered', 'timeout' => 'The host did not answer',
                'version' => 'The host runs a Porta that speaks another version of assist',
                default => 'The invite is not valid any more — ask the host for a new one',
            };
        }
    }

    /** Bring $mirror in line with the host's project: null once it is, else why not. */
    private function sync(Mirror $mirror, OutputInterface $output): ?string
    {
        $this->send(['t' => 'manifest']);
        $message = $this->next($this->timeout * 6, 'manifest');
        if (null === $message) {
            return 'The host did not list its files';
        }
        $host = Wire::files($message, 'files');
        $pruned = $mirror->prune($host);
        $missing = $mirror->missing($host);
        $output->writeln(\sprintf('%d files shared: %d to fetch, %d removed.', \count($host), \count($missing), $pruned));
        foreach (\array_chunk($missing, self::BATCH) as $batch) {
            $this->send(['t' => 'fetch', 'paths' => $batch]);
            while (true) {
                $message = $this->next($this->timeout * 6);
                if (null === $message) {
                    return 'The host stopped sending files';
                }
                if ('fetched' === $message['t']) {
                    break;
                }
                $content = \base64_decode(Wire::string($message, 'data'), strict: true);
                if ('file' === $message['t'] && false !== $content) {
                    $mirror->write(Wire::string($message, 'path'), $content);
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $message */
    private function send(array $message): void
    {
        if (null !== $this->socket) {
            \fwrite($this->socket, Wire::encode($message));
        }
    }

    /**
     * The next message — of type $type, when one is named: the session
     * talks on meanwhile (where the others' carets are, who arrived), and
     * none of that is this command's business. Null when none comes in
     * $seconds.
     *
     * @return array<string, mixed>|null
     */
    private function next(float $seconds, ?string $type = null): ?array
    {
        $deadline = \hrtime(true) + (int) ($seconds * 1e9);
        do {
            $message = $this->read(($deadline - \hrtime(true)) / 1e9);
        } while (null !== $message && null !== $type && $type !== $message['t']);

        return $message;
    }

    /** @return array<string, mixed>|null the next message, or null when none comes in $seconds */
    private function read(float $seconds): ?array
    {
        $socket = $this->socket;
        $deadline = \hrtime(true) + (int) ($seconds * 1e9);
        while (null !== $socket && [] === $this->received) {
            $left = ($deadline - \hrtime(true)) / 1e9;
            $read = [$socket];
            $write = null;
            $except = null;
            if ($left <= 0 || 1 !== \stream_select($read, $write, $except, (int) $left, (int) (($left - (int) $left) * 1e6))) {
                return null;
            }
            // Not blocking: readable can be a TLS record with nothing in it
            // for this side — a session ticket — and a blocking read would
            // wait out the socket's own timeout, past this one.
            \stream_set_blocking($socket, enable: false);
            $bytes = \fread($socket, length: 262_144);
            \stream_set_blocking($socket, enable: true);
            if (false === $bytes || '' === $bytes && \feof($socket)) {
                return null;
            }
            foreach ($this->framer->feed($bytes) as $line) {
                $message = Wire::decode($line);
                if (null !== $message) {
                    $this->received[] = $message;
                }
            }
        }

        return \array_shift($this->received);
    }

    /** The copy's own settings: the plugins this Porta enables by default, and this one. */
    private static function enable(string $root): void
    {
        $file = $root.'/.porta.json';
        // @mago-expect analysis:mixed-assignment -- narrowed below
        $config = \is_file($file) ? \json_decode((string) \file_get_contents($file), associative: true) : null;
        $config = \is_array($config) ? $config : [];
        $enabled = \is_array($config['enabledPlugins'] ?? null) ? $config['enabledPlugins'] : IdeConfig::DEFAULT_ENABLED_PLUGINS;
        if (!\in_array('assist', $enabled, strict: true)) {
            $enabled[] = 'assist';
        }
        $config['enabledPlugins'] = \array_values($enabled);
        \file_put_contents($file, \json_encode($config, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * This Porta, on the terminal this command runs in, until it quits. The
     * tests hand the command a launcher of their own: a real IDE needs a
     * real terminal.
     */
    // @codeCoverageIgnoreStart
    private static function launch(string $root): int
    {
        $pipes = [];
        $process = \proc_open([\PHP_BINARY, PortaBinary::path(), $root], [\STDIN, \STDOUT, \STDERR], $pipes);

        return false === $process ? 1 : \proc_close($process);
    }

    // @codeCoverageIgnoreEnd

    private static function user(): string
    {
        $user = \getenv('USER');

        return \is_string($user) && '' !== $user ? $user : 'guest';
    }
}
