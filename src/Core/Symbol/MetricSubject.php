<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

use InvalidArgumentException;
use LogicException;

/**
 * Tagged identity used by metric and reporting projections.
 */
final readonly class MetricSubject
{
    private function __construct(
        private ?DeclarationPath $declarationPath,
        private ?LogicalClassPath $logicalClassPath,
        private ?SymbolPath $aggregatePath,
    ) {}

    public static function declaration(DeclarationPath $path): self
    {
        return new self($path, null, null);
    }

    public static function logicalClass(LogicalClassPath $path): self
    {
        return new self(null, $path, null);
    }

    public static function aggregate(SymbolPath $path): self
    {
        if (!\in_array($path->getType(), [SymbolType::File, SymbolType::Namespace_, SymbolType::Project], true)) {
            throw new InvalidArgumentException('Aggregate MetricSubject requires a file, namespace, or project SymbolPath');
        }

        return new self(null, null, $path);
    }

    public function declarationPath(): ?DeclarationPath
    {
        return $this->declarationPath;
    }

    public function logicalClassPath(): ?LogicalClassPath
    {
        return $this->logicalClassPath;
    }

    public function aggregatePath(): ?SymbolPath
    {
        return $this->aggregatePath;
    }

    /**
     * Stable storage key for this subject kind.
     */
    public function toCanonical(): string
    {
        return $this->declarationPath?->toCanonical()
            ?? $this->logicalClassPath?->toCanonical()
            ?? $this->aggregatePath?->toCanonical()
            ?? throw new LogicException('MetricSubject must contain one identity');
    }

    /**
     * Logical SymbolPath used only by legacy aggregate projections.
     *
     * Exact declaration identity remains available through declarationPath().
     */
    public function toSymbolPath(): SymbolPath
    {
        return ($this->declarationPath !== null ? $this->declarationPath->logical : null)
            ?? ($this->logicalClassPath !== null ? $this->logicalClassPath->symbolPath : null)
            ?? $this->aggregatePath
            ?? throw new LogicException('MetricSubject must contain one identity');
    }
}
