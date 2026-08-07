<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Functional\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineExplainCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineMigrateCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineUpdateCommand;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Command\Command;

/**
 * §5.5's prohibition, checked rather than trusted.
 *
 * The measured set is defined by configuration and by the source's own
 * annotations; a flag that could move it would let a baseline command and the
 * `check` it must agree with measure two different sets, and the user who
 * passed the flag to one and not the other would never be told. The three
 * options below are exactly the ones that would do it, so no baseline command
 * may declare any of them.
 *
 * Asked of each command's own definition, because "we did not add it" is a
 * fact about today and this is a property of the surface.
 */
#[CoversClass(BaselineGenerateCommand::class)]
#[CoversClass(BaselineMigrateCommand::class)]
#[CoversClass(BaselineUpdateCommand::class)]
#[CoversClass(BaselineCleanupCommand::class)]
#[CoversClass(BaselineExplainCommand::class)]
final class BaselineCommandOptionSurfaceTest extends TestCase
{
    private const array FORBIDDEN_OPTIONS = ['exclude-path', 'exclude-namespace', 'no-suppression-annotations'];

    /**
     * @return iterable<string, array{class-string<Command>}>
     */
    public static function provideBaselineCommands(): iterable
    {
        yield 'generate' => [BaselineGenerateCommand::class];
        yield 'migrate' => [BaselineMigrateCommand::class];
        yield 'update' => [BaselineUpdateCommand::class];
        yield 'cleanup' => [BaselineCleanupCommand::class];
        yield 'explain' => [BaselineExplainCommand::class];
    }

    /**
     * @param class-string<Command> $commandClass
     */
    #[Test]
    #[DataProvider('provideBaselineCommands')]
    public function itDeclaresNoExclusionOrSuppressionOption(string $commandClass): void
    {
        $definition = self::command($commandClass)->getDefinition();

        foreach (self::FORBIDDEN_OPTIONS as $option) {
            self::assertFalse(
                $definition->hasOption($option),
                \sprintf('%s must not accept --%s: it would move the measured set (§5.5).', $commandClass, $option),
            );
        }
    }

    /**
     * The counterpart: `--config` *is* allowed, and is in fact required for
     * the commands to be pointed at the configuration that defines the set.
     * Without this the test above would also pass on five commands that took
     * no options at all.
     *
     * @param class-string<Command> $commandClass
     */
    #[Test]
    #[DataProvider('provideBaselineCommands')]
    public function itAcceptsTheConfigurationThatDefinesTheSet(string $commandClass): void
    {
        $definition = self::command($commandClass)->getDefinition();

        self::assertTrue($definition->hasOption('config'));
        self::assertTrue($definition->hasArgument('paths'));
    }

    /**
     * `--generate-baseline` is gone with no alias: `baseline:generate`
     * replaces it, and a command that reports must not also be able to write
     * the file that decides what it reports.
     */
    #[Test]
    public function itNoLongerLetsCheckWriteABaseline(): void
    {
        $definition = self::command(CheckCommand::class)->getDefinition();

        self::assertFalse($definition->hasOption('generate-baseline'));
        self::assertTrue($definition->hasOption('baseline'));
        self::assertTrue($definition->hasOption('show-resolved'));
    }

    /**
     * @param class-string<Command> $commandClass
     */
    private static function command(string $commandClass): Command
    {
        $container = (new ContainerFactory())->create();

        /** @var Command $command */
        $command = $container->get($commandClass);

        return $command;
    }
}
