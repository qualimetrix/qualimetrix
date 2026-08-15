<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Reporting\Contract\OutputFormat;
use Qualimetrix\Reporting\Contract\OutputFormatResolverInterface;

final class OutputFormatResolver implements OutputFormatResolverInterface
{
    public function resolve(ConfigurationDocument $document): OutputFormat
    {
        $value = OutputFormat::DEFAULT;
        foreach ($document->contributions(ConfigSchema::FORMAT) as $contribution) {
            if (\is_string($contribution)) {
                $value = $contribution;
            }
        }

        return new OutputFormat($value);
    }
}
