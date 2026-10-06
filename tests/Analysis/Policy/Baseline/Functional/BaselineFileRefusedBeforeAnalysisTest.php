<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Functional;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineCleaner;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\BaselineUpdater;
use Qualimetrix\Analysis\Policy\Baseline\BaselineWriter;
use Qualimetrix\Analysis\Policy\Baseline\BoundaryExplanationService;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineConfiguredThresholds;
use Qualimetrix\Infrastructure\Console\Command\BaselineExplainCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineUpdateCommand;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\FixedClock;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\StubBaselineRun;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\StubRuleCoverage;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\TempDirectory;
use ReflectionClass;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * An absent or grammatically invalid baseline is refused before analysis.
 *
 * Only the entries' channel and level semantics wait for configured
 * declarations (see {@see BaselineRunBeforeLoadTest}). The document grammar
 * is independent of them, so a full analysis cannot precede that refusal.
 *
 * The evidence is a count of runs, not a timing: the analysis pipeline for
 * `check`, the measured run for the baseline commands.
 */
#[CoversClass(CheckCommand::class)]
#[CoversClass(BaselineCleanupCommand::class)]
#[CoversClass(BaselineUpdateCommand::class)]
#[CoversClass(BaselineExplainCommand::class)]
#[CoversClass(BaselineLoader::class)]
final class BaselineFileRefusedBeforeAnalysisTest extends TestCase
{
    private const string FIXTURE = 'tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php';

    private string $tempDir;
    private string $missing;
    private int $runs = 0;

