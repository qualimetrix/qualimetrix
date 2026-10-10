<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use InvalidArgumentException;

final class RuleOptionRefusal extends InvalidArgumentException
{
    /**
     * @param list<string> $optionPath
     * @param array{warning: array{path: list<string>|null, value: int|float}, error: array{path: list<string>|null, value: int|float}}|array{} $bandValues
     */
    public function __construct(public readonly array $optionPath, string $summary, public readonly array $bandValues = [])
    {
        parent::__construct($summary);
    }

    public function under(string $level): self
    {
        $bandValues = $this->bandValues;
        foreach ($bandValues as $role => $half) {
            if ($half['path'] !== null) {
                $bandValues[$role]['path'] = [$level, ...$half['path']];
            }
        }
        return new self([$level, ...$this->optionPath], $this->getMessage(), $bandValues);
    }
}
