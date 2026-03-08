<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting;

/**
 * Context passed to formatters with rendering options.
 *
 * Created by CheckCommand from CLI flags and OutputInterface state.
 */
final readonly class FormatterContext
{
    /**
     * @param bool $useColor Whether to use ANSI colors (from OutputInterface::isDecorated())
     * @param GroupBy $groupBy How to group violations in output
     * @param array<string, string> $options Formatter-specific options from --format-opt
     * @param string $basePath Base directory for relativizing file paths in output (e.g., CWD)
     */
    public function __construct(
        public bool $useColor = true,
        public GroupBy $groupBy = GroupBy::None,
        public array $options = [],
        public string $basePath = '',
    ) {}

    public function getOption(string $key, string $default = ''): string
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Strips basePath prefix from an absolute file path to produce a relative path.
     *
     * Returns the path unchanged if it does not start with basePath or if basePath is empty.
     */
    public function relativizePath(string $filePath): string
    {
        if ($this->basePath === '') {
            return $filePath;
        }

        $normalizedBase = rtrim($this->basePath, '/') . '/';

        if (!str_starts_with($filePath, $normalizedBase)) {
            return $filePath;
        }

        return substr($filePath, \strlen($normalizedBase));
    }
}
