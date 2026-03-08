<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Metric;

use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolPath;

/**
 * Value object representing a method/function with its collected metrics.
 *
 * Used by collectors to provide method-level metrics for registration in repository.
 * This DTO bridges Symbol and Metric domains.
 */
final readonly class MethodWithMetrics
{
    public function __construct(
        public ?string $namespace,
        public ?string $class,
        public string $method,
        public int $line,
        public MetricBag $metrics,
    ) {}

    /**
     * Creates SymbolPath for this method.
     *
     * Returns null for closures (they don't have stable identity).
     */
    public function getSymbolPath(): ?SymbolPath
    {
        // Closures don't have stable identity
        if (str_starts_with($this->method, '{closure')) {
            return null;
        }

        // Method in a class
        if ($this->class !== null) {
            return SymbolPath::forMethod($this->namespace ?? '', $this->class, $this->method);
        }

        // Global function
        return SymbolPath::forGlobalFunction($this->namespace ?? '', $this->method);
    }

    /**
     * Creates SymbolInfo for this method with the given file path.
     *
     * Returns null for closures (they don't have stable identity).
     */
    public function toSymbolInfo(string $filePath): ?SymbolInfo
    {
        $symbolPath = $this->getSymbolPath();

        if ($symbolPath === null) {
            return null;
        }

        return new SymbolInfo($symbolPath, $filePath, $this->line);
    }
}
