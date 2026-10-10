<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document\Resolved;

use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedWriteHistoryInterface;

/** A scalar leaf and the one layer that won it. */
final readonly class ResolvedScalar implements ResolvedWriteHistoryInterface
{
    /** @param ?non-empty-list<array{provenance: Provenance, value: int|float|string|bool}> $history */
    public function __construct(
        public int|float|string|bool $value,
        public Provenance $provenance,
        private ?array $history = null,
    ) {}

    public function writes(): array
    {
        return $this->history ?? [['provenance' => $this->provenance, 'value' => $this->value]];
    }

    public function plain(): int|float|string|bool
    {
        return $this->value;
    }

    public function contributors(): array
    {
        return [$this->provenance];
    }

    public function refuse(string $summary): never
    {
        throw Provenance::refusalOf([$this->provenance], $summary);
    }
}
