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
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineCleaner;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\BaselineUpdater;
use Qualimetrix\Analysis\Policy\Baseline\BaselineWriter;
use Qualimetrix\Analysis\Policy\Baseline\BoundaryExplanationService;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
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
 * A baseline file that is not there is refused before anything is analysed.
 *
 * Only the file's *contents* must wait for the run — a `computed.*` entry is
 * parsed against declarations the run resolves (see
 * {@see BaselineRunBeforeLoadTest}). Whether the file exists at all is not
 * such a question, and answering it after a full analysis costs the user the
 * analysis and then says what could have been said first.
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
     * The early answer is the loader's own, not a second spelling of it.
     */
    #[Test]
    public function itRefusesInOneVoiceWhetherAskedEarlyOrByTheLoader(): void
    {
        $early = null;
        $late = null;

        try {
            BaselineLoader::assertReadable($this->missing);
        } catch (ConfigurationRefusal $refusal) {
            $early = $refusal->getMessage();
        }

        try {
            (new BaselineLoader(new BaselineEntryParser(StubChannelDeclarationRegistry::withDefaults())))->load($this->missing);
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
            'cleanup' => new BaselineCleanupCommand($run, $loader, new BaselineCleaner($clock), new BaselineWriter(), $declarations, StubRuleCoverage::everyRuleRan()),
            'update' => new BaselineUpdateCommand($run, $loader, new BaselineUpdater($declarations, $clock), new BaselineWriter()),
            'explain' => new BaselineExplainCommand(
                $run,
                $loader,
                new BoundaryExplanationService(self::createStub(ChannelIdentityInterface::class), StubRuleCoverage::everyRuleRan()),
                new BaselineConfiguredThresholds(self::emptyRuleRegistry(), new RuleOptionsFactory(new RuleOptionsRegistry())),
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

            public function analyze(RunConfiguration $configuration, ?FileDiscoveryInterface $customFileDiscovery = null): AnalysisResult
            {
                ++$this->calls;

                return $this->delegate->analyze($configuration, $customFileDiscovery);
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
            $property('refusalPresenter'),
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
            new Baseline(generated: (new FixedClock())->now(), scope: ['src'], entries: []),
            $path,
            AbsolutePath::fromString($this->tempDir),
        );
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
}
