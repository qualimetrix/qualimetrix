<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureTemplateSample\Module\Reports\Domain;

final class Report
{
    public function __construct(public string $title) {}
}
