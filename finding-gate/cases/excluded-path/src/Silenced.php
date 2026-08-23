<?php

namespace Corpus\ExcludedPath;

class Silenced
{
    public function run(string $input): void
    {
        goto finish;
        finish:
        echo $input;
    }
}
