<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\EditorSchema;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;

/**
 * The configuration document's declarations projected into a draft-07 JSON
 * Schema for editors: completion and hints for every declared key, and a
 * refusal only where one file is malformed whatever the other layers say.
 *
 * The schema promises form, not meaning. What an owner's closure judges, and
 * whatever depends on merging layers, is left to the engine; where the editor
 * and the engine type a scalar alike, the schema never refuses what the
 * engine accepts.
 */
final class EditorSchemaProjection
{
    public const string DIALECT = 'http://json-schema.org/draft-07/schema#';

    /** @return array<string, mixed> */
    public static function project(NodeSchema $documentRoot): array
    {
        $pool = new DefinitionPool();

        return $pool->resolve(['$schema' => self::DIALECT, ...(new NodeProjection($pool))->root($documentRoot)]);
    }
}
