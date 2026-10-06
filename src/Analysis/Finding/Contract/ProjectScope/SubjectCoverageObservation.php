<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\ProjectScope;

use Qualimetrix\Core\Path\RelativePath;

/** The measured evidence offered for one subject's absence or non-production. */
final readonly class SubjectCoverageObservation
{
    private function __construct(
        public string $kind,
        public ?RelativePath $file = null,
    ) {}

    public static function analyzedFile(RelativePath $file): self
    {
        return new self('analyzed-file', $file);
    }

    public static function verifiedAbsentFile(RelativePath $file): self
    {
        return new self('verified-absent-file', $file);
    }

    public static function nonlocalRegion(): self
    {
        return new self('nonlocal-region');
    }
}
