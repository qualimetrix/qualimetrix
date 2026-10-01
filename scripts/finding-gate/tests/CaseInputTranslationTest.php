<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\CaseDefinition;
use QmxFindingGate\CaseInputTranslation;
use QmxFindingGate\CaseScheduler;
use QmxFindingGate\ChannelWitness;
use QmxFindingGate\Corpus;
use QmxFindingGate\DeclaredStructuralMaps;
use QmxFindingGate\Fs;
use QmxFindingGate\Gate;
use QmxFindingGate\GateError;
use QmxFindingGate\GateReport;
use QmxFindingGate\Options;
use QmxFindingGate\RenameMaps;
use QmxFindingGate\SyntheticTree;
use QmxFindingGate\Tsv;
use QmxFindingGateControls\ChannelRenamePlants;
use QmxFindingGateControls\RenameControls;
use QmxFindingGateControls\Scratch;
use QmxFindingGateControls\Shell;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

final class CaseInputTranslationTest extends TestCase
{
    private string $root;
    private string $directory;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    protected function setUp(): void
    {
        $this->root = Fs::temporaryDirectory('case-input-test-');
        $this->directory = $this->root . '/cases/probe';
        Fs::write($this->directory . '/case.json', json_encode([
            'id' => 'probe', 'description' => 'Independent inputs.', 'paths' => ['src'],
            'config' => 'qmx.yaml', 'args' => ['--preset=presets/local.yaml'], 'channels' => ['probe.channel@class'],
        ], \JSON_THROW_ON_ERROR));
        Fs::write($this->directory . '/qmx.yaml', "# Retained bytes\r\nrules: {}\r\n");
        Fs::write($this->directory . '/presets/local.yaml', "rules: {}\n");
        Fs::write($this->directory . '/composer.json', "{\"autoload\":{\"psr-4\":{\"Probe\\\\\":\"src/\"}}}\n");
        Fs::write($this->directory . '/src/Main.php', "<?php class Main {}\n");
        Fs::write($this->directory . '/src/MainOnly.php', "<?php class MainOnly {}\n");
        Fs::write($this->directory . '/baseline-src/src/Variant.php', "<?php class Variant {}\n");
        Fs::write($this->directory . '/baseline-src/qmx.yaml', "# Variant configuration\nrules: {}\n");
    }

    protected function tearDown(): void
    {
        Fs::removeRecursively($this->root);
    }

    #[Test]
    public function itMaterializesTheWholeCaseAndReplacesVariantAnalysisPaths(): void
    {
        $case = CaseDefinition::load($this->directory);
        $translation = new CaseInputTranslation(RenameMaps::fromPairs([]), false, $this->root . '/scratch', 'candidate', DeclaredStructuralMaps::load($this->root));
        $main = $translation->materialize($case);
        $variant = $translation->materialize($case, true);
        self::assertNotSame($main->directory, $variant->directory);
        self::assertNotSame($case->directory, $main->directory);
        self::assertSame($case->id, $variant->id);
        self::assertSame($case->paths, $variant->paths);
        self::assertSame($main, $translation->materialize($case));
        foreach (['composer.json', 'presets/local.yaml', 'qmx.yaml', 'src/Main.php'] as $path) {
            self::assertSame(Fs::read($this->directory . '/' . $path), Fs::read($main->directory . '/' . $path));
        }
        self::assertSame(Fs::read($this->directory . '/composer.json'), Fs::read($variant->directory . '/composer.json'));
        self::assertSame(Fs::read($this->directory . '/presets/local.yaml'), Fs::read($variant->directory . '/presets/local.yaml'));
        self::assertSame(Fs::read($this->directory . '/baseline-src/qmx.yaml'), Fs::read($variant->directory . '/qmx.yaml'));
        self::assertFileExists($variant->directory . '/src/Variant.php');
        self::assertFileDoesNotExist($variant->directory . '/src/MainOnly.php');
        Fs::write($main->directory . '/src/Main.php', 'A scratch mutation.');
        self::assertSame("<?php class Main {}\n", Fs::read($this->directory . '/src/Main.php'));
    }

    #[Test]
    public function itPreservesContainedDirectoryLinksAndNeverLoadsCaseCode(): void
    {
        symlink('src', $this->directory . '/linked');
        Fs::write($this->directory . '/vendor/autoload.php', '<?php throw new Exception("Must never load case code");');
        $case = CaseDefinition::load($this->directory);
        $translation = new CaseInputTranslation(RenameMaps::fromPairs([]), true, $this->root . '/scratch', 'reference', DeclaredStructuralMaps::load($this->root));
        $copy = $translation->materialize($case);
        self::assertTrue(is_link($copy->directory . '/linked'));
        self::assertSame($copy->directory . '/src', realpath($copy->directory . '/linked'));
        self::assertSame(Fs::read($this->directory . '/vendor/autoload.php'), Fs::read($copy->directory . '/vendor/autoload.php'));
    }

