<?php

declare(strict_types=1);

namespace AiMessDetector\Baseline;

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
    public function write(Baseline $baseline, string $path): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException("Failed to create directory: {$directory}");
            }
        }

        $data = $this->serializeBaseline($baseline);
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
    private function serializeBaseline(Baseline $baseline): array
    {
        $violations = [];

        foreach ($baseline->entries as $file => $entries) {
            $violations[$file] = array_map(
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
}
