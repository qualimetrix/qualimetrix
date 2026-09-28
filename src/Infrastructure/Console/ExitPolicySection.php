<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;

final class ExitPolicySection implements DocumentSectionSchemaInterface
{
    public function key(): string
    {
        return ExitPolicy::CONFIGURATION_KEY;
    }

    public function schema(): NodeSchema
    {
        return NodeSchema::scalar(ScalarForm::String)->judgedInEachLayer(static function (ResolvedValueInterface $value): void {
            ExitPolicy::fromResolvedValue($value);
        });
    }
}
