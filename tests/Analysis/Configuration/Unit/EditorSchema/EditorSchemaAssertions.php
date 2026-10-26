<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\EditorSchema;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\EditorSchema\EditorSchemaProjection;
use Qualimetrix\Tests\Analysis\Configuration\Support\EditorSchemaValidator;

/** A node projected as the only key of a document, and YAML judged against it. */
trait EditorSchemaAssertions
{
    /** @return array<string, mixed> */
    private static function documentWith(NodeSchema $node, string $key = 'key'): array
    {
        return EditorSchemaProjection::project(NodeSchema::map([$key => $node]));
    }

    /** @param array<string, mixed> $schema */
    private static function assertAccepted(array $schema, string $yaml): void
    {
        self::assertSame([], EditorSchemaValidator::yamlErrors($schema, $yaml), $yaml);
    }

    /** @param array<string, mixed> $schema */
    private static function assertRefusedAt(array $schema, string $yaml, string ...$pointers): void
    {
        $found = array_column(EditorSchemaValidator::yamlErrors($schema, $yaml), 'pointer');
        sort($found);
        sort($pointers);

        self::assertSame($pointers, $found, $yaml);
    }
}
