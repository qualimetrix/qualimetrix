<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

use InvalidArgumentException;
use Qualimetrix\Core\Path\RelativePath;

final readonly class SymbolInfo
{
    public function __construct(
        SymbolPath|MetricSubject $symbolPath,
        public ?RelativePath $file,
        public ?int $line,
        public ?CallableKind $callableKind = null,
        public ?DeclarationPath $classAggregationOwner = null,
        public bool $anonymousClassContext = false,
    ) {
        if ($callableKind === null && $classAggregationOwner !== null) {
            throw new InvalidArgumentException('Class aggregation ownership requires callable metadata');
        }
        $callableKind?->assertClassAggregationOwner($classAggregationOwner, $anonymousClassContext);

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
