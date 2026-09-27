<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\CaseDefinition;
use QmxFindingGate\CaseInputTranslation;
use QmxFindingGate\CaseOutcome;
use QmxFindingGate\DeclaredOutcomes;
use QmxFindingGate\DeclaredStructuralMaps;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\RenameMaps;

/**
 * What a case may point at, judged where each path leads rather than how it is spelled.
 */
final class CaseDefinitionTest extends TestCase
{
    private string $root;

    private string $case;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    protected function setUp(): void
    {
        $this->root = Fs::temporaryDirectory('case-definition-test-');
        $this->case = $this->root . '/cases/probe';
        mkdir($this->case . '/src', 0o777, true);
        mkdir($this->root . '/outside');
        Fs::write($this->case . '/qmx.yaml', "suppress_paths: []\n");
        Fs::write($this->root . '/outside/qmx.yaml', "suppress_paths: []\n");
    }

    protected function tearDown(): void
    {
        Fs::removeRecursively($this->root);
    }

    #[Test]
    public function itRefusesAConfigThatLinksOutsideTheCase(): void
    {
        symlink($this->root . '/outside/qmx.yaml', $this->case . '/linked.yaml');

        $this->assertRefused('outside its own directory', config: 'linked.yaml');
    }

    #[Test]
    public function itRefusesAnAnalysisPathThatLinksOutsideTheCase(): void
    {
        symlink($this->root . '/outside', $this->case . '/linked');

        $this->assertRefused('outside its own directory', paths: ['linked']);
    }

    #[Test]
    public function itRefusesAnInputOptionThatLinksOutsideTheCase(): void
    {
        symlink($this->root . '/outside/qmx.yaml', $this->case . '/preset.yaml');

        $this->assertRefused('outside its own directory', args: ['--preset=preset.yaml']);
    }

    #[Test]
    public function itRefusesACaseDirectoryThatIsALink(): void
    {
        mkdir($this->root . '/outside/src');
        Fs::write($this->root . '/outside/case.json', (string) json_encode([
            'id' => 'linked',
            'description' => 'A case whose directory is a link.',
            'paths' => ['src'],
            'config' => 'qmx.yaml',
            'channels' => ['a.code@class'],
        ]));
        symlink($this->root . '/outside', $this->root . '/cases/linked');

        try {
            CaseDefinition::load($this->root . '/cases/linked');
        } catch (GateError $error) {
            self::assertStringContainsString('may not be a link', $error->getMessage());

            return;
        }

        self::fail('The linked case directory was accepted.');
    }

    #[Test]
    public function itReadsAConfigWithACommaAsOnePath(): void
    {
        Fs::write($this->case . '/a,b.yaml', "suppress_paths: []\n");

        self::assertSame(['a,b.yaml'], $this->load(config: 'a,b.yaml', args: ['--config=a,b.yaml'])->argumentPaths());
    }

    #[Test]
    public function itRefusesAWorkingDirectoryEvenInsideTheCase(): void
    {
        $this->assertRefused('The gate runs a case in its own directory', args: ['-d', 'src']);
    }

    #[Test]
    public function itRefusesAPathThatDoesNotExist(): void
    {
        $this->assertRefused('does not exist', paths: ['missing']);
    }

    #[Test]
    public function itRefusesAnOutputOptionEvenInsideTheCase(): void
    {
        $this->assertRefused('carries the output option --output', args: ['--output=report.json']);
    }

    #[Test]
    public function itAcceptsANameThatOnlyContainsTwoDots(): void
    {
        mkdir($this->case . '/a..b');

        self::assertSame(['a..b'], $this->load(paths: ['a..b'])->paths);
    }

    #[Test]
    public function itReadsANamedPresetAsNoPathAndAPresetFileAsOne(): void
    {
        self::assertSame([], $this->load(args: ['--preset=strict'])->argumentPaths());
        $this->assertRefused('does not exist', args: ['--preset=strict,missing.yaml']);
    }

    #[Test]
    public function itListsEveryInputFileWithTheOptionThatHandsItOver(): void
    {
        Fs::write($this->case . '/preset.yaml', "suppress_paths: []\n");

        self::assertSame(
            [
                ['option' => '--config', 'path' => 'qmx.yaml'],
                ['option' => '--preset', 'path' => 'preset.yaml'],
            ],
            $this->load(args: ['--preset=strict,preset.yaml'])->inputFiles(),
        );
    }

