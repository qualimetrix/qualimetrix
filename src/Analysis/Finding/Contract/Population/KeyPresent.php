<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Population;

use LogicException;

final readonly class KeyPresent implements GatePredicate
{
    /** @param non-empty-list<string>|non-empty-array<string, string> $keys */
    public function __construct(public string $source, public array $keys, public ?bool $activeWhen = null)
    {
        if ($source === '' || $keys === [] || \in_array('', $keys, true) || \count(array_unique($keys)) !== \count($keys)) {
            throw new LogicException('Presence requires distinct nonempty declared keys.');
        }
    }

    public function evaluate(GateInput $input): ?string
    {
        $input->requireVariant('metrics', $this->source);
        if (!$input->active($this->activeWhen)) {
            return null;
        }
        if ($input->selector !== null) {
            $key = $this->keys[$input->selector] ?? throw new LogicException('Undeclared population key alternative.');
        } elseif (\count($this->keys) === 1) {
            $key = array_values($this->keys)[0];
        } else {
            throw new LogicException('Population key alternatives require the declared selector.');
        }
        $bag = $input->metricBag();
        $value = $bag->get($key);
        if ($value !== null && !is_finite((float) $value)) {
            throw new LogicException('Measured population value must be finite.');
        }
        return $value === null ? 'Missing metric "' . $key . '".' : null;
    }
}
