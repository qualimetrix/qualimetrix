<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Loader\CommandLineLayer;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyOptions;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyRule;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomOptions;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityRule;
use Qualimetrix\Analysis\Evidence\Coupling\CboOptions;
use Qualimetrix\Analysis\Evidence\Coupling\DistanceOptions;
use Qualimetrix\Analysis\Evidence\Coupling\DistanceRule;
use Qualimetrix\Analysis\Evidence\Design\TypeCoverage\TypeCoverageOptions;
use Qualimetrix\Analysis\Evidence\Maintainability\MaintainabilityOptions;
use Qualimetrix\Analysis\Evidence\Maintainability\MaintainabilityRule;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParser;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParserFactory;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Infrastructure\Console\CheckCommandDefinition;
use Qualimetrix\Infrastructure\Console\CliOptionsParser;
use Qualimetrix\Infrastructure\Console\CliRuleOptionAddressing;
use Qualimetrix\Infrastructure\Rule\RuleRegistry;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

#[CoversClass(CliOptionsParser::class)]
#[CoversClass(CliRuleOptionAddressing::class)]
final class CliOptionsParserTest extends TestCase
{
    #[Test]
    public function itRefusesUnregisteredRuleOptionOwnersWithTheirActualCliOrigin(): void
    {
        $parser = new CliOptionsParser(new RuleOptionsParser([], [
            'complexity.ccn' => \Qualimetrix\Analysis\Evidence\Complexity\ComplexityOptions::class,
            'cohesion.lcom' => LcomOptions::class,
        ]));
        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
        ]);
        foreach ([
            'nosuch.rule' => 'Rule option owner "nosuch.rule" does not match any registered producer rule.',
            'COMPLEXITY.CCN' => 'Rule option owner "COMPLEXITY.CCN" does not match any registered producer rule. Did you mean "complexity.ccn"?',
            'design.lcom' => 'Rule option owner "design.lcom" does not match any registered producer rule. Did you mean "cohesion.lcom"?',
        ] as $owner => $summary) {
            try {
                $parser->pathWrites(new ArrayInput(['--rule-opt' => [$owner . ':enabled=false']], $definition));
                self::fail('An unregistered owner was accepted.');
            } catch (ConfigurationRefusal $error) {
                self::assertSame($summary . ' Written: --rule-opt=' . $owner . ':enabled=false.', $error->summary());
                self::assertSame(\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource::CommandLine, $error->sources()[0]->source());
                self::assertSame('--rule-opt', $error->sources()[0]->locator());
                self::assertNull($error->position());
            }
        }
    }

    #[Test]
    public function itTargetsTheDeclaredSchemaAndKeepsSelectorPayloadPlain(): void
    {
        $parser = new CliOptionsParser((new RuleOptionsParserFactory())->createFromClasses([DistanceRule::class]));
        $definition = new InputDefinition([
            new InputOption('distance-warning', null, InputOption::VALUE_REQUIRED),
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
        ]);
        $input = new ArrayInput([
            '--distance-warning' => '0.5',
            '--rule-opt' => ['coupling.distance:include-namespaces=exact:App\\Domain'],
        ], $definition);

        $writes = $parser->pathWrites($input);
        self::assertCount(2, $writes);
        self::assertSame(['rules', 'coupling.distance', 'max-distance-warning'], $writes[0]->path);
        self::assertSame(0, $writes[0]->target->scalar->minimum);
        self::assertSame(['rules', 'coupling.distance', 'include-namespaces'], $writes[1]->path);
        self::assertSame([['exact' => 'App\\Domain']], $writes[1]->selectorValue);
    }

    #[Test]
    public function itDecodesTheDistanceNamespaceSelectorAfterRawRuleOptionParsing(): void
    {
        $parser = new CliOptionsParser((new RuleOptionsParserFactory())->createFromClasses([DistanceRule::class]));
        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
        ]);

        $result = $this->resolvedRuleOptions($parser, new ArrayInput([
            '--rule-opt' => ['coupling.distance:include-namespaces=regex:App\\\\(?:Domain|Model)(?:\\\\[^\\\\]+)*'],
        ], $definition));

        self::assertSame([['regex' => 'App\\\\(?:Domain|Model)(?:\\\\[^\\\\]+)*']], $result['coupling.distance']['include-namespaces']);
        $selector = DistanceOptions::fromResolved(ResolvedOptionsFixture::values(DistanceOptions::class, ['include_namespaces' => $result['coupling.distance']['include-namespaces']]))->includeNamespaces[0] ?? null;
        self::assertInstanceOf(NamespacePattern::class, $selector);
        self::assertSame('regex:App\\\\(?:Domain|Model)(?:\\\\[^\\\\]+)*', $selector->definition->display());

        $yaml = DistanceOptions::fromResolved(ResolvedOptionsFixture::values(DistanceOptions::class, [
            'include_namespaces' => [['regex' => 'App\\\\(?:Domain|Model)(?:\\\\[^\\\\]+)*']],
        ]))->includeNamespaces;
        self::assertNotNull($yaml);
        self::assertSame($yaml[0]->rendered(), $selector->rendered());
    }

    #[Test]
    public function itRefusesABareDistanceNamespaceCliValue(): void
    {
        $parser = new CliOptionsParser((new RuleOptionsParserFactory())->createFromClasses([DistanceRule::class]));
        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
        ]);

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('must use KIND:VALUE');

        $this->resolvedRuleOptions($parser, new ArrayInput([
            '--rule-opt' => ['coupling.distance:include-namespaces=App\\Domain'],
        ], $definition));
    }

    #[Test]
    public function itDecodesPerRuleSuppressionSelectors(): void
    {
        $parser = new CliOptionsParser((new RuleOptionsParserFactory())->createFromClasses([ComplexityRule::class]));
        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
        ]);

        $result = $this->resolvedRuleOptions($parser, new ArrayInput([
            '--rule-opt' => [
                'complexity.ccn:suppress-paths=regex:.*Generated\\.php',
                'complexity.ccn:suppress-namespaces=subtree:App\\Generated',
            ],
        ], $definition));

        self::assertSame([['regex' => '.*Generated\\.php']], $result['complexity.ccn']['suppress-paths']);
        self::assertSame([['subtree' => 'App\\Generated']], $result['complexity.ccn']['suppress-namespaces']);
    }

    #[Test]
    public function itKeepsTheCanonicalIndexedSelectorPathAndOriginalCliOriginWhenTheSemanticValueIsInvalid(): void
    {
        $surface = \Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface::of(\Qualimetrix\Analysis\Evidence\Complexity\ComplexityOptions::class);
        $write = new \Qualimetrix\Analysis\Configuration\Contract\Pipeline\CommandLinePathWrite(
            ['rules', 'complexity.ccn', 'suppress-namespaces'],
            'unknown:App',
            '--rule-opt',
            '--rule-opt=complexity.ccn:suppress-namespaces=unknown:App',
            $surface->schemaAt(new \Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionAddress(null, 'suppress-namespaces')),
            [['unknown' => 'App']],
        );
        try {
            CommandLineLayer::of(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'), cliPathWrites: [$write]));
            self::fail('The invalid CLI selector was accepted.');
        } catch (ConfigurationRefusal $error) {
            self::assertSame('Option "suppress_namespaces.0" for rule "complexity.ccn" Unknown selector kind "unknown"; expected exact, subtree, or regex.', $error->summary());
            self::assertSame('--rule-opt=complexity.ccn:suppress-namespaces=unknown:App', $error->sources()[0]->authoredExpression());
            self::assertSame(\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource::CommandLine, $error->sources()[0]->source());
            self::assertSame('--rule-opt', $error->sources()[0]->locator());
            self::assertNull($error->position());
        }
    }

    #[Test]
    public function itProcessesEveryRegisteredAliasNotJustTheHardcodedOnes(): void
    {
        // Arrange: parser with aliases including non-hardcoded ones
        $ruleOptionsParser = new RuleOptionsParser([
            'cyclomatic-warning' => ['rule' => 'complexity.ccn', 'option' => 'warning'],
            'mi-warning' => ['rule' => 'maintainability.mi', 'option' => 'warning'],
            'cbo-error' => ['rule' => 'coupling.cbo', 'option' => 'error'],
        ], ['maintainability.mi' => MaintainabilityOptions::class, 'coupling.cbo' => CboOptions::class]);

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
        $result = $this->resolvedRuleOptions($cliParser, $input);

        // Assert: non-hardcoded aliases should be processed
        self::assertArrayHasKey('maintainability.mi', $result);
        self::assertSame(30, $result['maintainability.mi']['warning']);

        self::assertArrayHasKey('coupling.cbo', $result);
        self::assertSame(15, $result['coupling.cbo']['error']);
    }

    #[Test]
    public function itRefusesAConflictingAliasAndRuleOptionBeforeMerge(): void
    {
        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('mi-warning', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([
            '--rule-opt' => ['maintainability.mi:warning=50'],
            '--mi-warning' => '30',
        ], $definition);

        $cliParser = new CliOptionsParser((new RuleOptionsParserFactory())->createFromClasses([MaintainabilityRule::class]));
        $writes = $cliParser->pathWrites($input);
        self::assertCount(2, $writes);
        self::assertSame(['rules', 'maintainability.mi', 'warning'], $writes[0]->path);
        self::assertSame(['rules', 'maintainability.mi', 'warning'], $writes[1]->path);
        self::assertSame('--mi-warning', $writes[0]->optionName);
        self::assertSame('--rule-opt', $writes[1]->optionName);
        self::assertSame('30', $writes[0]->text);
        self::assertSame('50', $writes[1]->text);

        try {
            CommandLineLayer::of(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'), cliPathWrites: $writes));
            self::fail('Overlapping rule option writes must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('Options --mi-warning=30 and --rule-opt=maintainability.mi:warning=50 both write overlapping rule option paths.', $refusal->summary());
        }
    }

    #[Test]
    public function itNormalizesAnAliasValueToFloat(): void
    {
        $ruleOptionsParser = new RuleOptionsParser([
            'param-type-coverage-warning' => ['rule' => 'design.type-coverage.param', 'option' => 'warning'],
        ], ['design.type-coverage.param' => TypeCoverageOptions::class]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('param-type-coverage-warning', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([
            '--param-type-coverage-warning' => '0.7',
        ], $definition);

        $result = $this->resolvedRuleOptions($cliParser, $input);

        self::assertArrayHasKey('design.type-coverage.param', $result);
        self::assertSame(0.7, $result['design.type-coverage.param']['warning']);
    }

    #[Test]
    public function itNormalizesAliasValuesToBooleans(): void
    {
        $cliParser = new CliOptionsParser((new RuleOptionsParserFactory())->createFromClasses([CircularDependencyRule::class]));
        $command = new Command('check');
        CheckCommandDefinition::addOptions($command, new RuleRegistry([CircularDependencyRule::class]));
        $definition = $command->getDefinition();
        self::assertFalse($definition->getOption('circular-deps')->acceptValue());

        $input = new ArrayInput([
            '--circular-deps' => true,
            '--rule-opt' => ['architecture.circular-dependency:direct-as-error=false'],
        ], $definition);

        $result = $this->resolvedRuleOptions($cliParser, $input);

        self::assertArrayHasKey('architecture.circular-dependency', $result);
        self::assertTrue($result['architecture.circular-dependency']['enabled']);
        self::assertFalse($result['architecture.circular-dependency']['direct-as-error']);

        $rawWrites = $cliParser->pathWrites(new ArgvInput([
            'qmx', '--circular-deps', '--rule-opt=architecture.circular-dependency:direct-as-error=false',
        ], $definition));
        self::assertCount(2, $rawWrites);
        self::assertSame(['rules', 'architecture.circular-dependency', 'enabled'], $rawWrites[0]->path);
        self::assertSame('true', $rawWrites[0]->text);
        self::assertSame(['rules', 'architecture.circular-dependency', 'direct-as-error'], $rawWrites[1]->path);
        self::assertSame('false', $rawWrites[1]->text);
    }

    #[Test]
    public function itNormalizesAnAliasValueToInt(): void
    {
        $ruleOptionsParser = new RuleOptionsParser([
            'ccn-warning' => ['rule' => 'complexity.ccn', 'option' => 'callable.warning'],
        ], ['complexity.ccn' => ComplexityRule::getOptionsClass()]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('ccn-warning', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([
            '--ccn-warning' => '10',
        ], $definition);

        $result = $this->resolvedRuleOptions($cliParser, $input);

        self::assertArrayHasKey('complexity.ccn', $result);
        self::assertSame(10, $result['complexity.ccn']['callable']['warning']);
    }

    #[Test]
    public function itTreatsAPresentValueNoneAliasAsTrue(): void
    {
        $ruleOptionsParser = new RuleOptionsParser([
            'circular-deps' => ['rule' => 'architecture.circular-dependency', 'option' => 'enabled'],
        ], ['architecture.circular-dependency' => CircularDependencyOptions::class]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('circular-deps', null, InputOption::VALUE_NONE),
        ]);

        // Simulate --circular-deps: VALUE_NONE returns true when present
        $input = new ArrayInput([
            '--circular-deps' => true,
        ], $definition);

        $result = $this->resolvedRuleOptions($cliParser, $input);

        self::assertArrayHasKey('architecture.circular-dependency', $result);
        self::assertTrue($result['architecture.circular-dependency']['enabled']);
    }

    #[Test]
    public function itSkipsAnAbsentValueNoneAlias(): void
    {
        $ruleOptionsParser = new RuleOptionsParser([
            'circular-deps' => ['rule' => 'architecture.circular-dependency', 'option' => 'enabled'],
        ], ['architecture.circular-dependency' => CircularDependencyOptions::class]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('circular-deps', null, InputOption::VALUE_NONE),
        ]);

        // Not passing the option — VALUE_NONE default is false
        $input = new ArrayInput([], $definition);

        $result = $this->resolvedRuleOptions($cliParser, $input);

        self::assertArrayNotHasKey('architecture.circular-dependency', $result);
    }

    #[Test]
    public function itNormalizesScientificNotationToFloat(): void
    {
        $ruleOptionsParser = new RuleOptionsParser([
            'threshold' => ['rule' => 'coupling.distance', 'option' => 'max-distance-warning'],
        ], ['coupling.distance' => DistanceOptions::class]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('threshold', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([
            '--threshold' => '1e3',
        ], $definition);

        $result = $this->resolvedRuleOptions($cliParser, $input);

        self::assertArrayHasKey('coupling.distance', $result);
        self::assertSame(1000.0, $result['coupling.distance']['max-distance-warning']);
    }

    #[Test]
    public function itNormalizesScientificNotationWithADecimalPointToFloat(): void
    {
        $ruleOptionsParser = new RuleOptionsParser([
            'threshold' => ['rule' => 'coupling.distance', 'option' => 'max-distance-warning'],
        ], ['coupling.distance' => DistanceOptions::class]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('threshold', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([
            '--threshold' => '1.5e2',
        ], $definition);

        $result = $this->resolvedRuleOptions($cliParser, $input);

        self::assertArrayHasKey('coupling.distance', $result);
        self::assertSame(150.0, $result['coupling.distance']['max-distance-warning']);
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
     * `LcomOptions::fromResolved(ResolvedOptionsFixture::values(LcomOptions::class, ))`, and the door keeps its promise besides.
     */
    #[Test]
    public function itRefusesAnEmptyAliasValueInsteadOfFoldingItToAOneElementEmptyString(): void
    {
        $ruleOptionsParser = new RuleOptionsParser([
            'lcom-exclude-methods' => ['rule' => 'cohesion.lcom', 'option' => 'excludeMethods'],
        ], ['cohesion.lcom' => LcomOptions::class]);

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

        $this->resolvedRuleOptions($cliParser, $input);
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
        ], ['cohesion.lcom' => LcomOptions::class]);

        $cliParser = new CliOptionsParser($ruleOptionsParser);

        $definition = new InputDefinition([
            new InputOption('rule-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('lcom-exclude-methods', null, InputOption::VALUE_REQUIRED),
        ]);

        $input = new ArrayInput([
            '--lcom-exclude-methods' => '[getName]',
        ], $definition);

        $result = $this->resolvedRuleOptions($cliParser, $input);

        self::assertSame(['getName'], $result['cohesion.lcom']['exclude-methods']);
    }

    /** A boolean alias is a switch, so an attached empty value is refused during Symfony binding. */
    #[Test]
    public function itRefusesAnEmptyValueOnABooleanAliasAtTheRealCliDefinition(): void
    {
        $command = new Command('check');
        CheckCommandDefinition::addOptions($command, new RuleRegistry([CircularDependencyRule::class]));
        $definition = $command->getDefinition();
        self::assertFalse($definition->getOption('circular-deps')->acceptValue());

        $this->expectException(ConsoleRuntimeException::class);
        $this->expectExceptionMessage('The "--circular-deps" option does not accept a value.');

        new ArgvInput(['qmx', '--circular-deps='], $definition);
    }

    #[Test]
    public function itSkipsAnAliasThatWasNotPassedOnTheCommandLine(): void
    {
        // Arrange: alias registered but not passed via CLI
        $ruleOptionsParser = new RuleOptionsParser([
            'mi-warning' => ['rule' => 'maintainability.mi', 'option' => 'warning'],
            'mi-error' => ['rule' => 'maintainability.mi', 'option' => 'error'],
        ], ['maintainability.mi' => MaintainabilityOptions::class]);

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
        $result = $this->resolvedRuleOptions($cliParser, $input);

        // Assert: only mi-warning should be in result
        self::assertArrayHasKey('maintainability.mi', $result);
        self::assertSame(30, $result['maintainability.mi']['warning']);
        self::assertArrayNotHasKey('error', $result['maintainability.mi']);
    }

    /** @return array<string, array<string, mixed>> */
    private function resolvedRuleOptions(CliOptionsParser $parser, ArrayInput $input): array
    {
        $writes = $parser->pathWrites($input);
        $layer = CommandLineLayer::of(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'), cliPathWrites: $writes));
        $plain = $layer->root->plain();
        return $plain['rules'] ?? [];
    }
}
