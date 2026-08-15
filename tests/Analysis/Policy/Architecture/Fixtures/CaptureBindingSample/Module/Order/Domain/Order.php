<?php

declare(strict_types=1);

namespace Fixtures\CaptureBindingSample\Module\Order\Domain;

final class Order
{
    public function __construct(public string $orderId) {}
}
