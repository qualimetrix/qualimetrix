<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Violation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Violation\Severity;

#[CoversClass(Severity::class)]
final class SeverityTest extends TestCase
{
    #[DataProvider('exitCodeDataProvider')]
    public function testGetExitCode(Severity $severity, int $expectedExitCode): void
    {
        self::assertSame($expectedExitCode, $severity->getExitCode());
    }

    /**
     * @return iterable<string, array{Severity, int}>
     */
    public static function exitCodeDataProvider(): iterable
    {
        yield 'warning exit code' => [Severity::Warning, 1];
        yield 'error exit code' => [Severity::Error, 2];
    }

    #[DataProvider('displayNameDataProvider')]
    public function testDisplayName(Severity $severity, string $expectedDisplayName): void
    {
        self::assertSame($expectedDisplayName, $severity->displayName());
    }

    /**
     * @return iterable<string, array{Severity, string}>
     */
    public static function displayNameDataProvider(): iterable
    {
        yield 'warning display name' => [Severity::Warning, 'Warning'];
        yield 'error display name' => [Severity::Error, 'Error'];
    }

    public function testSeverityValues(): void
    {
        self::assertSame('warning', Severity::Warning->value);
        self::assertSame('error', Severity::Error->value);
    }

    public function testAllCasesHaveExitCode(): void
    {
        foreach (Severity::cases() as $severity) {
            $exitCode = $severity->getExitCode();
            self::assertGreaterThan(0, $exitCode);
        }
    }

    public function testAllCasesHaveDisplayName(): void
    {
        foreach (Severity::cases() as $severity) {
            $displayName = $severity->displayName();
            self::assertNotEmpty($displayName);
        }
    }

    public function testExitCodesAreUnique(): void
    {
        $exitCodes = array_map(
            static fn(Severity $severity) => $severity->getExitCode(),
            Severity::cases(),
        );

        self::assertSame(
            \count($exitCodes),
            \count(array_unique($exitCodes)),
            'Exit codes must be unique for each severity level',
        );
    }

    public function testErrorHasHigherExitCodeThanWarning(): void
    {
        self::assertGreaterThan(
            Severity::Warning->getExitCode(),
            Severity::Error->getExitCode(),
            'Error severity should have higher exit code than Warning',
        );
    }
}
