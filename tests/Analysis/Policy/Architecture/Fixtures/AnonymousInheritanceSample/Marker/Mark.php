<?php

declare(strict_types=1);

namespace Fixtures\AnonymousInheritanceSample\Marker;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Mark {}
