<?php

declare(strict_types=1);

namespace Layered\Domain;

use Layered\Infra\OrderWriter;

/** Depends downward on purpose: the layer policy must have something to judge. */
final class Order
{
    public function __construct(private readonly OrderWriter $writer) {}

    public function store(): void
    {
        $this->writer->write('order');
    }
}
