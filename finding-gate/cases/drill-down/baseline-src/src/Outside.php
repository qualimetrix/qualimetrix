<?php

declare(strict_types=1);

namespace Other;

final class Outside
{
    public function run(): void
    {
        eval('2;');
    }
}
