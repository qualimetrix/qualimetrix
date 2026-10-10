<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit;

use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfigurationFactory;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureFactoryResult;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureSection;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\LongFormAllowEntryNormalizer;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\SectionSpot;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\ArchitectureDocument;

/**
 * The `architecture:` section read through the configuration document engine:
 * which keys it recognises whatever their value, how the layers that wrote it
 * merge, and whose writing a refusal names.
 */
#[CoversClass(ArchitectureSection::class)]
#[CoversClass(SectionSpot::class)]
#[CoversClass(ArchitectureConfigurationFactory::class)]
#[CoversClass(LongFormAllowEntryNormalizer::class)]
final class ArchitectureSectionTest extends TestCase
{
    private const array LAYERS = [
        ['name' => 'domain', 'patterns' => ['App\\Domain']],
        ['name' => 'infra', 'patterns' => ['App\\Infra']],
    ];

    /**
     * Each was accepted before whenever its value was `~`: the null-valued key
     * was erased before the owner compared the keys against its vocabulary.
     *
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function provideMisspeltKeysWrittenAsTilde(): iterable
    {
        yield 'section' => [['layers' => self::LAYERS, 'layres' => null], ['architecture', 'layres']];
        yield 'layer entry' => [
            ['layers' => [['name' => 'domain', 'patterns' => ['App\\Domain'], 'patern' => null]]],
            ['architecture', 'layers', '0', 'patern'],
        ];
        yield 'exclude block' => [
            ['layers' => [['name' => 'domain', 'patterns' => ['App\\Domain'], 'exclude' => ['sufix' => null, 'patterns' => ['App\\Domain\\Legacy']]]]],
            ['architecture', 'layers', '0', 'exclude', 'sufix'],
        ];
        yield 'long-form allow target' => [
            ['layers' => self::LAYERS, 'allow' => ['infra' => [['target' => 'domain', 'relatons' => null]]]],
            ['architecture', 'allow', 'infra', '0', 'relatons'],
        ];
    }

    /**
     * @param array<string, mixed> $section
     * @param list<string> $path
     */
    #[Test]
    #[DataProvider('provideMisspeltKeysWrittenAsTilde')]
    public function itRefusesAMisspeltKeyWrittenAsTildeNamingItsFileAndSpelling(array $section, array $path): void
    {
        $refusal = self::refusal(static fn() => self::configure(ArchitectureDocument::file($section)));

        self::assertCount(1, $refusal->sources());
        self::assertSame(ConfigurationSource::ConfigFile, $refusal->sources()[0]->source());
        self::assertSame(ArchitectureDocument::FILE, $refusal->sources()[0]->locator());
        self::assertSame($path, $refusal->position()?->segments);
        self::assertSame($path[\count($path) - 1], $refusal->position()->written);
        self::assertTrue($refusal->position()->closed);
    }

    #[Test]
    public function itRefusesAnAllowSourceNamingNoLayerWithTheFileThatWroteIt(): void
    {
        // The layers come from a preset, the misspelt source from the file:
        // the name is judged against the merged layers, and the refusal
        // names the file, whose line is the one to fix.
        $refusal = self::refusal(static fn() => self::configure(ArchitectureDocument::compose(
            ArchitectureDocument::presetLayer(['layers' => self::LAYERS]),
            ArchitectureDocument::fileLayer(['allow' => ['infrq' => ['domain']]]),
        )));

        self::assertSame([ConfigurationSource::ConfigFile], self::kinds($refusal));
        self::assertSame(['architecture', 'allow', 'infrq'], $refusal->position()?->segments);
        self::assertStringContainsString('Unknown name "infrq" under "architecture.allow"', $refusal->getMessage());
    }

