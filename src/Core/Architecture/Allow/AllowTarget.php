<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Allow;

use Qualimetrix\Core\Dependency\DependencyType;

/**
 * One target on the right-hand side of an {@code architecture.allow} entry.
 *
 * Carries the target's {@see LayerSelector} plus two optional fields:
 *
 * - {@code $relations} — null by default (no relation filter). Step G
 *   ({@code relations: [method_call, ...]} long-form key) populates it with a
 *   non-empty list of {@see DependencyType} values; {@see LayerPolicy::isAllowed()}
 *   gains a {@code DependencyType} overload that consults this list.
 * - {@code $allowCrossInstance} — false by default. Set to true via the
 *   {@code allow_cross_instance: true} long-form key written on the **target
 *   entry** (not the source); the policy then substitutes an empty binding
 *   into the target's captured segments, letting captured template instances
 *   on the source side reach any other instance on the target side. Example
 *   YAML:
 *   {@code 'app-{m}': [{target: 'domain-{m}', allow_cross_instance: true}]}
 *   accepts {@code app-Order → domain-Inventory}. Two targets under the same
 *   source can independently opt in (each long-form map carries its own
 *   {@code allow_cross_instance} flag).
 *
 * The VO is immutable; later steps construct fresh instances rather than
 * mutating an existing one.
 */
final readonly class AllowTarget
{
    /**
     * @param list<DependencyType>|null $relations Optional whitelist of dependency
     *                                             types (null = "all relations").
     *                                             Wired in Step G.
     * @param bool $allowCrossInstance When true, the policy passes an empty
     *                                 binding into the target's
     *                                 {@see LayerSelector::matchesTarget()}
     *                                 call, allowing any same-shape target
     *                                 layer regardless of the source-side
     *                                 capture values.
     */
    public function __construct(
        public LayerSelector $target,
        public ?array $relations = null,
        public bool $allowCrossInstance = false,
    ) {}
}
