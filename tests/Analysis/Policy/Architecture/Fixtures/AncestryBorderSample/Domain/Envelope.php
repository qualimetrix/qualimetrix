<?php

declare(strict_types=1);

namespace Fixtures\AncestryBorderSample\Domain;

use Fixtures\AncestryBorderSample\Infra\Db;

final class Envelope implements \Vendor\Contract\Thing
{
    public function __construct(public Db $db) {}
}
