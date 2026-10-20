<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\RuntimePolicy;

use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;

final class ExitPolicySection implements DocumentSectionSchemaInterface
{
    public function declaration(): SectionDeclaration
    {
        return new SectionDeclaration(
            ExitPolicy::CONFIGURATION_KEY,
            NodeSchema::scalar(ScalarForm::String)
                ->withHint('Boolean false was retired; use "none" to keep findings from failing the run.', onlyFor: false)
                ->judgedInEachLayer(static function (ResolvedValueInterface $value): void {
                    ExitPolicy::fromResolvedValue($value);
                }),
        );
    }
}
