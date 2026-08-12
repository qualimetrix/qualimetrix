<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Fixtures\ModularTopologySample\Coverage\Owned;

use Qualimetrix\Tests\Architecture\Fixtures\ModularTopologySample\Coverage\Uncovered\UncoveredEndpoint;

final readonly class OwnedConsumer
{
    public function __construct(private UncoveredEndpoint $endpoint) {}
}
