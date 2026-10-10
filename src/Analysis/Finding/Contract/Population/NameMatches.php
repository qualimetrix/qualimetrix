<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Population;

use LogicException;

final readonly class NameMatches implements GatePredicate
{
    public function __construct(public string $source, public ?bool $activeWhen = null)
    {
        if ($source === '') {
            throw new LogicException('A bound population name requires its declaring source.');
        }
    }

    public function evaluate(GateInput $input): ?string
    {
        $input->requireVariant('bound-name', $this->source);
        if (!$input->active($this->activeWhen)) {
            return null;
        }
        return ($input->scalar() ?? throw new LogicException('Missing bound population name.')) === true ? null : 'Name is outside the declared population.';
    }
}
