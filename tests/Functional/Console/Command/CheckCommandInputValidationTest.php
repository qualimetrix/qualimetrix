<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Functional\Console\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckCommandInputValidationTest extends TestCase
{
    #[Test]
    public function itFormatsIncompleteAnalysisAndGivesItExitCodePriority(): void
    {
        $tester = $this->tester();
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
        self::assertStringNotContainsString('/Users/', $tester->getDisplay());
        self::assertStringContainsString('Parse error', $tester->getErrorOutput());
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
            ['paths' => ['tests/Fixtures/Ast/empty_file.php'], '--format' => 'json', '--rule-opt' => ['complexity.cyclomatic#method:warning=8']],
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
}
