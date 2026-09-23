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
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailure;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
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
use Qualimetrix\Infrastructure\Console\ResultPresenter;
use Qualimetrix\Infrastructure\Profiler\ProfileSession;
use Qualimetrix\Reporting\Contract\OutputFormat;
use Qualimetrix\Reporting\DrillDown\FindingFilter;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Health\SummaryEnricher;
use Qualimetrix\Reporting\Report;
use Qualimetrix\Tests\Analysis\Evidence\Prioritization\Support\StubRemediationMinutes;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

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
            new OutputFormat(),
            new ExitPolicy(),
        );
    }

    /**
     * An unwritable `--output` target is
     * refused before analysis runs, not discovered afterward. The counter
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
                ->assertOutputIsWritable($this->input(['--output' => $dir . '/report.json']));
            self::fail('An unwritable --output directory must be refused before analysis runs.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString($dir, $refusal->summary());
        } finally {
            chmod($dir, 0o755);
            rmdir($dir);
        }
    }

    /**
     * The report is written beside its target and renamed over it, so the
     * precheck asks about that write: a directory cannot be renamed over, and
     * a writable file in a directory that cannot be written cannot be
     * replaced. Either used to pass the precheck and fail after the analysis.
     */
    #[Test]
    public function itRefusesADirectoryOutputTargetBeforeAnalysis(): void
    {
        $dir = sys_get_temp_dir() . '/qmx-result-presenter-directory-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);

        try {
            $this->presenter(self::createStub(FormatterRegistryInterface::class))
                ->assertOutputIsWritable($this->input(['--output' => $dir]));
            self::fail('A directory named by --output must be refused before analysis runs.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('is a directory', $refusal->summary());
        } finally {
            rmdir($dir);
        }
    }

    #[Test]
    public function itRefusesAWritableOutputFileInADirectoryItCannotWriteBeforeAnalysis(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Directory permissions do not bind root.');
        }

        $dir = sys_get_temp_dir() . '/qmx-result-presenter-sealed-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        touch($dir . '/report.json');
        chmod($dir, 0o555);

        try {
            $this->presenter(self::createStub(FormatterRegistryInterface::class))
                ->assertOutputIsWritable($this->input(['--output' => $dir . '/report.json']));
            self::fail('A target file whose directory cannot be written must be refused before analysis runs.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString($dir, $refusal->summary());
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
        $presenter->assertOutputIsWritable($this->input());
        $presenter->assertOutputIsWritable($this->input(['--output' => $writableTarget]));
    }

    /**
     * The second mechanism the precheck above cannot cover: a target that was
     * writable when checked and stops being writable before the write
     * happens, or a directory that never existed because the precheck was
     * bypassed (as here, calling {@see ResultPresenter::presentResults()}
     * directly). `writeOutput()` throws rather than reporting the findings'
     * own exit code — the refusal beats the outcome, because a report that
     * never reached disk cannot honestly be exit code 2.
     */
    #[Test]
    public function itRefusesInsteadOfSwallowingAWriteFailureAndBeatsTheFindingsExitCode(): void
    {
        $formatter = self::createStub(FormatterInterface::class);
        $formatter->method('getDefaultGroupBy')->willReturn(GroupBy::None);
        $formatter->method('format')->willReturn('rendered');
        $registry = self::createStub(FormatterRegistryInterface::class);
        $registry->method('get')->willReturn($formatter);
        $finding = $this->finding(Severity::Error);
        $missingDirectoryTarget = sys_get_temp_dir() . '/qmx-result-presenter-missing-'
            . bin2hex(random_bytes(6)) . '/report.json';

        $this->expectException(ConfigurationRefusal::class);

        $this->presenter($registry)->presentResults(
            [$finding],
            $this->analysisResult([$finding]),
            $this->input(['--output' => $missingDirectoryTarget]),
            new BufferedOutput(),
            AbsolutePath::fromString('/project'),
            new OutputFormat(),
            new ExitPolicy(),
        );
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
                new OutputFormat(),
                new ExitPolicy(),
            );
            self::fail('Passing both --namespace and --class must stay refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('mutually exclusive', $refusal->summary());
        }
    }

    /**
     * A failure message names paths from two worlds. A file inside the project
     * is shown relative, because the rest of the report is; a dependency
     * outside it has no relative form and keeps the absolute one.
     *
     * The method is private and its result reaches no public surface of this
     * class, so reflection is the only oracle there is.
     */
    #[Test]
    public function itRelativizesProjectPathsInAFailureMessageAndKeepsOutsidersAbsolute(): void
    {
        $projectRoot = '/project';
        $outsider = '/elsewhere/vendor/Dependency.php';

        $relativized = (new ReflectionMethod(ResultPresenter::class, 'relativizeFailureMessage'))->invoke(
            $this->presenter(self::createStub(FormatterRegistryInterface::class)),
            'Parse error in ' . $projectRoot . '/src/Broken.php; dependency ' . $outsider,
            AbsolutePath::fromString($projectRoot),
        );

        self::assertIsString($relativized);
        self::assertStringNotContainsString($projectRoot . '/', $relativized);
        self::assertStringContainsString('src/Broken.php', $relativized);
        self::assertStringContainsString($outsider, $relativized);
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
        return new AnalysisResult(
            $findings,
            0.1,
            $metrics ?? new InMemoryMetricRepository(),
            $coverage ?? new AnalysisCoverage([], [], []),
            namespaceTree: $namespaceTree,
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
