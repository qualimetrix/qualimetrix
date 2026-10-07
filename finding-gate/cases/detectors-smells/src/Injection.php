<?php

namespace Corpus\Smells;

class Injection
{
    public function __construct(
        private string $a,
        private string $b,
        private string $c,
        private string $d,
        private string $e,
        private string $f,
        private string $g,
        private string $h,
        private string $i,
    ) {}

    public function render(bool $pretty, string $title, string $body, string $footer, string $locale): string
    {
        return $pretty ? $title . $body . $footer . $locale : $title;
    }
}
