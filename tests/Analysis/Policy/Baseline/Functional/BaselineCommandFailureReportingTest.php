<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Functional;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Exception\ConfigLoadException;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureConfigurationException;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePreparationException;
use Qualimetrix\Analysis\Policy\Baseline\BaselineConflictException;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Console\CheckScopeResolver;
use Qualimetrix\Infrastructure\Console\Command\BaselineCommand;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\Command\Debug\LayerAssignmentCommand;
use Qualimetrix\Infrastructure\Console\DiagnosticOutput;
use Qualimetrix\Infrastructure\Console\LayerAssignmentResolver;
use Qualimetrix\Infrastructure\Console\ResultPresenter;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\ViolationFilterOrchestrator;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

/**
 * What a baseline command says when it fails, and what `-v` adds to it.
 *
 * Every one of these exceptions used to be turned into its message and
 * nothing else, on the reasoning that they are the user's to fix. That
 * classification is a guess, and it is wrong exactly when a trace is worth
 * most: a `RuntimeException` can be raised from anywhere in the analysis the
 * command runs, and its message names a symptom rather than a site. So the
 * default output stays a single sentence — nobody wants a trace for a typo'd
 * path — and `-v` is what it was asked for.
 *
 * {@see \Qualimetrix\Infrastructure\Console\Command\CheckCommand} makes the
 * same trade, which is the point: the two commands must not answer the same
 * flag differently.
 */
#[CoversClass(BaselineCommand::class)]
final class BaselineCommandFailureReportingTest extends TestCase
{
    /**
     * @return iterable<string, array{Throwable, string}>
     */
    public static function provideFailures(): iterable
    {
        yield 'a path that does not exist' => [
            new InvalidArgumentException('Path(s) do not exist: src'),
            'Path(s) do not exist: src',
        ];

        yield 'an unreadable baseline envelope' => [
            new RuntimeException('Baseline file not found: b.json'),
            'Baseline file not found: b.json',
        ];

        yield 'a configuration that will not load' => [
            ConfigLoadException::fileNotFound('qmx.yaml'),
            'Configuration error:',
        ];

        yield 'a file somebody else rewrote' => [
            new BaselineConflictException('Baseline file b.json changed since it was read'),
            'changed since it was read',
        ];

        yield 'a defect in the tool itself' => [
            new LogicException('the invariant nobody expected to break'),
            'Unexpected error: the invariant nobody expected to break',
        ];
    }

