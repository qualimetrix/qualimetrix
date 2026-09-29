<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;

final readonly class OutputFormatSection implements DocumentSectionSchemaInterface
{
    public function __construct(private OutputFormatVocabulary $vocabulary) {}

    public function declaration(): SectionDeclaration
    {
        return new SectionDeclaration(
            ConfigSchema::FORMAT,
            NodeSchema::scalar(ScalarForm::String)->judgedInEachLayer(function (ResolvedValueInterface $format): void {
                $this->vocabulary->accepted($format);
            }),
        );
    }
}
