<?php

namespace Corpus\Smells;

class Suppression
{
    public function read(string $path): string
    {
        return @file_get_contents($path) ?: '';
    }

    /**
     * @qmx-ignore code-smell.eval — the corpus needs one deliberately suppressed finding
     */
    public function evaluate(string $code): void
    {
        eval($code);
    }
}
