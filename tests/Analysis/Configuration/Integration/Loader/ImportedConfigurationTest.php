<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Integration\Loader;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Loader\ImportChain;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationLayer;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationStageInterface;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\ConfigFileStage;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\PresetStage;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricFormulaValidator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricExpression;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration\HealthFormulaExcluder;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Configuration\RunConfigurationResolver;
use Qualimetrix\Core\FileTarget\FileIdentity;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Composer\ComposerManifestReader;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

/**
 * A configuration split by `imports`, resolved by the product's own pipeline:
 * each imported file is a layer below the root, in the configuration-file band.
 */
#[CoversClass(ConfigFileStage::class)]
#[CoversClass(PresetStage::class)]
#[CoversClass(ConfigurationPipeline::class)]
#[CoversClass(ImportChain::class)]
final class ImportedConfigurationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qmx-imported-configuration-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/cfg', 0o777, true);
    }

    protected function tearDown(): void
    {
        self::remove($this->directory);
    }

    #[Test]
    public function itLetsALaterImportOverrideAnEarlierOneAndTheRootOverrideBoth(): void
    {
        $this->write('cfg/a.yaml', "fail_on: warning\n");
        $this->write('cfg/b.yaml', "fail_on: error\n");
        $this->write('qmx.yaml', "imports: [cfg/a.yaml, cfg/b.yaml]\nfail_on: none\n");
        self::assertSame('none', $this->resolve()->resolved()->get('fail_on')?->plain());

        $this->write('qmx.yaml', "imports: [cfg/a.yaml, cfg/b.yaml]\n");
        self::assertSame('error', $this->resolve()->resolved()->get('fail_on')?->plain());
    }

    #[Test]
    public function itComposesEachCollectionByItsOwnPolicyAcrossFiles(): void
    {
        $this->write('cfg/a.yaml', "exclude: [{subtree: legacy}]\npaths: [src, lib]\n");
        $this->write('qmx.yaml', "imports: [cfg/a.yaml]\nexclude: [{subtree: generated}]\npaths: [app]\n");

        $resolved = $this->resolve()->resolved();

        self::assertSame([['subtree' => 'legacy'], ['subtree' => 'generated']], $resolved->get('exclude')?->plain());
        self::assertSame(['app'], $resolved->get('paths')?->plain());
    }

    #[Test]
    public function itExpandsAShorthandInTheFileThatWroteIt(): void
    {
        $this->write('cfg/a.yaml', "rules:\n  complexity.ccn:\n    callable: {threshold: 20}\n");
        $this->write('qmx.yaml', "imports: [cfg/a.yaml]\nrules:\n  complexity.ccn:\n    callable: {warning: 9}\n");

        $resolved = $this->resolve()->resolved();

        self::assertSame(9, $resolved->get('rules', 'complexity.ccn', 'callable', 'warning')?->plain());
        self::assertSame(20, $resolved->get('rules', 'complexity.ccn', 'callable', 'error')?->plain());
    }

    #[Test]
    public function itJudgesARelationBetweenFilesAfterTheMerge(): void
    {
        $this->write('cfg/a.yaml', "architecture:\n  layers:\n    - {name: domain, patterns: ['App\\Domain\\**']}\n    - {name: app, patterns: ['App\\App\\**']}\n");
        $this->write('qmx.yaml', "imports: [cfg/a.yaml]\narchitecture:\n  allow:\n    app: [domain]\n");

        self::assertSame([['domain']], $this->resolve()->resolved()->get('architecture', 'allow', 'app')?->plain());
    }

    #[Test]
    public function itResolvesAPathAnImportedFileWritesAgainstTheWorkingDirectory(): void
    {
        $this->write('cfg/a.yaml', "paths: [src]\n");
        $this->write('qmx.yaml', "imports: [cfg/a.yaml]\n");

        $run = (new RunConfigurationResolver(new ProjectScopeCoverage(new ComposerManifestReader())))->resolve($this->resolve());

        self::assertSame([$this->directory . '/src'], array_map(static fn($path): string => $path->value(), $run->paths));
    }

    /** @return iterable<string, array{string, non-empty-string}> */
    public static function provideImportedSpellings(): iterable
    {
        yield 'capitalized' => ["Imports: [x.yaml]\n", 'Key "Imports" in configuration file "cfg/a.yaml" (imported by configuration file "qmx.yaml") is not written in an accepted spelling; write "imports"'];
        yield 'near miss' => ["import: [x.yaml]\n", 'Unknown key "import" in configuration file "cfg/a.yaml" (imported by configuration file "qmx.yaml") (did you mean "imports"?)'];
    }

    /** @param non-empty-string $expected */
    #[Test]
    #[DataProvider('provideImportedSpellings')]
    public function itRefusesAnotherSpellingOfImportsInAnImportedFileAsAtTheRoot(string $imported, string $expected): void
    {
        $this->write('cfg/a.yaml', $imported);
        $this->write('qmx.yaml', "imports: [cfg/a.yaml]\n");

        self::assertStringStartsWith($expected, $this->refusal()->summary());
    }

    /** @return iterable<string, array{string, non-empty-string}> */
    public static function provideRootSpellings(): iterable
    {
        yield 'capitalized' => ["Imports: [cfg/a.yaml]\n", 'Key "Imports" in configuration file "qmx.yaml" is not written in an accepted spelling; write "imports"'];
        yield 'upper case' => ["IMPORTS: [cfg/a.yaml]\n", 'Key "IMPORTS" in configuration file "qmx.yaml" is not written in an accepted spelling; write "imports"'];
        yield 'near miss' => ["import: [cfg/a.yaml]\n", 'Unknown key "import" in configuration file "qmx.yaml" (did you mean "imports"?)'];
    }

    /** @param non-empty-string $expected */
    #[Test]
    #[DataProvider('provideRootSpellings')]
    public function itRefusesAnotherSpellingOfImportsAtTheRoot(string $root, string $expected): void
    {
        $this->write('cfg/a.yaml', '');
        $this->write('qmx.yaml', $root);

        self::assertStringStartsWith($expected, $this->refusal()->summary());
    }

    /** @return iterable<string, array{string, non-empty-string}> */
    public static function provideRefusedEntries(): iterable
    {
        yield 'url' => ['https://example.com/a.yaml', 'Import "https://example.com/a.yaml" (imports[0]) names a URL'];
        yield 'recursive glob' => ['"cfg/**/*.yaml"', 'Import "cfg/**/*.yaml" (imports[0]) uses "**"'];
        yield 'brace' => ['"cfg/{a,b}*.yaml"', 'Import "cfg/{a,b}*.yaml" (imports[0]) uses "{"'];
        yield 'backslash' => ["'cfg/a\\*.yaml'", 'Import "cfg/a\\*.yaml" (imports[0]) contains a backslash'];
        yield 'unclosed bracket' => ['"cfg/[ab.yaml"', 'Import "cfg/[ab.yaml" (imports[0]) has a "[" without its closing "]"'];
        yield 'glob without extension' => ['"cfg/*"', 'Import "cfg/*" (imports[0]) is not a .yaml or .yml file.'];
        yield 'partial name glob without extension' => ['"str*"', 'Import "str*" (imports[0]) is not a .yaml or .yml file.'];
        yield 'glob of another format' => ['"cfg/*.json"', 'Import "cfg/*.json" (imports[0]) is not a .yaml or .yml file.'];
        yield 'unknown preset' => ['base', 'Unknown preset "base". Available presets: ci, legacy, strict. A file needs a .yaml or .yml extension.'];
        yield 'extension' => ['a.json', 'Import "a.json" (imports[0]) is not a .yaml or .yml file.'];
        yield 'empty' => ['""', '"imports[0]" in configuration file "qmx.yaml" must be non-empty text.'];
        yield 'null' => ['~', 'Item 0 of "imports" in configuration file "qmx.yaml" is null'];
        yield 'integer' => ['1', '"imports[0]" in configuration file "qmx.yaml" must be non-empty string, got int.'];
    }

    /** @param non-empty-string $expected */
    #[Test]
    #[DataProvider('provideRefusedEntries')]
    public function itRefusesAnEntryOutsideTheGrammarAtItsPosition(string $entry, string $expected): void
    {
        $this->write('qmx.yaml', \sprintf("imports: [%s]\n", $entry));

        $refusal = $this->refusal();

        self::assertStringStartsWith($expected, $refusal->summary());
        self::assertSame(['imports', '0'], $refusal->position()?->segments);
        self::assertSame('configuration file "qmx.yaml"', $refusal->sources()[0]->describe());
    }

    #[Test]
    public function itRefusesABareImportsValue(): void
    {
        $this->write('qmx.yaml', "imports: cfg/a.yaml\n");

        self::assertSame('"imports" in configuration file "qmx.yaml" must be a list, got string.', $this->refusal()->summary());
    }

    #[Test]
    public function itAcceptsAnUpperCaseExtension(): void
    {
        $this->write('cfg/a.YAML', "fail_on: error\n");
        $this->write('qmx.yaml', "imports: [cfg/a.YAML]\n");

        self::assertSame('error', $this->resolve()->resolved()->get('fail_on')?->plain());
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideEmptyImports(): iterable
    {
        yield 'empty list' => ["imports: []\nfail_on: error\n", ''];
        yield 'null' => ["imports: ~\nfail_on: error\n", ''];
        yield 'empty imported file' => ["imports: [cfg/a.yaml]\nfail_on: error\n", ''];
    }

    #[Test]
    #[DataProvider('provideEmptyImports')]
    public function itAddsNothingAndSaysNothingForAnEmptyImport(string $root, string $imported): void
    {
        $this->write('cfg/a.yaml', $imported);
        $this->write('qmx.yaml', $root);

        $document = $this->resolve();

        self::assertSame('error', $document->resolved()->get('fail_on')?->plain());
        self::assertSame([], $document->diagnostics());
    }

    #[Test]
    public function itRefusesAnInvalidValueEvenWhenTheRootOverridesIt(): void
    {
        $this->write('cfg/a.yaml', "format: 7\n");
        $this->write('qmx.yaml', "imports: [cfg/a.yaml]\nformat: json\n");

        $refusal = $this->refusal();

        self::assertSame('configuration file "cfg/a.yaml" (imported by configuration file "qmx.yaml")', $refusal->sources()[0]->describe());
        self::assertSame(['format'], $refusal->position()?->segments);
    }

    #[Test]
    public function itNamesEveryFileOfARefusedMergedValueInPrecedenceOrder(): void
    {
        $this->write('cfg/a.yaml', "computed_metrics:\n  computed.a: {formula: 'm[\"computed.b\"]', levels: [project]}\n");
        $this->write('qmx.yaml', "imports: [cfg/a.yaml]\ncomputed_metrics:\n  computed.b: {formula: 'm[\"computed.a\"]', levels: [project]}\n");
        $document = $this->resolve();

        try {
            (new ComputedMetricsConfigResolver(
                new ComputedMetricFormulaValidator(),
                new HealthFormulaExcluder(new ComputedMetricExpression()),
            ))->resolve($document->resolved());
            self::fail('The cycle must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('Circular dependency', $refusal->summary());
            self::assertSame([
                ['cfg/a.yaml', 'qmx.yaml'],
                ['qmx.yaml', null],
            ], array_map(static fn(ConfigurationOrigin $origin): array => [$origin->locator(), $origin->importer()?->locator()], $refusal->sources()));
        }
    }

    #[Test]
    public function itRefusesAPresetThatWritesImports(): void
    {
        $this->write('team.yaml', "imports: [cfg/a.yaml]\n");

        $refusal = $this->refusal(presets: ['./team.yaml']);

        self::assertStringContainsString('is refused: a file given to --preset does not read it', $refusal->summary());
        self::assertSame('preset "./team.yaml"', $refusal->sources()[0]->describe());
        self::assertSame(['imports'], $refusal->position()?->segments);
    }

    #[Test]
    public function itLetsAnImportedFilesOwnImportsUnderlieItAndTheRootOverrideAll(): void
    {
        $this->write('qmx.yaml', "imports: [cfg/a.yaml]\nfail_on: none\n");
        $this->write('cfg/a.yaml', "imports: [b.yaml]\nfail_on: warning\nformat: json\n");
        $this->write('cfg/b.yaml', "fail_on: error\nformat: text\nmemory_limit: 1G\n");

        $document = $this->resolve();
        $resolved = $document->resolved();

        self::assertSame(['defaults', 'cfg/b.yaml', 'cfg/a.yaml', 'qmx.yaml'], $document->appliedSources());
        self::assertSame('none', $resolved->get('fail_on')?->plain());
        self::assertSame('json', $resolved->get('format')?->plain());
        self::assertSame('1G', $resolved->get('memory_limit')?->plain());
        self::assertNull($resolved->get('imports'));
    }

    #[Test]
    public function itImportsABuiltInPresetFromANestedFile(): void
    {
        $this->write('qmx.yaml', "imports: [cfg/a.yaml]\n");
        $this->write('cfg/a.yaml', "imports: [strict]\n");

        $document = $this->resolve();

        self::assertSame(['defaults', 'strict', 'cfg/a.yaml', 'qmx.yaml'], $document->appliedSources());
        self::assertSame(15, $document->resolved()->get('rules', 'complexity.ccn', 'callable', 'error')?->plain());
    }

    #[Test]
    public function itRefusesAnImportCycleAtTheClosingEntry(): void
    {
        $this->write('qmx.yaml', "imports: [cfg/a.yaml]\n");
        $this->write('cfg/a.yaml', "imports: [b.yaml]\n");
        $this->write('cfg/b.yaml', "imports: [./a.yaml]\n");

        $refusal = $this->refusal();

        self::assertStringContainsString('closes an import cycle: "cfg/a.yaml" imports "cfg/b.yaml" imports "cfg/a.yaml"', $refusal->summary());
        self::assertSame('configuration file "cfg/b.yaml" (imported by configuration file "cfg/a.yaml" (imported by configuration file "qmx.yaml"))', $refusal->sources()[0]->describe());
        self::assertSame(['imports', '0'], $refusal->position()?->segments);
    }

    #[Test]
    public function itPlacesAFileGivenAsAPresetAndImportedInBothBands(): void
    {
        $this->write('cfg/base.yaml', "fail_on: warning\n");
        $this->write('qmx.yaml', "imports: [cfg/base.yaml]\n");

        $document = $this->resolve(presets: ['./cfg/base.yaml']);

        self::assertSame(['preset:./cfg/base.yaml', 'cfg/base.yaml', 'qmx.yaml'], array_values(array_filter(
            $document->appliedSources(),
            static fn(string $source): bool => !\in_array($source, ['defaults', 'composer.json', 'cli'], true),
        )));
        self::assertSame('warning', $document->resolved()->get('fail_on')?->plain());
    }

    #[Test]
    public function itKeepsTheChainInTheConfigurationFileBand(): void
    {
        $this->write('cfg/a.yaml', "fail_on: error\nrules:\n  complexity.ccn:\n    callable: {warning: 30}\n");
        $this->write('qmx.yaml', "imports: [cfg/a.yaml]\n");

        $resolved = $this->resolve(['fail_on' => 'none'], ['strict'])->resolved();

        self::assertSame('none', $resolved->get('fail_on')?->plain());
        self::assertSame(30, $resolved->get('rules', 'complexity.ccn', 'callable', 'warning')?->plain());
        self::assertSame(15, $resolved->get('rules', 'complexity.ccn', 'callable', 'error')?->plain());
    }

    #[Test]
    public function itNamesEachFileAsAnAppliedSourceAndNeverResolvesImports(): void
    {
        $this->write('cfg/a.yaml', '');
        $this->write('cfg/b.yaml', '');
        $this->write('qmx.yaml', "imports: [cfg/a.yaml, ./cfg/b.yaml]\n");

        $document = $this->resolve();

        self::assertSame(['defaults', 'cfg/a.yaml', 'cfg/b.yaml', 'qmx.yaml'], $document->appliedSources());
        self::assertNull($document->resolved()->get('imports'));
    }

    #[Test]
    public function itImportsABuiltInPresetByNameBelowTheFileThatNamesIt(): void
    {
        $this->write('qmx.yaml', "imports: [strict]\nrules:\n  complexity.ccn:\n    callable: {warning: 30}\n");

        $document = $this->resolve();
        $resolved = $document->resolved();

        self::assertSame(['defaults', 'strict', 'qmx.yaml'], $document->appliedSources());
        self::assertSame(30, $resolved->get('rules', 'complexity.ccn', 'callable', 'warning')?->plain());
        self::assertSame(15, $resolved->get('rules', 'complexity.ccn', 'callable', 'error')?->plain());
    }

    #[Test]
    public function itKeepsTheNameOfAnImportedPresetApartFromTheOneOnTheCommandLine(): void
    {
        $this->write('qmx.yaml', "imports: [strict]\n");

        $document = $this->resolve(presets: ['strict']);

        self::assertSame(['defaults', 'preset:strict', 'strict', 'qmx.yaml'], array_values(array_filter(
            $document->appliedSources(),
            static fn(string $source): bool => !\in_array($source, ['composer.json', 'cli'], true),
        )));
    }

    #[Test]
    public function itNamesAnImportedPresetByIdentityAsAPresetImportedByTheFile(): void
    {
        $this->write('qmx.yaml', "imports: [strict]\n");
        $stat = stat(__DIR__ . '/../../../../../src/Analysis/Configuration/Preset/strict.yaml');
        self::assertNotFalse($stat);

        self::assertSame('preset "strict" (imported by configuration file "qmx.yaml")', $this->resolve()->fileReadAs(FileIdentity::fromStat($stat)));
    }

    #[Test]
    public function itImportsEveryFileAGlobMatchesInByteOrder(): void
    {
        $this->write('cfg/b.yaml', "fail_on: error\n");
        $this->write('cfg/a.yaml', "fail_on: warning\nformat: json\n");
        $this->write('qmx.yaml', "imports: [\"cfg/*.yaml\"]\n");

        $document = $this->resolve();

        self::assertSame(['defaults', 'cfg/a.yaml', 'cfg/b.yaml', 'qmx.yaml'], $document->appliedSources());
        self::assertSame('error', $document->resolved()->get('fail_on')?->plain());
        self::assertSame('json', $document->resolved()->get('format')?->plain());
    }

    #[Test]
    public function itAcceptsAGlobThatMatchesNothing(): void
    {
        $this->write('qmx.yaml', "imports: [\"cfg/*.yaml\"]\nfail_on: error\n");

        self::assertSame(['defaults', 'qmx.yaml'], $this->resolve()->appliedSources());
    }

    #[Test]
    public function itNamesEveryFileItReadByIdentity(): void
    {
        $this->write('cfg/a.yaml', '');
        $this->write('team.yaml', '');
        $this->write('other.yaml', '');
        $this->write('qmx.yaml', "imports: [cfg/a.yaml]\n");

        $document = $this->resolve(presets: ['strict', './team.yaml', './cfg/a.yaml']);

        $named = [
            'team.yaml' => 'preset "./team.yaml"',
            'cfg/a.yaml' => 'preset "./cfg/a.yaml"',
            'qmx.yaml' => 'configuration file "qmx.yaml"',
            'other.yaml' => null,
        ];
        foreach ($named as $path => $name) {
            $stat = stat($this->directory . '/' . $path);
            self::assertNotFalse($stat);
            self::assertSame($name, $document->fileReadAs(FileIdentity::fromStat($stat)), $path);
        }
        self::assertNull($document->fileReadAs(null));
    }

    #[Test]
    public function itRefusesToComposeALayerThatStillCarriesImports(): void
    {
        $pipeline = new ConfigurationPipeline();
        $pipeline->addStage(new class implements ConfigurationStageInterface {
            public function priority(): int
            {
                return 20;
            }

            public function name(): string
            {
                return 'leaking';
            }

            public function apply(ConfigurationResolutionRequest $request): ConfigurationLayer
            {
                return new ConfigurationLayer('leaking', authored: [new AuthoredLayer(
                    ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml'),
                    AuthoredNode::fromPlain(['imports' => ['a.yaml']]),
                )]);
            }
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The layer of configuration file "qmx.yaml" still carries "imports" at composition');

        $pipeline->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory)));
    }

    private function write(string $file, string $content): void
    {
        file_put_contents($this->directory . '/' . $file, $content);
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            self::remove($path . '/' . $entry);
        }
        rmdir($path);
    }

    /**
     * @param array<string, mixed> $cli
     * @param list<string> $presets
     */
    private function resolve(array $cli = [], array $presets = []): ConfigurationDocument
    {
        return self::pipeline()->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory), null, $presets, $cli));
    }

    /** @param list<string> $presets */
    private function refusal(array $presets = []): ConfigurationRefusal
    {
        try {
            $this->resolve(presets: $presets);
        } catch (ConfigurationRefusal $refusal) {
            return $refusal;
        }

        self::fail('The configuration must be refused.');
    }

    private static function pipeline(): ConfigurationPipelineInterface
    {
        static $pipeline = null;
        if ($pipeline === null) {
            $pipeline = (new ContainerFactory())->create()->get(ConfigurationPipelineInterface::class);
            \assert($pipeline instanceof ConfigurationPipelineInterface);
        }

        return $pipeline;
    }
}
