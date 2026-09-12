<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParser;
use Qualimetrix\Infrastructure\Console\CliOptionsParser;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

#[CoversClass(CliOptionsParser::class)]
final class CliOptionsParserTest extends TestCase
{
    #[Test]
    public function itProcessesEveryRegisteredAliasNotJustTheHardcodedOnes(): void
    {
        // Arrange: parser with aliases including non-hardcoded ones
        $ruleOptionsParser = new RuleOptionsParser([
            'cyclomatic-warning' => ['rule' => 'complexity.ccn', 'option' => 'warning'],
            'mi-warning' => ['rule' => 'maintainability.mi', 'option' => 'warning'],
            'cbo-error' => ['rule' => 'coupling.cbo', 'option' => 'error'],
        ]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('cyclomatic-warning', null, InputOption::VALUE_REQUIRED),
            new InputOption('mi-warning', null, InputOption::VALUE_REQUIRED),
            new InputOption('cbo-error', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([
            '--mi-warning' => '30',
            '--cbo-error' => '15',
        ], $definition);

        // Act
        $result = $cliParser->parseRuleOptions($input);

        // Assert: non-hardcoded aliases should be processed
        self::assertArrayHasKey('maintainability.mi', $result);
        self::assertSame(30, $result['maintainability.mi']['warning']);

        self::assertArrayHasKey('coupling.cbo', $result);
        self::assertSame(15, $result['coupling.cbo']['error']);
    }

    #[Test]
    public function itPrefersRuleOptOverAConflictingAlias(): void
    {
        // Arrange: --rule-opt and alias both set same rule option
        $ruleOptionsParser = new RuleOptionsParser([
            'mi-warning' => ['rule' => 'maintainability.mi', 'option' => 'warning'],
        ]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('mi-warning', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([
            '--rule-opt' => ['maintainability.mi:warning=50'],
            '--mi-warning' => '30',
        ], $definition);

        // Act
        $result = $cliParser->parseRuleOptions($input);

        // Assert: --rule-opt should take priority
        self::assertSame(50, $result['maintainability.mi']['warning']);
    }

    #[Test]
    public function itNormalizesAnAliasValueToFloat(): void
    {
        $ruleOptionsParser = new RuleOptionsParser([
            'param-type-coverage-warning' => ['rule' => 'design.type-coverage.param', 'option' => 'warning'],
        ]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('param-type-coverage-warning', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([
            '--param-type-coverage-warning' => '0.7',
        ], $definition);

        $result = $cliParser->parseRuleOptions($input);

        self::assertArrayHasKey('design.type-coverage.param', $result);
        self::assertSame(0.7, $result['design.type-coverage.param']['warning']);
    }

    #[Test]
    public function itNormalizesAliasValuesToBooleans(): void
    {
        $ruleOptionsParser = new RuleOptionsParser([
            'rule-enabled' => ['rule' => 'test.rule', 'option' => 'enabled'],
            'rule-disabled' => ['rule' => 'test.rule', 'option' => 'countNullsafe'],
        ]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('rule-enabled', null, InputOption::VALUE_REQUIRED),
            new InputOption('rule-disabled', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([
            '--rule-enabled' => 'true',
            '--rule-disabled' => 'false',
        ], $definition);

        $result = $cliParser->parseRuleOptions($input);

        self::assertArrayHasKey('test.rule', $result);
        self::assertTrue($result['test.rule']['enabled']);
        self::assertFalse($result['test.rule']['countNullsafe']);
    }

    #[Test]
    public function itNormalizesAnAliasValueToInt(): void
    {
        $ruleOptionsParser = new RuleOptionsParser([
            'ccn-warning' => ['rule' => 'complexity.ccn', 'option' => 'warning'],
        ]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('ccn-warning', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([
            '--ccn-warning' => '10',
        ], $definition);

        $result = $cliParser->parseRuleOptions($input);

        self::assertArrayHasKey('complexity.ccn', $result);
        self::assertSame(10, $result['complexity.ccn']['warning']);
    }

    #[Test]
    public function itTreatsAPresentValueNoneAliasAsTrue(): void
    {
        $ruleOptionsParser = new RuleOptionsParser([
            'circular-deps' => ['rule' => 'architecture.circular-dependency', 'option' => 'enabled'],
        ]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('circular-deps', null, InputOption::VALUE_NONE),
        ]);

        // Simulate --circular-deps: VALUE_NONE returns true when present
        $input = new ArrayInput([
            '--circular-deps' => true,
        ], $definition);

        $result = $cliParser->parseRuleOptions($input);

        self::assertArrayHasKey('architecture.circular-dependency', $result);
        self::assertTrue($result['architecture.circular-dependency']['enabled']);
    }

    #[Test]
    public function itSkipsAnAbsentValueNoneAlias(): void
    {
        $ruleOptionsParser = new RuleOptionsParser([
            'circular-deps' => ['rule' => 'architecture.circular-dependency', 'option' => 'enabled'],
        ]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('circular-deps', null, InputOption::VALUE_NONE),
        ]);

        // Not passing the option — VALUE_NONE default is false
        $input = new ArrayInput([], $definition);

        $result = $cliParser->parseRuleOptions($input);

        self::assertArrayNotHasKey('architecture.circular-dependency', $result);
    }

    #[Test]
    public function itNormalizesScientificNotationToFloat(): void
    {
        $ruleOptionsParser = new RuleOptionsParser([
            'threshold' => ['rule' => 'test.rule', 'option' => 'threshold'],
        ]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('threshold', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([
            '--threshold' => '1e3',
        ], $definition);

        $result = $cliParser->parseRuleOptions($input);

        self::assertArrayHasKey('test.rule', $result);
        // 1e3 should be parsed as float 1000.0, not int 1
        self::assertSame(1000.0, $result['test.rule']['threshold']);
    }

    #[Test]
    public function itNormalizesScientificNotationWithADecimalPointToFloat(): void
    {
        $ruleOptionsParser = new RuleOptionsParser([
            'threshold' => ['rule' => 'test.rule', 'option' => 'threshold'],
        ]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('threshold', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([
            '--threshold' => '1.5e2',
        ], $definition);

        $result = $cliParser->parseRuleOptions($input);

        self::assertArrayHasKey('test.rule', $result);
        self::assertSame(150.0, $result['test.rule']['threshold']);
    }

    /**
     * The sibling door of the `--rule-opt` empty-value defect: a short alias
     * carrying an empty value (`--lcom-exclude-methods=`) went through the
     * same `''` fallback and produced a genuine one-element list `['']`
     * instead of falling back to the option's default.
     *
     * The door's own promise (`promise-effect/promise-ledger.tsv`, `cli-alias`
     * rows) is `refuse`, not "fold to the default": the fix raises a
     * `ConfigurationRefusal` naming the alias, rule and option instead of a
     * silent `null`, so the defective `['']` still never reaches
     * `LcomOptions::fromArray()`, and the door keeps its promise besides.
     */
    #[Test]
    public function itRefusesAnEmptyAliasValueInsteadOfFoldingItToAOneElementEmptyString(): void
    {
        $ruleOptionsParser = new RuleOptionsParser([
            'lcom-exclude-methods' => ['rule' => 'cohesion.lcom', 'option' => 'excludeMethods'],
        ]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('lcom-exclude-methods', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([
            '--lcom-exclude-methods' => '',
        ], $definition);

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage(
            'Option --lcom-exclude-methods (rule "cohesion.lcom", option "excludeMethods")'
            . ' was written with an empty value ("--lcom-exclude-methods=").',
        );

        $cliParser->parseRuleOptions($input);
    }

    /**
     * The boundary case for this door: a single non-empty value must keep
     * parsing exactly as before the cure.
     */
    #[Test]
    public function itStillParsesANonEmptyAliasValueAsBefore(): void
    {
        $ruleOptionsParser = new RuleOptionsParser([
            'lcom-exclude-methods' => ['rule' => 'cohesion.lcom', 'option' => 'excludeMethods'],
        ]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('lcom-exclude-methods', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([
            '--lcom-exclude-methods' => 'getName',
        ], $definition);

        $result = $cliParser->parseRuleOptions($input);

        self::assertSame('getName', $result['cohesion.lcom']['excludeMethods']);
    }

    /**
     * The sixteen aliases with no external carrier for `null_means` (their CLI
     * option never appears in the CLI options table) are refused the same
     * way: this door has one code path for every alias, and there is no
     * reason an undocumented alias should behave differently from a
     * documented one for the same empty-value input.
     */
    #[Test]
    public function itRefusesAnEmptyValueOnAnUndocumentedAliasTheSameWay(): void
    {
        $ruleOptionsParser = new RuleOptionsParser([
            'circular-deps' => ['rule' => 'architecture.circular-dependency', 'option' => 'enabled'],
        ]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('circular-deps', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([
            '--circular-deps' => '',
        ], $definition);

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('Option --circular-deps (rule "architecture.circular-dependency", option "enabled") was written with an empty value');

        $cliParser->parseRuleOptions($input);
    }

    #[Test]
    public function itSkipsAnAliasThatWasNotPassedOnTheCommandLine(): void
    {
        // Arrange: alias registered but not passed via CLI
        $ruleOptionsParser = new RuleOptionsParser([
            'mi-warning' => ['rule' => 'maintainability.mi', 'option' => 'warning'],
            'mi-error' => ['rule' => 'maintainability.mi', 'option' => 'error'],
        ]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('mi-warning', null, InputOption::VALUE_REQUIRED),
            new InputOption('mi-error', null, InputOption::VALUE_REQUIRED),
        ]);

        // Only pass mi-warning, not mi-error
        $input = new ArrayInput([
            '--mi-warning' => '30',
        ], $definition);

        // Act
        $result = $cliParser->parseRuleOptions($input);

        // Assert: only mi-warning should be in result
        self::assertArrayHasKey('maintainability.mi', $result);
        self::assertSame(30, $result['maintainability.mi']['warning']);
        self::assertArrayNotHasKey('error', $result['maintainability.mi']);
    }

}
