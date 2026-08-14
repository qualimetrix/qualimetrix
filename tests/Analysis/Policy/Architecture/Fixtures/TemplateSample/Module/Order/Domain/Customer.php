<?php

declare(strict_types=1);

namespace Fixtures\TemplateSample\Module\Order\Domain;

use Fixtures\TemplateSample\Shared\Logger;

final class Customer
{
    public function __construct(private Logger $logger) {}
}
