<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Ot;

/**
 * The host's copy of one shared text: the order every change is applied in.
 *
 * Each change gets the next revision. A guest's change says which revision it
 * was made on; whatever came in since is transformed into it, so it applies
 * to the text as it is now — and that transformed change is what everyone
 * else is sent. Only the operations are kept, not the texts between them.
 */
final class ServerDocument
{
    /** @var list<TextOperation> every change so far, revision n at index n */
    private array $log = [];

    public function __construct(
        public private(set) string $text,
    ) {}

    public function revision(): int
    {
        return \count($this->log);
    }

    /**
     * A change made on $revision, rewritten for the text as it is now and
     * applied: what to send everyone, and to apply to the host's buffer.
     *
     * @throws \InvalidArgumentException when $revision is not one this document had, or the change does not fit it
     */
    public function receive(int $revision, TextOperation $operation): TextOperation
    {
        if ($revision < 0 || $revision > $this->revision()) {
            throw new \InvalidArgumentException(\sprintf('revision %d is not one of 0..%d', $revision, $this->revision()));
        }
        foreach (\array_slice($this->log, $revision) as $concurrent) {
            [$operation] = TextOperation::transform($operation, $concurrent);
        }
        $this->text = $operation->apply($this->text);
        $this->log[] = $operation;

        return $operation;
    }
}
