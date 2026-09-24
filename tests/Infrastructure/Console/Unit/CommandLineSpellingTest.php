<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Infrastructure\Console\CommandLineSpelling;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

#[CoversClass(CommandLineSpelling::class)]
final class CommandLineSpellingTest extends TestCase
{
    #[Test]
    public function itReadsStringsAsWrittenAndIntegersAsTheirDigits(): void
    {
        $input = $this->input(['--one' => 7, '--many' => ['a', 3], 'arg' => 12]);

        self::assertSame('7', CommandLineSpelling::option($input, 'one'));
        self::assertSame(['a', '3'], CommandLineSpelling::options($input, 'many'));
        self::assertSame('12', CommandLineSpelling::argument($input, 'arg'));
        self::assertSame('12', CommandLineSpelling::requiredArgument($input, 'arg'));
    }

    /** A lone value written for a repeatable option is one value, not a list to iterate. */
    #[Test]
    public function itReadsALoneValueOfARepeatableOptionAsOneValue(): void
    {
        self::assertSame(['only'], CommandLineSpelling::options($this->input(['--many' => 'only']), 'many'));
    }

    #[Test]
    public function itAnswersNothingForAnOptionTheCommandDoesNotDefineOrThatWasNotWritten(): void
    {
        $input = $this->input([]);

        self::assertNull(CommandLineSpelling::option($input, 'one'));
        self::assertNull(CommandLineSpelling::option($input, 'undefined'));
        self::assertSame([], CommandLineSpelling::options($input, 'many'));
        self::assertSame([], CommandLineSpelling::options($input, 'undefined'));
        self::assertSame([], CommandLineSpelling::arguments($input, 'undefined'));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function provideShapesNoCommandLineWrites(): iterable
    {
        yield 'bool' => [true, 'bool'];
        yield 'float' => [1.5, 'float'];
        yield 'array' => [['x'], 'array'];
    }

    #[Test]
    #[DataProvider('provideShapesNoCommandLineWrites')]
    public function itRefusesAShapeNoCommandLineWrites(mixed $value, string $type): void
    {
        try {
            CommandLineSpelling::of($value, '--one');
            self::fail('A shape no command line writes must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString(\sprintf('Invalid --one value of type %s', $type), $refusal->getMessage());
            self::assertSame('--one', $refusal->origin()->locator());
        }
    }

    /** @param array<string, mixed> $parameters */
    private function input(array $parameters): ArrayInput
    {
        return new ArrayInput($parameters, new InputDefinition([
            new InputOption('one', null, InputOption::VALUE_REQUIRED),
            new InputOption('many', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputArgument('arg', InputArgument::OPTIONAL),
        ]));
    }
}