    protected function setUp(): void
    {
        $this->tempDir = TempDirectory::create('qmx-baseline-missing-');
        $this->missing = $this->tempDir . '/absent.json';
        $this->runs = 0;
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->tempDir);
    }

    #[Test]
    public function itRefusesAMissingBaselineFileInCheckBeforeAnalysis(): void
    {
        [$tester, $pipeline] = $this->executeCheck($this->missing);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Baseline file not found', $tester->getDisplay());
        self::assertSame(0, $pipeline->calls, 'The analysis ran before the missing baseline was refused.');
    }

    /** @return iterable<string, array{string}> */
    public static function provideBrokenGrammar(): iterable
    {
        yield 'invalid JSON' => ['json'];
        yield 'old version' => ['version'];
        yield 'unknown envelope key' => ['key'];
    }

    #[Test]
    #[DataProvider('provideBrokenGrammar')]
    public function itRefusesInvalidBaselineInCheckBeforeAnalysis(string $defect): void
    {
        $path = $this->tempDir . '/invalid-check.json';
        $this->writeBrokenBaseline($path, $defect);

        [$tester, $pipeline] = $this->executeCheck($path);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(0, $pipeline->calls, 'Check analysed before judging document grammar.');
    }

    #[Test]
    #[DataProvider('provideBrokenGrammar')]
    public function itRefusesInvalidBaselineInLifecycleCommandsBeforeMeasurement(string $defect): void
    {
        $path = $this->tempDir . '/invalid-lifecycle.json';
        $this->writeBrokenBaseline($path, $defect);

        foreach (['update', 'cleanup', 'explain'] as $command) {
            $tester = $this->executeBaselineCommand($command, $path);
            self::assertSame(3, $tester->getStatusCode(), $command . ': ' . $tester->getDisplay() . $tester->getErrorOutput());
            self::assertSame(0, $this->runs, $command . ' measured before judging document grammar.');
        }
    }

    /**
     * The legitimate neighbour: a baseline that exists is still applied after
     * the one analysis it needs.
     */
    #[Test]
    public function itStillAnalysesCheckOnceWithABaselineThatExists(): void
    {
        $present = $this->tempDir . '/present.json';
        $this->writeEmptyBaseline($present);

        [$tester, $pipeline] = $this->executeCheck($present);

        self::assertStringNotContainsString('Baseline file not found', $tester->getDisplay() . $tester->getErrorOutput());
        self::assertSame(1, $pipeline->calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideBaselineCommands(): iterable
    {
        yield 'cleanup' => ['cleanup'];
        yield 'update' => ['update'];
        yield 'explain' => ['explain'];
    }

    #[Test]
    #[DataProvider('provideBaselineCommands')]
    public function itRefusesAMissingFileInABaselineCommandBeforeItMeasures(string $command): void
    {
        $tester = $this->executeBaselineCommand($command, $this->missing);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Baseline file not found', $tester->getErrorOutput());
        self::assertSame(0, $this->runs, 'The run was measured before the missing baseline was refused.');
    }

    #[Test]
    #[DataProvider('provideBaselineCommands')]
    public function itStillMeasuresABaselineCommandOnceWithAFileThatExists(string $command): void
    {
        $present = $this->tempDir . '/present.json';
        $this->writeEmptyBaseline($present);

        $tester = $this->executeBaselineCommand($command, $present);

        self::assertStringNotContainsString('Baseline file not found', $tester->getDisplay() . $tester->getErrorOutput());
        self::assertSame(1, $this->runs);
    }

    /**
     * A directory passes `file_exists()` and `is_readable()` alike, and the
     * reader only fails on it once the whole run has been spent.
     */
    #[Test]
    public function itRefusesADirectoryInCheckBeforeAnalysis(): void
    {
        [$tester, $pipeline] = $this->executeCheck($this->tempDir);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Baseline path is not a regular file', $tester->getDisplay());
        self::assertSame(0, $pipeline->calls, 'The analysis ran before the directory was refused.');
    }

    #[Test]
    #[DataProvider('provideBaselineCommands')]
    public function itRefusesADirectoryInABaselineCommandBeforeItMeasures(string $command): void
    {
        $tester = $this->executeBaselineCommand($command, $this->tempDir);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Baseline path is not a regular file', $tester->getErrorOutput());
        self::assertSame(0, $this->runs, 'The run was measured before the directory was refused.');
    }

    /**
     * The legitimate neighbour of the directory refusal: `is_file()` follows a
     * link, so a baseline reached through one is still read.
     */
    #[Test]
    public function itStillReadsABaselineReachedThroughASymlink(): void
    {
        $present = $this->tempDir . '/present.json';
        $this->writeEmptyBaseline($present);
        $link = $this->tempDir . '/link.json';
        symlink($present, $link);

        (new \Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentReader())->assertReadable($link);
        $baseline = (new BaselineLoader(new BaselineEntryParser(StubChannelDeclarationRegistry::withDefaults())))->load((new \Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentReader())->preflight($link));

        self::assertSame([], $baseline->entries);
    }

    /**
     * The early answer is the loader's own, not a second spelling of it.
     */
    #[Test]
    public function itRefusesInOneVoiceWhetherAskedEarlyOrByTheLoader(): void
    {
        $early = null;
        $late = null;

        try {
            (new \Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentReader())->assertReadable($this->missing);
        } catch (ConfigurationRefusal $refusal) {
            $early = $refusal->getMessage();
        }

        try {
            (new BaselineLoader(new BaselineEntryParser(StubChannelDeclarationRegistry::withDefaults())))->load((new \Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentReader())->preflight($this->missing));
        } catch (ConfigurationRefusal $refusal) {
            $late = $refusal->getMessage();
        }

        self::assertNotNull($early);
        self::assertSame($early, $late);
    }

    private function executeBaselineCommand(string $name, string $baselinePath): CommandTester
    {
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $loader = new BaselineLoader(new BaselineEntryParser($declarations));
        $run = new StubBaselineRun(
            [],
            ['src'],
            AbsolutePath::fromString($this->tempDir),
            onMeasure: function (): void {
                ++$this->runs;
            },
        );
        $clock = new FixedClock('2026-09-01T00:00:00+00:00');

        $command = match ($name) {
            'cleanup' => new BaselineCleanupCommand($run, $loader, new \Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentReader(), new BaselineCleaner($clock), new BaselineWriter(), $declarations, StubRuleCoverage::everyRuleRan()),
            'update' => new BaselineUpdateCommand($run, $loader, new \Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentReader(), new BaselineUpdater($declarations, $clock), new BaselineWriter(), StubRuleCoverage::everyRuleRan()),
            'explain' => new BaselineExplainCommand(
                $run,
                $loader,
                new \Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentReader(),
                new BoundaryExplanationService(self::createStub(ChannelIdentityInterface::class), StubRuleCoverage::everyRuleRan(), $declarations),
                new BaselineConfiguredThresholds(self::emptyRuleRegistry(), new RuleOptionsRegistry()),
                $declarations,
            ),
            default => throw new LogicException($name),
        };
        $command->setRefusalPresenter(new RefusalPresenter(new ErrorStream()));

        $input = $name === 'explain'
            ? ['subject' => 'file:src/Fixture.php', 'paths' => ['src'], '--baseline' => $baselinePath]
            : ['baseline' => $baselinePath, 'paths' => ['src']];

        $tester = new CommandTester($command);
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    /**
     * @return array{CommandTester, object{calls: int}&AnalysisPipelineInterface}
     */
    private function executeCheck(string $baselinePath): array
    {
        $container = (new ContainerFactory())->create();
        /** @var CheckCommand $original */
        $original = $container->get(CheckCommand::class);

        $reflection = new ReflectionClass(CheckCommand::class);
        $property = static fn(string $name): mixed => $reflection->getProperty($name)->getValue($original);

        /** @var AnalysisPipelineInterface $delegate */
        $delegate = $property('analyzer');
        $pipeline = new class ($delegate) implements AnalysisPipelineInterface {
            public int $calls = 0;

            public function __construct(private readonly AnalysisPipelineInterface $delegate) {}

            public function analyze(RunConfiguration $configuration): AnalysisResult
            {
                ++$this->calls;

                return $this->delegate->analyze($configuration);
            }
        };

        $command = new CheckCommand(
            $pipeline,
            $property('findingFilterOrchestrator'),
            $property('runtimeConfigurator'),
            $property('resultPresenter'),
            $property('ruleInputValidator'),
            $property('checkScopeResolver'),
            $property('configurationInputAdapter'),
            $property('configurationResolvers'),
            $property('runTargetSession'),
        );

        $tester = new CommandTester($command);
        $tester->execute(
            ['paths' => [self::FIXTURE], '--format' => 'json', '--baseline' => $baselinePath],
            ['capture_stderr_separately' => true],
        );

        return [$tester, $pipeline];
    }

    private function writeEmptyBaseline(string $path): void
    {
        (new BaselineWriter())->write(
            new Baseline(generated: (new FixedClock())->now(), scope: ['src'], entries: [], exclusions: self::fixtureExclusions()),
            \Qualimetrix\Core\FileTarget\TargetPath::resolve($path),
            AbsolutePath::fromString($this->tempDir),
        );
    }

    private function writeBrokenBaseline(string $path, string $defect): void
    {
        $this->writeEmptyBaseline($path);
        $valid = (string) file_get_contents($path);
        $broken = match ($defect) {
            'json' => '{ not json',
            'version' => preg_replace('/"version"\s*:\s*14/', '"version": 13', $valid),
            'key' => preg_replace('/^\{\n/', "{\n  \"mystery\": true,\n", $valid),
            default => throw new LogicException('Unknown test defect'),
        };
        self::assertIsString($broken);
        file_put_contents($path, $broken);
    }

    private static function emptyRuleRegistry(): RuleRegistryInterface
    {
        return new class implements RuleRegistryInterface {
            public function getClasses(): array
            {
                return [];
            }

            public function getAllCliAliases(): array
            {
                return [];
            }
        };
    }

    private static function fixtureExclusions(): \Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions
    {
        return new \Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions(
            [],
            \Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy::Exclude,
        );
    }
}
