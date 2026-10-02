<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Ot;

/**
 * A guest's side of one shared text: at most ONE change in flight to the
 * host, the ones made meanwhile composed into a buffer that goes when it is
 * acknowledged — the ot.js client's three states, as two nullable fields.
 *
 * Changes from the host are transformed against what is in flight and
 * buffered, so they apply to the text the guest sees; what is in flight and
 * buffered is transformed against them, so it still applies to the host's.
 *
 * The text the HOST has, as far as this side knows, is kept too: what is in
 * flight and buffered applies to it. A connection that drops takes the
 * acknowledgements with it, and that text is what what was typed here is
 * carried over onto — {@see carry()} — when the guest is back.
 */
final class ClientDocument
{
    /** Sent, not yet acknowledged. */
    private ?TextOperation $outstanding = null;

    /** Made while one was outstanding; sent when it is acknowledged. */
    private ?TextOperation $buffer = null;

    /**
     * @param string $acknowledged the host's text at $revision
     */
    public function __construct(
        public private(set) int $revision,
        private string $acknowledged,
    ) {}

    /** Whether a change of this guest's has not reached the host yet. */
    public function isPending(): bool
    {
        return null !== $this->outstanding;
    }

    /** A change made here: what to send now — null while one is still in flight. */
    public function local(TextOperation $operation): ?TextOperation
    {
        if (null === $this->outstanding) {
            $this->outstanding = $operation;

            return $operation;
        }
        $this->buffer = null === $this->buffer ? $operation : $this->buffer->compose($operation);

        return null;
    }

    /** The host applied the change in flight: the buffered one, if any, goes next. */
    public function acknowledged(): ?TextOperation
    {
        ++$this->revision;
        if (null !== $this->outstanding) {
            $this->acknowledged = $this->outstanding->apply($this->acknowledged);
        }
        $this->outstanding = $this->buffer;
        $this->buffer = null;

        return $this->outstanding;
    }

    /** A change from the host, as it must be applied to the text here. */
    public function remote(TextOperation $operation): TextOperation
    {
        ++$this->revision;
        $this->acknowledged = $operation->apply($this->acknowledged);
        if (null !== $this->outstanding) {
            [$this->outstanding, $operation] = TextOperation::transform($this->outstanding, $operation);
        }
        if (null !== $this->buffer) {
            [$this->buffer, $operation] = TextOperation::transform($this->buffer, $operation);
        }

        return $operation;
    }

    /**
     * What was typed here and never acknowledged, carried onto $host — the
     * host's text as it is now, found again after a dropped connection —
     * as the change to make to it; null when there is nothing to carry.
     *
     * The host may have applied what was in flight and lost only its
     * acknowledgement: then only what was buffered is left. Whatever else
     * the host changed meanwhile, the rest is transformed over it.
     */
    public function carry(string $host): ?TextOperation
    {
        $base = $this->acknowledged;
        $pending = $this->outstanding;
        if (null !== $pending && $pending->apply($base) === $host) {
            $base = $host;
            $pending = null;
        }
        if (null !== $this->buffer) {
            $pending = null === $pending ? $this->buffer : $pending->compose($this->buffer);
        }
        if (null === $pending) {
            return null;
        }
        [$carried] = TextOperation::transform($pending, TextDiff::between($base, $host));

        return $carried->isNoop() ? null : $carried;
    }
}
