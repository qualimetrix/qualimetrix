<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Reporting\Configuration\OutputFormatResolver;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;

/**
 * The pair each cured class owes: the form that used to be swallowed is now
 * refused, and the form that always worked still does. A refusal that also ate
 * the lawful spelling would pass the first half alone.
 */
final class OutputFormatRefusesUnexecutableValuesTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function provideUnexecutableValues(): iterable
    {
        yield 'boolean' => [true];
        yield 'integer' => [7331];
        yield 'float' => [7331.9];
        yield 'list' => [['json']];
        yield 'map' => [['a' => 'json']];
        yield 'empty string' => [''];
        yield 'unknown name' => ['zzz'];
    }

    #[Test]
    #[DataProvider('provideUnexecutableValues')]
    public function itRefusesAFormatTheRunCannotExecute(mixed $value): void
    {
        $this->expectException(ConfigurationRefusal::class);

        self::resolve($value);
    }

    /** @return iterable<string, array{string}> */
    public static function provideExecutableNames(): iterable
    {
        yield 'json' => ['json'];
        yield 'text' => ['text'];
        yield 'a hidden formatter the listing omits' => ['text-verbose'];
    }

    #[Test]
    #[DataProvider('provideExecutableNames')]
    public function itStillAcceptsEveryRegisteredFormatter(string $name): void
    {
        self::assertSame($name, self::resolve($name)->value);
    }

    #[Test]
    public function itStillDefaultsWhenNobodyNamedAFormat(): void
    {
        $document = new ConfigurationDocument([], AbsolutePath::fromString('/project'));

        self::assertSame('summary', (new OutputFormatResolver(self::registry()))->resolve($document)->value);
    }

    #[Test]
    public function itNamesTheOfferedFormattersWhenItRefusesAnUnknownOne(): void
    {
        try {
            self::resolve('zzz');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('json', $refusal->getMessage());
            self::assertStringNotContainsString('text-verbose', $refusal->getMessage());

            return;
        }

        self::fail('an unknown format name was not refused');
    }

    private static function resolve(mixed $value): \Qualimetrix\Reporting\Contract\OutputFormat
    {
        $document = new ConfigurationDocument(
            [['source' => 'cli', 'values' => ['format' => $value]]],
            AbsolutePath::fromString('/project'),
        );

        return (new OutputFormatResolver(self::registry()))->resolve($document);
    }

    private static function registry(): FormatterRegistryInterface
    {
        return new class implements FormatterRegistryInterface {
            public function get(string $name): FormatterInterface
            {
                throw new LogicException('not used');
            }

            public function has(string $name): bool
            {
                return \in_array($name, [...$this->getAvailableNames(), 'text-verbose'], true);
            }

            public function getAvailableNames(): array
            {
                return ['json', 'summary', 'text'];
            }

            public function declaredFormatOptionKeys(): array
            {
                return [];
            }
        };
    }
}
