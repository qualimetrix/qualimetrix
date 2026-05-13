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
        yield 'info exit code is 0' => [Severity::Info, 0];
        yield 'warning exit code is 1' => [Severity::Warning, 1];
        yield 'error exit code is 2' => [Severity::Error, 2];
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
        yield 'info display name' => [Severity::Info, 'Info'];
        yield 'warning display name' => [Severity::Warning, 'Warning'];
        yield 'error display name' => [Severity::Error, 'Error'];
    }

    public function testSeverityValues(): void
    {
        self::assertSame('info', Severity::Info->value);
        self::assertSame('warning', Severity::Warning->value);
        self::assertSame('error', Severity::Error->value);
    }

    public function testInfoFromStringValue(): void
    {
        self::assertSame(Severity::Info, Severity::from('info'));
    }

    public function testAllCasesHaveDisplayName(): void
    {
        foreach (Severity::cases() as $severity) {
            $displayName = $severity->displayName();
            self::assertNotEmpty($displayName);
        }
    }

    public function testInfoIsExitCodeZeroAllOthersNonZero(): void
    {
        self::assertSame(0, Severity::Info->getExitCode());

        foreach (Severity::cases() as $severity) {
            if ($severity === Severity::Info) {
                continue;
            }

            self::assertGreaterThan(0, $severity->getExitCode(), \sprintf(
                '%s severity should have non-zero exit code',
                $severity->displayName(),
            ));
        }
    }

    public function testExitCodesAreUniqueAcrossNonInfoSeverities(): void
    {
        $exitCodes = array_map(
            static fn(Severity $severity) => $severity->getExitCode(),
            Severity::cases(),
        );

        // All exit codes must be unique (Info=0 is also unique since others are >0)
        self::assertSame(
            \count($exitCodes),
            \count(array_unique($exitCodes)),
            'Exit codes must be unique for each severity level',
        );
    }

    public function testSeverityOrderingByExitCode(): void
    {
        // Priority order: Info (0) < Warning (1) < Error (2)
        self::assertLessThan(Severity::Warning->getExitCode(), Severity::Info->getExitCode());
        self::assertLessThan(Severity::Error->getExitCode(), Severity::Warning->getExitCode());
    }
}
