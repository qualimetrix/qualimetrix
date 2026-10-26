<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\EditorSchema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\EditorSchema\EditorSchemaProjection;
use Qualimetrix\Tests\Analysis\Configuration\Support\EditorSchemaValidator;

/**
 * The validator every editor-schema test relies on refuses what only draft-07
 * refuses; judged in its default mode, the same data would pass.
 */
final class EditorSchemaValidatorTest extends TestCase
{
    #[Test]
    public function itAppliesConditionalSubschemas(): void
    {
        $schema = self::draft07(['if' => ['required' => ['a']], 'then' => ['required' => ['b']]]);

        self::assertSame([['pointer' => '', 'messages' => ['The property b is required']]], EditorSchemaValidator::yamlErrors($schema, 'a: 1'));
        self::assertSame([], EditorSchemaValidator::yamlErrors($schema, '{a: 1, b: 2}'));
    }

    #[Test]
    public function itAppliesPropertyNames(): void
    {
        self::assertNotSame([], EditorSchemaValidator::yamlErrors(self::draft07(['propertyNames' => ['pattern' => '^x']]), 'y: 1'));
    }

    #[Test]
    public function itAppliesAFalseSubschemaAtTheKeyItGuards(): void
    {
        $errors = EditorSchemaValidator::yamlErrors(self::draft07(['properties' => ['a' => ['properties' => ['b' => false]]]]), 'a: {b: 1}');

        self::assertSame(['/a/b'], array_column($errors, 'pointer'));
    }

    #[Test]
    public function itKeepsAnEmptyMapApartFromAnEmptyList(): void
    {
        $schema = self::draft07(['properties' => ['map' => ['type' => 'object'], 'list' => ['type' => 'array']]]);

        self::assertSame([], EditorSchemaValidator::yamlErrors($schema, "map: {}\nlist: []"));
        self::assertSame(['/map', '/list'], array_column(EditorSchemaValidator::yamlErrors($schema, "map: []\nlist: {}"), 'pointer'));
    }

    #[Test]
    public function itEscapesKeysInPointers(): void
    {
        $errors = EditorSchemaValidator::yamlErrors(self::draft07(['additionalProperties' => false]), '"a/b~c": 1');

        self::assertSame(['/a~1b~0c'], array_column($errors, 'pointer'));
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function draft07(array $schema): array
    {
        return ['$schema' => EditorSchemaProjection::DIALECT, ...$schema];
    }
}
