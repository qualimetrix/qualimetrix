<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting;

use Qualimetrix\Core\Path\RelativePath;

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
     * @param bool $scopedReporting Whether reporting is scoped (e.g., --report=git:main..HEAD). Metrics and health are always complete; only violations are filtered to scope.
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
     * Renders a RelativePath as its wire-surface string; returns '' for null.
     *
     * Since Location::$file is already project-relative by construction (ADR 0015),
     * formatters call this only to handle the null case (architectural violations
     * with no associated file) without scattering null-checks at every print site.
     * The basePath field is retained for SARIF's `%SRCROOT%` URI builder.
     */
    public function relativizePath(?RelativePath $filePath): string
    {
        return $filePath?->value() ?? '';
    }
}
