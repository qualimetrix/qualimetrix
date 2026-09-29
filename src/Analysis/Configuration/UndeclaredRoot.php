<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;

/**
 * A known root whose owner has not declared its section: recognised by the
 * engine's spelling rule and carried unread, per layer, for the owner to fold.
 */
final readonly class UndeclaredRoot implements DocumentSectionSchemaInterface
{
    public function __construct(private string $key) {}

    public function declaration(): SectionDeclaration
    {
        return new SectionDeclaration($this->key, NodeSchema::opaque());
    }
}
