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
        $value = $this->key === null ? $input->flag : ($input->bag ?? throw new LogicException('Metrics input has no bag.'))->get($this->key);
        if ($value !== null && !is_finite((float) $value)) {
            throw new LogicException('Population flag must be finite.');
        }
        $excluded = $this->nonzero ? $value !== null && $value !== 0 : $value === $this->forbidden;
        return $excluded ? 'Excluded by the declared population flag.' : null;
    }
}
