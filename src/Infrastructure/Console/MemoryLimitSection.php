<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;

final class MemoryLimitSection implements DocumentSectionSchemaInterface
{
    public function declaration(): SectionDeclaration
    {
        return new SectionDeclaration(RuntimeLimits::MEMORY_LIMIT_KEY, NodeSchema::scalar(ScalarForm::String, ScalarForm::Integer)->judgedInEachLayer(static function (ResolvedValueInterface $value): void {
            RuntimeLimits::fromResolvedValue($value);
        }));
    }
}
