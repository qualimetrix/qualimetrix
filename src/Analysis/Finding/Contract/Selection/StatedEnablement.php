<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Selection;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ConfigurationDiagnostic;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\SelectionFilter;

/** Decisions from authored statements, before options decide level activity. */
final readonly class StatedEnablement
{
    /**
     * @param list<EnablementDecision> $decisions
     * @param list<ConfigurationDiagnostic> $diagnostics
     */
    public function __construct(private array $decisions, private ?SelectionFilter $filter, private array $diagnostics = []) {}

    /** @return list<EnablementDecision> */
    public function decisions(): array
    {
        return $this->decisions;
    }

    /** @return list<ConfigurationDiagnostic> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
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