    #[Test]
    public function itRefusesBothSpellingsOfATrackedBaselineInput(): void
    {
        Fs::write($this->case . '/baseline.json', "{}\n");
        $this->assertRefused('Use baseline-src/', args: ['--baseline=baseline.json']);
        $this->assertRefused('Use baseline-src/', args: ['--baseline', 'baseline.json']);
    }

    #[Test]
    public function itJudgesEveryInputFileByTheContainmentRule(): void
    {
        Fs::write($this->root . '/outside/preset.yaml', "suppress_paths: []\n");

        $this->assertRefused('outside its own directory', args: ['--preset=../../outside/preset.yaml']);
    }

    #[Test]
    public function itReadsACaseWithoutAnOutcomeAsAnAnalysis(): void
    {
        $case = $this->load();

        self::assertSame(CaseOutcome::ANALYSIS, $case->outcome);
        self::assertNull($case->outcomeExit);
    }

    #[Test]
    public function itKeepsDeclaredSideOutcomesWhenMaterializingTheCase(): void
    {
        $this->load();
        $case = CaseDefinition::load($this->case, DeclaredOutcomes::ANALYSIS_TO_REFUSAL);
        self::assertSame(CaseOutcome::REFUSAL, CaseOutcome::of($case, 'candidate'));
        self::assertSame(CaseOutcome::ANALYSIS, CaseOutcome::of($case, 'reference'));
        $copy = $case->withDirectory($this->root . '/materialized/probe');
        self::assertSame($case->id, $copy->id);
        self::assertSame($case->paths, $copy->paths);
        self::assertSame(CaseOutcome::REFUSAL, CaseOutcome::of($copy, 'candidate'));
        $reverse = CaseDefinition::load($this->case, DeclaredOutcomes::REFUSAL_TO_ANALYSIS);
        self::assertSame(CaseOutcome::ANALYSIS, CaseOutcome::of($reverse, 'candidate'));
        self::assertSame(CaseOutcome::REFUSAL, CaseOutcome::of($reverse, 'reference'));
    }

    #[Test]
    public function itReadsTheExpectedOutcomeAndExit(): void
    {
        $case = $this->load(outcome: ['kind' => 'incomplete', 'exit' => 4]);

        self::assertSame(CaseOutcome::INCOMPLETE, $case->outcome);
        self::assertSame(4, $case->outcomeExit);
    }

    #[Test]
    public function itAllowsAnExplicitlyEmptyChannelClaimOnlyForAnAuxiliaryRefusal(): void
    {
        $case = $this->load(outcome: ['kind' => 'refusal', 'exit' => 3], extra: ['coverage' => 'auxiliary', 'channels' => []]);
        self::assertSame([], $case->channels);
        self::assertSame(CaseOutcome::REFUSAL, $case->outcome);

        foreach ([null, ['kind' => 'incomplete', 'exit' => 4], ['kind' => 'refusal', 'exit' => 3]] as $outcome) {
            try {
                $this->load(outcome: $outcome, extra: ['channels' => []]);
            } catch (GateError $error) {
                self::assertStringContainsString('"channels" must not be empty', $error->getMessage());
                continue;
            }
            self::fail('An empty channel claim was accepted outside an auxiliary refusal.');
        }
    }

    #[Test]
    public function itStillRequiresTheChannelsListOnAnAuxiliaryRefusal(): void
    {
        $this->load(outcome: ['kind' => 'refusal', 'exit' => 3], extra: ['coverage' => 'auxiliary', 'channels' => []]);
        $file = $this->case . '/case.json';
        $definition = json_decode(Fs::read($file), true, flags: \JSON_THROW_ON_ERROR);
        unset($definition['channels']);
        Fs::write($file, json_encode($definition, \JSON_THROW_ON_ERROR));
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('"channels" must be an array of strings');
        CaseDefinition::load($this->case);
    }

