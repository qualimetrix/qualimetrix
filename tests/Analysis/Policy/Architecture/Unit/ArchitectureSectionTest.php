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

        self::assertSame(ConfigurationSource::ConfigFile, $refusal->origin()->source());
        self::assertSame(ArchitectureDocument::FILE, $refusal->origin()->locator());
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
        $refusal = self::refusal(static fn() => self::configure(ArchitectureDocument::file([
            'layers' => self::LAYERS,
            'allow' => ['in[fra' => null],
        ])));

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
    public function itJudgesTheMeaningOfTheLayersOnlyInTheListThatWon(): void
    {
        // Form is judged in every layer; what a pattern means is judged in the
        // list the run uses, and the file's list replaced the preset's.
        $result = self::configure(ArchitectureDocument::compose(
            ArchitectureDocument::presetLayer(['layers' => [['name' => 'broken', 'patterns' => ['App\\[bad']]]]),
            ArchitectureDocument::fileLayer(['layers' => self::LAYERS]),
        ));

        self::assertSame(['domain', 'infra'], $result->configuration->registry()->layerNames());
    }

    #[Test]
    public function itNamesThePresetWhenTheLayerListThatWonIsThePresets(): void
    {
        $refusal = self::refusal(static fn() => self::configure(ArchitectureDocument::compose(
            ArchitectureDocument::presetLayer(['layers' => [['name' => 'broken', 'patterns' => ['App\\[bad']]]], 'strict'),
            ArchitectureDocument::fileLayer(['coverage-gap' => 'warn']),
        )));

        self::assertSame([ConfigurationSource::Preset], self::kinds($refusal));
        self::assertSame('strict', $refusal->origin()->locator());
        self::assertSame(['architecture', 'layers', '0', 'patterns', '0'], $refusal->position()?->segments);
    }

    #[Test]
    public function itNamesBothWritersOfACoverageModeLeftWithNoLayers(): void
    {
        $refusal = self::refusal(static fn() => self::configure(ArchitectureDocument::compose(
            ArchitectureDocument::presetLayer(['layers' => self::LAYERS, 'coverage-gap' => 'error']),
            ArchitectureDocument::fileLayer(['layers' => []]),
        )));

        self::assertEqualsCanonicalizing([ConfigurationSource::Preset, ConfigurationSource::ConfigFile], self::kinds($refusal));
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
        self::assertTrue(self::configure(ResolvedDocument::empty())->configuration->isEmpty());
    }

    #[Test]
    public function itRefusesToReadASectionTheDocumentCarriedUndeclared(): void
    {
        // An undeclared root rides through the engine unread; reading it as
        // "no policy" would drop every layer without a word.
        $document = DocumentComposer::compose(new DocumentSchema([], admitsUndeclaredRoots: true), [
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
