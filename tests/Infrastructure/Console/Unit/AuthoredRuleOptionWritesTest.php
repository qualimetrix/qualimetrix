<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\AuthoredRuleOptionWrites;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;

#[CoversClass(AuthoredRuleOptionWrites::class)]
final class AuthoredRuleOptionWritesTest extends TestCase
{
    #[Test]
    public function itKeepsRepeatedAliasesAndRuleOptionsBeforeAndAfterBind(): void
    {
        $definition = $this->definition();
        $input = new ArgvInput(['qmx', '--warning=10', '--warning', '20', '--rule-opt', 'complexity.ccn:error=30'], $definition);
        $expected = [
            ['optionName' => '--warning', 'text' => '10', 'ordinal' => 0],
            ['optionName' => '--warning', 'text' => '20', 'ordinal' => 1],
            ['optionName' => '--rule-opt', 'text' => 'complexity.ccn:error=30', 'ordinal' => 2],
        ];

        self::assertSame($expected, AuthoredRuleOptionWrites::fromInput($input, ['warning' => true]));
        $input->bind($definition);
        self::assertSame($expected, AuthoredRuleOptionWrites::fromInput($input, ['warning' => true]));
        self::assertSame('20', $input->getOption('warning'));
    }

    #[Test]
    public function itStopsAtTerminatorAndSkipsValuesOfOtherOptions(): void
    {
        $definition = new InputDefinition([
            new InputArgument('paths', InputArgument::IS_ARRAY),
            new InputOption('config', null, InputOption::VALUE_REQUIRED),
            new InputOption('warning', null, InputOption::VALUE_REQUIRED),
        ]);
        $input = new ArgvInput(['qmx', '--config=--warning=10', '--warning=20', '--', '--warning=30'], $definition);

        self::assertSame(
            [['optionName' => '--warning', 'text' => '20', 'ordinal' => 0]],
            AuthoredRuleOptionWrites::fromInput($input, ['warning' => true]),
        );
    }

    #[Test]
    public function itReadsBoundProgrammaticInputWithoutInventingAliasHistory(): void
    {
        $input = new ArrayInput([
            '--warning' => '20',
            '--rule-opt' => ['complexity.ccn:error=30', 'complexity.ccn:class.warning=40'],
        ], $this->definition());

        self::assertSame([
            ['optionName' => '--warning', 'text' => '20', 'ordinal' => 0],
            ['optionName' => '--rule-opt', 'text' => 'complexity.ccn:error=30', 'ordinal' => 1],
            ['optionName' => '--rule-opt', 'text' => 'complexity.ccn:class.warning=40', 'ordinal' => 2],
        ], AuthoredRuleOptionWrites::fromInput($input, ['warning' => true]));
    }

    #[Test]
    public function itUsesTheSameRawOccurrencePathForStringInput(): void
    {
        $input = new StringInput('--warning=10 --warning=20');
        $input->bind($this->definition());

        self::assertSame([
            ['optionName' => '--warning', 'text' => '10', 'ordinal' => 0],
            ['optionName' => '--warning', 'text' => '20', 'ordinal' => 1],
        ], AuthoredRuleOptionWrites::fromInput($input, ['warning' => true]));
    }

    private function definition(): InputDefinition
    {
        return new InputDefinition([
            new InputOption('warning', null, InputOption::VALUE_REQUIRED),
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
        ]);
    }
}
