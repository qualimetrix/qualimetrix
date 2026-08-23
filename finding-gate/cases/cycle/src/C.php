<?php

namespace Corpus\Cycle;

class C
{
    public function label(): string
    {
        return 'c';
    }

    public function make(): A
    {
        return new A(new B(new C()));
    }
}
