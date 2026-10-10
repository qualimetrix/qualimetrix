<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Selection;

use Qualimetrix\Analysis\Configuration\Contract\Document\ConfigurationDiagnostic;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\SelectionFilter;
use Qualimetrix\Analysis\Finding\Selection\EnablementIndex;

/** Decisions from authored statements, before options decide level activity. */
final readonly class StatedEnablement
{
    private EnablementIndex $index;

    /**
     * @param list<EnablementDecision> $decisions
     * @param list<ConfigurationDiagnostic> $diagnostics
     */
    public function __construct(array $decisions, private ?SelectionFilter $filter, private array $diagnostics = [])
    {
        $this->index = new EnablementIndex($decisions);
    }

    /** @return list<EnablementDecision> */
    public function decisions(): array
    {
        return $this->index->all();
    }

    /** @return list<ConfigurationDiagnostic> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function isEnabled(string $producer): bool
    {
        return $this->index->enabled($producer);
    }

    public function decisionFor(string $producer): EnablementDecision
    {
        return $this->index->firstForProducer($producer);
    }

    public function filter(): ?SelectionFilter
    {
        return $this->filter;
    }
}
