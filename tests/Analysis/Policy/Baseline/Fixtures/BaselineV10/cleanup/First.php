<?php

declare(strict_types=1);

namespace BaselineFixture\Cleanup;

final class First
{
    public function execute(): void
    {
        goto completed;
        completed:
    }
}