    #[Test]
    public function itRefusesAnAllowSourceNamingNoLayerWhateverIsWrittenUnderIt(): void
    {
        $refusal = self::refusal(static fn() => ArchitectureDocument::file(['layers' => self::LAYERS, 'allow' => ['infrq' => null]]));

        self::assertSame([ConfigurationSource::ConfigFile], self::kinds($refusal));
        self::assertSame(['architecture', 'allow', 'infrq'], $refusal->position()?->segments);
        self::assertSame(['domain', 'infra'], $refusal->position()->accepted);
    }

    /** @return iterable<string, array{string}> */
    public static function provideSourcesNamingLayersAfterExpansion(): iterable
    {
        yield 'glob' => ['app-*'];
        yield 'captured' => ['app-{m}'];
    }

    #[Test]
    #[DataProvider('provideSourcesNamingLayersAfterExpansion')]
    public function itAdmitsAnAllowSourceThatNamesLayersOnlyTemplateExpansionProduces(string $source): void
    {
        $result = self::configure(ArchitectureDocument::file([
            'layers' => [...self::LAYERS, ['name' => 'app-{m}', 'patterns' => ['App\\{m}']]],
            'allow' => [$source => ['domain']],
        ]));

        self::assertTrue($result->configuration->policy()->isAllowed('app-billing', 'domain'));
    }

    #[Test]
    public function itLeavesAMalformedSourceSelectorToTheSelectorGrammar(): void
    {
        $refusal = self::refusal(static fn() => ArchitectureDocument::file([
            'layers' => self::LAYERS,
            'allow' => ['in[fra' => null],
        ]));

        self::assertStringContainsString('character classes are not part of the selector grammar', $refusal->summary());
        self::assertSame(['architecture', 'allow', 'in[fra'], $refusal->position()?->segments);
    }

    #[Test]
    public function itKeepsALowerLayersTargetsUnderASourceWrittenAsTilde(): void
    {
        $result = self::configure(ArchitectureDocument::compose(
            ArchitectureDocument::presetLayer(['layers' => self::LAYERS, 'allow' => ['infra' => ['domain']]]),
            ArchitectureDocument::fileLayer(['allow' => ['infra' => null]]),
        ));

        self::assertTrue($result->configuration->policy()->isAllowed('infra', 'domain'));
    }

    #[Test]
    public function itAcceptsAGlobAllowSourceThatNamesNoDeclaredLayerByItsExactName(): void
    {
        $result = self::configure(ArchitectureDocument::file([
            'layers' => [
                ['name' => 'app-a', 'patterns' => ['App\\A']],
                ['name' => 'app-b', 'patterns' => ['App\\B']],
                ['name' => 'shared', 'patterns' => ['Lib\\**']],
            ],
            'allow' => ['app-*' => ['shared']],
        ]));

        self::assertTrue($result->configuration->policy()->isAllowed('app-b', 'shared'));
    }

    #[Test]
    public function itReplacesOneSourcesTargetListAndKeepsTheOtherSources(): void
    {
        $result = self::configure(ArchitectureDocument::compose(
            ArchitectureDocument::presetLayer([
                'layers' => [...self::LAYERS, ['name' => 'app', 'patterns' => ['App\\App']]],
                'allow' => ['infra' => ['domain'], 'app' => ['domain']],
            ]),
            ArchitectureDocument::fileLayer(['allow' => ['infra' => ['app']]]),
        ));

        $policy = $result->configuration->policy();
        self::assertTrue($policy->isAllowed('infra', 'app'));
        self::assertFalse($policy->isAllowed('infra', 'domain'));
        self::assertTrue($policy->isAllowed('app', 'domain'));
    }

    #[Test]
    public function itReadsAnEmptyAllowMapAsWritingNothing(): void
    {
        $result = self::configure(ArchitectureDocument::compose(
            ArchitectureDocument::presetLayer(['layers' => self::LAYERS, 'allow' => ['infra' => ['domain']]]),
            ArchitectureDocument::fileLayer(['allow' => []]),
        ));

        self::assertTrue($result->configuration->policy()->isAllowed('infra', 'domain'));
    }

