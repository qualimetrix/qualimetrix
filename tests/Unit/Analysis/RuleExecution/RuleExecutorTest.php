<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\RuleExecution;

use AiMessDetector\Analysis\RuleExecution\RuleExecutor;
use AiMessDetector\Configuration\AnalysisConfiguration;
use AiMessDetector\Configuration\ConfigurationHolder;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\HierarchicalRuleInterface;
use AiMessDetector\Core\Rule\RuleInterface;
use AiMessDetector\Core\Rule\RuleLevel;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Core\Violation\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleExecutor::class)]
final class RuleExecutorTest extends TestCase
{
    public function testExecuteWithNoRules(): void
    {
        $provider = $this->createConfiguredProvider();
        $executor = new RuleExecutor([], $provider);

        $context = $this->createMinimalContext();

        self::assertSame([], $executor->execute($context));
        self::assertSame([], $executor->getActiveRules());
        self::assertSame(0, $executor->getTotalRulesCount());
    }

    public function testExecuteWithAllRulesEnabled(): void
    {
        $violation1 = $this->createViolation('rule1');
        $violation2 = $this->createViolation('rule2');

        $rule1 = $this->createRule('rule1', [$violation1]);
        $rule2 = $this->createRule('rule2', [$violation2]);

        $provider = $this->createConfiguredProvider();
        $executor = new RuleExecutor([$rule1, $rule2], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertCount(2, $violations);
        self::assertSame($violation1, $violations[0]);
        self::assertSame($violation2, $violations[1]);
        self::assertSame(2, $executor->getTotalRulesCount());
    }

    public function testExecuteFiltersDisabledRules(): void
    {
        $violation1 = $this->createViolation('rule1');
        $violation2 = $this->createViolation('rule2');

        $rule1 = $this->createRule('rule1', [$violation1]);
        $rule2 = $this->createRule('rule2', [$violation2]);

        $config = new AnalysisConfiguration(disabledRules: ['rule1']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule1, $rule2], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertCount(1, $violations);
        self::assertSame($violation2, $violations[0]);
    }

    public function testExecuteWithOnlyRulesFilter(): void
    {
        $violation1 = $this->createViolation('rule1');
        $violation2 = $this->createViolation('rule2');
        $violation3 = $this->createViolation('rule3');

        $rule1 = $this->createRule('rule1', [$violation1]);
        $rule2 = $this->createRule('rule2', [$violation2]);
        $rule3 = $this->createRule('rule3', [$violation3]);

        $config = new AnalysisConfiguration(onlyRules: ['rule1', 'rule3']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule1, $rule2, $rule3], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertCount(2, $violations);
        self::assertSame($violation1, $violations[0]);
        self::assertSame($violation3, $violations[1]);
    }

    public function testGetActiveRulesReturnsOnlyEnabled(): void
    {
        $rule1 = $this->createRule('enabled-rule', []);
        $rule2 = $this->createRule('disabled-rule', []);

        $config = new AnalysisConfiguration(disabledRules: ['disabled-rule']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule1, $rule2], $provider);

        $activeRules = $executor->getActiveRules();

        self::assertCount(1, $activeRules);
        self::assertSame('enabled-rule', $activeRules[0]->getName());
    }

    public function testGetTotalRulesCountIncludesDisabled(): void
    {
        $rule1 = $this->createRule('rule1', []);
        $rule2 = $this->createRule('rule2', []);

        $config = new AnalysisConfiguration(disabledRules: ['rule1']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule1, $rule2], $provider);

