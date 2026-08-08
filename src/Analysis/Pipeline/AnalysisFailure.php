<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Pipeline;

use Qualimetrix\Core\Path\RelativePath;

/** A typed terminal failure for one discovered file. */
final readonly class AnalysisFailure
{
    public function __construct(
        public RelativePath $path,
        public AnalysisFailureKind $kind,
        public string $message,
    ) {}
}
