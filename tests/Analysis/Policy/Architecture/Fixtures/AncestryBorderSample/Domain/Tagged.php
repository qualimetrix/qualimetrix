<?php

declare(strict_types=1);

namespace Fixtures\AncestryBorderSample\Domain;

use AllowDynamicProperties;
use Fixtures\AncestryBorderSample\Infra\Db;

#[AllowDynamicProperties]
final class Tagged
{
    public function __construct(public Db $db) {}
}
