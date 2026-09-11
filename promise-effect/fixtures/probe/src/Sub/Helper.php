<?php

declare(strict_types=1);

namespace Probe\Sub;

class Helper
{
    public function touch($what)
    {
        $x = @file_get_contents('/dev/null');

        return $what . $x;
    }
}