    #[Test]
    public function itReadsRelationsWrittenAsTildeAsAnyRelationEvenOverAPresetFilter(): void
    {
        // The file's target list replaces the preset's whole, and its target
        // leaves `relations` unwritten — the documented "any relation".
        $result = self::configure(ArchitectureDocument::compose(
            ArchitectureDocument::presetLayer([
                'layers' => self::LAYERS,
                'allow' => ['infra' => [['target' => 'domain', 'relations' => ['extends']]]],
            ]),
            ArchitectureDocument::fileLayer(['allow' => ['infra' => [['target' => 'domain', 'relations' => null]]]]),
        ));

        self::assertTrue($result->configuration->policy()->isAllowed('infra', 'domain', DependencyType::StaticCall));
    }

    #[Test]
    public function itRefusesAShadowedTemplateWithoutItsDeclaredBinding(): void
    {
        $refusal = self::refusal(static fn() => ArchitectureDocument::compose(
            ArchitectureDocument::presetLayer(['layers' => [['name' => 'app-{m}', 'patterns' => ['App\\Static']]]], 'team'),
            ArchitectureDocument::fileLayer(['layers' => self::LAYERS]),
        ));

        self::assertSame([ConfigurationSource::Preset], self::kinds($refusal));
        self::assertSame('team', $refusal->sources()[0]->locator());
        $position = $refusal->position();
        self::assertNotNull($position);
        self::assertSame(['architecture', 'layers', '0'], \array_slice($position->segments, 0, 3));
    }

    /** @return iterable<string, array{list<array<string, mixed>>}> */
    public static function provideShadowedLayerListSemantics(): iterable
    {
        yield 'duplicate names' => [[
            ['name' => 'old', 'patterns' => ['App\\Old']],
            ['name' => 'old', 'patterns' => ['App\\Other']],
        ]];
        yield 'duplicate patterns' => [[
            ['name' => 'old', 'patterns' => ['App\\Shared']],
            ['name' => 'other', 'patterns' => ['App\\Shared']],
        ]];
        yield 'no criteria' => [[['name' => 'old']]];
        yield 'unbound template name' => [[['name' => 'app-{m}', 'patterns' => ['App\\Static']]]];
        yield 'template any with global suffix' => [[['name' => 'app-{m}', 'patterns' => ['App\\{m}'], 'suffix' => 'Repository', 'match' => 'any']]];
        yield 'static capture pattern' => [[['name' => 'old', 'patterns' => ['App\\{m}']]]];
    }

    /** @param list<array<string, mixed>> $layers */
    #[Test]
    #[DataProvider('provideShadowedLayerListSemantics')]
    public function itRefusesAShadowedLayerListWithInvalidOwnSemantics(array $layers): void
    {
        $refusal = self::refusal(static fn() => ArchitectureDocument::compose(
            ArchitectureDocument::presetLayer(['layers' => $layers], 'team'),
            ArchitectureDocument::fileLayer(['layers' => self::LAYERS]),
        ));

        self::assertSame([ConfigurationSource::Preset], self::kinds($refusal));
        self::assertSame('team', $refusal->sources()[0]->locator());
        $position = $refusal->position();
        self::assertNotNull($position);
        self::assertSame(['architecture', 'layers'], \array_slice($position->segments, 0, 2));
    }

