<?php

declare(strict_types=1);

namespace Fixtures\CaptureBindingSample\Module\Inventory\App;

use Fixtures\CaptureBindingSample\Module\Inventory\Domain\Stock;

final class InventoryController
{
    public function __construct(private Stock $stock) {}
}
