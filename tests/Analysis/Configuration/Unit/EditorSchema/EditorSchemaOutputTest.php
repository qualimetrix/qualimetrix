<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\EditorSchema;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NameVocabulary;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\RefusedName;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SchemaWordSet;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\Shorthand;
use Qualimetrix\Analysis\Configuration\EditorSchema\DefinitionPool;
use Qualimetrix\Analysis\Configuration\EditorSchema\EditorSchemaJson;
use Qualimetrix\Analysis\Configuration\EditorSchema\EditorSchemaProjection;
use Qualimetrix\Analysis\Configuration\EditorSchema\NodeProjection;
use Qualimetrix\Analysis\Configuration\EditorSchema\PortableRegex;

#[CoversClass(EditorSchemaProjection::class)]
#[CoversClass(EditorSchemaJson::class)]
#[CoversClass(DefinitionPool::class)]
#[CoversClass(NodeProjection::class)]
#[CoversClass(PortableRegex::class)]
final class EditorSchemaOutputTest extends TestCase
{
    use EditorSchemaAssertions;

    /** Escapes both dialects read as the escaped character itself, outside a class as well as inside. */
    private const string PORTABLE_ESCAPES = '\\^$.|?*+()[]{}';

    #[Test]
    public function itDeclaresDraft07WithoutAnIdentityOrAVersion(): void
    {
        $schema = EditorSchemaProjection::project(self::sections(self::fields()));

        self::assertSame(EditorSchemaProjection::DIALECT, $schema['$schema']);
        self::assertArrayNotHasKey('$id', $schema);
        self::assertArrayNotHasKey('version', $schema);
    }

    #[Test]
    public function itNamesDefinitionsByContentWhateverTheOrderOfSections(): void
    {
        $fields = self::fields();
        $reversed = array_reverse($fields, true);

        $schema = EditorSchemaProjection::project(self::sections($fields));

        self::assertNotSame([], $schema['definitions'] ?? []);
        self::assertSame(
            EditorSchemaJson::encode($schema),
            EditorSchemaJson::encode(EditorSchemaProjection::project(self::sections($reversed))),
        );
    }

    #[Test]
    public function itKeepsADefinitionNameWhenAnUnrelatedSectionIsAdded(): void
    {
        $fields = self::fields();
        $before = array_keys(EditorSchemaProjection::project(self::sections($fields))['definitions']);
        $after = array_keys(EditorSchemaProjection::project(self::sections([...$fields, 'zebra' => NodeSchema::scalar(ScalarForm::Boolean)]))['definitions']);

        self::assertSame($before, $after);
    }

    #[Test]
    public function itWritesASubtreeUsedOnceInPlace(): void
    {
        $schema = self::documentWith(NodeSchema::map(['dir' => NodeSchema::scalar(ScalarForm::String)])->withWording(description: 'Where the cache lives.'));

        self::assertArrayNotHasKey('definitions', $schema);
        self::assertSame('Where the cache lives.', $schema['properties']['key']['description']);
        self::assertSame(['object', 'array', 'null'], $schema['properties']['key']['type']);
    }

    #[Test]
    public function itDescribesASharedSubtreeBesideItsReference(): void
    {
        $paths = NodeSchema::list(NodeSchema::scalar(ScalarForm::String));
        $schema = EditorSchemaProjection::project(NodeSchema::map([
            'include' => $paths->withWording(description: 'Paths to analyse.'),
            'exclude' => $paths,
        ]));

        $reference = $schema['properties']['exclude'];
        self::assertSame(['$ref'], array_keys($reference));
        self::assertSame(['description' => 'Paths to analyse.', 'allOf' => [$reference]], $schema['properties']['include']);
        self::assertArrayHasKey(substr($reference['$ref'], \strlen('#/definitions/')), $schema['definitions']);

        self::assertAccepted($schema, 'include: [src]');
        self::assertRefusedAt($schema, 'include: [5]', '/include/0');
        self::assertRefusedAt($schema, 'exclude: [5]', '/exclude/0');
    }

    #[Test]
    public function itDescribesAScalarInPlace(): void
    {
        $schema = self::documentWith(NodeSchema::scalar(ScalarForm::Integer)->withWording(description: 'How many workers.'));

        self::assertSame(['description' => 'How many workers.', 'type' => ['integer', 'null']], $schema['properties']['key']);
    }

    #[Test]
    public function itLeavesAValueItsOwnerJudgesToItsOwner(): void
    {
        $schema = self::documentWith(NodeSchema::opaque());

        self::assertSame(['description' => 'The form of this value is judged by its owner.'], $schema['properties']['key']);
        foreach (['~', '5', '[a, ~]', '{any: thing}'] as $value) {
            self::assertAccepted($schema, 'key: ' . $value);
        }
    }

