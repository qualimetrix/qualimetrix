<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfigurationResolverInterface;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfigurationResolverInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\IncompleteAnalysisException;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationResolverInterface;
use Qualimetrix\Infrastructure\Console\AnalysisPreflight;
use Qualimetrix\Infrastructure\Console\CheckConfigurationResolvers;
use Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineExplainCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineRenameChannelsCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineRun;
use Qualimetrix\Infrastructure\Console\Command\BaselineUpdateCommand;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\Command\Debug\LayerAssignmentCommand;
use Qualimetrix\Infrastructure\Console\Command\DirectivesCommand;
use Qualimetrix\Infrastructure\Console\ConfigurationInputAdapter;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\Console\ResultPresenter;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationResolverInterface;
use Qualimetrix\Infrastructure\Rule\Exception\ConflictingCliAliasException;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusionsResolverInterface;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

/**
 * `catch (ConfigurationRefusal)` must be the first clause in every ladder
 * that can reach it, and `RefusalPresenter` must be wired into each such
 * command. A substituted configuration resolver makes the routing testable
 * without depending on a particular refusal-producing input.
 *
 * Two independent things are proven, on purpose kept apart:
 *
 * - **Wiring** ({@see itWiresThePresenterIntoEveryCommandTheRealContainerBuilds}):
 *   the real production container, unmodified, builds every one of the eight
 *   commands with a presenter in place. `CheckCommand`, `DirectivesCommand`
 *   and `LayerAssignmentCommand` take it as a constructor argument, so a
 *   missed DI registration would fail loudly at construction; the five
 *   `baseline:*` commands take it through
 *   {@see BaselineCommand::setRefusalPresenter()} instead — a missed
 *   `addMethodCall` for one of those five leaves construction silently
 *   successful with the property uninitialized, so this is the one check
 *   that would catch it.
 * - **Behaviour** (the rest of this class): each command is hand-assembled
 *   with a substituted {@see ConfigurationPipelineInterface} that throws
 *   {@see ConfigurationRefusal}, and every other dependency the throw is
 *   reached before is an inert placeholder — never a mock, because most of
 *   these collaborators are declared `final` and cannot be doubled. Every
 *   collaborator actually invoked before the throw (the real
 *   {@see RuntimeConfigurator}, {@see ConfigurationInputAdapter},
 *   {@see AnalysisPreflight}, {@see BaselineRun}) is real.
 *
 * `baseline:rename-channels` is wired identically to the other four
 * `baseline:*` commands (proven in the wiring case) but carries no
 * behavioural case here: it takes no `--config`, resolves no configuration
 * document, and consults {@see \Qualimetrix\Analysis\Policy\Baseline\BaselineChannelRenamer}
 * alone — there is no resolver in its call path to substitute.
 */
#[CoversNothing]
final class ConfigurationRefusalRoutingTest extends TestCase
{
    private const string REFUSAL_SUMMARY = 'the substituted resolver refused this configuration';

    #[Test]
    public function itWiresThePresenterIntoEveryCommandTheRealContainerBuilds(): void
    {
        $container = (new ContainerFactory())->create();

        $constructorInjected = [
            CheckCommand::class,
            DirectivesCommand::class,
            LayerAssignmentCommand::class,
        ];
        foreach ($constructorInjected as $class) {
            self::assertInstanceOf($class, $container->get($class), \sprintf(
                '%s must build through the real container with a RefusalPresenter argument.',
                $class,
            ));
        }

        $setterInjected = [
            BaselineGenerateCommand::class,
            BaselineUpdateCommand::class,
            BaselineCleanupCommand::class,
            BaselineRenameChannelsCommand::class,
            BaselineExplainCommand::class,
        ];
        $property = new ReflectionProperty(BaselineCommand::class, 'refusalPresenter');
        foreach ($setterInjected as $class) {
            $command = $container->get($class);
            self::assertInstanceOf($class, $command);
            self::assertTrue(
                $property->isInitialized($command),
                \sprintf(
                    '%s must receive RefusalPresenter through setRefusalPresenter() — '
                    . 'a missing addMethodCall registration leaves construction silently successful.',
                    $class,
                ),
            );
        }
    }

