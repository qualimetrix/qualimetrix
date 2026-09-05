<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineExplainCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineUpdateCommand;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Command\Command;

/**
 * ADR 0017 rule, checked rather than trusted — and it has two halves.
 *
 * The measured set is defined by configuration and by the source's own
 * annotations. A flag that could move it lets a baseline command and the
 * `check` it must agree with measure two different sets, and the user who
 * passed the flag to one and not the other is never told. Which way the set
 * moves decides what the surface must do about the flag:
 *
 * - **Narrowing flags are refused.** `--suppress-path`, `--suppress-namespace`
 *   and `--no-suppression-annotations` only ever remove findings from a
 *   report. A capture taken under one of them records less than `check`
 *   measures, and the entries it did not write are debt nothing bounds.
 * - **Configuration flags are required.** `--preset`, `--rule-opt`,
 *   `--only-rule` and `--disable-rule` decide which rules run and against
 *   which thresholds — they *are* the configuration ADR 0017 defines the set by.
 *   Withholding them does not keep the two sides equal, it guarantees they
 *   differ the dangerous way: `check --preset=strict --baseline=b.json`
 *   measures more than `baseline:generate b.json` could capture, and every
 *   finding the capture never saw reads as a breach and promotes its group to
 *   Error on untouched code (ADR 0017).
 *
 * Both halves are asserted, because either alone is satisfied by a command
 * with no options at all.
 *
 * Asked of each command's own definition, because "we did not add it" is a
 * fact about today and this is a property of the surface.
 */
#[CoversClass(BaselineGenerateCommand::class)]
#[CoversClass(BaselineUpdateCommand::class)]
#[CoversClass(BaselineCleanupCommand::class)]
#[CoversClass(BaselineExplainCommand::class)]
final class BaselineCommandOptionSurfaceTest extends TestCase
{
    private const array FORBIDDEN_OPTIONS = ['suppress-path', 'suppress-namespace', 'no-suppression-annotations'];

    /**
     * The options that decide which rules run and against which thresholds.
     * Spelled exactly as `check` spells them — the configuration stages read
     * them off the input by name, so a different spelling here would leave
     * the option accepted and inert.
     */
    private const array REQUIRED_CONFIGURATION_OPTIONS = ['preset', 'rule-opt', 'only-rule', 'disable-rule'];

    /**
     * @return iterable<string, array{class-string<Command>}>
     */
    public static function provideBaselineCommands(): iterable
    {
        yield 'generate' => [BaselineGenerateCommand::class];
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
                \sprintf('%s must not accept --%s: it would move the measured set (ADR 0017).', $commandClass, $option),
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
     * The other half: every option that decides *what runs* is accepted, and
     * accepted under the same name `check` gives it.
     *
     * The `check` side is asserted in the same loop rather than assumed. The
     * property under test is agreement between two surfaces, and a test that
     * hard-coded the names would keep passing if `check` renamed one of them
     * — which is exactly the day the two commands would start measuring
     * different sets.
     *
     * @param class-string<Command> $commandClass
     */
    #[Test]
    #[DataProvider('provideBaselineCommands')]
    public function itAcceptsEveryOptionThatDecidesWhatIsMeasured(string $commandClass): void
    {
        $definition = self::command($commandClass)->getDefinition();
        $check = self::command(CheckCommand::class)->getDefinition();

        foreach (self::REQUIRED_CONFIGURATION_OPTIONS as $option) {
            self::assertTrue(
                $check->hasOption($option),
                \sprintf('check no longer declares --%s; the two surfaces must be compared on names both use.', $option),
            );

            self::assertTrue(
                $definition->hasOption($option),
                \sprintf(
                    '%s must accept --%s: without it `check --%s` measures more than this command '
                    . 'can capture, and the excess reads as a breach (ADR 0017).',
                    $commandClass,
                    $option,
                    $option,
                ),
            );
        }
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
     * Repository entrypoints are executable consumers of the CLI surface, not
     * historical documentation. Keep them in lockstep with the command
     * definitions so an action, compose profile, or installed hook does not
     * fail only after users upgrade.
     */
    #[Test]
    public function itKeepsRepositoryEntrypointsOnTheBaselineLifecycleSurface(): void
    {
        $entrypoints = [
            'action.yml' => ['ARGS="$ARGS --baseline=${{ inputs.baseline }}"'],
            'docker-compose.yml' => [
                'docker-compose run --rm qmx check lib/',
                'command: check src/',
                'command: baseline:generate baseline.json src/ --force',
                'command: check src/ --format=sarif',
                'command: check src/ --baseline=baseline.json',
                'command: check src/ --config=qmx.yaml',
            ],
            'scripts/pre-commit-hook.sh' => [
                'BASELINE_ADVICE="Replace accepted levels intentionally: $QMX_BIN baseline:generate baseline.json src/ --force"',
                'BASELINE_ADVICE="Create a baseline: $QMX_BIN baseline:generate baseline.json src/"',
            ],
        ];

        foreach ($entrypoints as $path => $expectedSnippets) {
            $contents = file_get_contents(\dirname(__DIR__, 5) . '/' . $path);

            self::assertIsString($contents, \sprintf('Could not read repository entrypoint %s.', $path));
            self::assertStringNotContainsString('--generate-baseline', $contents, $path);
            self::assertStringNotContainsString('--baseline-ignore-stale', $contents, $path);

            if ($path === 'docker-compose.yml') {
                self::assertStringNotContainsString('analyze ', $contents, $path);
                self::assertStringContainsString('check ', $contents, $path);
            }

            foreach ($expectedSnippets as $expectedSnippet) {
                self::assertStringContainsString($expectedSnippet, $contents, $path);
            }
        }
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
