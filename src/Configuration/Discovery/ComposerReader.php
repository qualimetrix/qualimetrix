<?php

declare(strict_types=1);

namespace AiMessDetector\Configuration\Discovery;

final class ComposerReader
{
    /**
     * Extracts paths from autoload.psr-4.
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

        // autoload.psr-4
        if (isset($data['autoload']['psr-4']) && \is_array($data['autoload']['psr-4'])) {
            foreach ($data['autoload']['psr-4'] as $path) {
                $normalized = $this->normalizePath($path);
                if ($normalized !== '') {
                    $paths[] = $normalized;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param string|list<string> $path
     */
    private function normalizePath(string|array $path): string
    {
        // PSR-4 can be a string or an array
        if (\is_array($path)) {
            $path = $path[0] ?? '';
        }

        return rtrim($path, '/');
    }
}