    #[Test]
    public function itAnswersTheCarrierWithExitThreeInCheck(): void
    {
        $command = new CheckCommand(
            $this->inert(AnalysisPipelineInterface::class),
            $this->inert('Qualimetrix\\Infrastructure\\Console\\FindingFilterOrchestrator'),
            $this->realRuntimeConfigurator(),
            $this->inert('Qualimetrix\\Infrastructure\\Console\\ResultPresenter'),
            $this->realRuleInputValidator(),
            $this->inert('Qualimetrix\\Infrastructure\\Console\\CheckScopeResolver'),
            $this->throwingConfigurationInputAdapter(),
            $this->inert(CheckConfigurationResolvers::class),
            $this->freshPresenter(),
        );

        $tester = new CommandTester($command);
        $code = $tester->execute(['paths' => ['src']], ['capture_stderr_separately' => true]);

        self::assertSame(3, $code);
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString(self::REFUSAL_SUMMARY, $tester->getErrorOutput());
    }

    #[Test]
    public function itAnswersTheCarrierWithExitThreeInDirectives(): void
    {
        $command = new DirectivesCommand(
            $this->inert(DirectiveAuditInterface::class),
            $this->realAnalysisPreflight(),
            $this->freshPresenter(),
        );

        $tester = new CommandTester($command);
        $code = $tester->execute(['paths' => ['src']], ['capture_stderr_separately' => true]);

        self::assertSame(3, $code);
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString(self::REFUSAL_SUMMARY, $tester->getErrorOutput());
    }

    #[Test]
    public function itAnswersTheCarrierWithExitThreeInDebugLayerAssignment(): void
    {
        // `AnalysisPreflight` reduces the command's constructor arguments;
        // the same
        // helper `itAnswersTheCarrierWithExitThreeInDirectives` already
        // builds, reused here rather than duplicated. `resolve()` reaches the
        // throwing `ConfigurationInputAdapter` before any of the run/cache/
        // parallel resolvers or `RuleInputValidator` it bundles, so those
        // stay inert.
        $command = new LayerAssignmentCommand(
            $this->realAnalysisPreflight(),
            $this->inert('Qualimetrix\\Infrastructure\\Console\\LayerAssignmentResolver'),
            $this->freshPresenter(),
        );

        $tester = new CommandTester($command);
        $code = $tester->execute(['fqn' => 'App\\Foo'], ['capture_stderr_separately' => true]);

        self::assertSame(3, $code);
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString(self::REFUSAL_SUMMARY, $tester->getErrorOutput());
    }

    #[Test]
    public function itAnswersTheCarrierWithExitThreeInBaselineGenerate(): void
    {
        $command = new BaselineGenerateCommand(
            $this->realBaselineRun(),
            $this->inert('Qualimetrix\\Analysis\\Policy\\Baseline\\BaselineGenerator'),
            $this->inert('Qualimetrix\\Analysis\\Policy\\Baseline\\BaselineWriter'),
        );
        $command->setRefusalPresenter($this->freshPresenter());

        $tester = new CommandTester($command);
        $code = $tester->execute(
            ['baseline' => $this->nonExistentBaselinePath()],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $code);
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString(self::REFUSAL_SUMMARY, $tester->getErrorOutput());
    }

    #[Test]
    public function itAnswersTheCarrierWithExitThreeInBaselineUpdate(): void
    {
        $command = new BaselineUpdateCommand(
            $this->realBaselineRun(),
            $this->inert('Qualimetrix\\Analysis\\Policy\\Baseline\\BaselineLoader'),
            $this->inert('Qualimetrix\\Analysis\\Policy\\Baseline\\BaselineUpdater'),
            $this->inert('Qualimetrix\\Analysis\\Policy\\Baseline\\BaselineWriter'),
        );
        $command->setRefusalPresenter($this->freshPresenter());

        $tester = new CommandTester($command);
        $code = $tester->execute(
            ['baseline' => $this->nonExistentBaselinePath()],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $code);
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString(self::REFUSAL_SUMMARY, $tester->getErrorOutput());
    }

    #[Test]
    public function itAnswersTheCarrierWithExitThreeInBaselineCleanup(): void
    {
        $command = new BaselineCleanupCommand(
            $this->realBaselineRun(),
            $this->inert('Qualimetrix\\Analysis\\Policy\\Baseline\\BaselineLoader'),
            $this->inert('Qualimetrix\\Analysis\\Policy\\Baseline\\BaselineCleaner'),
            $this->inert('Qualimetrix\\Analysis\\Policy\\Baseline\\BaselineWriter'),
            $this->inert('Qualimetrix\\Analysis\\Finding\\Contract\\ChannelDeclarationRegistryInterface'),
        );
        $command->setRefusalPresenter($this->freshPresenter());

        $tester = new CommandTester($command);
        $code = $tester->execute(
            ['baseline' => $this->nonExistentBaselinePath()],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $code);
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString(self::REFUSAL_SUMMARY, $tester->getErrorOutput());
    }

