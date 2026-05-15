<?php

declare(strict_types=1);

namespace Fixtures\CaptureBindingSample\Module\Order\App;

use Fixtures\CaptureBindingSample\Module\Inventory\Domain\Stock;
use Fixtures\CaptureBindingSample\Module\Order\Domain\Order;

final class OrderController
{
    public function __construct(
        private Order $order,
        private Stock $stock,
    ) {}
}