    #[Test]
    public function itTranslatesConfigAndPresetStructuralInputsOnlyOnReference(): void
    {
        Fs::write($this->directory . '/qmx.yaml', "renamed:\n  value: 7\nneighbor: retained\n");
        Fs::write($this->directory . '/presets/local.yaml', "renamed:\n  value: 8\n");
        Fs::write($this->root . '/' . DeclaredStructuralMaps::INDEX, Tsv::render(DeclaredStructuralMaps::COLUMNS, [
            ['config', 'legacy', 'renamed.value', 'same', 'Move the config key.'],
            ['preset', 'legacy', 'renamed.value', 'same', 'Move the preset key.'],
        ]));
        $case = CaseDefinition::load($this->directory);
        $maps = DeclaredStructuralMaps::load($this->root);
        $reference = new CaseInputTranslation(RenameMaps::fromPairs([]), true, $this->root . '/scratch', 'reference', $maps);
        $copy = $reference->materialize($case);
        self::assertStringContainsString('legacy: 7', Fs::read($copy->directory . '/qmx.yaml'));
        self::assertStringContainsString('neighbor: retained', Fs::read($copy->directory . '/qmx.yaml'));
        self::assertStringContainsString('legacy: 8', Fs::read($copy->directory . '/presets/local.yaml'));
        self::assertSame([], $maps->stale());
        self::assertCount(2, $maps->firedRows());
        self::assertSame("renamed:\n  value: 7\nneighbor: retained\n", Fs::read($this->directory . '/qmx.yaml'));
    }
    #[Test]
    public function itCarriesPresetArgumentsThroughTheRealComputedChannelProbe(): void
    {
        Fs::write($this->directory . '/qmx.yaml', "rules: {}\n");
        Fs::write($this->directory . '/presets/local.yaml', "computed_metrics:\n  computed.input-probe:\n    formula: '1'\n    levels: [class]\n    warning: 0\n");
        $original = Fs::read($this->directory . '/presets/local.yaml');
        $case = CaseDefinition::load($this->directory);
        $witness = new ChannelWitness(\dirname(__DIR__, 3));
        try {
            $pairs = $witness->computedPairs($case);
        } catch (GateError $error) {
            self::fail($error->getMessage());
        }
        self::assertContains('computed.input-probe@class', $pairs);
        self::assertNotContains('computed.input-probe@class', $witness->staticPairs());
        self::assertSame($original, Fs::read($this->directory . '/presets/local.yaml'));
        self::assertDirectoryDoesNotExist($this->directory . '/.qmx-cache');
    }

    #[Test]
    public function itRefusesUnsupportedDocumentTargetsAndSymbolPathGeometry(): void
    {
        $maps = RenameMaps::fromPairs([['old' => 'Probe\\OldName', 'new' => 'Probe\\NewName', 'source' => RenameMaps::SYMBOLS]]);
        Fs::write($this->directory . '/notes.txt', 'Probe\\NewName');
        $case = CaseDefinition::load($this->directory);
        $translation = new CaseInputTranslation($maps, true, $this->root . '/scratch', 'reference', DeclaredStructuralMaps::load($this->root));
        try {
            $translation->materialize($case);
            self::fail('An unsupported document containing a mapped target was copied silently.');
        } catch (GateError $error) {
            self::assertStringContainsString('without a supported named role', $error->getMessage());
        }
        self::assertSame([], $maps->firedRows());
        self::assertSame('Probe\\NewName', Fs::read($this->directory . '/notes.txt'));
        unlink($this->directory . '/notes.txt');
        Fs::write($this->directory . '/src/NewName.php', '<?php');
        Fs::write($this->directory . '/baseline-src/src/NewName.php', '<?php');
        $definition = json_decode(Fs::read($this->directory . '/case.json'), true, 512, \JSON_THROW_ON_ERROR);
        $definition['paths'] = ['src/NewName.php'];
        Fs::write($this->directory . '/case.json', json_encode($definition, \JSON_THROW_ON_ERROR));
        $case = CaseDefinition::load($this->directory);
        $this->expectException(GateError::class);
        $translation->materialize($case);
    }

