<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;

/** Document forms projected from a producer's declared option surface. */
interface RuleOptionDocumentFormsInterface
{
    public function schema(RuleOptionSurface $surface): NodeSchema;

    public function schemaAt(RuleOptionSurface $surface, RuleOptionAddress $address): NodeSchema;
}
