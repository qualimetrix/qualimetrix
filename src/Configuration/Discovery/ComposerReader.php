<?php

declare(strict_types=1);

namespace AiMessDetector\Configuration\Discovery;

final class ComposerReader
{
    /**
     * Extracts paths from autoload.psr-4 and autoload-dev.psr-4.
     *
     * Handles both single-path strings and multi-path arrays per PSR-4 spec:
     *   "App\\": "src/"
     *   "Lib\\": ["lib/", "packages/"]
     *
     * @return list<string> Paths relative to composer.json
     */
    public function extractAutoloadPaths(string $composerJsonPath): array
    {
        if (!file_exists($composerJsonPath)) {
            return [];
        }

        $content = file_get_contents($composerJsonPath);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (!\is_array($data)) {
            return [];
        }

        $paths = [];

        $this->collectPsr4Paths($data, 'autoload', $paths);
        $this->collectPsr4Paths($data, 'autoload-dev', $paths);

        return array_values(array_unique($paths));
    }

    /**
     * Collects normalized PSR-4 paths from the given autoload section.
     *
     * @param array<string, mixed> $data Decoded composer.json
     * @param string $section Either 'autoload' or 'autoload-dev'
     * @param list<string> $paths Collected paths (modified by reference)
     */
    private function collectPsr4Paths(array $data, string $section, array &$paths): void
    {
        if (!isset($data[$section]['psr-4']) || !\is_array($data[$section]['psr-4'])) {
            return;
        }

        foreach ($data[$section]['psr-4'] as $pathOrPaths) {
            foreach ($this->normalizePaths($pathOrPaths) as $normalized) {
                if ($normalized !== '') {
                    $paths[] = $normalized;
                }
            }
        }
    }

    /**
     * Normalizes a PSR-4 path value (string or array of strings).
     *
     * @param string|list<string> $pathOrPaths
     *
     * @return list<string>
     */
    private function normalizePaths(string|array $pathOrPaths): array
    {
        if (\is_string($pathOrPaths)) {
            return [rtrim($pathOrPaths, '/')];
        }

        $result = [];
        foreach ($pathOrPaths as $path) {
            $result[] = rtrim($path, '/');
        }

        return $result;
    }
}
