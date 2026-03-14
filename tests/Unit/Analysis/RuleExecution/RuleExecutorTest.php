<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\RuleExecution;

use AiMessDetector\Analysis\RuleExecution\RuleExecutor;
use AiMessDetector\Configuration\AnalysisConfiguration;
use AiMessDetector\Configuration\ConfigurationHolder;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Rule\RuleInterface;
use AiMessDetector\Core\Rule\RuleLevel;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
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

    // --- Prefix matching tests ---

    public function testExecuteWithPrefixDisable(): void
    {
        $v1 = $this->createViolation('complexity.cyclomatic', violationCode: 'complexity.cyclomatic');
        $v2 = $this->createViolation('complexity.cognitive', violationCode: 'complexity.cognitive');
        $v3 = $this->createViolation('size.method-count', violationCode: 'size.method-count');

        $rule1 = $this->createRule('complexity.cyclomatic', [$v1]);
        $rule2 = $this->createRule('complexity.cognitive', [$v2]);
        $rule3 = $this->createRule('size.method-count', [$v3]);

        // Disable entire complexity group
        $config = new AnalysisConfiguration(disabledRules: ['complexity']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule1, $rule2, $rule3], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertCount(1, $violations);
        self::assertSame('size.method-count', $violations[0]->ruleName);
    }

    public function testExecuteFiltersViolationsByViolationCode(): void
    {
        $methodViolation = $this->createViolation('complexity.cyclomatic', violationCode: 'complexity.cyclomatic.method');
        $classViolation = $this->createViolation('complexity.cyclomatic', violationCode: 'complexity.cyclomatic.class');

        $rule = $this->createHierarchicalRule(
            'complexity.cyclomatic',
            [RuleLevel::Method, RuleLevel::Class_],
            [
                RuleLevel::Method->value => [$methodViolation],
                RuleLevel::Class_->value => [$classViolation],
            ],
        );

        // Disable only class-level violations
        $config = new AnalysisConfiguration(disabledRules: ['complexity.cyclomatic.class']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertCount(1, $violations);
        self::assertSame($methodViolation, $violations[0]);
    }

    public function testGetActiveRulesWithPrefixOnlyRules(): void
    {
        $rule1 = $this->createRule('complexity.cyclomatic', []);
        $rule2 = $this->createRule('complexity.cognitive', []);
        $rule3 = $this->createRule('size.method-count', []);

        $config = new AnalysisConfiguration(onlyRules: ['complexity']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule1, $rule2, $rule3], $provider);

        $activeRules = $executor->getActiveRules();

        self::assertCount(2, $activeRules);
    }

    // --- Hierarchical rules tests ---

    public function testExecuteHierarchicalRuleWithAllLevelsEnabled(): void
    {
        $methodViolation = $this->createViolation('complexity', violationCode: 'complexity.method', level: RuleLevel::Method);
        $classViolation = $this->createViolation('complexity', violationCode: 'complexity.class', level: RuleLevel::Class_);

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

    public function testExecuteHierarchicalRuleWithSpecificViolationCodeDisabled(): void
    {
        $methodViolation = $this->createViolation('complexity', violationCode: 'complexity.method', level: RuleLevel::Method);
        $classViolation = $this->createViolation('complexity', violationCode: 'complexity.class', level: RuleLevel::Class_);

        $rule = $this->createHierarchicalRule(
            'complexity',
            [RuleLevel::Method, RuleLevel::Class_],
            [
                RuleLevel::Method->value => [$methodViolation],
                RuleLevel::Class_->value => [$classViolation],
            ],
        );

        // Disable class-level violations via violationCode filtering
        $config = new AnalysisConfiguration(disabledRules: ['complexity.class']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        // Only method level should pass through
        self::assertCount(1, $violations);
        self::assertSame($methodViolation, $violations[0]);
    }

    public function testExecuteHierarchicalRuleWithEntireRuleDisabled(): void
    {
        $rule = $this->createHierarchicalRule(
            'complexity',
            [RuleLevel::Method, RuleLevel::Class_],
            [
                RuleLevel::Method->value => [$this->createViolation('complexity', violationCode: 'complexity.method')],
                RuleLevel::Class_->value => [$this->createViolation('complexity', violationCode: 'complexity.class')],
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

    public function testHierarchicalRuleWithOnlyRulesFilter(): void
    {
        $methodViolation = $this->createViolation('complexity', violationCode: 'complexity.method', level: RuleLevel::Method);
        $classViolation = $this->createViolation('complexity', violationCode: 'complexity.class', level: RuleLevel::Class_);

        $rule = $this->createHierarchicalRule(
            'complexity',
            [RuleLevel::Method, RuleLevel::Class_],
            [
                RuleLevel::Method->value => [$methodViolation],
                RuleLevel::Class_->value => [$classViolation],
            ],
        );

        // Only enable method-level violations
        $config = new AnalysisConfiguration(onlyRules: ['complexity.method']);
        $provider = $this->createConfiguredProvider($config);
        $executor = new RuleExecutor([$rule], $provider);

        $context = $this->createMinimalContext();
        $violations = $executor->execute($context);

        self::assertCount(1, $violations);
        self::assertSame($methodViolation, $violations[0]);
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
    private function createRule(string $name, array $violations, RuleCategory $category = RuleCategory::Complexity): RuleInterface
    {
        $rule = $this->createStub(RuleInterface::class);
        $rule->method('getName')->willReturn($name);
        $rule->method('analyze')->willReturn($violations);
        $rule->method('getCategory')->willReturn($category);

        return $rule;
    }

    private function createMinimalContext(): AnalysisContext
    {
        $repository = $this->createStub(\AiMessDetector\Core\Metric\MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([]);

        return new AnalysisContext($repository, []);
    }

    /**
     * @param list<RuleLevel> $supportedLevels
     * @param array<string, list<Violation>> $violationsByLevel
     */
    private function createHierarchicalRule(
        string $name,
        array $supportedLevels,
        array $violationsByLevel,
        RuleCategory $category = RuleCategory::Complexity,
    ): RuleInterface {
        // RuleExecutor now calls analyze() for all rules uniformly.
        // Flatten all level violations into a single list for analyze().
        $allViolations = array_merge(...array_values($violationsByLevel));

        return $this->createRule($name, $allViolations, $category);
    }

    private function createViolation(string $ruleName, ?string $violationCode = null, ?RuleLevel $level = null): Violation
    {
        return new Violation(
            location: new Location(
                file: '/test/file.php',
                line: 1,
            ),
            symbolPath: SymbolPath::forFile('/test/file.php'),
            ruleName: $ruleName,
            violationCode: $violationCode ?? $ruleName,
            message: "Violation from $ruleName",
            severity: Severity::Warning,
            level: $level,
        );
    }
}
