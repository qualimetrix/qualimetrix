<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\EditorSchema;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NameVocabulary;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\RefusedName;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\EditorSchema\EditorSchemaProjection;
use Qualimetrix\Analysis\Configuration\EditorSchema\NodeProjection;

#[CoversClass(NodeProjection::class)]
final class NamedMapNodeTest extends TestCase
{
    use EditorSchemaAssertions;

    #[Test]
    public function itListsTheNamesOfAClosedVocabularyAndRefusesEveryOther(): void
    {
        $schema = self::documentWith(self::rules(), 'rules');

        self::assertSame(['complexity.ccn', 'size.class-count'], array_keys($schema['properties']['rules']['properties']));
        self::assertFalse($schema['properties']['rules']['additionalProperties']);
        self::assertAccepted($schema, 'rules: {complexity.ccn: {threshold: 5}}');
        self::assertRefusedAt($schema, 'rules: {complexity.cnn: {threshold: 5}}', '/rules/complexity.cnn');
        self::assertRefusedAt($schema, 'rules: {complexity-ccn: false}', '/rules/complexity-ccn');
    }

    /** @return iterable<string, array{string}> */
    public static function provideAcceptedEntries(): iterable
    {
        yield 'bare boolean' => ['false'];
        yield 'unwritten' => ['~'];
        yield 'empty map' => ['{}'];
        yield 'empty collection' => ['[]'];
    }

    #[Test]
    #[DataProvider('provideAcceptedEntries')]
    public function itAcceptsAnEntryBareOrWithoutABody(string $value): void
    {
        self::assertAccepted(self::documentWith(self::rules(), 'rules'), 'rules: {complexity.ccn: ' . $value . '}');
    }

    #[Test]
    public function itRefusesAnEntryOfAnotherForm(): void
    {
        self::assertRefusedAt(self::documentWith(self::rules(), 'rules'), 'rules: {complexity.ccn: 5}', '/rules/complexity.ccn');
    }

    #[Test]
    public function itAcceptsAMapOfNamedEntriesWrittenEmpty(): void
    {
        self::assertAccepted(self::documentWith(self::rules(), 'rules'), 'rules: []');
    }

    #[Test]
    public function itGivesEveryClosedNameItsOwnEntry(): void
    {
        $schema = self::documentWith(self::rules(), 'rules');

        self::assertAccepted($schema, 'rules: {size.class-count: {max: 3}}');
        self::assertRefusedAt($schema, 'rules: {complexity.ccn: {max: 3}}', '/rules/complexity.ccn/max');
    }

    /** @return iterable<string, array{?NameVocabulary}> */
    public static function provideOpenVocabularies(): iterable
    {
        yield 'any name' => [null];
        yield 'a predicate' => [NameVocabulary::predicate(static fn(string $name): ?RefusedName => null)];
        yield 'a sibling' => [NameVocabulary::fromSibling('layers', static fn(mixed $layers): array => [])];
    }

    #[Test]
    #[DataProvider('provideOpenVocabularies')]
    public function itLeavesAnOpenVocabularyToTheEngineAndJudgesEveryEntry(?NameVocabulary $names): void
    {
        $schema = self::documentWith(NodeSchema::namedMap(NodeSchema::map(['formula' => self::text()]), $names));

        self::assertAccepted($schema, 'key: {anything: {formula: x}, other: ~}');
        self::assertRefusedAt($schema, 'key: {anything: 5}', '/key/anything');
    }

    #[Test]
    public function itRecognisesAFixedNameInEveryAcceptedSpelling(): void
    {
        $schema = self::documentWith(NodeSchema::namedMap(self::text(), NameVocabulary::fixed(['first_name'])));

        self::assertAccepted($schema, 'key: {first_name: a, firstName: b}');
        self::assertRefusedAt($schema, 'key: {last_name: a}', '/key/last_name');
    }

    #[Test]
    public function itRefusesToProjectEntriesDerivedFromAnOpenVocabulary(): void
    {
        $node = NodeSchema::namedMapOf(static fn(string $name): NodeSchema => self::text(), NameVocabulary::predicate(static fn(string $name): ?RefusedName => null));

        $this->expectException(LogicException::class);
        EditorSchemaProjection::project(NodeSchema::map(['key' => $node]));
    }

    #[Test]
    public function itRefusesToProjectAClosedNameWithoutAnEntry(): void
    {
        $node = NodeSchema::namedMapOf(static fn(string $name): ?NodeSchema => null, NameVocabulary::closed(['a'], static fn(string $name): RefusedName => RefusedName::open('no')));

        $this->expectException(LogicException::class);
        EditorSchemaProjection::project(NodeSchema::map(['key' => $node]));
    }

    private static function rules(): NodeSchema
    {
        $enabled = NodeSchema::scalar(ScalarForm::Boolean);
        $entries = [
            'size.class-count' => NodeSchema::map(['enabled' => $enabled, 'max' => NodeSchema::scalar(ScalarForm::Integer)])->bareFor('enabled'),
            'complexity.ccn' => NodeSchema::map(['enabled' => $enabled, 'threshold' => NodeSchema::scalar(ScalarForm::Integer)])->bareFor('enabled'),
        ];

        return NodeSchema::namedMapOf(
            static fn(string $name): ?NodeSchema => $entries[$name] ?? null,
            NameVocabulary::closed(array_keys($entries), static fn(string $name): RefusedName => RefusedName::open('Unknown rule.')),
        );
    }

    private static function text(): NodeSchema
    {
        return NodeSchema::scalar(ScalarForm::String);
    }
}
