<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Allow;

use Qualimetrix\Core\Dependency\DependencyType;

/**
 * One target on the right-hand side of an {@code architecture.allow} entry.
 *
 * Carries the target's {@see LayerSelector} plus two forward-looking optional
 * fields placeholder-wired here for Step C and exercised by later steps:
 *
 * - {@code $relations} — null in Step C (no relation filter). Step G ({@code
 *   relations: [method_call, ...]}) populates it with a non-empty list of
 *   {@see DependencyType} values; {@see LayerPolicy::isAllowed()} gains a
 *   {@code DependencyType} overload that consults this list.
 * - {@code $allowCrossInstance} — false in Step C. Step E sets it to true when
 *   the user writes {@code 'app-{m}/*'} to opt out of capture-binding identity
 *   (allowing cross-instance template dependencies).
 *
 * The VO is immutable; Step G / Step E construct fresh instances rather than
 * mutating an existing one.
 */
final readonly class AllowTarget
{
    /**
     * @param list<DependencyType>|null $relations Optional whitelist of dependency
     *                                             types (null = "all relations").
     *                                             Always null in Step C; wired in Step G.
     * @param bool $allowCrossInstance When true, captured template instances
     *                                 on the source side may target other
     *                                 template instances on the target side.
     *                                 Always false in Step C; wired in Step E.
     */
    public function __construct(
        public LayerSelector $target,
        public ?array $relations = null,
        public bool $allowCrossInstance = false,
    ) {}
}
