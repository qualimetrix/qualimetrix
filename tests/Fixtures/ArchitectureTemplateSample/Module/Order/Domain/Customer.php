<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureTemplateSample\Module\Order\Domain;

use Fixtures\ArchitectureTemplateSample\Shared\Logger;

final class Customer
{
    public function __construct(private Logger $logger) {}
}
