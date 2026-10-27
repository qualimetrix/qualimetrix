<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\EditorSchema;

/**
 * The schema as a file: the same bytes for the same declarations.
 *
 * Control characters in patterns leave as JSON string escapes, so the
 * pattern an editor compiles holds the character itself.
 */
final class EditorSchemaJson
{
    /** @param array<string, mixed> $schema */
    public static function encode(array $schema): string
    {
        return json_encode(
            $schema,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR,
        ) . "\n";
    }
}
