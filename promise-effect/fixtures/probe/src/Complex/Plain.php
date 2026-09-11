<?php

declare(strict_types=1);

namespace Probe\Complex;

class Plain
{
    public int $one = 0;

    public int $two = 0;

    public int $three = 0;

    public function getOne(): int
    {
        return $this->one;
    }

    public function getTwo(): int
    {
        return $this->two;
    }

    public function getThree(): int
    {
        return $this->three;
    }
}
