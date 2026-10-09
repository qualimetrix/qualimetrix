<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Population;

use LogicException;

final readonly class RuleValueThreshold implements GatePredicate
{
    public function __construct(public string $source, public string $comparison, public int|float|string $boundary, public bool $nonpositiveBypasses = false)
    {
        if (!\in_array($comparison, ['<', '<=', '>', '>='], true)
            || $source === '' || (\is_string($boundary) ? $boundary !== $source : !is_finite((float) $boundary))) {
            throw new LogicException('Invalid declared rule count threshold.');
        }
    }

    public function evaluate(GateInput $input): ?string
    {
        $input->requireVariant('rule-number', $this->source);
        $boundary = $input->effectiveBoundary($this->boundary);
        if ($this->nonpositiveBypasses && $boundary <= 0) {
            return null;
        }
        $value = $input->number ?? throw new LogicException('Missing declared rule count.');
        if ($value < 0) {
            throw new LogicException('A rule population count cannot be negative.');
        }
        return KeyThreshold::compare($value, $this->comparison, $boundary) ? null : 'Rule count population boundary was not met.';
    }
}
