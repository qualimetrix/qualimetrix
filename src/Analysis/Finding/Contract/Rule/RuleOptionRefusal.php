<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use InvalidArgumentException;

final class RuleOptionRefusal extends InvalidArgumentException
{
    /** @param list<string> $optionPath */
    public function __construct(public readonly array $optionPath, string $summary)
    {
        parent::__construct($summary);
    }
    public function under(string $level): self
    {
        return new self([$level, ...$this->optionPath], $this->getMessage());
    }
}
