<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document\Resolved;

use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedListInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedWriteHistoryInterface;

/**
 * A replaced list — one writer — or an accumulated set — every layer that
 * added to it. Each item keeps the provenance of the layer that wrote it.
 */
final readonly class ResolvedList implements ResolvedListInterface, ResolvedWriteHistoryInterface
{
    /**
     * @param list<ResolvedValueInterface> $items
     * @param non-empty-list<Provenance> $writers
     * @param ?non-empty-list<array{provenance: Provenance, value: list<mixed>}> $history
     */
    public function __construct(
        private array $items,
        private array $writers,
        private ?array $history = null,
    ) {}

    public function writes(): array
    {
        return $this->history ?? [['provenance' => $this->writers[0], 'value' => $this->plain()]];
    }

    /** @return list<ResolvedValueInterface> */
    public function items(): array
    {
        return $this->items;
    }

    /** @return list<mixed> */
    public function plain(): array
    {
        return array_map(static fn(ResolvedValueInterface $value): mixed => $value->plain(), $this->items);
    }

    public function contributors(): array
    {
        return $this->writers;
    }

    public function refuse(string $summary): never
    {
        throw Provenance::refusalOf($this->writers, $summary);
    }
}
