<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Population;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Core\Symbol\SymbolLevel;

final readonly class PopulationGate
{
    public function __construct(
        public string $id,
        public FindingChannel $channel,
        public SymbolLevel $level,
        public string $unit,
        public GatePredicate $predicate,
        public string $reason,
    ) {
        if ($id === '' || trim($reason) === '' || !PopulationIdentity::knowsUnit($unit)
            || !\in_array($predicate::class, [KeyPresent::class, KeyThreshold::class, FlagExcludes::class, KindIn::class, NameMatches::class, RuleValueThreshold::class, ContextGuard::class], true)) {
            throw new LogicException('Invalid closed population declaration.');
        }
    }

    public function evaluate(GateInput $input): ?string
    {
        $failure = $this->predicate->evaluate($input);
        return $failure === null ? null : $this->reason . ' ' . $failure;
    }
}
