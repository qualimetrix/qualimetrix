<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting;

/**
 * Context passed to formatters with rendering options.
 *
 * Created by CheckCommand from CLI flags and OutputInterface state.
 */
final readonly class FormatterContext
{
    public const int DEFAULT_TOP_ISSUES_LIMIT = 10;

    /**
     * @param bool $useColor Whether to use ANSI colors (from OutputInterface::isDecorated())
     * @param GroupBy $groupBy How to group violations in output
     * @param array<string, string> $options Formatter-specific options from --format-opt
     * @param string $basePath Base directory for relativizing file paths in output (e.g., CWD)
     * @param bool $scopedReporting Whether reporting is scoped (e.g., --analyze=git:staged). Metrics and health are always complete; only violations/worst offenders are filtered to scope.
     * @param list<string>|null $scopeFilePaths Relative file paths in scope (for filtering worst offenders). null = all files.
     * @param string|null $namespace Namespace filter for drill-down (boundary-aware prefix match)
     * @param string|null $class Class filter for drill-down (exact FQCN match)
     * @param int $terminalWidth Terminal width for adaptive rendering (0 = use default 80)
     * @param int|null $detailLimit Violation limit for --detail mode (null = off, 0 = all, N = limit)
     * @param bool $isGroupByExplicit Whether --group-by was explicitly set by the user
     * @param int $topIssuesLimit Number of top impact issues to show (0 = disabled)
     */
    public function __construct(
        public bool $useColor = true,
        public GroupBy $groupBy = GroupBy::None,
        public array $options = [],
        public string $basePath = '',
        public bool $scopedReporting = false,
        public ?array $scopeFilePaths = null,
        public ?string $namespace = null,
        public ?string $class = null,
        public int $terminalWidth = 0,
        public ?int $detailLimit = null,
        public bool $isGroupByExplicit = false,
        public int $topIssuesLimit = self::DEFAULT_TOP_ISSUES_LIMIT,
    ) {}

    /**
     * Whether detail mode is enabled (any non-null detailLimit).
     */
    public function isDetailEnabled(): bool
    {
        return $this->detailLimit !== null;
    }

    /**
     * Returns a copy with detail mode enabled/disabled.
     *
     * Centralizes context cloning to avoid fragile manual field copying.
     * This is the single place to update when FormatterContext fields change.
     */
    public function withDetail(bool $detail): self
    {
        return $this->withDetailLimit($detail ? 0 : null);
    }

    /**
     * Returns a copy with a specific detail limit.
     *
     * @param int|null $detailLimit null = off, 0 = all, N = limit
     */
    public function withDetailLimit(?int $detailLimit): self
    {
        if ($this->detailLimit === $detailLimit) {
            return $this;
        }

        return new self(
            useColor: $this->useColor,
            groupBy: $this->groupBy,
            options: $this->options,
            basePath: $this->basePath,
            scopedReporting: $this->scopedReporting,
            scopeFilePaths: $this->scopeFilePaths,
            namespace: $this->namespace,
            class: $this->class,
            terminalWidth: $this->terminalWidth,
            detailLimit: $detailLimit,
            isGroupByExplicit: $this->isGroupByExplicit,
            topIssuesLimit: $this->topIssuesLimit,
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
