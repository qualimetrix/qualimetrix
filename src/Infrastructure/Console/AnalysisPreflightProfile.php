<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

/** Declares the configuration roots one command may carry into its run. */
final readonly class AnalysisPreflightProfile
{
    /** @param list<string>|null $mappedOptions */
    private function __construct(
        public bool $requiresFindingConfiguration,
        public bool $requiresReportingFormat,
        private ?array $mappedOptions = null,
    ) {}

    public static function analysis(): self
    {
        return new self(true, true);
    }

    public static function graph(): self
    {
        return new self(false, false, [
            'config', 'preset', 'exclude', 'include-generated', 'include-autoload-dev',
            'no-cache', 'workers', 'memory-limit',
        ]);
    }

    public function mapsOption(string $option): bool
    {
        return $this->mappedOptions === null || \in_array($option, $this->mappedOptions, true);
    }
}
