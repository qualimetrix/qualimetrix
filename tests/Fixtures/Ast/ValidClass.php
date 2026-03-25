<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Fixtures\Ast;

final class ValidClass
{
    private int $value;

    public function __construct(int $value)
    {
        $this->value = $value;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function calculate(int $multiplier): int
    {
        if ($multiplier > 0) {
            return $this->value * $multiplier;
        }

        return 0;
    }
}
