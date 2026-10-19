<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerDeclaration;

use Qualimetrix\Analysis\Finding\Contract\Rule\EnablementOptionsTrait;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

final readonly class LayerDeclarationOptions implements RuleOptionsInterface
{
    use EnablementOptionsTrait;

    public function __construct(public bool $enabled = true) {}

    public function getSeverity(int|float $value): ?Severity
    {
        return null;
    }
}
