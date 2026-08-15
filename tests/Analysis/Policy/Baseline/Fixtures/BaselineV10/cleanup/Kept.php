<?php

declare(strict_types=1);

namespace BaselineFixture\Cleanup;

final class Kept
{
    public function execute(): void
    {
        goto completed;
        completed:
    }
}