    #[Test]
    public function itAnswersTheCarrierWithExitThreeInBaselineExplain(): void
    {
        $command = new BaselineExplainCommand(
            $this->realBaselineRun(),
            $this->inert('Qualimetrix\\Analysis\\Policy\\Baseline\\BaselineLoader'),
            $this->inert('Qualimetrix\\Analysis\\Policy\\Baseline\\BoundaryExplanationService'),
            $this->inert('Qualimetrix\\Infrastructure\\Console\\Command\\BaselineConfiguredThresholds'),
            $this->inert('Qualimetrix\\Analysis\\Finding\\Contract\\ChannelDeclarationRegistryInterface'),
        );
        $command->setRefusalPresenter($this->freshPresenter());

        $tester = new CommandTester($command);
        $code = $tester->execute(
            ['subject' => 'App\\Foo::bar'],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $code);
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString(self::REFUSAL_SUMMARY, $tester->getErrorOutput());
    }

    /**
     * `IncompleteAnalysisException` (code 4) sits below the new first clause
     * in `BaselineCommand::execute()` — proof the insertion did not reorder
     * or swallow it. `BaselineConflictException` (code 1) gets the same
     * proof from {@see \Qualimetrix\Tests\Analysis\Policy\Baseline\Functional\BaselineCommandFailureReportingTest::provideFailures()}
     * staying green under the same constructor change, not duplicated here.
     */
    #[Test]
    public function itLeavesIncompleteAnalysisAtExitFourInTheSharedLadder(): void
    {
        $command = new class extends BaselineCommand {
            protected function configure(): void {}

            protected function doExecute(InputInterface $input, OutputInterface $output): int
            {
                throw new IncompleteAnalysisException(new AnalysisCoverage([], [], []));
            }
        };
        $command->setRefusalPresenter($this->freshPresenter());

        $tester = new CommandTester($command);
        $code = $tester->execute([]);

        self::assertSame(4, $code);
    }

    /**
     * `ConflictingCliAliasException` predates the carrier and, unlike the
     * cases above, was never a configuration refusal: its clause in
     * `CheckCommand::execute()` was dead code — the alias collision it names
     * is built by `RuleRegistry` from rule classes' own declarations, not
     * from anything a CLI invocation supplies, so the CLI path this test
     * drives can never throw it. The clause is absent; this case proves the
     * exception falls all the way to
     * `catch (Throwable)` and answers as a product defect, code 1, not 3 —
     * the behaviour the removal is supposed to have, not a regression of it.
     */
    #[Test]
    public function itLeavesConflictingCliAliasAtExitOneAsAnInternalError(): void
    {
        $command = new CheckCommand(
            $this->inert(AnalysisPipelineInterface::class),
            $this->inert('Qualimetrix\\Infrastructure\\Console\\FindingFilterOrchestrator'),
            $this->realRuntimeConfigurator(),
            $this->realResultPresenter(),
            $this->realRuleInputValidator(),
            $this->inert('Qualimetrix\\Infrastructure\\Console\\CheckScopeResolver'),
            $this->throwingConfigurationInputAdapter(new ConflictingCliAliasException(
                'complexity.cyclomatic',
                'security.hardcoded-credentials',
                '--strict',
            )),
            $this->inert(CheckConfigurationResolvers::class),
            $this->freshPresenter(),
        );

        $tester = new CommandTester($command);
        $code = $tester->execute(['paths' => ['src']]);

        self::assertSame(1, $code);
    }

    private function freshPresenter(): RefusalPresenter
    {
        // A fresh ErrorStream per case, not one shared across assertions:
        // ErrorStream::bind() remembers the output it was last bound to, and
        // a stale binding would answer this case's stderr assertion with a
        // previous case's stream.
        return new RefusalPresenter(new ErrorStream());
    }

    private function throwingConfigurationInputAdapter(?Throwable $throwable = null): ConfigurationInputAdapter
    {
        $refusal = $throwable ?? ConfigurationRefusal::aboutInput(
            ConfigurationOrigin::of(ConfigurationSource::CommandLine, '--config'),
            self::REFUSAL_SUMMARY,
        );

        return new ConfigurationInputAdapter(new class ($refusal) implements ConfigurationPipelineInterface {
            public function __construct(private readonly Throwable $refusal) {}

            public function resolve(ConfigurationResolutionRequest $request): ConfigurationDocument
            {
                throw $this->refusal;
            }
        });
    }

