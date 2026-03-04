<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Namespace_;

use AiMessDetector\Core\Namespace_\NamespaceDetectorInterface;
use SplFileInfo;

final class Psr4NamespaceDetector implements NamespaceDetectorInterface
{
    /** @var array<string, string> prefix => directory (sorted by path length descending) */
    private array $mapping = [];

    private string $baseDir;

    public function __construct(string $composerJsonPath)
    {
        $this->loadMapping($composerJsonPath);
    }

    public function detect(SplFileInfo $file): string
    {
        $realPath = $file->getRealPath();

        if ($realPath === false) {
            return '';
        }

        foreach ($this->mapping as $prefix => $directory) {
            if (str_starts_with($realPath, $directory . '/')) {
                $relativePath = substr($realPath, \strlen($directory) + 1);

                // Remove .php extension
                $relativePath = preg_replace('/\.php$/i', '', $relativePath);

                if ($relativePath === null || $relativePath === '') {
                    return rtrim($prefix, '\\');
                }

                // Convert path to namespace
                $namespace = str_replace('/', '\\', $relativePath);

                // Remove class name (last segment) to get just namespace
                $lastBackslash = strrpos($namespace, '\\');
                if ($lastBackslash !== false) {
                    $namespace = substr($namespace, 0, $lastBackslash);
                } else {
                    // File directly in namespace root
                    return rtrim($prefix, '\\');
                }

                return rtrim($prefix, '\\') . '\\' . $namespace;
            }
        }

        return '';
    }

    private function loadMapping(string $composerJsonPath): void
    {
        if (!file_exists($composerJsonPath)) {
            return;
        }

        $content = file_get_contents($composerJsonPath);
        if ($content === false) {
            return;
        }

        $composer = json_decode($content, true);
        if (!\is_array($composer)) {
            return;
        }

        $this->baseDir = \dirname(realpath($composerJsonPath) ?: $composerJsonPath);

        $psr4 = [];

        // Load autoload mappings
        if (isset($composer['autoload']['psr-4']) && \is_array($composer['autoload']['psr-4'])) {
            $psr4 = array_merge($psr4, $composer['autoload']['psr-4']);
        }

        // Load autoload-dev mappings
        if (isset($composer['autoload-dev']['psr-4']) && \is_array($composer['autoload-dev']['psr-4'])) {
            $psr4 = array_merge($psr4, $composer['autoload-dev']['psr-4']);
        }

        // Normalize and sort mappings
        foreach ($psr4 as $prefix => $path) {
            $paths = \is_array($path) ? $path : [$path];

            foreach ($paths as $p) {
                $absolutePath = $this->baseDir . '/' . rtrim($p, '/');
                $realPath = realpath($absolutePath);

                if ($realPath !== false) {
                    $this->mapping[$prefix] = $realPath;
                }
            }
        }

        // Sort by path length descending (more specific paths first)
        uasort($this->mapping, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));
    }
}
