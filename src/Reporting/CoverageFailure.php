<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting;

/** Reporting-safe projection of one analysis failure. */
final readonly class CoverageFailure
{
    public function __construct(
        public string $path,
        public string $kind,
        public string $message,
    ) {}
}
