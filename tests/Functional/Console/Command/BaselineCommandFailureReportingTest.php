<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Functional\Console\Command;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Exception\ConfigLoadException;
use Qualimetrix\Baseline\BaselineConflictException;
use Qualimetrix\Infrastructure\Console\Command\BaselineCommand;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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
}
