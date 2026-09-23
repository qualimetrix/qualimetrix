<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Infrastructure\Console\RuntimeLimits;

#[CoversClass(RuntimeLimits::class)]
final class RuntimeLimitsTest extends TestCase
{
    /**
     * A zero limit has the shape of a size and is not one: PHP refuses it at
     * `ini_set()`, and until the shape check knew that, the refusal arrived
     * from there without the value it was about.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideNonPositiveSizes(): iterable
    {
        yield 'zero' => ['0'];
        yield 'zeros' => ['00'];
        yield 'zero with a suffix' => ['0M'];
        yield 'negative other than -1' => ['-2'];
        yield 'leading zero, which PHP reads as octal' => ['010M'];
    }

    #[Test]
    #[DataProvider('provideNonPositiveSizes')]
    public function itRefusesANonPositiveSizeQuotingTheValue(string $value): void
    {
        try {
            new RuntimeLimits($value);
            self::fail(\sprintf('memory_limit "%s" must be refused by its shape.', $value));
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString(\sprintf('"%s"', $value), $refusal->summary());
            self::assertStringContainsString('positive', $refusal->summary());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function provideAcceptedSizes(): iterable
    {
        yield 'unlimited' => ['-1'];
        yield 'bytes' => ['134217728'];
        yield 'megabytes' => ['512M'];
        yield 'lowercase suffix' => ['1g'];
    }

    #[Test]
    #[DataProvider('provideAcceptedSizes')]
    public function itAcceptsAPositiveSizeOrUnlimited(string $value): void
    {
        self::assertSame($value, (new RuntimeLimits($value))->memoryLimit);
    }
}
