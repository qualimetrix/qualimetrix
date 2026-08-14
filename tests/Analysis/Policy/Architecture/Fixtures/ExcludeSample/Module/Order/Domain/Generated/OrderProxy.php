<?php

declare(strict_types=1);

namespace Fixtures\ExcludeSample\Module\Order\Domain\Generated;

use Fixtures\ExcludeSample\Marker\Marker;

/**
 * Per-instance exclude target: the {@code module-Order} template's exclude
 * clause filters out the {@code Generated} subtree, so this class is
 * unassigned despite matching the positive {@code App\Module\Order\**}
 * pattern.
 */
final class OrderProxy
{
    public function __construct(public Marker $marker) {}
}
