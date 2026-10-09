<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance;

/** Complete answer for one declaration; classifier knowledge is independent of depth completeness. */
final readonly class InheritanceResolution
{
    public function __construct(
        public ?int $depth,
        public InheritanceOutcome $outcome,
        public ?bool $reachesThrowable,
    ) {}
}
