<?php

namespace Corpus\Complexity;

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
}