    private function realRuntimeConfigurator(): RuntimeConfigurator
    {
        $configurator = (new ContainerFactory())->create()->get(RuntimeConfigurator::class);
        self::assertInstanceOf(RuntimeConfigurator::class, $configurator);

        return $configurator;
    }

    /**
     * Only `writeDiagnostic()` — which writes through `ErrorStream` — is
     * exercised by the cases in this class; every other collaborator is
     * inert.
     */
    private function realResultPresenter(): ResultPresenter
    {
        return new ResultPresenter(
            $this->inert('Qualimetrix\\Reporting\\Formatter\\FormatterRegistryInterface'),
            $this->inert('Qualimetrix\\Core\\Profiler\\Contract\\ProfilerInterface'),
            $this->inert('Qualimetrix\\Reporting\\Health\\SummaryEnricher'),
            $this->inert('Qualimetrix\\Infrastructure\\Console\\ProfilePresenter'),
            $this->inert('Qualimetrix\\Infrastructure\\Console\\ExitCodeResolver'),
            $this->inert('Qualimetrix\\Reporting\\DrillDown\\FindingFilter'),
            $this->inert('Qualimetrix\\Infrastructure\\Console\\FormatterContextFactory'),
            $this->inert('Qualimetrix\\Analysis\\Finding\\Contract\\RuleConfigurationInterface'),
            new ErrorStream(),
        );
    }

    private function realRuleInputValidator(): RuleInputValidator
    {
        // Only its constructor runs before the carrier is thrown
        // (`configure()` calls `configureCheckCommand()`, which reads
        // `RuleRegistryInterface::getClasses()`/`getAllCliAliases()`); every
        // other collaborator is inert.
        $ruleRegistry = new class implements RuleRegistryInterface {
            public function getClasses(): array
            {
                return [];
            }

            public function getAllCliAliases(): array
            {
                return [];
            }
        };

        return new RuleInputValidator(
            $ruleRegistry,
            $this->inert('Qualimetrix\\Analysis\\Finding\\Contract\\Rule\\RuleSelector'),
            $this->inert(FindingConfigurationResolverInterface::class),
            $this->inert('Qualimetrix\\Infrastructure\\Rule\\Contract\\RuleChannelSnapshotFactoryInterface'),
        );
    }

    private function realAnalysisPreflight(): AnalysisPreflight
    {
        return new AnalysisPreflight(
            $this->realRuntimeConfigurator(),
            $this->throwingConfigurationInputAdapter(),
            $this->inert(RunConfigurationResolverInterface::class),
            $this->inert(CacheConfigurationResolverInterface::class),
            $this->inert(ParallelConfigurationResolverInterface::class),
            $this->inert('Qualimetrix\\Infrastructure\\Console\\RuleInputValidator'),
            $this->inert(FileDiscoveryFactoryInterface::class),
        );
    }

    private function realBaselineRun(): BaselineRun
    {
        return new BaselineRun(
            $this->realRuntimeConfigurator(),
            $this->inert('Qualimetrix\\Infrastructure\\Console\\MeasuredFindingSet'),
            $this->inert('Qualimetrix\\Infrastructure\\Console\\RuleInputValidator'),
            $this->throwingConfigurationInputAdapter(),
            $this->inert(RunConfigurationResolverInterface::class),
            $this->inert(ConfiguredFindingExclusionsResolverInterface::class),
            $this->inert(CacheConfigurationResolverInterface::class),
            $this->inert(ParallelConfigurationResolverInterface::class),
        );
    }

    private function nonExistentBaselinePath(): string
    {
        return sys_get_temp_dir() . '/qmx-refusal-routing-' . bin2hex(random_bytes(6)) . '.json';
    }

    /**
     * A placeholder for a collaborator the carrier is thrown before the
     * command ever calls. An interface is stubbed through PHPUnit's test
     * double generator; a concrete class — most of these are `final`, so
     * `createStub()`/`createMock()` would reject them — is built with every
     * property left uninitialized. Either way, invoking a method on the
     * result fails loudly, which is exactly the signal that a case's
     * call-order assumption was wrong.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function inert(string $class): object
    {
        $reflection = new ReflectionClass($class);

        /** @var T */
        return $reflection->isInterface()
            ? self::createStub($class)
            : $reflection->newInstanceWithoutConstructor();
    }
}
