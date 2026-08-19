<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Infrastructure\Console\ExitPolicy;

#[CoversClass(ExitPolicy::class)]
final class ExitPolicyTest extends TestCase
{
    /**
     * A `fail_on: info` inherited from an older config must be named as no
     * longer supported rather than silently read as "fail on everything".
     */
    #[Test]
    #[DataProvider('provideInfoSpellings')]
    public function itRejectsInfoAsAFailOnThreshold(mixed $configured): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report-only');

        ExitPolicy::fromContributions([$configured]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function provideInfoSpellings(): iterable
    {
        yield 'string' => ['info'];
        yield 'enum case' => [Severity::Info];
    }

    #[Test]
    public function itRefusesToBeConstructedWithInfoAtAll(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExitPolicy(Severity::Info);
    }

    #[Test]
    #[DataProvider('provideAcceptedValues')]
    public function itAcceptsTheRemainingThresholds(mixed $configured, Severity|false|null $expected): void
    {
        self::assertSame($expected, ExitPolicy::fromContributions([$configured])->failOn);
    }

    /** @return iterable<string, array{mixed, Severity|false|null}> */
    public static function provideAcceptedValues(): iterable
    {
        yield 'none' => ['none', false];
        yield 'false' => [false, false];
        yield 'warning' => ['warning', Severity::Warning];
        yield 'error' => ['error', Severity::Error];
        yield 'unset' => [null, null];
    }

    #[Test]
    public function itNamesTheAllowedValuesWhenRejectingAnUnknownWord(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Allowed values: none, warning, error');

        ExitPolicy::fromContributions(['warnin']);
    }
}
