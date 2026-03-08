<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Storage;

use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;

/**
 * Storage interface for metrics and file metadata.
 */
interface StorageInterface
{
    // === File operations ===

    /**
     * Retrieves file record by path.
     */
    public function getFile(string $path): ?FileRecord;

    /**
     * Stores file record and returns the file ID.
     */
    public function storeFile(FileRecord $record): int;

    /**
     * Removes file and all associated metrics (cascade).
     */
    public function removeFile(string $path): void;

    /**
     * Checks if file has changed since last storage.
     * Returns true if file is new or content hash differs.
     */
    public function hasFileChanged(string $path, string $contentHash): bool;

    // === Metrics operations ===

    /**
     * Retrieves metrics for a given symbol.
     * Returns associative array of metric_name => value, or null if not found.
     *
     * @return array<string, int|float>|null
     */
    public function getMetrics(SymbolPath $path): ?array;

    /**
     * Stores metrics for a given symbol.
     *
     * @param array<string, int|float> $metrics
     */
    public function storeMetrics(SymbolPath $path, array $metrics, int $fileId, int $line = 0): void;

    /**
     * Returns iterator over all metrics of given type.
     * Format: symbol_path_string => [metric_name => value]
     *
     * @return iterable<string, array<string, int|float>>
     */
    public function allMetrics(SymbolType $type): iterable;

    // === File-level dependencies (for caching) ===

    /**
     * Stores all dependencies collected from a file.
     * Replaces any previously stored dependencies for the given file.
     *
     * @param list<Dependency> $dependencies
     */
    public function storeFileDependencies(int $fileId, array $dependencies): void;

    /**
     * Retrieves all dependencies collected from a file.
     * Returns null if no dependencies are cached for this file.
     *
     * @return list<Dependency>|null
     */
    public function getFileDependencies(int $fileId): ?array;

    // === Class-level dependencies (for graph analysis) ===

    /**
     * Stores a dependency relationship.
     */
    public function storeDependency(string $from, string $to, string $type): void;

    /**
     * Gets all dependencies of a class (what it depends on).
     *
     * @return list<array{to: string, type: string}>
     */
    public function getDependencies(string $class): array;

    /**
     * Gets all dependents of a class (what depends on it).
     *
     * @return list<array{from: string, type: string}>
     */
    public function getDependents(string $class): array;

    /**
     * Returns iterator over all dependencies.
     *
     * @return iterable<array{from: string, to: string, type: string}>
     */
    public function getAllDependencies(): iterable;

    // === Aggregated metrics ===

    /**
     * Stores aggregated metrics for a scope.
     * Scope examples: 'namespace:App\Service', 'project'
     *
     * @param array<string, int|float> $metrics
     */
    public function storeAggregated(string $scope, array $metrics): void;

    /**
     * Retrieves aggregated metrics for a scope.
     *
     * @return array<string, int|float>|null
     */
    public function getAggregated(string $scope): ?array;

    // === Transactions ===

    /**
     * Begins a transaction.
     */
    public function beginTransaction(): void;

    /**
     * Commits the current transaction.
     */
    public function commit(): void;

    /**
     * Rolls back the current transaction.
     */
    public function rollback(): void;

    // === Maintenance ===

    /**
     * Clears all data from storage.
     */
    public function clear(): void;

    /**
     * Optimizes storage (e.g., VACUUM for SQLite).
     */
    public function vacuum(): void;

    /**
     * Returns storage statistics.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array;
}
