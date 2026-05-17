<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\Formatter\FormatterRegistry;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Report;

#[CoversClass(FormatterRegistry::class)]
final class FormatterRegistryTest extends TestCase
{
    #[Test]
    public function itHasNoFormattersWhenEmpty(): void
    {
        $registry = new FormatterRegistry();

        self::assertSame([], $registry->getAvailableNames());
    }

    #[Test]
    public function itAddsFormatterOnRegister(): void
    {
        $formatter = $this->createMockFormatter('text');
        $registry = new FormatterRegistry();
        $registry->register($formatter);

        self::assertTrue($registry->has('text'));
        self::assertSame(['text'], $registry->getAvailableNames());
    }

    #[Test]
    public function itAcceptsIterableOfFormattersInConstructor(): void
    {
        $text = $this->createMockFormatter('text');
        $json = $this->createMockFormatter('json');

        $registry = new FormatterRegistry([$text, $json]);

        self::assertTrue($registry->has('text'));
        self::assertTrue($registry->has('json'));
        self::assertSame(['json', 'text'], $registry->getAvailableNames());
    }

    #[Test]
    public function itReturnsRegisteredFormatterOnGet(): void
    {
        $formatter = $this->createMockFormatter('text');
        $registry = new FormatterRegistry([$formatter]);

        self::assertSame($formatter, $registry->get('text'));
    }

    #[Test]
    public function itThrowsExceptionForUnknownFormatterOnGet(): void
    {
        $registry = new FormatterRegistry();

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Formatter "unknown" not found. Available formatters: none');

        $registry->get('unknown');
    }

    #[Test]
    public function itIncludesAvailableFormattersInExceptionMessage(): void
    {
        $text = $this->createMockFormatter('text');
        $json = $this->createMockFormatter('json');
        $registry = new FormatterRegistry([$text, $json]);

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Formatter "xml" not found. Available formatters: text, json');

        $registry->get('xml');
    }

    #[Test]
    public function itReturnsFalseForUnregisteredFormatter(): void
    {
        $registry = new FormatterRegistry();

        self::assertFalse($registry->has('text'));
    }

    #[Test]
    public function itReturnsSortedListOfAvailableNames(): void
    {
        $xml = $this->createMockFormatter('xml');
        $json = $this->createMockFormatter('json');
        $text = $this->createMockFormatter('text');
        $checkstyle = $this->createMockFormatter('checkstyle');

        $registry = new FormatterRegistry([$xml, $json, $text, $checkstyle]);

        self::assertSame(['checkstyle', 'json', 'text', 'xml'], $registry->getAvailableNames());
    }

    #[Test]
    public function itOverwritesExistingFormatterOnRegister(): void
    {
        $first = $this->createMockFormatter('text', 'first');
        $second = $this->createMockFormatter('text', 'second');

        $registry = new FormatterRegistry([$first]);
        $registry->register($second);

        $formatter = $registry->get('text');
        $report = new Report([], 0, 0, 0.0, 0, 0);

        self::assertSame('second', $formatter->format($report, new FormatterContext()));
    }

    private function createMockFormatter(string $name, string $output = ''): FormatterInterface
    {
        return new class ($name, $output) implements FormatterInterface {
            public function __construct(
                private readonly string $name,
                private readonly string $output,
            ) {}

            public function format(Report $report, FormatterContext $context): string
            {
                return $this->output;
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getDefaultGroupBy(): GroupBy
            {
                return GroupBy::None;
            }
        };
    }
}
