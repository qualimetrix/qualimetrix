<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;

/**
 * The whole document's schema: every declared section under one root map.
 */
final readonly class DocumentSchema
{
    private NodeSchema $root;

    /** @param list<DocumentSectionSchemaInterface> $sections */
    public function __construct(array $sections)
    {
        $fields = [];
        foreach ($sections as $section) {
            if (isset($fields[$section->key()])) {
                throw new LogicException(\sprintf('Two sections declare the configuration root "%s".', $section->key()));
            }

            $fields[$section->key()] = $section->schema();
        }

        $this->root = NodeSchema::map($fields);
    }

    public function root(): NodeSchema
    {
        return $this->root;
    }
}
