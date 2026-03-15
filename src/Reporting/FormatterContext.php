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
     * @param bool $partialAnalysis Whether this is a partial analysis (e.g., git:staged)
     * @param string|null $namespace Namespace filter for drill-down (boundary-aware prefix match)
     * @param string|null $class Class filter for drill-down (exact FQCN match)
     * @param int $terminalWidth Terminal width for adaptive rendering (0 = use default 80)
     * @param bool $detail Whether to show detailed output (grouped violations, debt breakdown)
     * @param bool $isGroupByExplicit Whether --group-by was explicitly set by the user
     */
    public function __construct(
        public bool $useColor = true,
        public GroupBy $groupBy = GroupBy::None,
        public array $options = [],
        public string $basePath = '',
        public bool $partialAnalysis = false,
        public ?string $namespace = null,
        public ?string $class = null,
        public int $terminalWidth = 0,
        public bool $detail = false,
        public bool $isGroupByExplicit = false,
    ) {}

    /**
     * Returns a copy with detail mode enabled/disabled.
     *
     * Centralizes context cloning to avoid fragile manual field copying.
     * This is the single place to update when FormatterContext fields change.
     */
    public function withDetail(bool $detail): self
    {
        if ($this->detail === $detail) {
            return $this;
        }

        return new self(
            useColor: $this->useColor,
            groupBy: $this->groupBy,
            options: $this->options,
            basePath: $this->basePath,
            partialAnalysis: $this->partialAnalysis,
            namespace: $this->namespace,
            class: $this->class,
            terminalWidth: $this->terminalWidth,
            detail: $detail,
            isGroupByExplicit: $this->isGroupByExplicit,
        );
    }

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
