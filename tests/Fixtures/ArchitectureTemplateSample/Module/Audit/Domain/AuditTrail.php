<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureTemplateSample\Module\Audit\Domain;

final class AuditTrail
{
    public function __construct(public string $entry) {}
}
