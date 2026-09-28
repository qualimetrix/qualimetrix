<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document\Resolved;

use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedMapInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;

/**
 * A map — schema keys or names — with the layers that wrote into it. Keys are
 * canonical for a schema map and as written for a named map.
 */
final readonly class ResolvedMap implements ResolvedMapInterface
{
    /**
     * @param array<string, ResolvedValueInterface> $entries never empty: a map nothing was written into is absent
     * @param non-empty-list<Provenance> $writers this map's own spot in each contributing layer
     */
    public function __construct(
        private array $entries,
        private array $writers,
    ) {}

    public function get(string $key): ?ResolvedValueInterface
    {
        return $this->entries[$key] ?? null;
    }

    /** @return array<string, ResolvedValueInterface> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return array<string, mixed> */
    public function plain(): array
    {
        return array_map(static fn(ResolvedValueInterface $value): mixed => $value->plain(), $this->entries);
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
