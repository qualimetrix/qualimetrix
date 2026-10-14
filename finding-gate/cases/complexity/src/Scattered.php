<?php

namespace Corpus\Complexity;

function describeMode(int $mode): string
{
    return match ($mode) {
        1 => 'strict',
        2 => 'lenient',
        default => 'unknown',
    };
}

class Scattered
{
    private int $counter = 0;
    private string $label = '';
    private array $bucket = [];

    public function bumpCounter(): int
    {
        return ++$this->counter;
    }

    public function relabel(string $label): void
    {
        $this->label = $label;
    }

    public function collect(string $item): void
    {
        $this->bucket[] = $item;
    }

    public function unrelated(): string
    {
        return date('Y');
    }

    public function coalesceChoices(array $choices): int
    {
        return ($choices['first'] ?? 0)
            + ($choices['second'] ?? 0)
            + ($choices['third'] ?? 0)
            + ($choices['fourth'] ?? 0);
    }
}
