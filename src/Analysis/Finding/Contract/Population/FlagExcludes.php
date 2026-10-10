<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Population;

use LogicException;

final readonly class FlagExcludes implements GatePredicate
{
    public function __construct(
        public string $source,
        public ?string $key,
        public int|float|bool $forbidden = 1,
        public ?bool $activeWhen = null,
        public bool $nonzero = false,
    ) {
        if ($source === '' || $key === '' || !is_finite((float) $forbidden)) {
            throw new LogicException('Invalid declared population flag.');
        }
    }

    public function evaluate(GateInput $input): ?string
    {
        $input->requireVariant($this->key === null ? 'flag' : 'metrics', $this->source);
        if (!$input->active($this->activeWhen)) {
            return null;
        }
        $value = $this->key === null ? $input->scalar() : ($input->metricBag())->get($this->key);
        if ($value !== null && !is_finite((float) $value)) {
            throw new LogicException('Population flag must be finite.');
        }
        return $this->excludes($value) ? 'Excluded by the declared population flag.' : null;
    }
    /** @qmx-ignore code-smell.boolean-argument -- The boolean is a measured flag operand compared to the declared value, not an execution mode. */
    private function excludes(int|float|bool|null $value): bool
    {
        return $this->nonzero ? $value !== null && $value !== 0 : $value === $this->forbidden;
    }
}
