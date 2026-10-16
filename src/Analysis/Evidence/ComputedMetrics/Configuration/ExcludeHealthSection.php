<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;

/** The `exclude_health:` section: dimension names every layer adds to. */
final readonly class ExcludeHealthSection implements DocumentSectionSchemaInterface
{
    public const string KEY = 'exclude_health';

    public function declaration(): SectionDeclaration
    {
        return new SectionDeclaration(self::KEY, NodeSchema::set(NodeSchema::scalar(ScalarForm::String)));
    }
}
