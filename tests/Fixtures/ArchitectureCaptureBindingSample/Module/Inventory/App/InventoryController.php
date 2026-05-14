<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureCaptureBindingSample\Module\Inventory\App;

use Fixtures\ArchitectureCaptureBindingSample\Module\Inventory\Domain\Stock;

final class InventoryController
{
    public function __construct(private Stock $stock) {}
}
