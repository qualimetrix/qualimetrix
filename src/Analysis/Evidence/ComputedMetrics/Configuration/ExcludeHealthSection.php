<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;

/** The `exclude_health:` section: dimension names every layer adds to. */
final readonly class ExcludeHealthSection implements DocumentSectionSchemaInterface
{
    public const string KEY = 'exclude_health';

    public function key(): string
    {
        return self::KEY;
    }

    public function schema(): NodeSchema
    {
        return NodeSchema::set(NodeSchema::scalar(ScalarForm::String));
    }
}
