<?php

declare(strict_types=1);

namespace Fixtures\ExcludeSample\Module\Cache\Domain\Generated;

use Fixtures\ExcludeSample\Marker\Marker;

/**
 * The Cache module exists in the codebase only as Generated/ classes. Under
 * the {@code module-{m}} template's exclude clause filtering the Generated
 * subtree, every Cache candidate is excluded — so M1 (Phase 5.1) drops the
 * `m=Cache` tuple during observation and no {@code module-Cache} concrete
 * layer is produced. Without M1, the layer would be produced and surface
 * as an {@code architecture.unreachable-layer} diagnostic at runtime.
 */
final class CacheProxy
{
    public function __construct(public Marker $marker) {}
}