    /**
     * The default: one sentence, no trace, and a failing exit code.
     */
    #[Test]
    #[DataProvider('provideFailures')]
    public function itReportsAFailureAsOneSentence(Throwable $thrown, string $expected): void
    {
        $tester = self::execute($thrown, verbose: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString($expected, $tester->getDisplay());
        self::assertStringNotContainsString('Stack trace:', $tester->getDisplay());
    }

    /**
     * Under `-v` the same failure carries the trace — including the two
     * classes previously declared not to deserve one.
     */
    #[Test]
    #[DataProvider('provideFailures')]
    public function itAddsTheTraceWhenTheUserAsksForVerbosity(Throwable $thrown, string $expected): void
    {
        $tester = self::execute($thrown, verbose: true);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString($expected, $tester->getDisplay());
        self::assertStringContainsString('Stack trace:', $tester->getDisplay());
        self::assertStringContainsString(self::class, $tester->getDisplay());
    }

    /** @return iterable<string, array{Throwable, string}> */
    public static function provideArchitectureFailures(): iterable
    {
        yield 'invalid Architecture syntax' => [
            new ArchitectureConfigurationException('architecture.layers', 'invalid architecture syntax'),
            'Configuration error: invalid architecture syntax',
        ];

        yield 'Architecture preparation failure' => [
            new ArchitecturePreparationException('template expansion failed'),
            'template expansion failed',
        ];
    }

    #[Test]
    #[DataProvider('provideArchitectureFailures')]
    public function itPreservesBaselineArchitectureFailureFraming(Throwable $thrown, string $expected): void
    {
        $tester = self::execute($thrown, verbose: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame($expected, trim($tester->getDisplay()));
    }

    #[Test]
    public function itFramesCheckArchitectureSyntaxAndPreparationLikeBeforeP4(): void
    {
        [$syntaxExit, $syntaxOutput] = $this->executeCheckFailure(
            new ArchitectureConfigurationException('architecture.layers', 'invalid architecture syntax'),
        );
        [$preparationExit, $preparationOutput] = $this->executeCheckFailure(
            new ArchitecturePreparationException('template expansion failed'),
        );

        self::assertSame(3, $syntaxExit);
        self::assertSame('Configuration error: invalid architecture syntax', $syntaxOutput);
        self::assertSame(3, $preparationExit);
        self::assertSame('Architecture configuration error: template expansion failed', $preparationOutput);
    }

    #[Test]
    public function itFramesDebugArchitectureSyntaxAndPreparationLikeBeforeP4(): void
    {
        $syntax = $this->executeDebugFailure(
            new ArchitectureConfigurationException('architecture.layers', 'invalid architecture syntax'),
        );
        $preparation = $this->executeDebugFailure(
            new ArchitecturePreparationException('template expansion failed'),
        );

        self::assertSame(Command::FAILURE, $syntax->getStatusCode());
        self::assertSame('Configuration error: invalid architecture syntax', trim($syntax->getDisplay()));
        self::assertSame(Command::FAILURE, $preparation->getStatusCode());
        self::assertSame('Failed to load configuration: template expansion failed', trim($preparation->getDisplay()));
    }

    private static function execute(Throwable $thrown, bool $verbose): CommandTester
    {
        $command = new class ($thrown) extends BaselineCommand {
            public function __construct(private readonly Throwable $thrown)
            {
                parent::__construct('baseline:throwing-stub');
            }

            protected function doExecute(InputInterface $input, OutputInterface $output): int
            {
                throw $this->thrown;
            }
        };

        $tester = new CommandTester($command);
        $tester->execute([], $verbose ? ['verbosity' => OutputInterface::VERBOSITY_VERBOSE] : []);

        return $tester;
    }

    /** @return array{int, string} */
    private function executeCheckFailure(Throwable $thrown): array
    {
        $pipeline = self::createStub(ConfigurationPipelineInterface::class);
        $pipeline->method('resolve')->willThrowException($thrown);
        $rules = self::createStub(RuleRegistryInterface::class);
        $rules->method('getClasses')->willReturn([]);
        $rules->method('getAllCliAliases')->willReturn([]);

        $command = new CheckCommand(
            $rules,
            self::createStub(AnalysisPipelineInterface::class),
            self::withoutConstructor(CacheFactory::class),
            self::withoutConstructor(ViolationFilterOrchestrator::class),
            $pipeline,
            self::withoutConstructor(RuntimeConfigurator::class),
            self::withoutConstructor(ResultPresenter::class),
            self::withoutConstructor(RuleInputValidator::class),
            new DiagnosticOutput(),
            self::withoutConstructor(CheckScopeResolver::class),
        );

        $diagnostics = new BufferedOutput();
        $output = new ConsoleOutput();
        $output->setErrorOutput($diagnostics);
        $exit = $command->run(new ArrayInput([], $command->getDefinition()), $output);

        return [$exit, trim($diagnostics->fetch())];
    }

    private function executeDebugFailure(Throwable $thrown): CommandTester
    {
        $pipeline = self::createStub(ConfigurationPipelineInterface::class);
        $pipeline->method('resolve')->willThrowException($thrown);
        $command = new LayerAssignmentCommand(
            $pipeline,
            self::withoutConstructor(RuntimeConfigurator::class),
            self::withoutConstructor(LayerAssignmentResolver::class),
        );
        $tester = new CommandTester($command);
        $tester->execute(['fqn' => 'App\\Service\\Example']);

        return $tester;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function withoutConstructor(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
