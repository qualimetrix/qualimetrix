<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Reporting\Contract\OutputFormat;
use Qualimetrix\Reporting\Contract\OutputFormatResolverInterface;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;

final readonly class OutputFormatResolver implements OutputFormatResolverInterface, DocumentSectionSchemaInterface
{
    public function __construct(
        private FormatterRegistryInterface $formatters,
    ) {}

    public function key(): string
    {
        return ConfigSchema::FORMAT;
    }

    public function schema(): NodeSchema
    {
        return NodeSchema::scalar(ScalarForm::String)->judgedInEachLayer(function (ResolvedValueInterface $format): void {
            $this->accepted($format);
        });
    }

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
