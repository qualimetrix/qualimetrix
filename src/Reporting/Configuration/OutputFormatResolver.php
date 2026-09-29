<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Reporting\Contract\OutputFormat;
use Qualimetrix\Reporting\Contract\OutputFormatResolverInterface;

final readonly class OutputFormatResolver implements OutputFormatResolverInterface
{
    public function __construct(
        private OutputFormatVocabulary $vocabulary,
    ) {}

    public function resolve(ConfigurationDocument $document): OutputFormat
    {
        $format = $document->resolved()->get(ConfigSchema::FORMAT);

        return new OutputFormat($format === null ? OutputFormat::DEFAULT : $this->vocabulary->accepted($format));
    }
}
