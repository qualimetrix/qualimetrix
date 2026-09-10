<?php

declare(strict_types=1);

namespace Layered\Infra;

final class OrderWriter
{
    public function write(string $payload): void
    {
        error_log($payload);
    }
}
