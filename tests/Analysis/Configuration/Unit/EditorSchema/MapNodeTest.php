<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\EditorSchema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\EditorSchema\EditorSchemaProjection;
use Qualimetrix\Analysis\Configuration\EditorSchema\NodeProjection;
use Qualimetrix\Analysis\Configuration\EditorSchema\PortableRegex;
use Qualimetrix\Tests\Analysis\Configuration\Support\EditorSchemaValidator;
use stdClass;

#[CoversClass(NodeProjection::class)]
#[CoversClass(EditorSchemaProjection::class)]
#[CoversClass(PortableRegex::class)]
final class MapNodeTest extends TestCase
{
    use EditorSchemaAssertions;

    #[Test]
    public function itClosesTheDocumentToItsDeclaredRootsListedAlphabetically(): void
    {
        $schema = EditorSchemaProjection::project(NodeSchema::map(['paths' => self::text(), 'cache' => NodeSchema::map(['dir' => self::text()])]));

        self::assertSame(['cache', 'paths'], array_keys($schema['properties']));
        self::assertAccepted($schema, "paths: src\ncache: {dir: .cache}");
        self::assertRefusedAt($schema, 'unknown: 1', '/unknown');
    }

    /** @return iterable<string, array{string}> */
    public static function provideEmptyDocuments(): iterable
    {
        yield 'empty file' => [''];
        yield 'empty list' => ['[]'];
        yield 'empty map' => ['{}'];
    }

    #[Test]
    #[DataProvider('provideEmptyDocuments')]
    public function itAcceptsAnEmptyDocument(string $yaml): void
    {
        self::assertAccepted(EditorSchemaProjection::project(NodeSchema::map(['paths' => self::text()])), $yaml);
    }

    #[Test]
    public function itOffersTheCanonicalSpellingAndAcceptsTheOtherTwo(): void
    {
        $schema = self::documentWith(NodeSchema::map(['fail_on' => self::text()]));
        $map = $schema['properties']['key'];

        self::assertSame(['fail_on'], array_keys($map['properties']));
        self::assertArrayHasKey('^(?:fail-on|failOn)$', $map['patternProperties']);
        foreach (['fail_on', 'fail-on', 'failOn'] as $spelling) {
            self::assertAccepted($schema, \sprintf('key: {%s: error}', $spelling));
        }
        foreach (['Fail_On', 'FAILON', 'failon'] as $spelling) {
            self::assertRefusedAt($schema, \sprintf('key: {%s: error}', $spelling), '/key/' . $spelling);
        }
    }

    #[Test]
    public function itAcceptsEveryAcceptedSpellingOfARootKey(): void
    {
        $schema = EditorSchemaProjection::project(NodeSchema::map(['suppress_paths' => self::text()]));

        self::assertAccepted($schema, 'suppress-paths: src');
        self::assertAccepted($schema, 'suppressPaths: src');
    }

    /** @return iterable<string, array{string}> */
    public static function provideEmptyMaps(): iterable
    {
        yield 'unwritten' => ['~'];
        yield 'empty map' => ['{}'];
        yield 'empty collection' => ['[]'];
        yield 'unwritten key' => ['{dir: ~}'];
    }

    #[Test]
    #[DataProvider('provideEmptyMaps')]
    public function itAcceptsAMapWrittenEmptyOrUnwritten(string $value): void
    {
        self::assertAccepted(self::documentWith(NodeSchema::map(['dir' => self::text()])), 'key: ' . $value);
    }

    #[Test]
    public function itRefusesAnUnknownKeyWhateverItsValue(): void
    {
        $schema = self::documentWith(NodeSchema::map(['dir' => self::text()]));

        self::assertRefusedAt($schema, 'key: {nope: ~}', '/key/nope');
        self::assertRefusedAt($schema, 'key: [a]', '/key');
        self::assertRefusedAt($schema, 'key: text', '/key');
    }

    #[Test]
    public function itRefusesARetiredKeyInEverySpellingAndSaysWhy(): void
    {
        $schema = self::documentWith(NodeSchema::map(['new_key' => self::text()])->retiring(['old_key' => 'Write new_key instead.']));

        self::assertEquals(
            ['description' => 'Write new_key instead.', 'not' => new stdClass()],
            $schema['properties']['key']['patternProperties']['^(?:old_key|old-key|oldKey)$'],
        );
        foreach (['old_key', 'old-key', 'oldKey'] as $spelling) {
            self::assertRefusedAt($schema, \sprintf('key: {%s: x}', $spelling), '/key/' . $spelling);
            self::assertRefusedAt($schema, \sprintf('key: {%s: ~}', $spelling), '/key/' . $spelling);
        }
    }

    #[Test]
    public function itAcceptsTheBareValueOfABareField(): void
    {
        $schema = self::documentWith(NodeSchema::map([
            'enabled' => NodeSchema::scalar(ScalarForm::Boolean),
            'depth' => NodeSchema::scalar(ScalarForm::Integer),
        ])->bareFor('enabled'));

        self::assertAccepted($schema, 'key: false');
        self::assertAccepted($schema, 'key: {enabled: true, depth: 3}');
        self::assertRefusedAt($schema, 'key: 5', '/key');
        self::assertRefusedAt($schema, 'key: off-ish', '/key');
    }

    #[Test]
    public function itAdmitsTheYamlMergeKeyBesideTheKeysOfAMap(): void
    {
        $schema = self::documentWith(NodeSchema::map(['dir' => self::text()]));

        self::assertSame([], EditorSchemaValidator::errors($schema, json_decode('{"key": {"<<": {"dir": "a"}, "dir": "b"}, "<<": 1}', false, 512, \JSON_THROW_ON_ERROR)));
    }

    private static function text(): NodeSchema
    {
        return NodeSchema::scalar(ScalarForm::String);
    }
}
