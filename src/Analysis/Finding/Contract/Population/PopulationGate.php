<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Population;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Channel\FindingChannel;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * @qmx-threshold coupling.cbo 26 -- Population admission carries the exact predicate vocabulary and its evaluated inputs; separating that vocabulary would duplicate the same judgement contract.
 */
final readonly class PopulationGate
{
    public function __construct(
        public string $id,
        public FindingChannel $channel,
        public SymbolLevel $level,
        public string $unit,
        public GatePredicate $predicate,
        public string $reason,
        public ?string $failureUnit = null,
    ) {
        if ($id === '' || trim($reason) === '' || !PopulationIdentity::knowsUnit($unit)
            || !\in_array($predicate::class, [KeyPresent::class, KeyThreshold::class, FlagExcludes::class, KindIn::class, NameMatches::class, RuleValueThreshold::class, ContextGuard::class], true)) {
            throw new LogicException('Invalid closed population declaration.');
        }
        $this->assertFailureUnit();
    }

    private function assertFailureUnit(): void
    {
        if ($this->failureUnit !== null && ($this->failureUnit !== 'invocation' || $this->unit === 'invocation'
            || !$this->predicate instanceof ContextGuard
            || !\in_array($this->predicate->source, ['graphAvailable', 'preparedEvidenceAvailable'], true))) {
            throw new LogicException('Only graph or prepared-evidence absence can override a member failure to invocation.');
        }
    }

    public function isDataGap(GateInput $input): bool
    {
        return match (true) {
            $this->predicate instanceof KeyPresent => !array_any(array_keys($this->predicate->absenceBoundaries), fn(string $key): bool => $input->metricBag()->get($key) === $this->predicate->absenceBoundaries[$key]),
            $this->predicate instanceof KeyThreshold => $this->predicate->missing !== 'zero' && array_any($this->predicate->keys, static fn(string $key): bool => $input->metricBag()->get($key) === null),
            $this->predicate instanceof ContextGuard => !\in_array($this->predicate->source, ['edgeSourceAssigned', 'edgeTargetAssigned', 'callableHasClassContext', 'excludeClauseActive', 'namespaceOwnCountExpected', 'configuredValueWrittenHere'], true),
            default => false,
        };
    }

    public function evaluate(GateInput $input): ?string
    {
        $failure = $this->predicate->evaluate($input);
        return $failure === null ? null : $this->reason . ' ' . $failure;
    }
}
