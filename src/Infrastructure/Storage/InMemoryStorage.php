<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Storage;

use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use Generator;

/**
 * In-memory storage implementation for small projects.
 * Suitable for projects with < 1000 files.
 */
final class InMemoryStorage implements StorageInterface
{
    /** @var array<string, FileRecord> path => FileRecord */
    private array $files = [];

    /** @var array<int, string> file_id => path */
    private array $fileIds = [];

    /** @var array<string, array<string, int|float>> symbol_path => metrics */
    private array $metrics = [];

    /** @var array<int, list<string>> file_id => list of symbol_paths */
    private array $fileSymbols = [];

    /** @var array<string, array<string, int|float>> scope => metrics */
    private array $aggregated = [];

    /** @var array<int, list<Dependency>> file_id => dependencies */
    private array $fileDependencies = [];

    /** @var array<string, list<array{to: string, type: string}>> from_class => dependencies */
    private array $dependencies = [];

    /** @var array<string, list<array{from: string, type: string}>> to_class => dependents */
    private array $dependents = [];

    private int $nextFileId = 1;
    private ?self $transactionBackup = null;

    // === File operations ===

    public function getFileId(string $path): ?int
    {
        $fileId = array_search($path, $this->fileIds, true);

        return $fileId !== false ? $fileId : null;
    }

    public function getFile(string $path): ?FileRecord
    {
        return $this->files[$path] ?? null;
    }

    public function storeFile(FileRecord $record): int
    {
        // Find existing file ID or create new one
        $fileId = array_search($record->path, $this->fileIds, true);

        if ($fileId === false) {
            $fileId = $this->nextFileId++;
            $this->fileIds[$fileId] = $record->path;
        }

        $this->files[$record->path] = $record;

        return $fileId;
    }

    public function removeFile(string $path): void
    {
        $fileId = array_search($path, $this->fileIds, true);

        if ($fileId !== false) {
            // Remove all metrics associated with this file (cascade)
            if (isset($this->fileSymbols[$fileId])) {
                foreach ($this->fileSymbols[$fileId] as $symbolPath) {
                    unset($this->metrics[$symbolPath]);
                }
                unset($this->fileSymbols[$fileId]);
            }

            unset($this->fileDependencies[$fileId], $this->fileIds[$fileId]);
        }

        unset($this->files[$path]);
    }

    public function hasFileChanged(string $path, string $contentHash): bool
    {
        if (!isset($this->files[$path])) {
            return true; // New file
        }

        return $this->files[$path]->contentHash !== $contentHash;
    }

    // === Metrics operations ===

    public function getMetrics(SymbolPath $path): ?array
    {
        $key = $this->getStorageKey($path);

        return $this->metrics[$key] ?? null;
    }

    public function storeMetrics(SymbolPath $path, array $metrics, int $fileId, int $line = 0): void
    {
        $key = $this->getStorageKey($path);
        $this->metrics[$key] = $metrics;

        // Track symbol-to-file relationship for cascade delete
        if (!isset($this->fileSymbols[$fileId])) {
            $this->fileSymbols[$fileId] = [];
        }

        if (!\in_array($key, $this->fileSymbols[$fileId], true)) {
            $this->fileSymbols[$fileId][] = $key;
        }
    }

    /**
     * @return Generator<string, array<string, int|float>>
     */
    public function allMetrics(SymbolType $type): iterable
    {
        foreach ($this->metrics as $path => $metrics) {
            if ($this->matchesType($path, $type)) {
                yield $path => $metrics;
            }
        }
    }

    // === File-level dependencies (for caching) ===

    public function storeFileDependencies(int $fileId, array $dependencies): void
    {
        $this->fileDependencies[$fileId] = $dependencies;
    }

    public function getFileDependencies(int $fileId): ?array
    {
        if (!\array_key_exists($fileId, $this->fileDependencies)) {
            return null;
        }

        return $this->fileDependencies[$fileId];
    }

    // === Class-level dependencies (for graph analysis) ===

    public function storeDependency(string $from, string $to, string $type): void
    {
        $this->dependencies[$from] ??= [];

        // Check if already exists
        foreach ($this->dependencies[$from] as $dep) {
            if ($dep['to'] === $to && $dep['type'] === $type) {
                return;
            }
        }

        $this->dependencies[$from][] = ['to' => $to, 'type' => $type];

        // Store reverse mapping
        $this->dependents[$to] ??= [];
        $this->dependents[$to][] = ['from' => $from, 'type' => $type];
    }

    public function getDependencies(string $class): array
    {
        return $this->dependencies[$class] ?? [];
    }

    public function getDependents(string $class): array
    {
        return $this->dependents[$class] ?? [];
    }

    /**
     * @return Generator<array{from: string, to: string, type: string}>
     */
    public function getAllDependencies(): iterable
    {
        foreach ($this->dependencies as $from => $deps) {
            foreach ($deps as $dep) {
                yield ['from' => $from, 'to' => $dep['to'], 'type' => $dep['type']];
            }
        }
    }

    // === Aggregated metrics ===

    public function storeAggregated(string $scope, array $metrics): void
    {
        $this->aggregated[$scope] = $metrics;
    }

    public function getAggregated(string $scope): ?array
    {
        return $this->aggregated[$scope] ?? null;
    }

    // === Transactions ===

    public function beginTransaction(): void
    {
        $this->transactionBackup = clone $this;
    }

    public function commit(): void
    {
        $this->transactionBackup = null;
    }

    public function rollback(): void
    {
        if ($this->transactionBackup !== null) {
            $this->files = $this->transactionBackup->files;
            $this->fileIds = $this->transactionBackup->fileIds;
            $this->metrics = $this->transactionBackup->metrics;
            $this->aggregated = $this->transactionBackup->aggregated;
            $this->fileDependencies = $this->transactionBackup->fileDependencies;
            $this->dependencies = $this->transactionBackup->dependencies;
            $this->dependents = $this->transactionBackup->dependents;
            $this->nextFileId = $this->transactionBackup->nextFileId;
        }

        $this->transactionBackup = null;
    }

    // === Maintenance ===

    public function clear(): void
    {
        $this->files = [];
        $this->fileIds = [];
        $this->metrics = [];
        $this->fileSymbols = [];
        $this->aggregated = [];
        $this->fileDependencies = [];
        $this->dependencies = [];
        $this->dependents = [];
        $this->nextFileId = 1;
    }

    public function vacuum(): void
    {
        // No-op for in-memory storage
    }

    public function getStats(): array
    {
        return [
            'files' => \count($this->files),
            'metrics' => \count($this->metrics),
            'aggregated' => \count($this->aggregated),
            'dependencies' => array_sum(array_map('count', $this->dependencies)),
            'db_size_mb' => 0,
        ];
    }

    // === Private helpers ===

    private function getStorageKey(SymbolPath $path): string
    {
        return $path->toCanonical();
    }

    private function matchesType(string $storageKey, SymbolType $type): bool
    {
        $prefix = match ($type) {
            SymbolType::File => 'file:',
            SymbolType::Namespace_ => 'ns:',
            SymbolType::Class_ => 'class:',
            SymbolType::Method => 'method:',
            SymbolType::Function_ => 'func:',
            SymbolType::Project => 'project:',
        };

        return str_starts_with($storageKey, $prefix);
    }
}
