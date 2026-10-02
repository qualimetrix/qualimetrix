<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract\Directive;

use InvalidArgumentException;
use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Finding;

/** The part of a declaration an authored directive can reach. */
final readonly class DeclarationReach
{
    private function __construct(
        private ?int $startLine,
        private ?int $endLine,
        public string $standsOn,
    ) {}

    public static function whole(?int $endLine, string $standsOn): self
    {
        return new self(null, $endLine, $standsOn);
    }

    public static function lines(int $start, int $end, string $standsOn): self
    {
        if ($start < 1 || $start > $end) {
            throw new InvalidArgumentException('A declaration line reach requires a positive, ordered range');
        }

        return new self($start, $end, $standsOn);
    }

    public function covers(Finding $finding, string $authoredIn): bool
    {
        if ($this->startLine === null) {
            return true;
        }

        $line = $finding->location->line;

        return $finding->location->pathString() === $authoredIn
            && $line !== null && $line >= $this->startLine && $line <= $this->lineEnd();
    }

    /** Stable grouping key; the human-readable declaration label is deliberately excluded. */
    public function key(): string
    {
        return $this->startLine === null
            ? 'whole:' . ($this->endLine ?? 'unknown')
            : \sprintf('lines:%d:%d', $this->startLine, $this->lineEnd());
    }

    public function describe(): string
    {
        return $this->startLine === null
            ? $this->standsOn
            : \sprintf('%s, lines %d–%d', $this->standsOn, $this->startLine, $this->lineEnd());
    }

    private function lineEnd(): int
    {
        return $this->endLine ?? throw new LogicException('A line reach requires an end line');
    }
}