    /** @return iterable<string, array{mixed}> */
    public static function provideMalformedOutcomes(): iterable
    {
        yield 'an analysis spelled out' => [['kind' => 'analysis', 'exit' => 0]];
        yield 'no exit' => [['kind' => 'refusal']];
        yield 'an exit of zero' => [['kind' => 'refusal', 'exit' => 0]];
        yield 'an exit as a string' => [['kind' => 'refusal', 'exit' => '3']];
        yield 'an unknown kind' => [['kind' => 'crash', 'exit' => 3]];
        yield 'an extra key' => [['kind' => 'refusal', 'exit' => 3, 'why' => 'x']];
        yield 'a bare string' => ['refusal'];
    }

    #[Test]
    #[DataProvider('provideMalformedOutcomes')]
    public function itRefusesAMalformedOutcome(mixed $outcome): void
    {
        try {
            $this->load(outcome: $outcome);
        } catch (GateError $error) {
            self::assertStringContainsString('"outcome" must be', $error->getMessage());

            return;
        }

        self::fail('The case was accepted.');
    }

    #[Test]
    public function itListsAndTranslatesTheAdditionalInputsThroughOneObject(): void
    {
        Fs::write($this->case . '/channels.tsv', "from\tto\n");
        Fs::write($this->case . '/baseline-src/src/A.php', "<?php\n");
        $case = $this->load(extra: ['layerAssignmentSubjects' => ['App\\A'], 'renameChannelsMap' => 'channels.tsv']);
        $translation = new CaseInputTranslation(RenameMaps::fromPairs([]), false, $this->root, 'candidate', DeclaredStructuralMaps::load($this->root));
        self::assertSame(['App\\A'], $translation->layerAssignmentSubjects($case));
        self::assertSame('channels.tsv', $translation->renameChannelsMap($case));
        self::assertSame('baseline-src', $translation->baselineSource($case));
        self::assertContains(['option' => 'baseline:rename-channels', 'path' => 'channels.tsv'], $case->inputFiles());
        self::assertContains(['option' => 'baseline-source', 'path' => 'baseline-src/src/A.php'], $case->inputFiles());
    }

    #[Test]
    public function itRefusesALayerAssignmentSubjectThatIsNotAClassName(): void
    {
        $this->expectException(GateError::class);
        $this->load(extra: ['layerAssignmentSubjects' => ['App::method']]);
    }

    #[Test]
    public function itRefusesARenameMapThatLinksOutsideTheCase(): void
    {
        symlink($this->root . '/outside/qmx.yaml', $this->case . '/channels.tsv');
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('outside its own directory');
        $this->load(extra: ['renameChannelsMap' => 'channels.tsv']);
    }

    #[Test]
    public function itRefusesABaselineVariantThatDoesNotMirrorTheAnalysisPaths(): void
    {
        mkdir($this->case . '/baseline-src');
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('does not exist');
        $this->load();
    }

    #[Test]
    public function itRefusesAnEscapingFileInsideABaselineVariant(): void
    {
        mkdir($this->case . '/baseline-src/src', 0o777, true);
        symlink($this->root . '/outside/qmx.yaml', $this->case . '/baseline-src/src/A.php');
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('outside its own directory');
        $this->load();
    }

    /**
     * @param list<string> $paths
     * @param list<string> $args
     */
    private function assertRefused(string $reason, array $paths = ['src'], string $config = 'qmx.yaml', array $args = []): void
    {
        try {
            $this->load($paths, $config, $args);
        } catch (GateError $error) {
            self::assertStringContainsString($reason, $error->getMessage());

            return;
        }

        self::fail('The case was accepted.');
    }

    /**
     * @param list<string> $paths
     * @param list<string> $args
     * @param array<string,mixed> $extra
     */
    private function load(array $paths = ['src'], string $config = 'qmx.yaml', array $args = [], mixed $outcome = null, array $extra = []): CaseDefinition
    {
        $definition = [
            'id' => 'probe',
            'description' => 'A case written by the test.',
            'paths' => $paths,
            'config' => $config,
            'args' => $args,
            'channels' => ['a.code@class'],
        ];

        if ($outcome !== null) {
            $definition['outcome'] = $outcome;
        }

        Fs::write($this->case . '/case.json', (string) json_encode([...$definition, ...$extra]));

        return CaseDefinition::load($this->case);
    }
}
