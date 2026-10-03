<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Summary\HealthSummaryBuilder;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthMetricCatalog;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Evidence\Prioritization\Impact\ClassRankResolver;
use Qualimetrix\Analysis\Evidence\Prioritization\Impact\ImpactCalculator;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Inline\Contract\DirectiveObservations;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailure;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Analysis\Run\Contract\Pipeline\MeasuredRunResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\ExitCodeResolver;
use Qualimetrix\Infrastructure\Console\ExitPolicy;
use Qualimetrix\Infrastructure\Console\FormatterContextFactory;
use Qualimetrix\Infrastructure\Console\OutputHelper;
use Qualimetrix\Infrastructure\Console\ProfilePresenter;
use Qualimetrix\Infrastructure\Console\Refusal\EnvironmentRefusal;
use Qualimetrix\Infrastructure\Console\ResultPresenter;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargets;
use Qualimetrix\Infrastructure\Logging\LoggerFactory;
use Qualimetrix\Infrastructure\Profiler\ProfileSession;
use Qualimetrix\Reporting\Contract\OutputFormat;
use Qualimetrix\Reporting\DrillDown\FindingFilter;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Health\SummaryEnricher;
use Qualimetrix\Reporting\Report;
use Qualimetrix\Subprocess\ChildProcess;
use Qualimetrix\Tests\Analysis\Evidence\Prioritization\Support\StubRemediationMinutes;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

#[CoversClass(ResultPresenter::class)]
#[CoversClass(OutputHelper::class)]
final class ResultPresenterTest extends TestCase
{
    #[Test]
    #[DataProvider('provideSerializedPayloads')]
    public function itWritesSerializedPayloadsByteForByte(string $payload): void
    {
        $output = new BufferedOutput();

        OutputHelper::write($output, $payload);

        self::assertSame($payload, $output->fetch());
    }

    /** @return iterable<string, array{string}> */
    public static function provideSerializedPayloads(): iterable
    {
        yield 'HTML' => ["<!DOCTYPE html><html><body><finding id=\"1\">literal <tag></finding></body></html>\n"];
        yield 'Checkstyle XML' => ["<?xml version=\"1.0\"?><checkstyle><file name=\"src/<literal>.php\"/></checkstyle>\n"];
        yield 'JSON' => ["{\"message\":\"literal <tag>\",\"node\":\"<element/>\"}\n"];
        yield 'DOT' => ["digraph Dependencies { \"<literal>\" -> \"Target\"; }\n"];
        yield 'text' => ["Finding: literal <tag> must remain verbatim\n"];
    }

    #[Test]
    public function itLeavesDiagnosticsOnSymfonyFormattedOutput(): void
    {
        $output = new BufferedOutput();

        OutputHelper::write($output, '<payload>literal</payload>');
        $output->writeln('<info>Diagnostic</info>');

        self::assertSame('<payload>literal</payload>Diagnostic' . "\n", $output->fetch());
    }

    #[Test]
    public function itUsesTheExplicitResolvedFormatAndProjectRoot(): void
    {
        $formatter = $this->createMock(FormatterInterface::class);
        $formatter->method('getDefaultGroupBy')->willReturn(GroupBy::None);
        $formatter->expects(self::once())->method('format')->willReturn('rendered');

        $registry = $this->createMock(FormatterRegistryInterface::class);
        $registry->expects(self::once())->method('get')->with('json')->willReturn($formatter);

        $output = new BufferedOutput();
        $exit = $this->presenter($registry)->presentResults(
            [],
            $this->analysisResult(),
            $this->input(['--format' => 'text']),
            $output,
            AbsolutePath::fromString('/project'),
            $this->targets(),
            outputFormat: new OutputFormat('json'),
            exitPolicy: new ExitPolicy(),
        );

        self::assertSame(0, $exit);
        self::assertStringContainsString('rendered', $output->fetch());
    }

