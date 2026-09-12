<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Reporting\Contract\OutputFormat;
use Qualimetrix\Reporting\Contract\OutputFormatResolverInterface;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;

final readonly class OutputFormatResolver implements OutputFormatResolverInterface
{
    public function __construct(
        private FormatterRegistryInterface $formatters,
    ) {}

    /**
     * The set of formats is closed before any file is read, so a value this
     * resolver cannot execute is refused here rather than carried on to the
     * registry, which would raise it after the analysis had already run and
     * without the product's refusal framing.
     */
    public function resolve(ConfigurationDocument $document): OutputFormat
    {
        $value = OutputFormat::DEFAULT;

        foreach ($document->contributions(ConfigSchema::FORMAT) as $contribution) {
            $value = $this->accepted($contribution);
        }

        return new OutputFormat($value);
    }

    private function accepted(mixed $contribution): string
    {
        if (!\is_string($contribution)) {
            throw $this->refusal(\sprintf(
                'Invalid value for "%s": expected the name of an output format, got %s.',
                ConfigSchema::FORMAT,
                get_debug_type($contribution),
            ));
        }

        if (!$this->formatters->has($contribution)) {
            throw $this->refusal(\sprintf(
                'Output format "%s" is not one of: %s.',
                $contribution,
                implode(', ', $this->formatters->getAvailableNames()),
            ));
        }

        return $contribution;
    }

    private function refusal(string $summary): ConfigurationRefusal
    {
        // Resolved rather than CommandLine: `--format` and `format:` merge into
        // one value here, and which of them wrote it is no longer recoverable.
        return ConfigurationRefusal::aboutResolvedInput($summary, ConfigSchema::FORMAT);
    }
}
