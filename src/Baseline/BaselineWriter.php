<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use RuntimeException;

/**
 * Writes baseline to JSON file using atomic write strategy.
 */
final readonly class BaselineWriter
{
    /**
     * Writes baseline to file atomically.
     *
     * Uses atomic rename strategy to prevent corruption:
     * 1. Write to temporary file
     * 2. Rename to target file (atomic operation)
     *
     * @throws RuntimeException if write fails
     */
    public function write(Baseline $baseline, string $path, string $projectRoot = '.'): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException("Failed to create directory: {$directory}");
            }
        }

        $data = $this->serializeBaseline($baseline, $projectRoot);
        $json = json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        // Atomic write: write to temp file, then rename
        $tempPath = $path . '.tmp.' . getmypid();

        try {
            if (file_put_contents($tempPath, $json) === false) {
                throw new RuntimeException("Failed to write baseline to: {$tempPath}");
            }

            if (!rename($tempPath, $path)) {
                throw new RuntimeException("Failed to move baseline from {$tempPath} to {$path}");
            }
        } finally {
            // Clean up temp file if it still exists
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeBaseline(Baseline $baseline, string $projectRoot): array
    {
        $rootVO = self::resolveProjectRoot($projectRoot);
        $violations = [];

        foreach ($baseline->entries as $canonical => $entries) {
            $portableKey = $this->relativizeCanonical($canonical, $rootVO);
            $violations[$portableKey] = array_map(
                fn(BaselineEntry $entry) => $entry->toArray(),
                $entries,
            );
        }

        return [
            'version' => $baseline->version,
            'generated' => $baseline->generated->format('c'), // ISO 8601
            'count' => $baseline->count(),
            'violationCount' => $baseline->count(),
            'symbolCount' => \count($baseline->entries),
            'violations' => $violations,
        ];
    }

    /**
     * Converts absolute file: canonical paths to relative for portability.
     *
     * Only affects file: keys — class:, method:, ns: keys are FQN-based
     * and already portable. Out-of-tree absolute paths are preserved verbatim
     * so external baselines stay round-trippable. Malformed `file:` payloads
     * (empty, lexically escaping segments) propagate as VO construction
     * exceptions: the writer treats them as in-memory corruption, not as
     * tolerated input.
     */
    private function relativizeCanonical(string $canonical, AbsolutePath $projectRoot): string
    {
        if (!str_starts_with($canonical, 'file:')) {
            return $canonical;
        }

        $filePath = substr($canonical, 5);

        if ($filePath === '') {
            return $canonical;
        }

        $relative = PathFactory::tryProjectRelative($filePath, $projectRoot);

        return $relative !== null ? 'file:' . $relative->value() : $canonical;
    }

    private static function resolveProjectRoot(string $projectRoot): AbsolutePath
    {
        if ($projectRoot === '.' || !str_starts_with($projectRoot, '/')) {
            $resolved = realpath($projectRoot);
            $absolute = $resolved !== false ? $resolved : ((string) getcwd());

            return AbsolutePath::fromString($absolute);
        }

        return AbsolutePath::fromString($projectRoot);
    }
}
