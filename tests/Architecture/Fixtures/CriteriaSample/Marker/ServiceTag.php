<?php

declare(strict_types=1);

namespace Fixtures\CriteriaSample\Marker;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class ServiceTag
{
    public function __construct(public readonly string $name = '') {}
}
