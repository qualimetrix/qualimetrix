<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Infrastructure\Console\AuthoredRuleOptionWrites;
use Qualimetrix\Infrastructure\Console\RuleOptionArgv;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;

#[CoversClass(AuthoredRuleOptionWrites::class)]
#[CoversClass(RuleOptionArgv::class)]
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

        $integer = new ArrayInput(['--warning' => 20], $this->definition());
        self::assertSame(
            [['optionName' => '--warning', 'text' => '20', 'ordinal' => 0]],
            AuthoredRuleOptionWrites::fromInput($integer, ['warning' => true]),
        );
        foreach (['warning', 'rule-opt'] as $option) {
            foreach ([true, false] as $value) {
                $valued = new ArrayInput(['--' . $option => $option === 'rule-opt' ? [$value] : $value], $this->definition());
                try {
                    AuthoredRuleOptionWrites::fromInput($valued, ['warning' => true]);
                    self::fail('A valued --' . $option . ' must refuse a boolean before spelling it.');
                } catch (ConfigurationRefusal $refusal) {
                    self::assertSame(
                        'Invalid --' . $option . ' value of type bool: expected it as written on a command line.',
                        $refusal->summary(),
                    );
                    self::assertSame('option --' . $option, $refusal->sources()[0]->describe());
                }
            }
        }
        $flags = new InputDefinition([new InputOption('no-progress', null, InputOption::VALUE_NONE)]);
        self::assertSame(
            [['optionName' => '--no-progress', 'text' => 'true', 'ordinal' => 0]],
            AuthoredRuleOptionWrites::fromInput(new ArrayInput(['--no-progress' => true], $flags), ['no-progress' => false]),
        );
        self::assertSame([], AuthoredRuleOptionWrites::fromInput(new ArrayInput(['--no-progress' => false], $flags), ['no-progress' => false]));
        self::assertSame([], AuthoredRuleOptionWrites::fromInput(new ArrayInput([], $this->definition()), ['warning' => true]));
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
