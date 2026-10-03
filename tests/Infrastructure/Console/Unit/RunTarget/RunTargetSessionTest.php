<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit\RunTarget;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargets;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargetSession;
use Qualimetrix\Infrastructure\Logging\Contract\LoggerFactoryInterface;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(RunTargetSession::class)]
final class RunTargetSessionTest extends TestCase
{
    #[Test]
    public function itClassifiesAnUnexpectedCleanupArgumentFailureAsInternal(): void
    {
        $factory = self::createMock(LoggerFactoryInterface::class);
        $factory->expects(self::once())
            ->method('reset')
            ->willThrowException(new InvalidArgumentException('logger reset failed'));
        $session = new RunTargetSession(new RunTargets($factory), new RefusalPresenter(new ErrorStream()));
        $output = new BufferedOutput();

        $exit = $session->run($output, 'json', static fn(): int => 0);

        self::assertSame(1, $exit);
        /** @var array{error: string, exit_code: int} $envelope */
        $envelope = json_decode($output->fetch(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(1, $envelope['exit_code']);
        self::assertStringContainsString('Internal error:', $envelope['error']);
        self::assertStringContainsString('logger reset failed', $envelope['error']);
    }
}
