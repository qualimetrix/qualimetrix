<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\RuntimePolicy;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\LiteralHint;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SchemaWordSet;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Severity;

final class ExitPolicySection implements DocumentSectionSchemaInterface
{
    public function declaration(): SectionDeclaration
    {
        return new SectionDeclaration(
            ExitPolicy::CONFIGURATION_KEY,
            NodeSchema::scalar(ScalarForm::String)
                ->words(SchemaWordSet::of(...ExitPolicy::acceptedWords()))
                ->withWording(
                    hint: 'Use "none" to never fail on findings.',
                    literalHints: [
                        new LiteralHint(false, 'Boolean false was retired; use "none" to keep findings from failing the run.'),
                        new LiteralHint(Severity::Info->value, ExitPolicy::INFO_ADVICE),
                    ],
                ),
        );
    }
}
