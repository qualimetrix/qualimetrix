<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter;

final readonly class FormattedReport
{
    public function __construct(
        public string $body,
        public int $escapedStrings = 0,
    ) {}
}