    #[Test]
    public function itAdmitsTheYamlMergeKeyOnEveryObjectNode(): void
    {
        $schema = EditorSchemaProjection::project(self::sections(self::fields()));
        $objects = self::objectNodes($schema);

        self::assertGreaterThan(5, \count($objects));
        foreach ($objects as $object) {
            self::assertTrue($object['patternProperties']['^<<$'] ?? null, json_encode($object, \JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function itWritesOnlyPatternsThatEcmaScriptAndPcreReadAlike(): void
    {
        $decoded = json_decode(EditorSchemaJson::encode(EditorSchemaProjection::project(self::sections(self::fields()))), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));
        $patterns = self::patterns($decoded);

        self::assertGreaterThanOrEqual(6, \count(array_unique($patterns)));
        foreach ($patterns as $pattern) {
            self::assertNotFalse(@preg_match('/' . str_replace('/', '\/', $pattern) . '/u', ''), $pattern);
            self::assertDoesNotMatchRegularExpression('/\(\?(?!:)/', $pattern, 'inline flags, lookaround and named groups are not portable');
            foreach (self::escapedCharacters($pattern) as $escaped) {
                self::assertStringContainsString($escaped, self::PORTABLE_ESCAPES, \sprintf('"\%s" in %s is not portable', $escaped, $pattern));
            }
        }
    }

    #[Test]
    public function itEncodesTheSameDeclarationsToTheSameBytes(): void
    {
        $json = EditorSchemaJson::encode(EditorSchemaProjection::project(self::sections(self::fields())));

        self::assertSame($json, EditorSchemaJson::encode(EditorSchemaProjection::project(self::sections(self::fields()))));
        self::assertStringEndsWith("}\n", $json);
        self::assertStringNotContainsString('\/', $json);
        self::assertStringContainsString("\n    \"type\"", $json);
    }

    #[Test]
    public function itRefusesToProjectADocumentWhoseRootIsNotAMap(): void
    {
        $this->expectException(LogicException::class);
        EditorSchemaProjection::project(NodeSchema::list(NodeSchema::scalar(ScalarForm::String)));
    }

    /**
     * Every row of the mapping at least once, with the shared and the
     * pattern-bearing subtrees appearing more than once.
     *
     * @return array<string, NodeSchema>
     */
    private static function fields(): array
    {
        $integer = NodeSchema::scalar(ScalarForm::Integer)->atLeast(0);
        $text = NodeSchema::scalar(ScalarForm::String)->nonEmpty();
        $paths = NodeSchema::set($text);
        $rule = NodeSchema::map(
            [
                'enabled' => NodeSchema::scalar(ScalarForm::Boolean),
                'warning' => $integer,
                'error' => $integer,
                'severity' => NodeSchema::scalar(ScalarForm::String)->words(SchemaWordSet::foldingCase('error', 'warning')),
                'scope' => NodeSchema::scalar(ScalarForm::String)->words(SchemaWordSet::of('class', 'namespace')),
                'suppress-paths' => $paths,
                'allowed-prefixes' => NodeSchema::list($text)->admittingBareElement(),
            ],
            Shorthand::spreading('threshold', ['warning', 'error']),
        )->bareFor('enabled')->retiring(['exclude-paths' => 'Write suppress-paths instead.']);

        return [
            'paths' => NodeSchema::list($text),
            'memory_limit' => NodeSchema::scalar(ScalarForm::String, ScalarForm::Integer),
            'suppress_paths' => $paths,
            'rules' => NodeSchema::namedMap($rule, NameVocabulary::closed(['complexity.ccn', 'size.loc'], static fn(string $name): RefusedName => RefusedName::open('Unknown.'))),
            'computed_metrics' => NodeSchema::namedMap($rule, NameVocabulary::predicate(static fn(string $name): ?RefusedName => null)),
            'architecture' => NodeSchema::map(['layers' => NodeSchema::list(NodeSchema::map(['name' => $text, 'patterns' => NodeSchema::opaque()]))]),
        ];
    }

    /** @param array<string, NodeSchema> $fields */
    private static function sections(array $fields): NodeSchema
    {
        return NodeSchema::map($fields);
    }

    /**
     * @param array<mixed> $schema
     *
     * @return list<array<mixed>>
     */
    private static function objectNodes(array $schema): array
    {
        $found = \is_array($schema['type'] ?? null) && \in_array('object', $schema['type'], true) && isset($schema['maxItems']) ? [$schema] : [];
        foreach ($schema as $child) {
            if (\is_array($child)) {
                $found = [...$found, ...self::objectNodes($child)];
            }
        }

        return $found;
    }

    /**
     * @param array<mixed> $schema
     *
     * @return list<string>
     */
    private static function patterns(array $schema): array
    {
        $found = \is_string($schema['pattern'] ?? null) ? [$schema['pattern']] : [];
        if (\is_array($schema['patternProperties'] ?? null)) {
            $found = [...$found, ...array_map('strval', array_keys($schema['patternProperties']))];
        }

        foreach ($schema as $child) {
            if (\is_array($child)) {
                $found = [...$found, ...self::patterns($child)];
            }
        }

        return $found;
    }

    /** @return list<string> the character after every backslash that escapes */
    private static function escapedCharacters(string $pattern): array
    {
        preg_match_all('/\\\\(.)/s', $pattern, $matches);

        return $matches[1];
    }
}
