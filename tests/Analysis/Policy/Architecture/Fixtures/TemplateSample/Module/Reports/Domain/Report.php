<?php

declare(strict_types=1);

namespace Fixtures\TemplateSample\Module\Reports\Domain;

final class Report
{
    public function __construct(public string $title) {}
}
