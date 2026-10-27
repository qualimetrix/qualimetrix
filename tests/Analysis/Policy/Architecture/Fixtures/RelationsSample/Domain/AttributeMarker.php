<?php

declare(strict_types=1);

namespace Fixtures\RelationsSample\Domain;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class AttributeMarker
{
    public function __construct(public readonly string $subject) {}
}
