<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\Command\RulesCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckCommandInputValidationTest extends TestCase
{
    #[Test]
    public function itFormatsIncompleteAnalysisAndGivesItExitCodePriority(): void
    {
        $workingDirectory = getcwd();
        self::assertNotFalse($workingDirectory);
        $projectRoot = realpath($workingDirectory);
        self::assertNotFalse($projectRoot);

        $tester = $this->tester();
        $tester->execute(
            [
                'paths' => ['tests/Infrastructure/Console/Fixtures/parser_refuses_it.php'],
                '--format' => 'json',
                '--disable-rule' => ['computed', 'health.*', 'architecture.layer-violation'],
            ],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(4, $tester->getStatusCode());
        $payload = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertFalse($payload['coverage']['complete']);
        self::assertSame(1, $payload['coverage']['failed']);
        self::assertStringNotContainsString($projectRoot . '/', $tester->getDisplay());
        self::assertStringContainsString('Parse error', $tester->getErrorOutput());
    }

    #[Test]
    public function itRejectsWrongTypedScalarConfigValueAsConfigError(): void
    {
        $config = tempnam(sys_get_temp_dir(), 'qmx-type-check-');
        self::assertNotFalse($config);
        file_put_contents($config, "cache:\n  enabled: \"false\"\n");

        $tester = $this->tester();
        try {
            $tester->execute(
                [
                    'paths' => ['tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php'],
                    '--format' => 'json',
                    '--config' => $config,
                    '--disable-rule' => ['computed', 'health.*', 'architecture.layer-violation'],
                ],
                ['capture_stderr_separately' => true],
            );

            self::assertSame(3, $tester->getStatusCode());
            self::assertStringContainsString(
                'Invalid value for "cache.enabled": expected boolean, got string',
                self::envelopeError($tester),
            );
        } finally {
            unlink($config);
        }
    }

    #[Test]
    public function itRejectsABareNamespaceDrillDownSelector(): void
    {
        $tester = $this->tester();
        $tester->execute(
            [
                'paths' => ['tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php'],
                '--namespace' => 'App\\Service',
            ],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('must use KIND:VALUE', $tester->getErrorOutput());
    }

    #[Test]
    public function itFailsClosedForUnknownRuleSelectorWithoutPollutingStdout(): void
    {
        $tester = $this->tester();
        $tester->execute(
            ['paths' => ['tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php'], '--format' => 'json', '--only-rule' => ['security.eval']],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('does not match any registered', self::envelopeError($tester));
    }

    /**
     * A selection selector left in the retired `rule#code` spelling is refused
     * **by name**, with the name to write instead. Falling through to "matches
     * nothing" would be true and useless: the text names a channel that used to
     * exist under that exact spelling.
     */
    #[Test]
    public function itRejectsASelectionSelectorInTheRetiredChannelPairForm(): void
    {
        $tester = $this->tester();
        $tester->execute(
            [
                'paths' => ['tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php'],
                '--format' => 'json',
                '--only-rule' => ['complexity.ccn#complexity.ccn'],
            ],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('Write "complexity.ccn"', self::envelopeError($tester));
    }

    /**
     * The configuration and CLI seam of the one refusal point for an
     * impossible `channel:level` pair, {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelAddressing}.
     * The other seam is the inline directive one, checked by
     * {@see \Qualimetrix\Tests\Analysis\Policy\Inline\Integration\UnusedDirectiveRuleTest}
     * — both ask the same object, so the two families of directive cannot
     * answer one mistake two ways.
     */
    #[Test]
    public function itRejectsASelectionSelectorNamingALevelItsChannelDoesNotReportAt(): void
    {
        $tester = $this->tester();
        $tester->execute(
            [
                'paths' => ['tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php'],
                '--format' => 'json',
                '--disable-rule' => ['coupling.cbo:file'],
            ],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('it does not report at level "file"', self::envelopeError($tester));
    }

    /** A level a channel does declare is accepted, so the refusal above is not refusing every pair. */
    #[Test]
    public function itAcceptsASelectionSelectorNamingADeclaredLevel(): void
    {
        $tester = $this->tester();
        $tester->execute(
            [
                'paths' => ['tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php'],
                '--format' => 'json',
                '--disable-rule' => ['coupling.cbo:namespace'],
            ],
            ['capture_stderr_separately' => true],
        );

        self::assertNotSame(3, $tester->getStatusCode(), $tester->getErrorOutput());
    }

    /** A level word outside the vocabulary is refused by the same one point. */
    #[Test]
    public function itRejectsASelectionSelectorWhoseLevelIsNotOne(): void
    {
        $tester = $this->tester();
        $tester->execute(
            [
                'paths' => ['tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php'],
                '--format' => 'json',
                '--disable-rule' => ['coupling.cbo:klass'],
            ],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('names no level after ":"', self::envelopeError($tester));
    }

    #[Test]
    public function itRejectsAChannelAsRuleOptionOwner(): void
    {
        $tester = $this->tester();
        $tester->execute(
            ['paths' => ['tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php'], '--format' => 'json', '--rule-opt' => ['complexity.ccn#callable:warning=8']],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('Rule option owner', self::envelopeError($tester));
    }

    #[Test]
    public function itClassifiesMissingGitReferenceAsInputErrorBeforePayload(): void
    {
        $tester = $this->tester();
        $tester->execute(
            ['paths' => ['tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php'], '--format' => 'json', '--report' => 'git:qmx-ref-that-does-not-exist..HEAD'],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('does not resolve to a commit', self::envelopeError($tester));
        self::assertStringNotContainsString('Analyzed paths do not cover', $tester->getErrorOutput());
    }

    #[Test]
    public function itWritesPartialScopeWarningOnlyToStderrBeforeStructuredPayload(): void
    {
        $tester = $this->tester();
        $tester->execute(
            [
                'paths' => ['tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php'],
                '--format' => 'json',
                '--disable-rule' => ['computed', 'health.*', 'architecture.layer-violation'],
            ],
            ['capture_stderr_separately' => true],
        );

        json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Warning:', $tester->getDisplay());
        self::assertStringContainsString('Analyzed paths do not cover', $tester->getErrorOutput());
    }

    #[Test]
    public function itValidatesDynamicComputedSelectorsAgainstTheCurrentCommandConfiguration(): void
    {
        $configA = tempnam(sys_get_temp_dir(), 'qmx-computed-selector-a-');
        $configB = tempnam(sys_get_temp_dir(), 'qmx-computed-selector-b-');
        self::assertNotFalse($configA);
        self::assertNotFalse($configB);
        file_put_contents($configA, "computed_metrics:\n  computed.a:\n    formula: '1'\n    levels: [class]\n");
        file_put_contents($configB, "computed_metrics:\n  computed.b:\n    formula: '1'\n    levels: [class]\n");

        $tester = $this->tester();
        try {
            foreach (['computed', 'health.complexity', 'health.*', 'computed.a'] as $selector) {
                $tester->execute([
                    'paths' => ['tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php'],
                    '--format' => 'json',
                    '--config' => $configA,
                    '--only-rule' => [$selector],
                ], ['capture_stderr_separately' => true]);
                self::assertNotSame(3, $tester->getStatusCode(), $tester->getErrorOutput());
            }

            $tester->execute([
                'paths' => ['tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php'],
                '--format' => 'json',
                '--config' => $configB,
                '--only-rule' => ['computed.a'],
            ], ['capture_stderr_separately' => true]);
            self::assertSame(3, $tester->getStatusCode());
            self::assertStringContainsString('does not match any registered', self::envelopeError($tester));

            $tester->execute([
                'paths' => ['tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php'],
                '--format' => 'json',
                '--config' => $configB,
                '--only-rule' => ['computed.b'],
            ], ['capture_stderr_separately' => true]);
            self::assertNotSame(3, $tester->getStatusCode(), $tester->getErrorOutput());
        } finally {
            unlink($configA);
            unlink($configB);
        }
    }

    /**
     * A retired suppression flag is refused the way its config-file twin is —
     * by name, with the fork spelled out, at exit 3. Symfony's own
     * "option does not exist" is a distinct route from this one — it never
     * reaches `execute()`, and cannot be reproduced through `CommandTester`
     * at all (it throws `InvalidOptionException` straight out of
     * `Command::run()`, uncaught) — but it lands at the same exit 3, through
     * `Application::doRun()`'s `catch (ConsoleExceptionInterface)` clause
     * rather than this command's own `ConfigurationRefusal` handling
     * ({@see self::itRejectsAnUnknownOptionThroughTheApplicationLadder()}).
     * A CI wrapper that tells 3 from 1 would not see a difference either way.
     */
    #[Test]
    #[DataProvider('provideRetiredSuppressionFlags')]
    public function itRejectsARetiredSuppressionFlagByName(string $retired, string $replacement): void
    {
        $tester = $this->tester();
        $tester->execute(
            [
                'paths' => ['tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php'],
                '--format' => 'json',
                $retired => 'src/Entity',
            ],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $tester->getStatusCode());
        $error = self::envelopeError($tester);
        self::assertStringContainsString(\sprintf('The "%s" option was retired', $retired), $error);
        self::assertStringContainsString(\sprintf('use "%s"', $replacement), $error);
        self::assertStringContainsString('"--exclude" option instead', $error);
    }

    /**
     * A path that happens to be spelled like a retired flag is a value, not a
     * flag. Recognizing retired flags by walking the token list could not tell
     * the two apart and refused this run; the parser can, and does.
     */
    #[Test]
    public function itAcceptsAPathValueSpelledLikeARetiredFlag(): void
    {
        $tester = $this->tester();
        $tester->execute(
            [
                'paths' => ['tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php'],
                '--format' => 'json',
                '--suppress-path' => ['exact:--exclude-path'],
            ],
            ['capture_stderr_separately' => true],
        );

        self::assertNotSame(3, $tester->getStatusCode(), $tester->getErrorOutput());
        self::assertStringNotContainsString('was retired', $tester->getErrorOutput());
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideRetiredSuppressionFlags(): iterable
    {
        yield 'path' => ['--exclude-path', '--suppress-path'];
        yield 'namespace' => ['--exclude-namespace', '--suppress-namespace'];
    }

    /**
     * An option absent from `check`'s own definition
     * never declared. `CommandTester` cannot show this — it runs the command
     * directly and the `InvalidOptionException` comes out of `Command::run()`
     * uncaught, never touching a `catch` clause — so this goes through the
     * real `Application::doRun()` ladder in-process instead, the same
     * mechanism {@see \Qualimetrix\Tests\Infrastructure\Console\Unit\ApplicationTest}
     * proves synthetically. `setCatchExceptions(false)` keeps Symfony's own
     * `run()` out of the way so a wrong answer here fails the assertion
     * instead of being swallowed by the base class's fallback handling.
     */
    #[Test]
    public function itRejectsAnUnknownOptionThroughTheApplicationLadder(): void
    {
        [$exitCode, $display] = $this->runThroughApplication(
            new StringInput('check tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php --this-option-does-not-exist'),
        );

        self::assertSame(3, $exitCode);
        self::assertStringContainsString('option does not exist', $display);
    }

    /**
     * Route 6's second live input, on a different command: `rules` declares
     * no positional argument at all, so a stray one is Symfony's own
     * "no arguments expected" — the same uncaught-outside-`Application`
     * shape as the unknown option above.
     */
    #[Test]
    public function itRejectsAnUnexpectedArgumentForRulesThroughTheApplicationLadder(): void
    {
        [$exitCode, $display] = $this->runThroughApplication(new StringInput('rules extra-arg'));

        self::assertSame(3, $exitCode);
        self::assertStringContainsString('No arguments expected', $display);
    }

    /**
     * Builds the real `Application` ladder around the real, container-wired
     * commands and drives it in-process — no subprocess needed, since
     * `Application::doRun()` is public and does not call `exit()`.
     *
     * @return array{int, string}
     */
    private function runThroughApplication(StringInput $input): array
    {
        $container = (new ContainerFactory())->create();
        /** @var RefusalPresenter $refusalPresenter */
        $refusalPresenter = $container->get(RefusalPresenter::class);
        $app = new Application(new ErrorStream(), $refusalPresenter);
        $app->setAutoExit(false);
        $app->setCatchExceptions(false);

        $checkCommand = $container->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $checkCommand);
        $app->addCommand($checkCommand);

        $rulesCommand = $container->get(RulesCommand::class);
        self::assertInstanceOf(RulesCommand::class, $rulesCommand);
        $app->addCommand($rulesCommand);

        $output = new BufferedOutput();
        $exitCode = $app->doRun($input, $output);

        return [$exitCode, $output->fetch()];
    }

    /**
     * The `--rule-opt` door of the retired per-rule option: refused while the
     * name is still spelled the way it was typed, so kebab is answered in
     * kebab. The config-file door is the loader's
     * ({@see \Qualimetrix\Tests\Analysis\Configuration\Unit\Loader\YamlConfigLoaderTest}).
     */
    #[Test]
    public function itRejectsARetiredRuleOptionInTheSpellingTheCommandLineUsed(): void
    {
        $tester = $this->tester();
        $tester->execute(
            [
                'paths' => ['tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php'],
                '--format' => 'json',
                '--rule-opt' => ['code-smell.long-parameter-list:exclude-paths=src/Entity'],
            ],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $tester->getStatusCode());
        $error = self::envelopeError($tester);
        self::assertStringContainsString('The "exclude-paths" option was retired', $error);
        self::assertStringContainsString('use "suppress-paths"', $error);
    }

    /**
     * Parses the `{error, exit_code}` envelope every `--format=json` refusal
     * carries on stdout and returns its message.
     */
    private static function envelopeError(CommandTester $tester): string
    {
        /** @var array{error: string, exit_code: int} $envelope */
        $envelope = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        return $envelope['error'];
    }

    private function tester(): CommandTester
    {
        $container = (new ContainerFactory())->create();
        /** @var CheckCommand $command */
        $command = $container->get(CheckCommand::class);
        /** @var RefusalPresenter $refusalPresenter */
        $refusalPresenter = $container->get(RefusalPresenter::class);
        $application = new Application(new ErrorStream(), $refusalPresenter);
        $application->addCommand($command);

        return new CommandTester($command);
    }
}
