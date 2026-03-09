<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Console;

use AiMessDetector\Configuration\RuleOptionsParser;
use AiMessDetector\Infrastructure\Console\CliOptionsParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

#[CoversClass(CliOptionsParser::class)]
final class CliOptionsParserTest extends TestCase
{
    #[Test]
    public function parseRuleOptions_processesAllRegisteredAliases(): void
    {
        // Arrange: parser with aliases including non-hardcoded ones
        $ruleOptionsParser = new RuleOptionsParser([
            'cyclomatic-warning' => ['rule' => 'complexity.cyclomatic', 'option' => 'warning'],
            'mi-warning' => ['rule' => 'maintainability.index', 'option' => 'warning'],
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
        self::assertArrayHasKey('maintainability.index', $result);
        self::assertSame(30, $result['maintainability.index']['warning']);

        self::assertArrayHasKey('coupling.cbo', $result);
        self::assertSame(15, $result['coupling.cbo']['error']);
    }

    #[Test]
    public function parseRuleOptions_ruleOptTakesPriorityOverAliases(): void
    {
        // Arrange: --rule-opt and alias both set same rule option
        $ruleOptionsParser = new RuleOptionsParser([
            'mi-warning' => ['rule' => 'maintainability.index', 'option' => 'warning'],
        ]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('mi-warning', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([
            '--rule-opt' => ['maintainability.index:warning=50'],
            '--mi-warning' => '30',
        ], $definition);

        // Act
        $result = $cliParser->parseRuleOptions($input);

        // Assert: --rule-opt should take priority
        self::assertSame(50, $result['maintainability.index']['warning']);
    }

    #[Test]
    public function parseRuleOptions_normalizesFloatValues(): void
    {
        $ruleOptionsParser = new RuleOptionsParser([
            'type-coverage-param-warning' => ['rule' => 'design.type-coverage', 'option' => 'paramWarning'],
        ]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('type-coverage-param-warning', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([
            '--type-coverage-param-warning' => '0.7',
        ], $definition);

        $result = $cliParser->parseRuleOptions($input);

        self::assertArrayHasKey('design.type-coverage', $result);
        self::assertSame(0.7, $result['design.type-coverage']['paramWarning']);
    }

    #[Test]
    public function parseRuleOptions_normalizesBooleanValues(): void
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
    public function parseRuleOptions_normalizesIntValues(): void
    {
        $ruleOptionsParser = new RuleOptionsParser([
            'ccn-warning' => ['rule' => 'complexity.cyclomatic', 'option' => 'warning'],
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

        self::assertArrayHasKey('complexity.cyclomatic', $result);
        self::assertSame(10, $result['complexity.cyclomatic']['warning']);
    }

    #[Test]
    public function parseRuleOptions_booleanAliasPresentIsTreatedAsTrue(): void
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
    public function parseRuleOptions_booleanAliasNotPresentIsSkipped(): void
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
    public function parseRuleOptions_skipsNullAliases(): void
    {
        // Arrange: alias registered but not passed via CLI
        $ruleOptionsParser = new RuleOptionsParser([
            'mi-warning' => ['rule' => 'maintainability.index', 'option' => 'warning'],
            'mi-error' => ['rule' => 'maintainability.index', 'option' => 'error'],
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
        self::assertArrayHasKey('maintainability.index', $result);
        self::assertSame(30, $result['maintainability.index']['warning']);
        self::assertArrayNotHasKey('error', $result['maintainability.index']);
    }

}
