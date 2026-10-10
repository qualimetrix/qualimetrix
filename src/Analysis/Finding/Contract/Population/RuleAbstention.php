<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Population;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Core\Symbol\SymbolLevel;

final readonly class RuleAbstention
{
    /**
     * @param list<string> $examples
     *
     * @qmx-threshold code-smell.constructor-overinjection warning=9 error=9 -- Eight independent immutable observations describe one compact abstention group. Splitting them would relocate the same transport record; the exclusive boundary keeps the next field visible.
     * @qmx-threshold code-smell.long-parameter-list warning=9 error=9 -- Eight independent immutable observations describe one compact abstention group. Splitting them would relocate the same transport record; the exclusive boundary keeps the next field visible.
     */
    public function __construct(
        public string $producer,
        public FindingChannel $channel,
        public SymbolLevel $level,
        public string $gate,
        public string $reason,
        public string $unit,
        public int $count,
        public array $examples,
    ) {
        $canonical = array_values(array_unique($examples));
        sort($canonical, \SORT_STRING);
        if ($producer === '' || $gate === '' || $reason === '' || $count <= 0
            || !PopulationIdentity::knowsUnit($unit) || \count($examples) > min(5, $count) || $examples !== $canonical) {
            throw new LogicException('Invalid compact population abstention.');
        }
    }
}
