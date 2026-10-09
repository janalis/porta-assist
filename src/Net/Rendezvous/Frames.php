<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Net\Rendezvous;

/**
 * WebSocket frames (RFC 6455), both ways: {@see encode()} one, and
 * {@see feed()} bytes as they arrive to get back each whole message —
 * fragments put together, the mask a client puts on taken off.
 */
final class Frames
{
    public const int TEXT = 0x1;

    public const int CLOSE = 0x8;

    public const int PING = 0x9;

    public const int PONG = 0xA;

    private const int CONTINUATION = 0x0;

    private string $buffer = '';

    /** The opcode and payload so far of a message in fragments. */
    private ?int $opcode = null;

    private string $fragments = '';

    /** @param \Closure(string): void $onError a frame no peer should send — why, once */
    public function __construct(
        private readonly \Closure $onError,
        private readonly int $maxMessage = 1_048_576,
    ) {}

    /** One whole frame, masked with $mask when it is a client's (four bytes), not when it is a server's (''). */
    public static function encode(int $opcode, string $payload, string $mask = ''): string
    {
        $length = \strlen($payload);
        $bit = '' === $mask ? 0 : 0x80;
        $head = \chr(0x80 | $opcode).match (true) {
            $length < 126 => \chr($bit | $length),
            $length < 65_536 => \chr($bit | 126).\pack('n', $length),
            default => \chr($bit | 127).\pack('J', $length),
        };

        return $head.$mask.('' === $mask ? $payload : $payload ^ \str_pad('', $length, $mask));
    }

    /**
     * @return list<array{int, string}> each message completed by $bytes: its opcode and payload
     */
    public function feed(string $bytes): array
    {
        $this->buffer .= $bytes;
        $messages = [];
        while (null !== ($frame = $this->frame())) {
            [$final, $opcode, $payload] = $frame;
            if ($opcode >= self::CLOSE) {
                $messages[] = [$opcode, $payload];

                continue;
            }
            if (self::CONTINUATION !== $opcode) {
                $this->opcode = $opcode;
                $this->fragments = '';
            }
            if (null === $this->opcode || (\strlen($this->fragments) + \strlen($payload)) > $this->maxMessage) {
                ($this->onError)(null === $this->opcode ? 'a continuation of nothing' : 'a message too long');
                $this->buffer = '';

                return $messages;
            }
            $this->fragments .= $payload;
            if ($final) {
                $messages[] = [$this->opcode, $this->fragments];
                $this->opcode = null;
                $this->fragments = '';
            }
        }

        return $messages;
    }

    /** @return array{bool, int, string}|null the next whole frame in the buffer, taken out of it */
    private function frame(): ?array
    {
        $header = $this->header();
        if (null !== $header && $header[1] > $this->maxMessage) {
            ($this->onError)('a message too long');
            $this->buffer = '';
            $header = null;
        }

        return null === $header ? null : $this->payload($header[0], $header[1]);
    }

    /** @return array{int, int}|null where the next frame's mask or payload starts, and the payload's length — once the buffer has that much */
    private function header(): ?array
    {
        if (\strlen($this->buffer) < 2) {
            return null;
        }
        $second = \ord($this->buffer[1]);
        // The length is in the second byte, or in the two or eight after it.
        $extended = match ($second & 0x7F) {
            126 => 2,
            127 => 8,
            default => 0,
        };
        $offset = 2 + $extended;

        $length = match (true) {
            \strlen($this->buffer) < $offset => null,
            2 === $extended => (int) \unpack('n', $this->buffer, offset: 2)[1],
            8 === $extended => (int) \unpack('J', $this->buffer, offset: 2)[1],
            default => $second & 0x7F,
        };

        return null === $length ? null : [$offset, $length];
    }

    /** @return array{bool, int, string}|null the frame whose header ends at $offset, taken out of the buffer once it is all in */
    private function payload(int $offset, int $length): ?array
    {
        $first = \ord($this->buffer[0]);
        $second = \ord($this->buffer[1]);
        $mask = 0 === ($second & 0x80) ? '' : \substr($this->buffer, $offset, length: 4);
        $offset += \strlen($mask);
        if (\strlen($this->buffer) < ($offset + $length)) {
            return null;
        }
        $payload = \substr($this->buffer, $offset, $length);
        $this->buffer = \substr($this->buffer, $offset + $length);
        if ('' !== $mask) {
            $payload ^= \str_pad('', $length, $mask);
        }

        return [0 !== ($first & 0x80), $first & 0x0F, $payload];
    }
}