        self::assertSame(2, $executor->getTotalRulesCount());
        self::assertCount(1, $executor->getActiveRules());
    }

    public function testExecuteWithIterableRules(): void
    {
        $violation = $this->createViolation('rule1');
        $rule = $this->createRule('rule1', [$violation]);

        $generator = (function () use ($rule) {
            yield $rule;
        })();

        $provider = $this->createConfiguredProvider();
        $executor = new RuleExecutor($generator, $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertCount(1, $violations);
        self::assertSame(1, $executor->getTotalRulesCount());
    }

    public function testDisabledRulesTakePrecedenceOverOnlyRules(): void
    {
        $violation = $this->createViolation('rule1');
        $rule = $this->createRule('rule1', [$violation]);

        $config = new AnalysisConfiguration(
            disabledRules: ['rule1'],
            onlyRules: ['rule1'],
        );
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertSame([], $violations);
        self::assertSame([], $executor->getActiveRules());
    }

    private function createConfiguredProvider(?AnalysisConfiguration $config = null): ConfigurationHolder
    {
        $provider = new ConfigurationHolder();
        $provider->setConfiguration($config ?? new AnalysisConfiguration());

        return $provider;
    }

    /**
     * @param list<Violation> $violations
     */
    private function createRule(string $name, array $violations): RuleInterface
    {
        $rule = $this->createMock(RuleInterface::class);
        $rule->method('getName')->willReturn($name);
        $rule->method('analyze')->willReturn($violations);

        return $rule;
    }

    private function createMinimalContext(): AnalysisContext
    {
        $repository = $this->createMock(\AiMessDetector\Core\Metric\MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([]);

        return new AnalysisContext($repository, []);
    }

    // --- Hierarchical rules tests ---

    public function testExecuteHierarchicalRuleWithAllLevelsEnabled(): void
    {
        $methodViolation = $this->createViolation('complexity', RuleLevel::Method);
        $classViolation = $this->createViolation('complexity', RuleLevel::Class_);

        $rule = $this->createHierarchicalRule(
            'complexity',
            [RuleLevel::Method, RuleLevel::Class_],
            [
                RuleLevel::Method->value => [$methodViolation],
                RuleLevel::Class_->value => [$classViolation],
            ],
        );

        $provider = $this->createConfiguredProvider();
        $executor = new RuleExecutor([$rule], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertCount(2, $violations);
        self::assertContains($methodViolation, $violations);
        self::assertContains($classViolation, $violations);
    }

    public function testExecuteHierarchicalRuleWithSomeLevelsDisabled(): void
    {
        $methodViolation = $this->createViolation('complexity', RuleLevel::Method);
        $classViolation = $this->createViolation('complexity', RuleLevel::Class_);

        $rule = $this->createHierarchicalRule(
            'complexity',
            [RuleLevel::Method, RuleLevel::Class_],
            [
                RuleLevel::Method->value => [$methodViolation],
                RuleLevel::Class_->value => [$classViolation],
            ],
        );

        // Disable class level
        $config = new AnalysisConfiguration(disabledRules: ['complexity.class']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        // Only method level should be executed
        self::assertCount(1, $violations);
        self::assertSame($methodViolation, $violations[0]);
    }

    public function testExecuteHierarchicalRuleWithAllLevelsDisabled(): void
    {
        $rule = $this->createHierarchicalRule(
            'complexity',
            [RuleLevel::Method, RuleLevel::Class_],
            [
                RuleLevel::Method->value => [$this->createViolation('complexity')],
                RuleLevel::Class_->value => [$this->createViolation('complexity')],
            ],
        );

        // Disable entire rule
        $config = new AnalysisConfiguration(disabledRules: ['complexity']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertSame([], $violations);
    }

    public function testGetActiveRulesIncludesHierarchicalRuleIfAnyLevelEnabled(): void
    {
        $rule = $this->createHierarchicalRule(
            'complexity',
            [RuleLevel::Method, RuleLevel::Class_],
            [],
        );

        // Disable only class level, method level still enabled
        $config = new AnalysisConfiguration(disabledRules: ['complexity.class']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule], $provider);

        $activeRules = $executor->getActiveRules();

        self::assertCount(1, $activeRules);
        self::assertSame('complexity', $activeRules[0]->getName());
    }

    public function testGetActiveRulesExcludesHierarchicalRuleIfAllLevelsDisabled(): void
    {
        $rule = $this->createHierarchicalRule(
            'complexity',
            [RuleLevel::Method, RuleLevel::Class_],
            [],
        );

        // Disable all levels
        $config = new AnalysisConfiguration(disabledRules: ['complexity.method', 'complexity.class']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule], $provider);

        $activeRules = $executor->getActiveRules();

        self::assertSame([], $activeRules);
    }

    public function testGetActiveLevelsForHierarchicalRule(): void
    {
        $rule = $this->createHierarchicalRule(
            'complexity',
            [RuleLevel::Method, RuleLevel::Class_, RuleLevel::Namespace_],
            [],
        );

        // Disable class level only
        $config = new AnalysisConfiguration(disabledRules: ['complexity.class']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule], $provider);

        $activeLevels = $executor->getActiveLevels($rule);

        self::assertCount(2, $activeLevels);
        self::assertContains(RuleLevel::Method, $activeLevels);
        self::assertContains(RuleLevel::Namespace_, $activeLevels);
        self::assertNotContains(RuleLevel::Class_, $activeLevels);
    }

    public function testHierarchicalRuleWithOnlyRulesFilter(): void
    {
        $methodViolation = $this->createViolation('complexity', RuleLevel::Method);
        $classViolation = $this->createViolation('complexity', RuleLevel::Class_);

        $rule = $this->createHierarchicalRule(
            'complexity',
            [RuleLevel::Method, RuleLevel::Class_],
            [
                RuleLevel::Method->value => [$methodViolation],
                RuleLevel::Class_->value => [$classViolation],
            ],
        );

        // Only enable method level
        $config = new AnalysisConfiguration(onlyRules: ['complexity.method']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertCount(1, $violations);
        self::assertSame($methodViolation, $violations[0]);
    }

    /**
     * @param list<RuleLevel> $supportedLevels
     * @param array<string, list<Violation>> $violationsByLevel
     */
    private function createHierarchicalRule(
        string $name,
        array $supportedLevels,
        array $violationsByLevel,
    ): HierarchicalRuleInterface {
        $rule = $this->createMock(HierarchicalRuleInterface::class);
        $rule->method('getName')->willReturn($name);
        $rule->method('getSupportedLevels')->willReturn($supportedLevels);

        $rule->method('analyzeLevel')->willReturnCallback(
            static fn(RuleLevel $level): array => $violationsByLevel[$level->value] ?? [],
        );

        return $rule;
    }

    private function createViolation(string $ruleName, ?RuleLevel $level = null): Violation
    {
        return new Violation(
            location: new Location(
                file: '/test/file.php',
                line: 1,
            ),
            symbolPath: SymbolPath::forFile('/test/file.php'),
            ruleName: $ruleName,
            violationCode: $ruleName,
            message: "Violation from $ruleName",
            severity: Severity::Warning,
            level: $level,
        );
    }
}
