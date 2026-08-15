<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Contract;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;

interface OutputFormatResolverInterface
{
    public function resolve(ConfigurationDocument $document): OutputFormat;
}