    /**
     * A criterion and an allow target are carried unread by the engine, so
     * their form is judged by the section's own judgements in each layer: a
     * higher layer replacing the value must not hide the lower layer's
     * mistake.
     *
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, list<string>}>
     */
    public static function provideMalformedValuesAHigherLayerReplaces(): iterable
    {
        yield 'coverage mode word' => [
            ['coverage-gap' => 'bogus'],
            ['coverage-gap' => 'ignore'],
            ['architecture', 'coverage-gap'],
        ];
        yield 'zero expansion ceiling' => [
            ['max_expanded_layers' => 0],
            ['max_expanded_layers' => 10],
            ['architecture', 'max_expanded_layers'],
        ];
        yield 'negative expansion ceiling' => [
            ['max_expanded_layers' => -1],
            ['max_expanded_layers' => 10],
            ['architecture', 'max_expanded_layers'],
        ];
        yield 'layer match word' => [
            ['layers' => [['name' => 'old', 'patterns' => ['App\\Old'], 'match' => 'bogus']]],
            ['layers' => self::LAYERS],
            ['architecture', 'layers', '0', 'match'],
        ];
        yield 'exclude match word' => [
            ['layers' => [['name' => 'old', 'patterns' => ['App\\Old'], 'exclude' => ['suffix' => 'Legacy', 'match' => 'bogus']]]],
            ['layers' => self::LAYERS],
            ['architecture', 'layers', '0', 'exclude', 'match'],
        ];
        yield 'template name syntax' => [
            ['layers' => [['name' => 'app-{m', 'patterns' => ['App\\Old']]]],
            ['layers' => self::LAYERS],
            ['architecture', 'layers', '0', 'name'],
        ];
        yield 'static layer name syntax' => [
            ['layers' => [['name' => 'INVALID', 'patterns' => ['App\\Old']]]],
            ['layers' => self::LAYERS],
            ['architecture', 'layers', '0', 'name'],
        ];
        yield 'pattern syntax' => [
            ['layers' => [['name' => 'old', 'patterns' => ['App\\[bad']]]],
            ['layers' => self::LAYERS],
            ['architecture', 'layers', '0', 'patterns', '0'],
        ];
        yield 'exclude pattern syntax' => [
            ['layers' => [['name' => 'old', 'patterns' => ['App\\Old'], 'exclude' => ['patterns' => ['App\\[bad']]]]],
            ['layers' => self::LAYERS],
            ['architecture', 'layers', '0', 'exclude', 'patterns', '0'],
        ];
        yield 'suffix syntax' => [
            ['layers' => [['name' => 'old', 'suffix' => ['App\\Suffix']]]],
            ['layers' => self::LAYERS],
            ['architecture', 'layers', '0', 'suffix', '0'],
        ];
        foreach (['attributes', 'member_attributes', 'implements', 'extends'] as $kind) {
            yield $kind . ' name syntax' => [
                ['layers' => [['name' => 'old', $kind => ['ShortName']]]],
                ['layers' => self::LAYERS],
                ['architecture', 'layers', '0', $kind, '0'],
            ];
        }
        yield 'short target syntax' => [
            ['layers' => self::LAYERS, 'allow' => ['infra' => ['dom{ain']]],
            ['allow' => ['infra' => ['domain']]],
            ['architecture', 'allow', 'infra', '0'],
        ];
        yield 'long target syntax' => [
            ['layers' => self::LAYERS, 'allow' => ['infra' => [['target' => 'dom{ain']]]],
            ['allow' => ['infra' => ['domain']]],
            ['architecture', 'allow', 'infra', '0', 'target'],
        ];
        yield 'relation kind word' => [
            ['layers' => self::LAYERS, 'allow' => ['infra' => [['target' => 'domain', 'relations' => ['extnds']]]]],
            ['allow' => ['infra' => ['domain']]],
            ['architecture', 'allow', 'infra', '0', 'relations', '0'],
        ];
        yield 'criterion of a layer' => [
            ['layers' => [['name' => 'old', 'patterns' => 42]]],
            ['layers' => self::LAYERS],
            ['architecture', 'layers', '0', 'patterns'],
        ];
        yield 'criterion list item of a layer' => [
            ['layers' => [['name' => 'old', 'implements' => ['App\\Port', 5]]]],
            ['layers' => self::LAYERS],
            ['architecture', 'layers', '0', 'implements', '1'],
        ];
        yield 'criterion of an exclude block' => [
            ['layers' => [['name' => 'old', 'patterns' => ['App\\Old'], 'exclude' => ['suffix' => []]]]],
            ['layers' => self::LAYERS],
            ['architecture', 'layers', '0', 'exclude', 'suffix'],
        ];
        foreach ([
            'suffix' => 'Foo{m}',
            'attributes' => 'App\\Attr{m}',
            'implements' => 'App\\Port{m}',
            'extends' => 'App\\Base{m}',
        ] as $kind => $value) {
            yield 'static exclude ' . $kind . ' capture' => [
                ['layers' => [['name' => 'old', 'patterns' => ['App\\Old'], 'exclude' => [$kind => [$value]]]]],
                ['layers' => self::LAYERS],
                ['architecture', 'layers', '0', 'exclude', $kind, '0'],
            ];
            yield 'template exclude ' . $kind . ' capture' => [
                ['layers' => [['name' => 'old-{m}', 'patterns' => ['App\\{m}'], 'exclude' => [$kind => [$value]]]]],
                ['layers' => self::LAYERS],
                ['architecture', 'layers', '0', 'exclude', $kind, '0'],
            ];
        }
        yield 'allow target of the wrong shape' => [
            ['layers' => self::LAYERS, 'allow' => ['infra' => [42]]],
            ['allow' => ['infra' => ['domain']]],
            ['architecture', 'allow', 'infra', '0'],
        ];
        yield 'misspelt long-form key of an allow target' => [
            ['layers' => self::LAYERS, 'allow' => ['infra' => [['target' => 'domain', 'relashions' => ['extends']]]]],
            ['allow' => ['infra' => ['domain']]],
            ['architecture', 'allow', 'infra', '0', 'relashions'],
        ];
        yield 'relation list of an allow target' => [
            ['layers' => self::LAYERS, 'allow' => ['infra' => [['target' => 'domain', 'relations' => 'extends']]]],
            ['allow' => ['infra' => ['domain']]],
            ['architecture', 'allow', 'infra', '0', 'relations'],
        ];
    }

