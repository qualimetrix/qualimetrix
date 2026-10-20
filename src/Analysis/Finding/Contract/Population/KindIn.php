<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Population;

use LogicException;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\SymbolType;

final readonly class KindIn implements GatePredicate
{
    /** @param non-empty-list<ClassType>|non-empty-list<SymbolType> $kinds */
    public function __construct(public string $source, public array $kinds)
    {
        if ($source === '' || $kinds === []) {
            throw new LogicException('A population kind requires one explicit enum domain.');
        }
        $seen = [];
        foreach ($kinds as $kind) {
            if (!$kind instanceof ClassType && !$kind instanceof SymbolType) {
                throw new LogicException('Population kind must use a neutral class or symbol domain.');
            }
            if ($kind::class !== $kinds[0]::class || isset($seen[$kind->value])) {
                throw new LogicException('Population kinds must be distinct in one enum domain.');
            }
            $seen[$kind->value] = true;
        }
    }

    public function evaluate(GateInput $input): ?string
    {
        $input->requireVariant('kind', $this->source);
        if (($input->kindValue())::class !== $this->kinds[0]::class) {
            throw new LogicException('Population kind has the wrong enum domain.');
        }
        return \in_array($input->kindValue(), $this->kinds, true) ? null : 'Declaration kind is outside the declared population.';
    }
}