    #[Test]
    public function itAppliesTheExplicitExitPolicyWithoutAConfigurationProvider(): void
    {
        $formatter = self::createStub(FormatterInterface::class);
        $formatter->method('getDefaultGroupBy')->willReturn(GroupBy::None);
        $formatter->method('format')->willReturn('');
        $registry = self::createStub(FormatterRegistryInterface::class);
        $registry->method('get')->willReturn($formatter);
        $finding = $this->finding(Severity::Warning);

        $exit = $this->presenter($registry)->presentResults(
            [$finding],
            $this->analysisResult([$finding]),
            $this->input(),
            new BufferedOutput(),
            AbsolutePath::fromString('/project'),
            $this->targets(),
            outputFormat: new OutputFormat(),
            exitPolicy: new ExitPolicy(Severity::Warning),
        );

        self::assertSame(Severity::Warning->getExitCode(), $exit);
    }

    #[Test]
    public function itDefaultsToErrorOnlyExitBehavior(): void
    {
        $formatter = self::createStub(FormatterInterface::class);
        $formatter->method('getDefaultGroupBy')->willReturn(GroupBy::None);
        $formatter->method('format')->willReturn('');
        $registry = self::createStub(FormatterRegistryInterface::class);
        $registry->method('get')->willReturn($formatter);
        $finding = $this->finding(Severity::Warning);

        self::assertSame(0, $this->presenter($registry)->presentResults(
            [$finding],
            $this->analysisResult([$finding]),
            $this->input(),
            new BufferedOutput(),
            AbsolutePath::fromString('/project'),
            $this->targets(),
            new OutputFormat(),
            new ExitPolicy(),
        ));
    }

    #[Test]
    public function itRelativizesOnlyTheProjectRootPrefixInCoverageFailureMessages(): void
    {
        $formatter = $this->createMock(FormatterInterface::class);
        $formatter->method('getDefaultGroupBy')->willReturn(GroupBy::None);
        $formatter->expects(self::once())->method('format')->willReturnCallback(
            static function (Report $report): string {
                self::assertNotNull($report->coverage);
                self::assertSame(
                    'Parse error in src/Broken.php; dependency /external/project/shared.php',
                    $report->coverage->failures[0]->message,
                );

                return '';
            },
        );
        $registry = self::createStub(FormatterRegistryInterface::class);
        $registry->method('get')->willReturn($formatter);
        $coverage = new AnalysisCoverage([], [], [new AnalysisFailure(
            RelativePath::fromString('src/Broken.php'),
            AnalysisFailureKind::Parse,
            'Parse error in /project/src/Broken.php; dependency /external/project/shared.php',
        )]);

        $this->presenter($registry)->presentResults(
            [],
            $this->analysisResult(coverage: $coverage),
            $this->input(),
            new BufferedOutput(),
            AbsolutePath::fromString('/project'),
            $this->targets(),
            new OutputFormat(),
            new ExitPolicy(),
        );
    }

    /**
     * An unwritable `--output` target is refused before analysis runs. The counter
     * evidence that no analysis ran lives in {@see \Qualimetrix\Infrastructure\Console\Command\CheckCommand::doExecute()},
     * which calls this precheck before `runAnalysis()` — this test pins the
     * precheck itself, in isolation from that ordering.
     */
    #[Test]
    public function itRefusesAnUnwritableOutputTargetBeforeAnalysis(): void
    {
        $dir = sys_get_temp_dir() . '/qmx-result-presenter-precheck-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        chmod($dir, 0o555);

        try {
            $this->presenter(self::createStub(FormatterRegistryInterface::class))
                ->assertOutputIsWritable($this->input(['--output' => $dir . '/report.json']), $this->targets());
            self::fail('An unwritable --output directory must be refused before analysis runs.');
        } catch (EnvironmentRefusal $refusal) {
            self::assertStringContainsString($dir, $refusal->summary());
            self::assertFileDoesNotExist($dir . '/report.json');
        } finally {
            chmod($dir, 0o755);
            rmdir($dir);
        }
    }

