<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Environment\EnvironmentFailureInterface;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\FileTargetFailureKind;
use Qualimetrix\Infrastructure\Console\Command\BaselineCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

#[CoversClass(BaselineCommand::class)]
final class BaselineEnvironmentFailureTest extends TestCase
{
    #[Test]
    public function itClassifiesRawFileFailureThroughBaselineRuntimeCatch(): void
    {
        $tester = self::execute(new FileTargetFailure(
            FileTargetFailureKind::Unopenable,
            '/tmp/baseline.json',
            'cannot publish replacement',
        ));

        self::assertEnvironmentEnvelope($tester, '/tmp/baseline.json');
    }

    #[Test]
    public function itClassifiesNonRuntimeEnvironmentFailureThroughBaselineThrowableCatch(): void
    {
        $tester = self::execute(new class ('filesystem unavailable') extends Exception implements EnvironmentFailureInterface {});

        self::assertEnvironmentEnvelope($tester, 'filesystem unavailable');
    }

    private static function execute(Throwable $failure): CommandTester
    {
        $command = new class ($failure) extends BaselineCommand {
            public function __construct(private readonly Throwable $failure)
            {
                parent::__construct('baseline:environment-failure');
            }

            protected function refusalFormat(InputInterface $input): string
            {
                return 'json';
            }

            protected function doExecute(InputInterface $input, OutputInterface $output): int
            {
                throw $this->failure;
            }
        };
        $errorStream = new ErrorStream();
        $command->setRefusalPresenter(new RefusalPresenter($errorStream));

        $tester = new CommandTester($command);
        $tester->execute([], ['capture_stderr_separately' => true]);

        return $tester;
    }

    private static function assertEnvironmentEnvelope(CommandTester $tester, string $message): void
    {
        self::assertSame(3, $tester->getStatusCode());
        self::assertSame('', $tester->getErrorOutput());
        self::assertSame(
            [
                'error' => null,
                'exit_code' => 3,
                'position' => null,
                'source' => null,
            ],
            [
                ...json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR),
                'error' => null,
            ],
        );
        self::assertStringContainsString('Environment error:', $tester->getDisplay());
        self::assertStringContainsString($message, $tester->getDisplay());
    }
}
