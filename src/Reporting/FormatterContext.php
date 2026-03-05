<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting;

/**
 * Context passed to formatters with rendering options.
 *
 * Created by AnalyzeCommand from CLI flags and OutputInterface state.
 */
final readonly class FormatterContext
{
    /**
     * @param bool $useColor Whether to use ANSI colors (from OutputInterface::isDecorated())
     * @param GroupBy $groupBy How to group violations in output
     * @param array<string, string> $options Formatter-specific options from --format-opt
     */
    public function __construct(
        public bool $useColor = true,
        public GroupBy $groupBy = GroupBy::None,
        public array $options = [],
    ) {}

    public function getOption(string $key, string $default = ''): string
    {
        return $this->options[$key] ?? $default;
    }
}
