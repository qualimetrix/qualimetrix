<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Preset;

use Qualimetrix\Configuration\Exception\ConfigLoadException;

/**
 * Resolves preset names to absolute file paths.
 *
 * Built-in presets (ci, legacy, strict) map to bundled YAML files.
 * Custom file paths are resolved relative to the working directory.
 */
final class PresetResolver
{
    /** @var list<string> */
    private const array BUILT_IN_PRESETS = ['ci', 'legacy', 'strict'];

    /**
     * Resolves a preset name or path to an absolute file path.
     *
     * Built-in names (ci, legacy, strict) resolve to bundled YAML files.
     * Values containing '/' or '\', or ending with '.yaml'/'.yml' are treated as file paths.
     *
     * @throws ConfigLoadException If the preset name is unknown or the file does not exist
     */
    public function resolve(string $name, string $workingDirectory): string
    {
        if ($this->isFilePath($name)) {
            return $this->resolveFilePath($name, $workingDirectory);
        }

        if (!$this->isBuiltIn($name)) {
            throw new ConfigLoadException(
                $name,
                \sprintf(
                    'Unknown preset "%s". Available presets: %s. To use a custom file, specify a path (e.g., --preset=./my-preset.yaml)',
                    $name,
                    implode(', ', self::getAvailableNames()),
                ),
            );
        }

        return __DIR__ . '/' . $name . '.yaml';
    }

    /**
     * Returns true if the name matches a built-in preset.
     */
    public function isBuiltIn(string $name): bool
    {
        return \in_array($name, self::BUILT_IN_PRESETS, true);
    }

    /**
     * Returns available built-in preset names (sorted alphabetically).
     *
     * @return list<string>
     */
    public static function getAvailableNames(): array
    {
        return self::BUILT_IN_PRESETS;
    }

    private function isFilePath(string $name): bool
    {
        return str_contains($name, '/') || str_contains($name, '\\')
            || str_ends_with($name, '.yaml') || str_ends_with($name, '.yml');
    }

    private function resolveFilePath(string $path, string $workingDirectory): string
    {
        $resolvedPath = str_starts_with($path, '/')
            ? $path
            : $workingDirectory . '/' . $path;

        if (!file_exists($resolvedPath)) {
            throw ConfigLoadException::fileNotFound($resolvedPath);
        }

        return $resolvedPath;
    }
}