    /**
     * @param array<string, mixed> $preset
     * @param array<string, mixed> $file
     * @param list<string> $path
     */
    #[Test]
    #[DataProvider('provideMalformedValuesAHigherLayerReplaces')]
    public function itRefusesAMalformedValueInTheLayerThatWroteItEvenWhenAHigherLayerReplacesIt(array $preset, array $file, array $path): void
    {
        $refusal = self::refusal(static fn() => ArchitectureDocument::compose(
            ArchitectureDocument::presetLayer($preset, 'team'),
            ArchitectureDocument::fileLayer($file),
        ));

        self::assertSame([ConfigurationSource::Preset], self::kinds($refusal));
        self::assertCount(1, $refusal->sources());
        self::assertSame('team', $refusal->sources()[0]->locator());
        self::assertSame($path, $refusal->position()?->segments);
    }

    #[Test]
    public function itJudgesTheLayersAnAllowTargetNamesOnlyInTheListThatWon(): void
    {
        $result = self::configure(ArchitectureDocument::compose(
            ArchitectureDocument::presetLayer(['layers' => self::LAYERS, 'allow' => ['infra' => [
                'undeclared',
            ]]]),
            ArchitectureDocument::fileLayer(['allow' => ['infra' => ['domain']]]),
        ));

        self::assertTrue($result->configuration->policy()->isAllowed('infra', 'domain', DependencyType::StaticCall));
    }

    #[Test]
    public function itNamesThePresetWhenTheLayerListThatWonIsThePresets(): void
    {
        $refusal = self::refusal(static fn() => self::configure(ArchitectureDocument::compose(
            ArchitectureDocument::presetLayer(['layers' => [['name' => 'broken', 'patterns' => ['App\\[bad']]]], 'strict'),
            ArchitectureDocument::fileLayer(['coverage-gap' => 'warn']),
        )));

        self::assertSame([ConfigurationSource::Preset], self::kinds($refusal));
        self::assertCount(1, $refusal->sources());
        self::assertSame('strict', $refusal->sources()[0]->locator());
        self::assertSame(['architecture', 'layers', '0', 'patterns', '0'], $refusal->position()?->segments);
    }

