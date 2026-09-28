<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
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
        $format = $document->resolved()->get(ConfigSchema::FORMAT);

        return new OutputFormat($format === null ? OutputFormat::DEFAULT : $this->accepted($format));
    }

    private function accepted(ResolvedValueInterface $format): string
    {
        $value = $format->plain();
        if (!\is_string($value)) {
            $format->refuse(\sprintf(
                'Invalid value for "%s": expected the name of an output format, got %s.',
                ConfigSchema::FORMAT,
                get_debug_type($value),
            ));
        }

        if (!$this->formatters->has($value)) {
            $format->refuse(\sprintf(
                'Output format "%s" is not one of: %s.',
                $value,
                implode(', ', $this->formatters->getAvailableNames()),
            ));
        }

        return $value;
    }
}
