<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Storage;

use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Dependency\DependencyType;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use Generator;
use PDO;
use RuntimeException;

/**
 * SQLite-based storage for metrics with caching and efficient querying.
 */
final class SqliteStorage implements StorageInterface
{
    private readonly PDO $pdo;
    private readonly string $dbPath;

    public function __construct(string $dbPath = '.aimd-cache/metrics.db')
    {
        $this->dbPath = $dbPath;
        $dir = \dirname($dbPath);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create cache directory: {$dir}");
        }

        $this->pdo = new PDO("sqlite:{$dbPath}");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->optimize();
        $this->migrate();
    }

    /**
     * Optimizes SQLite for performance.
     */
    private function optimize(): void
    {
        // WAL mode for concurrent reads
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        // Sync only on commit (faster, safe with WAL)
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        // 64MB cache
        $this->pdo->exec('PRAGMA cache_size = -65536');
        // Memory-mapped I/O (256MB)
        $this->pdo->exec('PRAGMA mmap_size = 268435456');
        // Temp tables in memory
        $this->pdo->exec('PRAGMA temp_store = MEMORY');
        // Enable foreign keys
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    /**
     * Creates database schema if not exists.
     */
    private function migrate(): void
    {
        // Files table
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                path TEXT UNIQUE NOT NULL,
                content_hash TEXT NOT NULL,
                mtime INTEGER NOT NULL,
                size INTEGER NOT NULL,
                namespace TEXT,
                collected_at INTEGER NOT NULL
            )
        SQL);

        // File metrics table
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS file_metrics (
                file_id INTEGER PRIMARY KEY REFERENCES files(id) ON DELETE CASCADE,
                metrics BLOB NOT NULL
            )
        SQL);

        // Class metrics table
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS class_metrics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_id INTEGER NOT NULL REFERENCES files(id) ON DELETE CASCADE,
                symbol_path TEXT UNIQUE NOT NULL,
                class_name TEXT NOT NULL,
                namespace TEXT,
                metrics BLOB NOT NULL,
                line INTEGER
            )
        SQL);

        // Method metrics table
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS method_metrics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_id INTEGER NOT NULL REFERENCES files(id) ON DELETE CASCADE,
                class_id INTEGER REFERENCES class_metrics(id) ON DELETE CASCADE,
                symbol_path TEXT UNIQUE NOT NULL,
                method_name TEXT NOT NULL,
                metrics BLOB NOT NULL,
                line INTEGER
            )
        SQL);

        // Aggregated metrics table
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS aggregated_metrics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scope TEXT UNIQUE NOT NULL,
                metrics BLOB NOT NULL,
                aggregated_at INTEGER NOT NULL
            )
        SQL);

        // File dependencies table (for caching per-file dependency lists)
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS file_dependencies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_id INTEGER NOT NULL REFERENCES files(id) ON DELETE CASCADE,
                source_class TEXT NOT NULL,
                target_class TEXT NOT NULL,
                type TEXT NOT NULL,
                file_path TEXT NOT NULL,
                line INTEGER
            )
        SQL);

        // Dependencies table
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS dependencies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                from_class TEXT NOT NULL,
                to_class TEXT NOT NULL,
                type TEXT NOT NULL,
                UNIQUE(from_class, to_class, type)
            )
        SQL);

        // Create indices
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_files_path ON files(path)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_files_content_hash ON files(content_hash)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_class_symbol ON class_metrics(symbol_path)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_class_namespace ON class_metrics(namespace)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_method_symbol ON method_metrics(symbol_path)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_method_class ON method_metrics(class_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_file_deps_file_id ON file_dependencies(file_id)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_deps_from ON dependencies(from_class)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_deps_to ON dependencies(to_class)');
    }

    // === File operations ===

    public function getFile(string $path): ?FileRecord
    {
        $stmt = $this->pdo->prepare(
            'SELECT path, content_hash, mtime, size, namespace, collected_at
             FROM files WHERE path = :path',
        );
        $stmt->execute(['path' => $path]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return new FileRecord(
            path: $row['path'],
            contentHash: $row['content_hash'],
            mtime: (int) $row['mtime'],
            size: (int) $row['size'],
            namespace: $row['namespace'],
            collectedAt: (int) $row['collected_at'],
        );
    }

    public function storeFile(FileRecord $record): int
    {
        $collectedAt = $record->collectedAt ?? time();

        $stmt = $this->pdo->prepare(
            'INSERT OR REPLACE INTO files (path, content_hash, mtime, size, namespace, collected_at)
             VALUES (:path, :content_hash, :mtime, :size, :namespace, :collected_at)',
        );

        $stmt->execute([
            'path' => $record->path,
            'content_hash' => $record->contentHash,
            'mtime' => $record->mtime,
            'size' => $record->size,
            'namespace' => $record->namespace,
            'collected_at' => $collectedAt,
        ]);

        // Get file ID
        $stmt = $this->pdo->prepare('SELECT id FROM files WHERE path = :path');
        $stmt->execute(['path' => $record->path]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) $row['id'];
    }

    public function removeFile(string $path): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM files WHERE path = :path');
        $stmt->execute(['path' => $path]);
    }

    public function hasFileChanged(string $path, string $contentHash): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT content_hash FROM files WHERE path = :path',
        );
        $stmt->execute(['path' => $path]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return true; // New file
        }

        return $row['content_hash'] !== $contentHash;
    }

    // === Metrics operations ===

    public function getMetrics(SymbolPath $path): ?array
    {
        [$table, $column] = $this->getTableAndColumn($path);

        $key = $this->getStorageKey($path);

        $stmt = $this->pdo->prepare(
            "SELECT metrics FROM {$table} WHERE {$column} = :key",
        );
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return unserialize($row['metrics']);
    }

    public function storeMetrics(SymbolPath $path, array $metrics, int $fileId, int $line = 0): void
    {
        $serialized = serialize($metrics);

        if ($path->filePath !== null) {
            // File-level metrics
            $stmt = $this->pdo->prepare(
                'INSERT OR REPLACE INTO file_metrics (file_id, metrics) VALUES (:file_id, :metrics)',
            );
            $stmt->execute(['file_id' => $fileId, 'metrics' => $serialized]);
        } elseif ($path->member !== null && $path->type !== null) {
            // Method metrics
            $classId = $this->getOrCreateClassId($path, $fileId);

            $stmt = $this->pdo->prepare(
                'INSERT OR REPLACE INTO method_metrics
                 (file_id, class_id, symbol_path, method_name, metrics, line)
                 VALUES (:file_id, :class_id, :symbol_path, :method_name, :metrics, :line)',
            );
            $stmt->execute([
                'file_id' => $fileId,
                'class_id' => $classId,
                'symbol_path' => $path->toCanonical(),
                'method_name' => $path->member,
                'metrics' => $serialized,
                'line' => $line,
            ]);
        } elseif ($path->type !== null) {
            // Class metrics
            $stmt = $this->pdo->prepare(
                'INSERT OR REPLACE INTO class_metrics
                 (file_id, symbol_path, class_name, namespace, metrics, line)
                 VALUES (:file_id, :symbol_path, :class_name, :namespace, :metrics, :line)',
            );
            $stmt->execute([
                'file_id' => $fileId,
                'symbol_path' => $path->toCanonical(),
                'class_name' => $path->type,
                'namespace' => $path->namespace,
                'metrics' => $serialized,
                'line' => $line,
            ]);
        }
    }

    /**
     * @return Generator<string, array<string, int|float>>
     */
    public function allMetrics(SymbolType $type): iterable
    {
        [$table, $column] = $this->getTableAndColumn($type);

        $stmt = $this->pdo->prepare("SELECT {$column} as path, metrics FROM {$table}");
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield $row['path'] => unserialize($row['metrics']);
        }
    }

    // === File-level dependencies (for caching) ===

    public function storeFileDependencies(int $fileId, array $dependencies): void
    {
        // Remove old dependencies for this file
        $stmt = $this->pdo->prepare('DELETE FROM file_dependencies WHERE file_id = :file_id');
        $stmt->execute(['file_id' => $fileId]);

        if ($dependencies === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO file_dependencies (file_id, source_class, target_class, type, file_path, line)
             VALUES (:file_id, :source_class, :target_class, :type, :file_path, :line)',
        );

        foreach ($dependencies as $dep) {
            $stmt->execute([
                'file_id' => $fileId,
                'source_class' => $dep->source->toString(),
                'target_class' => $dep->target->toString(),
                'type' => $dep->type->value,
                'file_path' => $dep->location->file,
                'line' => $dep->location->line,
            ]);
        }
    }

    public function getFileDependencies(int $fileId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT source_class, target_class, type, file_path, line
             FROM file_dependencies WHERE file_id = :file_id',
        );
        $stmt->execute(['file_id' => $fileId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === [] || $rows === false) {
            // Distinguish "no deps cached" from "file has zero deps":
            // Check if the file exists in the files table
            $fileStmt = $this->pdo->prepare('SELECT id FROM files WHERE id = :id');
            $fileStmt->execute(['id' => $fileId]);

            if ($fileStmt->fetch() === false) {
                return null; // File not in cache at all
            }

            // File exists but has no dependencies — return empty list
            // However, we can't distinguish "stored empty" from "never stored"
            // without a marker. We use a sentinel approach: storeFileDependencies
            // always deletes first, so if file_dependencies has no rows but file
            // exists, it means either never stored or stored empty.
            // We solve this by checking if file_metrics exist (both are stored together).
            $metricsStmt = $this->pdo->prepare('SELECT file_id FROM file_metrics WHERE file_id = :id');
            $metricsStmt->execute(['id' => $fileId]);

            if ($metricsStmt->fetch() === false) {
                return null; // Metrics not cached either — old cache format
            }

            return []; // Metrics cached, deps are intentionally empty
        }

        $dependencies = [];

        foreach ($rows as $row) {
            $dependencies[] = new Dependency(
                source: SymbolPath::fromClassFqn($row['source_class']),
                target: SymbolPath::fromClassFqn($row['target_class']),
                type: DependencyType::from($row['type']),
                location: new Location(
                    file: $row['file_path'],
                    line: $row['line'] !== null ? (int) $row['line'] : null,
                ),
            );
        }

        return $dependencies;
    }

    // === Class-level dependencies (for graph analysis) ===

    public function storeDependency(string $from, string $to, string $type): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO dependencies (from_class, to_class, type)
             VALUES (:from, :to, :type)',
        );
        $stmt->execute(['from' => $from, 'to' => $to, 'type' => $type]);
    }

    public function getDependencies(string $class): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT to_class as "to", type FROM dependencies WHERE from_class = :class',
        );
        $stmt->execute(['class' => $class]);

        /** @var list<array{to: string, type: string}> */
        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getDependents(string $class): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT from_class as "from", type FROM dependencies WHERE to_class = :class',
        );
        $stmt->execute(['class' => $class]);

        /** @var list<array{from: string, type: string}> */
        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return Generator<array{from: string, to: string, type: string}>
     */
    public function getAllDependencies(): iterable
    {
        $stmt = $this->pdo->query('SELECT from_class as "from", to_class as "to", type FROM dependencies');

        if ($stmt === false) {
            return;
        }

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!\is_array($row)) {
                break;
            }

            /** @var array{from: string, to: string, type: string} $row */
            yield $row;
        }
    }

    // === Aggregated metrics ===

    public function storeAggregated(string $scope, array $metrics): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR REPLACE INTO aggregated_metrics (scope, metrics, aggregated_at)
             VALUES (:scope, :metrics, :aggregated_at)',
        );
        $stmt->execute([
            'scope' => $scope,
            'metrics' => serialize($metrics),
            'aggregated_at' => time(),
        ]);
    }

    public function getAggregated(string $scope): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT metrics FROM aggregated_metrics WHERE scope = :scope',
        );
        $stmt->execute(['scope' => $scope]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return unserialize($row['metrics']);
    }

    // === Transactions ===

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        $this->pdo->rollBack();
    }

    // === Maintenance ===

    public function clear(): void
    {
        $this->pdo->exec('DELETE FROM files');
        $this->pdo->exec('DELETE FROM file_metrics');
        $this->pdo->exec('DELETE FROM class_metrics');
        $this->pdo->exec('DELETE FROM method_metrics');
        $this->pdo->exec('DELETE FROM aggregated_metrics');
        $this->pdo->exec('DELETE FROM file_dependencies');
        $this->pdo->exec('DELETE FROM dependencies');
    }

    public function vacuum(): void
    {
        $this->pdo->exec('VACUUM');
    }

    public function getStats(): array
    {
        $stats = [
            'files' => $this->count('files'),
            'classes' => $this->count('class_metrics'),
            'methods' => $this->count('method_metrics'),
            'dependencies' => $this->count('dependencies'),
        ];

        if (file_exists($this->dbPath)) {
            $stats['db_size_mb'] = round(filesize($this->dbPath) / 1024 / 1024, 2);
        }

        return $stats;
    }

    // === Private helpers ===

    private function count(string $table): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) as cnt FROM {$table}");

        if ($stmt === false) {
            return 0;
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return 0;
        }

        return (int) $row['cnt'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function getTableAndColumn(SymbolPath|SymbolType $pathOrType): array
    {
        if ($pathOrType instanceof SymbolPath) {
            $type = $pathOrType->getType();
        } else {
            $type = $pathOrType;
        }

        return match ($type) {
            SymbolType::File => ['file_metrics fm JOIN files f ON fm.file_id = f.id', 'f.path'],
            SymbolType::Class_ => ['class_metrics', 'symbol_path'],
            SymbolType::Method => ['method_metrics', 'symbol_path'],
            SymbolType::Function_ => ['method_metrics', 'symbol_path'], // Functions stored with methods
            SymbolType::Namespace_, SymbolType::Project => ['aggregated_metrics', 'scope'],
        };
    }

    private function getStorageKey(SymbolPath $path): string
    {
        // For file metrics, we use the raw file path (not canonical) for DB lookup
        if ($path->filePath !== null) {
            return $path->filePath;
        }

        return $path->toCanonical();
    }

    private function getOrCreateClassId(SymbolPath $methodPath, int $fileId): ?int
    {
        if ($methodPath->type === null) {
            return null;
        }

        $classPath = SymbolPath::forClass($methodPath->namespace ?? '', $methodPath->type);
        $canonical = $classPath->toCanonical();

        // Try to get existing class
        $stmt = $this->pdo->prepare('SELECT id FROM class_metrics WHERE symbol_path = :path');
        $stmt->execute(['path' => $canonical]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row !== false) {
            return (int) $row['id'];
        }

        // Create placeholder class entry
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO class_metrics
             (file_id, symbol_path, class_name, namespace, metrics, line)
             VALUES (:file_id, :symbol_path, :class_name, :namespace, :metrics, 0)',
        );
        $stmt->execute([
            'file_id' => $fileId,
            'symbol_path' => $canonical,
            'class_name' => $methodPath->type,
            'namespace' => $methodPath->namespace,
            'metrics' => serialize([]),
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
