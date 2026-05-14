<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureTemplateSample\Module\Order\Domain;

final class Order
{
    public function __construct(public string $orderId) {}
}
