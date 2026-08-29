<?php

namespace Corpus\OnlyRules;

class Statements
{
    public function run(string $input): void
    {
        eval('1;');
        goto finish;
        finish:
        echo $input;
    }
}