    /**
     * A directory passes a check that only asks whether the path is writable,
     * and the write then failed after the analysis.
     */
    #[Test]
    public function itRefusesADirectoryOutputTargetBeforeAnalysis(): void
    {
        $dir = sys_get_temp_dir() . '/qmx-result-presenter-directory-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);

        try {
            $this->presenter(self::createStub(FormatterRegistryInterface::class))
                ->assertOutputIsWritable($this->input(['--output' => $dir]), $this->targets());
            self::fail('A directory named by --output must be refused before analysis runs.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('is a directory', $refusal->summary());
        } finally {
            rmdir($dir);
        }
    }

    /** An existing report is written in place, which needs the file and not its directory. */
    #[Test]
    public function itAcceptsAWritableOutputFileInADirectoryItCannotWrite(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Directory permissions do not bind root.');
        }

        self::expectNotToPerformAssertions();

        $dir = sys_get_temp_dir() . '/qmx-result-presenter-sealed-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        touch($dir . '/report.json');
        chmod($dir, 0o555);

        try {
            $this->presenter(self::createStub(FormatterRegistryInterface::class))
                ->assertOutputIsWritable($this->input(['--output' => $dir . '/report.json']), $this->targets());
        } finally {
            chmod($dir, 0o755);
            unlink($dir . '/report.json');
            rmdir($dir);
        }
    }

    #[Test]
    public function itAcceptsAWritableOutputTargetOrNoTargetAtAll(): void
    {
        self::expectNotToPerformAssertions();

        $presenter = $this->presenter(self::createStub(FormatterRegistryInterface::class));
        $writableTarget = sys_get_temp_dir() . '/qmx-result-presenter-writable-' . bin2hex(random_bytes(6)) . '.json';

        // Neither call may throw — the assertion is that execution reaches the end.
        $presenter->assertOutputIsWritable($this->input(), $this->targets());
        $presenter->assertOutputIsWritable($this->input(['--output' => $writableTarget]), $this->targets());
    }

