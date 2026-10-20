<?php

namespace Corpus\ThresholdRaising;

/** @qmx-threshold design.god-class warning=4 */
final class GodRetuned
{
    private int $left = 1;
    private int $middle = 2;
    private int $right = 3;

    public function left(): int { return $this->left + 10; }
    public function middle(): int { return $this->middle + 20; }
    public function right(): int { return $this->right + 30; }
}

final class GodNeighbour
{
    private int $one = 4;
    private int $two = 5;
    private int $three = 6;

    public function one(): int { return $this->one * 2; }
    public function two(): int { return $this->two * 3; }
    public function three(): int { return $this->three * 4; }
}

/** @qmx-threshold design.data-class warning=50 error=10 */
final class DataRetuned
{
    public int $value = 1;

    public function getValue(): int { return $this->value; }
    public function getLabel(): string { return 'label'; }
    public function advance(): int { return $this->value + 1; }
}

final class DataNeighbour
{
    public int $value = 2;

    public function getValue(): int { return $this->value; }
    public function getLabel(): string { return 'other'; }
    public function advance(): int { return $this->value + 2; }
}
