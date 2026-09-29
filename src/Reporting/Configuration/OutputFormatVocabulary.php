<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;

final readonly class OutputFormatVocabulary
{
    public function __construct(private FormatterRegistryInterface $formatters) {}

    public function accepted(ResolvedValueInterface $format): string
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
