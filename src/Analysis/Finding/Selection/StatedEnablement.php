<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Selection;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\SelectionFilter;

/** Decisions from authored statements, before options decide level activity. */
final readonly class StatedEnablement
{
    /** @param list<EnablementDecision> $decisions */
    public function __construct(private array $decisions, private ?SelectionFilter $filter) {}

    /** @return list<EnablementDecision> */
    public function decisions(): array
    {
        return $this->decisions;
    }

    public function isEnabled(string $producer): bool
    {
        foreach ($this->decisions as $decision) {
            if ($decision->producer === $producer && $decision->on) {
                return true;
            }
        }

        return false;
    }

    public function decisionFor(string $producer): EnablementDecision
    {
        foreach ($this->decisions as $decision) {
            if ($decision->producer === $producer) {
                return $decision;
            }
        }

        throw new LogicException(\sprintf('No declared cell for producer "%s".', $producer));
    }

    public function filter(): ?SelectionFilter
    {
        return $this->filter;
    }
}
