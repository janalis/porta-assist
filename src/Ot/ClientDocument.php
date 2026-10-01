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
 */
final class ClientDocument
{
    /** Sent, not yet acknowledged. */
    private ?TextOperation $outstanding = null;

    /** Made while one was outstanding; sent when it is acknowledged. */
    private ?TextOperation $buffer = null;

    public function __construct(
        public private(set) int $revision,
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
        $this->outstanding = $this->buffer;
        $this->buffer = null;

        return $this->outstanding;
    }

    /** A change from the host, as it must be applied to the text here. */
    public function remote(TextOperation $operation): TextOperation
    {
        ++$this->revision;
        if (null !== $this->outstanding) {
            [$this->outstanding, $operation] = TextOperation::transform($this->outstanding, $operation);
        }
        if (null !== $this->buffer) {
            [$this->buffer, $operation] = TextOperation::transform($this->buffer, $operation);
        }

        return $operation;
    }
}