    /**
     * A target may fail after it passes judgement and claim. The refusal beats
     * the findings' exit code because the report never reached disk.
     */
    #[Test]
    public function itRefusesInsteadOfSwallowingAWriteFailureAndBeatsTheFindingsExitCode(): void
    {
        $target = sys_get_temp_dir() . '/qmx-result-presenter-fault-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($target, 'old');
        $script = <<<'PHP'
            namespace Qualimetrix\Core\FileTarget {
                function fwrite($stream, string $bytes): int|false
                {
                    ++$GLOBALS['qmx_report_hit'];
                    return 0;
                }
            }
            namespace {
                require $argv[1];
                require $argv[2];
                $GLOBALS['qmx_report_hit'] = 0;
                $fixture = new \Qualimetrix\Tests\Infrastructure\Console\Integration\ResultPresenterTest('itRefusesInsteadOfSwallowingAWriteFailureAndBeatsTheFindingsExitCode');
                $stub = new \ReflectionMethod(\PHPUnit\Framework\TestCase::class, 'createStub');
                $formatter = $stub->invoke(null, \Qualimetrix\Reporting\Formatter\FormatterInterface::class);
                $formatter->method('getDefaultGroupBy')->willReturn(\Qualimetrix\Reporting\GroupBy::None);
                $formatter->method('format')->willReturn('rendered');
                $registry = $stub->invoke(null, \Qualimetrix\Reporting\Formatter\FormatterRegistryInterface::class);
                $registry->method('get')->willReturn($formatter);
                $method = static fn (string $name, ...$args) => (new \ReflectionMethod($fixture, $name))->invoke($fixture, ...$args);
                $finding = $method('finding', \Qualimetrix\Analysis\Finding\Contract\Severity::Error);
                $targets = $method('targets');
                $targets->judge('--output', $argv[3]);
                $targets->claim();
                try {
                    $exit = $method('presenter', $registry)->presentResults(
                        [$finding],
                        $method('analysisResult', [$finding]),
                        $method('input', ['--output' => $argv[3]]),
                        new \Symfony\Component\Console\Output\BufferedOutput(),
                        \Qualimetrix\Core\Path\AbsolutePath::fromString('/project'),
                        $targets,
                        new \Qualimetrix\Reporting\Contract\OutputFormat(),
                        new \Qualimetrix\Infrastructure\Console\ExitPolicy(),
                    );
                    $failure = null;
                } catch (\Qualimetrix\Infrastructure\Console\Refusal\EnvironmentRefusal $caught) {
                    $exit = null;
                    $failure = $caught->summary();
                }
                $targets->abandon();
                echo json_encode(['hit' => $GLOBALS['qmx_report_hit'], 'exit' => $exit, 'failure' => $failure, 'content' => file_get_contents($argv[3])]);
            }
            PHP;

        try {
            $run = ChildProcess::run([\PHP_BINARY, '-r', $script, \dirname(__DIR__, 4) . '/vendor/autoload.php', __FILE__, $target]);
            self::assertSame(0, $run['exitCode'], $run['stderr']);
            $result = json_decode($run['stdout'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertGreaterThan(0, $result['hit']);
            self::assertNull($result['exit']);
            self::assertStringContainsString('--output', $result['failure']);
            self::assertSame('', $result['content']);
        } finally {
            unlink($target);
        }
    }

    /**
     * `--namespace` selects what the report shows, so a value
     * naming nothing empties the report instead of failing the run — the same
     * `No violations found.` a genuinely clean subtree produces. The pair below
     * is the discriminator: one exit code each, and the refusal says which.
     */
    #[Test]
    public function itRefusesANamespaceThatSelectsNothingInTheRun(): void
    {
        try {
            $this->drillDownExit('--namespace', 'Zzz\\Nope');
            self::fail('A --namespace matching nothing must be refused, not silently emptied.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('Zzz\\Nope', $refusal->summary());
            self::assertStringContainsString('matched none of the', $refusal->summary());
        }
    }

    #[Test]
    public function itPresentsANamespaceThatExistsAndIsClean(): void
    {
        self::assertSame(0, $this->drillDownExit('--namespace', 'Demo\\Alpha'));
    }

    /** The same refusal behavior applies to the class selector. */
    #[Test]
    public function itRefusesAClassThatSelectsNothingInTheRun(): void
    {
        try {
            $this->drillDownExit('--class', 'Zzz\\Nope\\Thing');
            self::fail('A --class matching nothing must be refused, not silently emptied.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('Zzz\\Nope\\Thing', $refusal->summary());
            self::assertStringContainsString('matched none of the', $refusal->summary());
        }
    }

    #[Test]
    public function itPresentsAClassThatExistsAndIsClean(): void
    {
        self::assertSame(0, $this->drillDownExit('--class', 'Demo\\Alpha\\Widget'));
    }

    /**
     * The namespace tree is optional on {@see AnalysisResult}, so the check has
     * to reach its verdict from the metric repository alone. Both verdicts are
     * pinned, because "no tree" must not become "refuse everything" any more
     * than it becomes "accept everything".
     */
    #[Test]
    public function itReachesBothVerdictsWithoutANamespaceTree(): void
    {
        self::assertSame(0, $this->drillDownExit('--namespace', 'Demo\\Alpha', null));

        $this->expectException(ConfigurationRefusal::class);
        $this->drillDownExit('--namespace', 'Zzz\\Nope', null);
    }

    #[Test]
    public function itBindsANamespaceTheTreeKnowsAndTheMetricsDoNot(): void
    {
        self::assertSame(
            0,
            $this->drillDownExit('--namespace', 'Demo\\Gamma', new NamespaceTree(['Demo\\Gamma'])),
        );
    }

    /**
     * The mutually exclusive pair is settled by {@see FormatterContextFactory}
     * before either binding is looked up, so the earlier, more specific refusal
     * is the one the user sees.
     */
    #[Test]
    public function itKeepsTheMutuallyExclusivePairRefusedByItsOwnMessage(): void
    {
        $formatter = self::createStub(FormatterInterface::class);
        $formatter->method('getDefaultGroupBy')->willReturn(GroupBy::None);
        $registry = self::createStub(FormatterRegistryInterface::class);
        $registry->method('get')->willReturn($formatter);

        try {
            $this->presenter($registry)->presentResults(
                [],
                $this->analysisResult(metrics: $this->analyzedRepository()),
                $this->input(['--namespace' => 'Zzz\\Nope', '--class' => 'Zzz\\Nope\\Thing']),
                new BufferedOutput(),
                AbsolutePath::fromString('/project'),
                $this->targets(),
                new OutputFormat(),
                new ExitPolicy(),
            );
            self::fail('Passing both --namespace and --class must stay refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('mutually exclusive', $refusal->summary());
        }
    }

    private function targets(): RunTargets
    {
        return new RunTargets(new LoggerFactory());
    }

    private function presenter(FormatterRegistryInterface $registry): ResultPresenter
    {
        $session = new ProfileSession();
        $definitions = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $definitions->method('all')->willReturn([]);
        $remediation = new RemediationTimeRegistry(StubChannelDeclarationRegistry::alwaysHigherMagnitude(), StubRemediationMinutes::withRealValues());

        return new ResultPresenter(
            $registry,
            $session,
            new SummaryEnricher(
                new DebtCalculator($remediation),
                new ImpactCalculator(new ClassRankResolver(), $remediation),
                new HealthSummaryBuilder(new HealthMetricCatalog(), $definitions),
            ),
            new ProfilePresenter($session, new ErrorStream()),
            new ExitCodeResolver(StubChannelDeclarationRegistry::withDefaults()),
            new FindingFilter(),
            new FormatterContextFactory($registry),
            self::createStub(RuleConfigurationInterface::class),
            new ErrorStream(),
        );
    }

    /** @param array<string, mixed> $options */
    private function input(array $options = []): ArrayInput
    {
        $definition = new InputDefinition([
            new InputOption('format', null, InputOption::VALUE_REQUIRED),
            new InputOption('output', null, InputOption::VALUE_REQUIRED),
            new InputOption('profile', null, InputOption::VALUE_OPTIONAL),
            new InputOption('profile-format', null, InputOption::VALUE_REQUIRED),
            new InputOption('group-by', null, InputOption::VALUE_REQUIRED),
            new InputOption('format-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []),
            new InputOption('detail', null, InputOption::VALUE_OPTIONAL, '', false),
            new InputOption('top', null, InputOption::VALUE_REQUIRED),
            new InputOption('all', null, InputOption::VALUE_NONE),
            new InputOption('namespace', null, InputOption::VALUE_REQUIRED),
            new InputOption('class', null, InputOption::VALUE_REQUIRED),
        ]);

        return new ArrayInput($options, $definition);
    }

    /** @param list<Finding> $findings */
    private function analysisResult(
        array $findings = [],
        ?AnalysisCoverage $coverage = null,
        ?InMemoryMetricRepository $metrics = null,
        ?NamespaceTree $namespaceTree = null,
    ): AnalysisResult {
        return AnalysisResult::fromRun(
            measured: new MeasuredRunResult(
                repository: $metrics ?? new InMemoryMetricRepository(),
                coverage: $coverage ?? new AnalysisCoverage([], [], []),
                namespaceTree: $namespaceTree,
                projectScope: null,
                duration: 0.1,
            ),
            directives: new DirectiveObservations(
                suppressions: [],
                thresholdOverrides: [],
            ),
            ruleExecution: null,
            latePublished: $findings,
        );
    }

    /** A run that measured one class, so a drill-down value has something to bind to. */
    private function analyzedRepository(): InMemoryMetricRepository
    {
        $repository = new InMemoryMetricRepository();
        $repository->add(
            SymbolPath::forClass('Demo\\Alpha', 'Widget'),
            new MetricBag(),
            RelativePath::fromString('src/Alpha/Widget.php'),
            5,
        );

        return $repository;
    }

    private function drillDownExit(string $option, string $value, ?NamespaceTree $namespaceTree = null): int
    {
        $formatter = self::createStub(FormatterInterface::class);
        $formatter->method('getDefaultGroupBy')->willReturn(GroupBy::None);
        $formatter->method('format')->willReturn('No violations found.');
        $registry = self::createStub(FormatterRegistryInterface::class);
        $registry->method('get')->willReturn($formatter);

        return $this->presenter($registry)->presentResults(
            [],
            $this->analysisResult(metrics: $this->analyzedRepository(), namespaceTree: $namespaceTree),
            $this->input([$option => $option === '--namespace' ? 'subtree:' . $value : $value]),
            new BufferedOutput(),
            AbsolutePath::fromString('/project'),
            $this->targets(),
            new OutputFormat(),
            new ExitPolicy(),
        );
    }

    private function finding(Severity $severity): Finding
    {
        $path = RelativePath::fromString('src/Subject.php');
        $symbol = SymbolPath::forFile($path);

        return new Finding(
            location: new Location($path, 1),
            subject: MetricSubject::aggregate($symbol),
            symbolPath: $symbol,
            ruleName: 'fixture.rule',
            code: 'fixture.rule',
            message: 'Fixture',
            severity: $severity,
        );
    }
}