    #[Test]
    #[Group('finding-gate-e2e')]
    public function itCarriesActualWorkerStructuralCreditsIntoTheParentAndPublicVerdict(): void
    {
        $tree = SyntheticTree::clean();
        $tree['declarations'][DeclaredStructuralMaps::INDEX] = Tsv::render(DeclaredStructuralMaps::COLUMNS, [
            ['config', 'old', 'new', 'same', 'The input key moved.'],
        ]);
        $tree['declarations']['cases/alpha/qmx.yaml'] = "new: 7\nneighbor: retained\n";
        $root = SyntheticTree::create($tree);
        $temporary = Fs::temporaryDirectory('worker-structural-test-');
        try {
            $maps = DeclaredStructuralMaps::load($root . '/finding-gate');
            $scheduler = new CaseScheduler($root, $root, $temporary, 'reference', true, 2, RenameMaps::fromPairs([]), $maps);
            $capture = $scheduler->captureCases(Corpus::load($root)->cases);
            $artifacts = $capture->artifacts;
            self::assertArrayHasKey('case:alpha|format:json', $artifacts);
            self::assertSame(["config\0old" => 1], $maps->firedRows());
            self::assertSame([], $maps->stale());
            $report = new GateReport();
            (new Gate(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD', '--jobs=2'], $root), $report))->compare();
            self::assertSame(GateReport::VERDICT_GREEN, $report->verdict(), $report->render());
            self::assertSame("new: 7\nneighbor: retained\n", Fs::read($root . '/finding-gate/cases/alpha/qmx.yaml'));
        } finally {
            Fs::removeRecursively($temporary);
            SyntheticTree::remove($root);
        }
    }

    #[Test]
    public function itKeepsNamespaceSuppressionWhenTheControlRenamesItsDeclaredRoot(): void
    {
        require_once \dirname(__DIR__, 2) . '/finding-gate-controls/classes.php';

        $repository = \dirname(__DIR__, 3);
        $scratch = Scratch::contentOf($repository);
        $program = <<<'PHP'
            require getcwd() . '/vendor/autoload.php';
            use Qualimetrix\Analysis\Configuration\ConfigSchema;
            use Qualimetrix\Analysis\Configuration\ConfigurationRoot;
            use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;
            use Qualimetrix\Core\Path\AbsolutePath;
            use Qualimetrix\Reporting\FindingProjection\Configuration\ConfiguredFindingExclusionsResolver;
            $document = LayeredDocument::of([
                ['source' => 'qmx.yaml', 'values' => [
                    ConfigSchema::SUPPRESS_NAMESPACES => [['subtree' => 'Corpus\\Root']],
                ]],
            ], AbsolutePath::fromString(getcwd()));
            $patterns = (new ConfiguredFindingExclusionsResolver())->resolve($document)->suppressNamespaces;
            $loadedFromClone = true;
            foreach ([ConfigSchema::class, ConfigurationRoot::class] as $class) {
                $file = (new ReflectionClass($class))->getFileName();
                $loadedFromClone = $loadedFromClone
                    && realpath($file) === realpath(getcwd() . '/src/Analysis/Configuration/' . basename($file));
            }
            echo json_encode([
                'root' => ConfigurationRoot::SuppressNamespaces->declaration()->key,
                'patterns' => count($patterns),
                'matches' => $patterns !== [] && $patterns[0]->matches('Corpus\\Root\\Child'),
                'loadedFromClone' => $loadedFromClone,
            ], JSON_THROW_ON_ERROR);
            PHP;

        try {
            RenameControls::rootKeyRenamed()->mutation->apply($scratch, $repository);
            $result = Shell::run([\PHP_BINARY, '-r', $program], $scratch->tree);
            self::assertSame(0, $result['exit'], $result['stderr']);
            self::assertSame([
                'root' => 'suppress_ns',
                'patterns' => 1,
                'matches' => true,
                'loadedFromClone' => true,
            ], json_decode($result['stdout'], true, 512, \JSON_THROW_ON_ERROR));
        } finally {
            $scratch->remove();
        }

        $listing = new ReflectionMethod(ChannelRenamePlants::class, 'publishedRules');
        self::assertTrue($listing->invoke(null, 'probe', ['exit' => 0, 'stdout' => "one rule\n", 'stderr' => '']));
        self::assertFalse($listing->invoke(null, 'probe', ['exit' => 3, 'stdout' => '', 'stderr' => "Configuration error\n"]));
        foreach ([
            ['exit' => 0, 'stdout' => '', 'stderr' => ''],
            ['exit' => 3, 'stdout' => 'partial', 'stderr' => 'refusal'],
            ['exit' => 1, 'stdout' => '', 'stderr' => 'failed'],
            ['exit' => 2, 'stdout' => '', 'stderr' => 'failed'],
        ] as $invalid) {
            try {
                $listing->invoke(null, 'probe', $invalid);
                self::fail('An unexpected rules result must be refused.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('Cannot classify the source rules listing', $error->getMessage());
            }
        }

        $expectations = ChannelRenamePlants::caseListingFailures(declarationReplaced: true);
        $scopes = array_map(static fn($expectation): ?string => $expectation->scopeContains, $expectations);
        $selector = CaseDefinition::load(\dirname(__DIR__, 3) . '/finding-gate/cases/selector-after-split');
        self::assertSame(\QmxFindingGate\CaseOutcome::REFUSAL, $selector->outcome);
        self::assertContains('case:selector-after-split|rules', $scopes);
        self::assertNotContains('case:computed-cross-level|rules', $scopes);
        self::assertCount(32, (new ReflectionProperty(ChannelRenamePlants::class, 'caseRules'))->getValue());
        self::assertNotContains(
            'case:complexity|rules',
            array_map(static fn($expectation): ?string => $expectation->scopeContains, ChannelRenamePlants::caseListingFailures('complexity', true)),
        );
        self::assertCount(32, (new ReflectionProperty(ChannelRenamePlants::class, 'caseRules'))->getValue());
        foreach ($expectations as $expectation) {
            self::assertTrue($expectation->exactScope);
        }
    }

}
