<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Selection;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Core\Symbol\SymbolLevel;

/** Ordered cell lookups by producer and channel, retaining nullable-level wildcards. */
final readonly class EnablementIndex
{
    /** @var array<string, list<EnablementDecision>> */
    private array $byProducer;
    /** @var array<string, list<EnablementDecision>> */
    private array $byChannel;

    /** @param list<EnablementDecision> $cells */
    public function __construct(private array $cells)
    {
        $byProducer = [];
        $byChannel = [];
        foreach ($cells as $cell) {
            $byProducer[$cell->producer][] = $cell;
            $byChannel[$cell->channel->code][] = $cell;
        }
        $this->byProducer = $byProducer;
        $this->byChannel = $byChannel;
    }

    /** @return list<EnablementDecision> */
    public function all(): array
    {
        return $this->cells;
    }

    /** @return list<string> */
    public function producers(): array
    {
        return array_keys($this->byProducer);
    }

    /** @return list<EnablementDecision> */
    public function producerCells(string $producer): array
    {
        return $this->byProducer[$producer] ?? [];
    }

    /** @return list<EnablementDecision> */
    public function channelCells(FindingChannel $channel, ?SymbolLevel $level): array
    {
        return array_values(array_filter(
            $this->byChannel[$channel->code] ?? [],
            static fn(EnablementDecision $cell): bool => $level === null || $cell->level === null || $cell->level === $level,
        ));
    }

    public function firstForProducer(string $producer): EnablementDecision
    {
        return $this->producerCells($producer)[0]
            ?? throw new LogicException(\sprintf('No declared cell for producer "%s".', $producer));
    }

    public function enabled(string $producer): bool
    {
        return array_any($this->producerCells($producer), static fn(EnablementDecision $cell): bool => $cell->on);
    }

    public function direct(string $producer): bool
    {
        return array_any($this->producerCells($producer), static fn(EnablementDecision $cell): bool => $cell->direct);
    }
}
