<?php

declare(strict_types=1);

namespace Fixtures\AncestryBorderSample\Domain;

use Fixtures\AncestryBorderSample\Infra\Db;
use RuntimeException;

final class OrderFailed extends RuntimeException
{
    public function __construct(public Db $db)
    {
        parent::__construct('order failed');
    }
}
