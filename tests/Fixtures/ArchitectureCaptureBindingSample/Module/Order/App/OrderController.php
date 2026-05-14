<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureCaptureBindingSample\Module\Order\App;

use Fixtures\ArchitectureCaptureBindingSample\Module\Inventory\Domain\Stock;
use Fixtures\ArchitectureCaptureBindingSample\Module\Order\Domain\Order;

final class OrderController
{
    public function __construct(
        private Order $order,
        private Stock $stock,
    ) {}
}
