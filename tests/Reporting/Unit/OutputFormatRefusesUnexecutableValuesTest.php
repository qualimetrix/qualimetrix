<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Reporting\Configuration\OutputFormatResolver;
use Qualimetrix\Reporting\Configuration\OutputFormatSection;
use Qualimetrix\Reporting\Configuration\OutputFormatVocabulary;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

/**
 * The pair each cured class owes: the form that used to be swallowed is now
 * refused, and the form that always worked still does. A refusal that also ate
 * the lawful spelling would pass the first half alone.
 */
final class OutputFormatRefusesUnexecutableValuesTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function provideMalformedValues(): iterable
    {
        yield 'boolean' => [true];
        yield 'integer' => [7331];
        yield 'float' => [7331.9];
        yield 'list' => [['json']];
        yield 'map' => [['a' => 'json']];
    }

    #[Test]
    #[DataProvider('provideMalformedValues')]
    public function itRefusesAMalformedLowerFormatEvenWhenTheCommandLineWins(mixed $value): void
    {
        try {
            self::resolve($value, withCommandLineOverride: true);
            self::fail('A malformed lower format was accepted.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame(
                [ConfigurationSource::ConfigFile],
                array_map(static fn(ConfigurationOrigin $origin): ConfigurationSource => $origin->source(), $refusal->sources()),
            );
            self::assertSame(['format'], $refusal->position()?->segments);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function provideUnexecutableWinningNames(): iterable
    {
        yield 'empty string' => [''];
        yield 'unknown name' => ['zzz'];
        yield 'retired name' => ['text-verbose'];
    }

    #[Test]
    #[DataProvider('provideUnexecutableWinningNames')]
    public function itRefusesAnUnexecutableWinningFormat(string $value): void
    {
        try {
            self::resolve($value);
            self::fail('An unexecutable winning format was accepted.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame(
                [ConfigurationSource::ConfigFile],
                array_map(static fn(ConfigurationOrigin $origin): ConfigurationSource => $origin->source(), $refusal->sources()),
            );
            self::assertSame(['format'], $refusal->position()?->segments);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function provideExecutableNames(): iterable
    {
        yield 'json' => ['json'];
        yield 'text' => ['text'];
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
        [$resolver, $section] = self::resolverAndSection();
        $document = LayeredDocument::of([], AbsolutePath::fromString('/project'), $section);

        self::assertSame('summary', $resolver->resolve($document)->value);
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

    private static function resolve(mixed $value, bool $withCommandLineOverride = false): \Qualimetrix\Reporting\Contract\OutputFormat
    {
        $sources = [['source' => 'qmx.yaml', 'values' => ['format' => $value]]];
        if ($withCommandLineOverride) {
            $sources[] = ['source' => 'cli', 'values' => ['format' => 'json']];
        }

        [$resolver, $section] = self::resolverAndSection();
        $document = LayeredDocument::of(
            $sources,
            AbsolutePath::fromString('/project'),
            $section,
        );

        return $resolver->resolve($document);
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
                return \in_array($name, $this->getAvailableNames(), true);
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

    /** @return array{OutputFormatResolver, OutputFormatSection} */
    private static function resolverAndSection(): array
    {
        $vocabulary = new OutputFormatVocabulary(self::registry());

        return [new OutputFormatResolver($vocabulary), new OutputFormatSection($vocabulary)];
    }
}
