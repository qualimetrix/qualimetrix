<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

/**
 * What one `check` invocation asks the violation pipeline to do.
 *
 * The narrowing this invocation supplies on its own is held apart from the
 * rest rather than flattened into it: those three flags are the part of a
 * run that no other command shares, and keeping them in
 * {@see CliOnlyNarrowing} is what stops them from quietly becoming part of
 * the measured set (ADR 0017).
 */
final readonly class ViolationFilterOptions
{
    public function __construct(
        public ?string $baselinePath = null,
        public CliOnlyNarrowing $narrowing = new CliOnlyNarrowing(),
        public ?GitScopeFilterConfig $gitScope = null,
    ) {}
}
