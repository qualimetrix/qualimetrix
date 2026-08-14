<?php

declare(strict_types=1);

namespace BaselineFixture\Cleanup;

final class Second
{
    public function execute(): void
    {
        goto completed;
        completed:
    }
}
