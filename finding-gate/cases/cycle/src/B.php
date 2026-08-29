<?php

namespace Corpus\Cycle;

class B
{
    public function __construct(private C $c) {}

    public function label(): string
    {
        return 'b' . $this->c->label();
    }
}