    /**
     * A refused list item is addressed by its index, and `written` carries
     * what the author wrote there, not the index again.
     *
     * @return iterable<string, array{array<string, mixed>, list<string>, string}>
     */
    public static function provideRefusedItemValues(): iterable
    {
        yield 'pattern syntax' => [
            ['layers' => [['name' => 'domain', 'patterns' => ['App\\[bad']]]],
            ['architecture', 'layers', '0', 'patterns', '0'],
            'App\\[bad',
        ];
        yield 'pattern written as one string' => [
            ['layers' => [['name' => 'domain', 'patterns' => 'App\\[bad']]],
            ['architecture', 'layers', '0', 'patterns'],
            'App\\[bad',
        ];
        yield 'capture in a static layer' => [
            ['layers' => [['name' => 'domain', 'patterns' => ['App\\{m}\\**']]]],
            ['architecture', 'layers', '0', 'patterns', '0'],
            'App\\{m}\\**',
        ];
        yield 'unknown allow target' => [
            ['layers' => self::LAYERS, 'allow' => ['infra' => ['domain', 'nowhere']]],
            ['architecture', 'allow', 'infra', '1'],
            'nowhere',
        ];
        yield 'unparsable allow target' => [
            ['layers' => self::LAYERS, 'allow' => ['infra' => ['dom{ain']]],
            ['architecture', 'allow', 'infra', '0'],
            'dom{ain',
        ];
    }

    /**
     * @param array<string, mixed> $section
     * @param list<string> $path
     */
    #[Test]
    #[DataProvider('provideRefusedItemValues')]
    public function itPublishesTheRefusedValueAsWritten(array $section, array $path, string $written): void
    {
        $refusal = self::refusal(static fn() => self::configure(ArchitectureDocument::file($section)));

        self::assertSame($path, $refusal->position()?->segments);
        self::assertSame($written, $refusal->position()->written);
    }

