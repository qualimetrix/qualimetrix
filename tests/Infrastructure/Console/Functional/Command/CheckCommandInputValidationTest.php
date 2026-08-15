<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\ResultPresenter;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use ReflectionMethod;
use ReflectionProperty;
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
        $externalPath = tempnam(sys_get_temp_dir(), 'qmx-external-invalid-');
        self::assertNotFalse($externalPath);
        file_put_contents($externalPath, '<?php function broken( {');

        $tester = $this->tester();
        try {
            $tester->execute(
                [
                    'paths' => ['tests/Fixtures/Ast/invalid_syntax.php'],
                    '--format' => 'json',
                    '--disable-rule' => ['computed.health', 'architecture.layer-violation'],
                ],
                ['capture_stderr_separately' => true],
            );

            self::assertSame(4, $tester->getStatusCode());
            $payload = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
            self::assertFalse($payload['coverage']['complete']);
            self::assertSame(1, $payload['coverage']['failed']);
            self::assertStringNotContainsString($projectRoot . '/', $tester->getDisplay());
            $relativizedMessage = $this->relativizeFailureMessage(
                'Parse error in ' . $projectRoot . '/tests/Fixtures/Ast/invalid_syntax.php; dependency ' . $externalPath,
                $projectRoot,
            );
            self::assertStringNotContainsString($projectRoot . '/', $relativizedMessage);
            self::assertStringContainsString($externalPath, $relativizedMessage);
            self::assertStringContainsString('Parse error', $tester->getErrorOutput());
        } finally {
            unlink($externalPath);
        }
    }

    #[Test]
    public function itFailsClosedForUnknownRuleSelectorWithoutPollutingStdout(): void
    {
        $tester = $this->tester();
        $tester->execute(
            ['paths' => ['tests/Fixtures/Ast/empty_file.php'], '--format' => 'json', '--only-rule' => ['security.eval']],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('does not match any registered', $tester->getErrorOutput());
    }

    #[Test]
    public function itRejectsAChannelAsRuleOptionOwner(): void
    {
        $tester = $this->tester();
        $tester->execute(
            ['paths' => ['tests/Fixtures/Ast/empty_file.php'], '--format' => 'json', '--rule-opt' => ['complexity.cyclomatic#callable:warning=8']],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('Rule option owner', $tester->getErrorOutput());
    }

    #[Test]
    public function itClassifiesMissingGitReferenceAsInputErrorBeforePayload(): void
    {
        $tester = $this->tester();
        $tester->execute(
            ['paths' => ['tests/Fixtures/Ast/empty_file.php'], '--format' => 'json', '--report' => 'git:qmx-ref-that-does-not-exist..HEAD'],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('does not resolve to a commit', $tester->getErrorOutput());
        self::assertStringNotContainsString('Analyzed paths do not cover', $tester->getErrorOutput());
    }

    #[Test]
    public function itWritesPartialScopeWarningOnlyToStderrBeforeStructuredPayload(): void
    {
        $tester = $this->tester();
        $tester->execute(
            [
                'paths' => ['tests/Fixtures/Ast/empty_file.php'],
                '--format' => 'json',
                '--disable-rule' => ['computed.health', 'architecture.layer-violation'],
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
            foreach (['computed.health', 'health.complexity', 'computed.health#health.complexity', 'computed.a'] as $selector) {
                $tester->execute([
                    'paths' => ['tests/Fixtures/Ast/empty_file.php'],
                    '--format' => 'json',
                    '--config' => $configA,
                    '--only-rule' => [$selector],
                ], ['capture_stderr_separately' => true]);
                self::assertNotSame(3, $tester->getStatusCode(), $tester->getErrorOutput());
            }

            $tester->execute([
                'paths' => ['tests/Fixtures/Ast/empty_file.php'],
                '--format' => 'json',
                '--config' => $configB,
                '--only-rule' => ['computed.a'],
            ], ['capture_stderr_separately' => true]);
            self::assertSame(3, $tester->getStatusCode());
            self::assertStringContainsString('does not match any registered', $tester->getErrorOutput());

            $tester->execute([
                'paths' => ['tests/Fixtures/Ast/empty_file.php'],
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

    private function tester(): CommandTester
    {
        $container = (new ContainerFactory())->create();
        /** @var CheckCommand $command */
        $command = $container->get(CheckCommand::class);
        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($command);
    }

    private function relativizeFailureMessage(string $message, string $projectRoot): string
    {
        $container = (new ContainerFactory())->create();
        $command = $container->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);
        $presenter = (new ReflectionProperty(CheckCommand::class, 'resultPresenter'))->getValue($command);
        self::assertInstanceOf(ResultPresenter::class, $presenter);
        $method = new ReflectionMethod(ResultPresenter::class, 'relativizeFailureMessage');
        $result = $method->invoke($presenter, $message, AbsolutePath::fromString($projectRoot));
        self::assertIsString($result);

        return $result;
    }
}
