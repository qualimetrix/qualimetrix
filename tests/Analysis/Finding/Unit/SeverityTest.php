<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Severity;

#[CoversClass(Severity::class)]
final class SeverityTest extends TestCase
{
    #[DataProvider('exitCodeDataProvider')]
    #[Test]
    public function itGetExitCode(Severity $severity, int $expectedExitCode): void
    {
        self::assertSame($expectedExitCode, $severity->getExitCode());
    }

    /**
     * @return iterable<string, array{Severity, int}>
     */
    public static function exitCodeDataProvider(): iterable
    {
        yield 'info exit code is 0' => [Severity::Info, 0];
        yield 'warning exit code is 1' => [Severity::Warning, 1];
        yield 'error exit code is 2' => [Severity::Error, 2];
    }

    #[DataProvider('displayNameDataProvider')]
    #[Test]
    public function itDisplayName(Severity $severity, string $expectedDisplayName): void
    {
        self::assertSame($expectedDisplayName, $severity->displayName());
    }

    /**
     * @return iterable<string, array{Severity, string}>
     */
    public static function displayNameDataProvider(): iterable
    {
        yield 'info display name' => [Severity::Info, 'Info'];
        yield 'warning display name' => [Severity::Warning, 'Warning'];
        yield 'error display name' => [Severity::Error, 'Error'];
    }

    #[Test]
    public function itSeverityValues(): void
    {
        self::assertSame('info', Severity::Info->value);
        self::assertSame('warning', Severity::Warning->value);
        self::assertSame('error', Severity::Error->value);
    }

    #[Test]
    public function itInfoFromStringValue(): void
    {
        self::assertSame(Severity::Info, Severity::from('info'));
    }

    #[Test]
    public function itSeverityOrderingByExitCode(): void
    {
        // Priority order: Info (0) < Warning (1) < Error (2)
        self::assertLessThan(Severity::Warning->getExitCode(), Severity::Info->getExitCode());
        self::assertLessThan(Severity::Error->getExitCode(), Severity::Warning->getExitCode());
    }
}