    /**
     * An `exclude:` block that writes nothing is an empty map, which changes
     * nothing anywhere in the document; a block that writes a key but no
     * criterion is still refused.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function provideExcludeBlocksThatWriteNothing(): iterable
    {
        yield 'empty map' => [[]];
        yield 'criteria written as tilde' => [['patterns' => null, 'suffix' => null]];
    }

    #[Test]
    #[DataProvider('provideExcludeBlocksThatWriteNothing')]
    public function itReadsAnExcludeBlockThatWritesNothingAsNoExclusion(mixed $exclude): void
    {
        $result = self::configure(ArchitectureDocument::file([
            'layers' => [['name' => 'domain', 'patterns' => ['App\\Domain\\**'], 'exclude' => $exclude]],
        ]));

        self::assertSame(['domain'], $result->configuration->registry()->layerNames());
    }

    #[Test]
    public function itAcceptsMemberAttributesInALayerAndItsExcludeBlock(): void
    {
        $result = self::configure(ArchitectureDocument::file([
            'layers' => [[
                'name' => 'http',
                'member_attributes' => ['App\\Route'],
                'exclude' => ['member_attributes' => ['App\\InternalRoute']],
            ]],
        ]));

        $membership = $result->configuration->registry()->definitions()[0]->membership();
        self::assertSame(['App\\Route'], $membership->memberAttributes);
        self::assertSame(['App\\InternalRoute'], $membership->exclude?->memberAttributes);
    }

    #[Test]
    public function itRefusesAnExcludeBlockThatWritesOnlyMatch(): void
    {
        $refusal = self::refusal(static fn() => self::configure(ArchitectureDocument::file([
            'layers' => [['name' => 'domain', 'patterns' => ['App\\Domain\\**'], 'exclude' => ['match' => 'all']]],
        ])));

        self::assertStringContainsString('"exclude" must declare at least one of', $refusal->summary());
    }

    #[Test]
    public function itNamesBothWritersOfACoverageModeLeftWithNoLayers(): void
    {
        $refusal = self::refusal(static fn() => self::configure(ArchitectureDocument::compose(
            ArchitectureDocument::presetLayer(['layers' => self::LAYERS, 'coverage-gap' => 'error']),
            ArchitectureDocument::fileLayer(['layers' => []]),
        )));

        self::assertSame([ConfigurationSource::Preset, ConfigurationSource::ConfigFile], self::kinds($refusal));
        self::assertSame(['architecture', 'layers'], $refusal->position()?->segments);
        self::assertStringContainsString('requires at least one entry under "architecture.layers"', $refusal->getMessage());
    }

    #[Test]
    public function itAcceptsEveryDocumentSpellingOfASectionKeyAndOfALongFormKey(): void
    {
        $result = self::configure(ArchitectureDocument::file([
            'layers' => [['name' => 'app-{m}', 'patterns' => ['App\\{m}\\**']], ['name' => 'lib-{m}', 'patterns' => ['Lib\\{m}\\**']]],
            'coverageGap' => 'warn',
            'max-expanded-layers' => 7,
            'allow' => ['app-{m}' => [['target' => 'lib-{m}', 'allow-cross-instance' => true]]],
        ]));

        self::assertSame(CoverageMode::Warn, $result->configuration->coverage());
        self::assertSame(7, $result->configuration->maxExpandedLayers());
        self::assertTrue($result->configuration->policy()->isAllowed('app-Order', 'lib-Billing'));
    }

    #[Test]
    public function itRefusesALongFormKeyInAStyleNoSpellingHasWithTheCanonicalOne(): void
    {
        $refusal = self::refusal(static fn() => self::configure(ArchitectureDocument::file([
            'layers' => self::LAYERS,
            'allow' => ['infra' => [['Target' => 'domain']]],
        ])));

        self::assertStringContainsString("write 'target'", $refusal->getMessage());
        self::assertSame(['architecture', 'allow', 'infra', '0', 'Target'], $refusal->position()?->segments);
    }

    #[Test]
    public function itReadsANeverWrittenSectionAsNoPolicy(): void
    {
        self::assertTrue(self::configure(DocumentComposer::compose(new DocumentSchema([new ArchitectureSection()]), []))->configuration->isEmpty());
    }

    #[Test]
    public function itRefusesToReadAnExplicitlyOpaqueSection(): void
    {
        // Reading an opaque fixture as "no policy" would silently discard its layers.
        $document = DocumentComposer::compose(new DocumentSchema([new class implements DocumentSectionSchemaInterface {
            public function declaration(): SectionDeclaration
            {
                return new SectionDeclaration(ArchitectureSection::KEY, NodeSchema::opaque());
            }
        }]), [
            new AuthoredLayer(
                ConfigurationOrigin::of(ConfigurationSource::ConfigFile, ArchitectureDocument::FILE),
                AuthoredNode::fromPlain(['architecture' => ['layers' => self::LAYERS]]),
            ),
        ]);

        $this->expectException(LogicException::class);

        self::configure($document);
    }

    private static function configure(ResolvedDocument $document): ArchitectureFactoryResult
    {
        return (new ArchitectureConfigurationFactory())->fromResolved($document);
    }

    /** @param Closure(): mixed $action */
    private static function refusal(Closure $action): ConfigurationRefusal
    {
        try {
            $action();
        } catch (ConfigurationRefusal $refusal) {
            return $refusal;
        }

        self::fail('Expected a ConfigurationRefusal.');
    }

    /** @return list<ConfigurationSource> */
    private static function kinds(ConfigurationRefusal $refusal): array
    {
        return array_map(static fn(ConfigurationOrigin $origin): ConfigurationSource => $origin->source(), $refusal->sources());
    }
}
