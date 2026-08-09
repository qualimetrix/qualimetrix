<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

use Qualimetrix\Core\Path\RelativePath;

final readonly class SymbolInfo
{
    public function __construct(
        SymbolPath|MetricSubject $symbolPath,
        public ?RelativePath $file,
        public ?int $line,
        public ?CallableKind $callableKind = null,
        public ?LogicalClassPath $classAggregationOwner = null,
    ) {
        $this->subject = $symbolPath instanceof MetricSubject ? $symbolPath : null;
        $this->symbolPath = $symbolPath instanceof MetricSubject ? $symbolPath->toSymbolPath() : $symbolPath;
    }

    /** Exact typed identity when this information came through typed storage. */
    public ?MetricSubject $subject;

    /**
     * Legacy logical/aggregate projection for existing SymbolPath consumers.
     * Declaration callers must use subject instead.
     */
    public SymbolPath $symbolPath;
}
