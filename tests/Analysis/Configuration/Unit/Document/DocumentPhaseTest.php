<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Document;

use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedOpaque;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedScalar;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NameVocabulary;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Configuration\Document\KeyClaims;
use Qualimetrix\Analysis\Configuration\Document\KeyRecognition;
use Qualimetrix\Analysis\Configuration\Document\LayerReading;
use Qualimetrix\Analysis\Configuration\Document\NameRecognition;
use Qualimetrix\Analysis\Configuration\Document\WrittenForm;
use Qualimetrix\Analysis\Configuration\Document\WrittenNames;
use Qualimetrix\Analysis\Configuration\UndeclaredRoot;
use Qualimetrix\Tests\Analysis\Configuration\Fixtures\Document\SampleDocument;

/**
 * The engine's phases in their fixed order: each layer recognised and shaped
 * alone (1), merged (2), names from the merged document judged (3), and the
 * result attributed (4) — and what `~` means in each.
 */
#[CoversClass(DocumentComposer::class)]
#[CoversClass(LayerReading::class)]
#[CoversClass(KeyRecognition::class)]
#[CoversClass(NameRecognition::class)]
#[CoversClass(KeyClaims::class)]
#[CoversClass(WrittenForm::class)]
#[CoversClass(WrittenNames::class)]
final class DocumentPhaseTest extends TestCase
{
    #[Test]
    public function itReadsAKnownKeyWrittenAsTildeAsUnwrittenSoTheLowerLayerStands(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['fail_on' => 'error', 'cache' => ['dir' => '/tmp/c']]),
            SampleDocument::file(['fail_on' => null, 'cache' => ['dir' => null]]),
        );

        self::assertSame('error', $document->get('fail_on')?->plain());
        self::assertSame('strict', self::leaf($document->get('fail_on'))->provenance->origin->locator());
        self::assertSame(['dir' => '/tmp/c'], $document->get('cache')?->plain());
    }

    #[Test]
    public function itLeavesAKeyOnlyEverWrittenAsTildeAbsent(): void
    {
        $document = SampleDocument::compose(SampleDocument::file(['fail_on' => null, 'cache' => ['dir' => null]]));

        self::assertNull($document->get('fail_on'));
        self::assertNull($document->get('cache'));
    }

    /**
     * @param array<string, mixed> $document
     * @param list<string> $path
     */
    #[Test]
    #[DataProvider('provideUnknownKeysWrittenAsTilde')]
    public function itRefusesAnUnknownKeyWhateverItsValueIncludingTilde(array $document, array $path, string $written): void
    {
        $refusal = self::refusal(static fn() => SampleDocument::compose(SampleDocument::file($document)));

        self::assertSame([ConfigurationSource::ConfigFile], self::kinds($refusal));
        self::assertSame('/p/qmx.yaml', $refusal->origin()->locator());
        self::assertSame($path, $refusal->position()?->segments);
        self::assertSame($written, $refusal->position()->written);
        self::assertTrue($refusal->position()->closed);
        self::assertStringContainsString(\sprintf('"%s"', implode('.', $path)), $refusal->summary());
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>, string}> */
    public static function provideUnknownKeysWrittenAsTilde(): iterable
    {
        yield 'root' => [['fail_onn' => null], ['fail_onn'], 'fail_onn'];
        yield 'section' => [['cache' => ['dri' => null]], ['cache', 'dri'], 'dri'];
        yield 'entry of a named map' => [['computed_metrics' => ['health.x' => ['formla' => null]]], ['computed_metrics', 'health.x', 'formla'], 'formla'];
        yield 'with a value' => [['cache' => ['dri' => '/tmp']], ['cache', 'dri'], 'dri'];
    }

    #[Test]
    public function itSuggestsTheClosestKeyOfAnUnknownOne(): void
    {
        $refusal = self::refusal(static fn() => SampleDocument::compose(SampleDocument::file(['fail_onn' => 'error'])));

        self::assertStringContainsString('(did you mean "fail_on"?)', $refusal->summary());
    }

    /** @param array<string, mixed> $document */
    #[Test]
    #[DataProvider('provideAcceptedSpellings')]
    public function itAcceptsTheSnakeCamelAndKebabSpellingOfAKey(array $document, string $root, string $key): void
    {
        self::assertNotNull(SampleDocument::compose(SampleDocument::file($document))->get($root, ...($key === '' ? [] : [$key])));
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function provideAcceptedSpellings(): iterable
    {
        yield 'snake' => [['fail_on' => 'error'], 'fail_on', ''];
        yield 'camel' => [['failOn' => 'error'], 'fail_on', ''];
        yield 'kebab' => [['fail-on' => 'error'], 'fail_on', ''];
        yield 'kebab canonical, snake written' => [['architecture' => ['coverage_gap' => 'error']], 'architecture', 'coverage-gap'];
        yield 'kebab canonical, camel written' => [['architecture' => ['coverageGap' => 'error']], 'architecture', 'coverage-gap'];
    }

    /**
     * @param array<string, mixed> $document
     * @param list<string> $path
     */
    #[Test]
    #[DataProvider('provideOtherSpellings')]
    public function itRefusesAnyOtherSpellingOfAKeyWithTheCanonicalOne(array $document, array $path, string $canonical): void
    {
        $refusal = self::refusal(static fn() => SampleDocument::compose(SampleDocument::file($document)));

        self::assertSame($path, $refusal->position()?->segments);
        self::assertSame([$canonical], $refusal->position()->accepted);
        self::assertStringContainsString(\sprintf('write "%s"', $canonical), $refusal->summary());
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>, string}> */
    public static function provideOtherSpellings(): iterable
    {
        yield 'Title-case root' => [['Paths' => ['src']], ['Paths'], 'paths'];
        yield 'upper snake root' => [['FAIL_ON' => 'error'], ['FAIL_ON'], 'fail_on'];
        yield 'joined lowercase root' => [['failon' => 'error'], ['failon'], 'fail_on'];
        yield 'Title-case in a section' => [['architecture' => ['Coverage-Gap' => 'error']], ['architecture', 'Coverage-Gap'], 'coverage-gap'];
        yield 'upper-case fixed name' => [
            ['computed_metrics' => ['health.x' => ['formulas' => ['CALLABLE' => 'a']]]],
            ['computed_metrics', 'health.x', 'formulas', 'CALLABLE'],
            'callable',
        ];
    }

    #[Test]
    public function itRefusesTwoSpellingsOfOneKeyInOneMapping(): void
    {
        $refusal = self::refusal(static fn() => SampleDocument::compose(SampleDocument::file(['cache' => ['dir' => '/a', 'Dir' => '/b']])));

        self::assertStringContainsString('Key "cache.Dir"', $refusal->summary(), 'A style no spelling has is refused before it can collide.');

        $refusal = self::refusal(static fn() => SampleDocument::compose(SampleDocument::file(['fail_on' => 'error', 'failOn' => 'warning'])));

        self::assertStringContainsString('"fail_on" and "failOn"', $refusal->summary());
        self::assertSame(['failOn'], $refusal->position()?->segments);
    }

    #[Test]
    public function itRefusesAMalformedValueInALayerAHigherLayerOverrides(): void
    {
        $refusal = self::refusal(static fn() => SampleDocument::compose(
            SampleDocument::preset(['cache' => ['enabled' => 'yes']]),
            SampleDocument::file(['cache' => ['enabled' => false]]),
        ));

        self::assertSame('strict', $refusal->origin()->locator());
        self::assertSame(['cache', 'enabled'], $refusal->position()?->segments);
        self::assertStringContainsString('must be boolean, got string', $refusal->summary());
    }

    /**
     * @param array<string, mixed> $document
     * @param list<string> $path
     */
    #[Test]
    #[DataProvider('provideWrongShapes')]
    public function itRefusesAValueOfTheWrongShape(array $document, array $path, string $message): void
    {
        $refusal = self::refusal(static fn() => SampleDocument::compose(SampleDocument::file($document)));

        self::assertSame($path, $refusal->position()?->segments);
        self::assertStringContainsString($message, $refusal->summary());
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>, string}> */
    public static function provideWrongShapes(): iterable
    {
        yield 'scalar for a map' => [['cache' => 'on'], ['cache'], 'must be a map, got string'];
        yield 'list for a map' => [['cache' => ['/tmp']], ['cache'], 'must be a map, got a list'];
        yield 'map for a list' => [['paths' => ['a' => 'src']], ['paths'], 'must be a list, got a map'];
        yield 'scalar for a list' => [['paths' => 'src'], ['paths'], 'must be a list, got string'];
        yield 'map for a scalar' => [['fail_on' => ['error']], ['fail_on'], 'must be string, got a list'];
        yield 'integer string list item' => [['only_rules' => ['a', 5]], ['only_rules', '1'], 'must be string, got int'];
        yield 'boolean string list item' => [['only_rules' => [true]], ['only_rules', '0'], 'must be string, got bool'];
        yield 'map string list item' => [['only_rules' => [['a' => 'b']]], ['only_rules', '0'], 'must be string, got a map'];
        yield 'null string list item' => [['only_rules' => ['a', null]], ['only_rules', '1'], 'Item 1 of "only_rules" in configuration file "/p/qmx.yaml" is null'];
        yield 'null set item' => [['exclude' => [null]], ['exclude', '0'], 'is null (`~`)'];
        yield 'empty map list item' => [['architecture' => ['layers' => [['name' => 'a'], []]]], ['architecture', 'layers', '1'], 'Item 1 of "architecture.layers"'];
        yield 'list item of nothing but tilde' => [['architecture' => ['layers' => [['name' => null]]]], ['architecture', 'layers', '0'], 'writes nothing'];
    }

    #[Test]
    public function itAcceptsEitherDeclaredScalarForm(): void
    {
        self::assertSame(-1, SampleDocument::compose(SampleDocument::file(['memory_limit' => -1]))->get('memory_limit')?->plain());
        self::assertSame('1G', SampleDocument::compose(SampleDocument::file(['memory_limit' => '1G']))->get('memory_limit')?->plain());
    }

    #[Test]
    public function itRefusesAShorthandMixedWithItsFullFormInOneLayer(): void
    {
        $refusal = self::refusal(static fn() => SampleDocument::compose(
            SampleDocument::file(['computed_metrics' => ['health.x' => ['threshold' => 5, 'warning' => 3]]]),
        ));

        self::assertSame('/p/qmx.yaml', $refusal->origin()->locator());
        self::assertSame(['computed_metrics', 'health.x', 'threshold'], $refusal->position()?->segments);
        self::assertStringContainsString('both "threshold" and "warning"', $refusal->summary());
    }

    #[Test]
    public function itJudgesANameDrawnFromTheDocumentAfterMergingTheLayersThatDeclareIt(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::preset(['architecture' => ['allow' => ['infra' => ['domain']]]]),
            SampleDocument::file(['architecture' => ['layers' => [['name' => 'domain'], ['name' => 'infra']]]]),
        );

        self::assertSame(['infra' => ['domain']], $document->get('architecture', 'allow')?->plain());
    }

    #[Test]
    public function itRefusesANameTheMergedDocumentDoesNotDeclareNamingItsWriter(): void
    {
        $refusal = self::refusal(static fn() => SampleDocument::compose(
            SampleDocument::preset(['architecture' => ['layers' => [['name' => 'domain'], ['name' => 'infra']]]]),
            SampleDocument::file(['architecture' => ['allow' => ['infr' => ['domain']]]]),
        ));

        self::assertSame([ConfigurationSource::ConfigFile], self::kinds($refusal));
        self::assertSame(['architecture', 'allow', 'infr'], $refusal->position()?->segments);
        self::assertSame(['domain', 'infra'], $refusal->position()->accepted);
        self::assertStringContainsString('(did you mean "infra"?)', $refusal->summary());
    }

    #[Test]
    public function itRefusesAnUnknownNameWrittenAsTilde(): void
    {
        $refusal = self::refusal(static fn() => SampleDocument::compose(
            SampleDocument::file(['architecture' => ['layers' => [['name' => 'domain']], 'allow' => ['infra' => null]]]),
        ));

        self::assertSame(['architecture', 'allow', 'infra'], $refusal->position()?->segments);
        self::assertSame('/p/qmx.yaml', $refusal->origin()->locator());
    }

    #[Test]
    public function itNamesEveryLayerThatWroteAnUnknownName(): void
    {
        $refusal = self::refusal(static fn() => SampleDocument::compose(
            SampleDocument::preset(['architecture' => ['allow' => ['ghost' => ['a']]]]),
            SampleDocument::file(['architecture' => ['allow' => ['ghost' => ['b']]]]),
        ));

        self::assertSame(['strict', '/p/qmx.yaml'], array_map(static fn(ConfigurationOrigin $origin): ?string => $origin->locator(), $refusal->sources()));
        self::assertSame([], $refusal->position()?->accepted, 'No layer is declared, so nothing is accepted.');
    }

    #[Test]
    public function itNamesTheOptionOfACommandLineValueAndGivesItNoPosition(): void
    {
        $document = SampleDocument::compose(
            SampleDocument::file(['fail_on' => 'warning']),
            SampleDocument::cli(['fail_on' => 'error'], ['fail_on' => '--fail-on']),
        );
        $leaf = self::leaf($document->get('fail_on'));
        self::assertSame('--fail-on', $leaf->provenance->origin->locator());
        self::assertNull($leaf->provenance->path);

        $refusal = self::refusal(static fn() => SampleDocument::compose(
            SampleDocument::cli(['fail_on' => 5], ['fail_on' => '--fail-on']),
        ));
        self::assertSame(ConfigurationSource::CommandLine, $refusal->origin()->source());
        self::assertSame('--fail-on', $refusal->origin()->locator());
        self::assertNull($refusal->position());
        self::assertStringContainsString('Option --fail-on must be string', $refusal->summary());
    }

    #[Test]
    public function itCarriesAKnownRootNoOwnerDeclaredYetAndRefusesAnyOtherRoot(): void
    {
        $layer = SampleDocument::file(['legacy_root' => ['x' => 1]]);

        $carried = DocumentComposer::compose(new DocumentSchema([new UndeclaredRoot('legacy_root')]), [$layer]);
        self::assertInstanceOf(ResolvedOpaque::class, $carried->get('legacy_root'));
        self::assertSame([['x' => 1]], $carried->get('legacy_root')->plain());

        $refusal = self::refusal(static fn() => DocumentComposer::compose(SampleDocument::schema(), [$layer]));
        self::assertSame(['legacy_root'], $refusal->position()?->segments);
    }

    #[Test]
    public function itAppliesTheSpellingRuleToARootNoOwnerDeclaredYet(): void
    {
        $refusal = self::refusal(static fn() => DocumentComposer::compose(
            new DocumentSchema([new UndeclaredRoot('legacy_root')]),
            [SampleDocument::file(['Legacy_Root' => ['x' => 1]])],
        ));

        self::assertSame(['legacy_root'], $refusal->position()?->accepted);
    }

    #[Test]
    public function itRejectsASiblingVocabularyAnywhereBelowAListItemAsASchemaDefect(): void
    {
        $section = new readonly class implements DocumentSectionSchemaInterface {
            public function key(): string
            {
                return 'groups';
            }

            public function schema(): NodeSchema
            {
                return NodeSchema::list(NodeSchema::map([
                    'members' => NodeSchema::map([
                        'names' => NodeSchema::stringList(),
                        'allow' => NodeSchema::namedMap(
                            NodeSchema::stringList(),
                            NameVocabulary::fromSibling('names', static fn(mixed $names): array => \is_array($names) ? array_values(array_filter($names, 'is_string')) : []),
                        ),
                    ]),
                ]));
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"groups.0.members.allow": a name vocabulary drawn from a sibling cannot be judged inside a list item.');

        DocumentComposer::compose(new DocumentSchema([$section]), [SampleDocument::file(['groups' => [['members' => ['allow' => ['a' => []]]]]])]);
    }

    #[Test]
    public function itComposesNoLayersIntoAnEmptyDocument(): void
    {
        $document = SampleDocument::compose();

        self::assertSame([], $document->roots());
        self::assertSame([], $document->diagnostics());
    }

    private static function leaf(mixed $value): ResolvedScalar
    {
        self::assertInstanceOf(ResolvedScalar::class, $value);

        return $value;
    }

    /** @return list<ConfigurationSource> */
    private static function kinds(ConfigurationRefusal $refusal): array
    {
        return array_map(static fn(ConfigurationOrigin $origin): ConfigurationSource => $origin->source(), $refusal->sources());
    }

    private static function refusal(Closure $compose): ConfigurationRefusal
    {
        try {
            $compose();
        } catch (ConfigurationRefusal $refusal) {
            return $refusal;
        }

        self::fail('Expected a ConfigurationRefusal.');
    }
}
