<?php

declare(strict_types=1);

namespace Fixtures\ExcludeSample\Service\Legacy;

use Fixtures\ExcludeSample\Marker\Marker;

/**
 * Sits in the {@code Service\Legacy} subtree that the exclude clause filters
 * out of the {@code service} layer. With exclude active, this class is
 * unassigned — depending on Marker yields no architecture violation.
 */
final class OldUserService
{
    public function __construct(public Marker $marker) {}
}
