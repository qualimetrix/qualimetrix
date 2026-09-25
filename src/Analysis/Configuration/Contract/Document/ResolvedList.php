<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

/**
 * A replaced list — one writer — or an accumulated set — every layer that
 * added to it. Each item keeps the provenance of the layer that wrote it.
 */
final readonly class ResolvedList implements ResolvedValueInterface
{
    /**
     * @param list<ResolvedValueInterface> $items
     * @param non-empty-list<Provenance> $writers
     */
    public function __construct(
        private array $items,
        private array $writers,
    ) {}

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

    public function refusal(string $summary): ConfigurationRefusal
    {
        return Provenance::refusalOf($this->writers, $summary);
    }
}
