<?php

namespace Corpus\Cycle;

class A
{
    public function __construct(private B $b) {}

    public function label(): string
    {
        return 'a' . $this->b->label();
    }
}
