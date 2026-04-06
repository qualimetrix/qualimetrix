<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\RuleExecution;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\RuleExecution\RuleExecutor;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationHolder;
use Qualimetrix\Configuration\RuleNamespaceExclusionProvider;
use Qualimetrix\Configuration\RuleOptionsRegistry;
use Qualimetrix\Configuration\RulePathExclusionProvider;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Rule\RuleInterface;
use Qualimetrix\Core\Rule\RuleLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;

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

    // --- Namespace exclusion tests ---

    public function testNamespaceExclusionFiltersViolations(): void
    {
        $excludedViolation = $this->createViolationWithNamespace('rule1', 'App\\Tests');
        $includedViolation = $this->createViolationWithNamespace('rule1', 'App\\Core');

        $rule = $this->createRule('rule1', [$excludedViolation, $includedViolation]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = new RuleExecutor([$rule], $provider, $registry);

        $violations = $executor->execute($this->createMinimalContext());

        self::assertCount(1, $violations);
        self::assertSame($includedViolation, $violations[0]);
    }

    public function testNamespaceExclusionPassesThroughNullNamespace(): void
    {
        $fileViolation = $this->createViolation('rule1');
        $rule = $this->createRule('rule1', [$fileViolation]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = new RuleExecutor([$rule], $provider, $registry);

        $violations = $executor->execute($this->createMinimalContext());

        self::assertCount(1, $violations);
    }

    public function testNamespaceExclusionPassesThroughEmptyNamespace(): void
    {
        $globalViolation = $this->createViolationWithNamespace('rule1', '');
        $rule = $this->createRule('rule1', [$globalViolation]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = new RuleExecutor([$rule], $provider, $registry);

        $violations = $executor->execute($this->createMinimalContext());

        self::assertCount(1, $violations);
    }

    public function testNamespaceExclusionDoesNotAffectOtherRules(): void
    {
        $v1 = $this->createViolationWithNamespace('rule1', 'App\\Tests');
        $v2 = $this->createViolationWithNamespace('rule2', 'App\\Tests');

        $rule1 = $this->createRule('rule1', [$v1]);
        $rule2 = $this->createRule('rule2', [$v2]);

        $exclusionProvider = new RuleNamespaceExclusionProvider();
        $exclusionProvider->setExclusions('rule1', ['App\\Tests']);

        $registry = new RuleOptionsRegistry(exclusionProvider: $exclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = new RuleExecutor([$rule1, $rule2], $provider, $registry);

        $violations = $executor->execute($this->createMinimalContext());

        self::assertCount(1, $violations);
        self::assertSame($v2, $violations[0]);
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
        $rule = self::createStub(RuleInterface::class);
        $rule->method('getName')->willReturn($name);
        $rule->method('analyze')->willReturn($violations);
        $rule->method('getCategory')->willReturn($category);

        return $rule;
    }

    private function createMinimalContext(): AnalysisContext
    {
        $repository = self::createStub(\Qualimetrix\Core\Metric\MetricRepositoryInterface::class);
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

    private function createViolationWithNamespace(string $ruleName, string $namespace): Violation
    {
        return new Violation(
            location: new Location(
                file: '/test/file.php',
                line: 1,
            ),
            symbolPath: SymbolPath::forNamespace($namespace),
            ruleName: $ruleName,
            violationCode: $ruleName,
            message: "Violation from $ruleName in $namespace",
            severity: Severity::Warning,
        );
    }

    // --- Path exclusion tests ---

    public function testExcludePathsFiltersViolations(): void
    {
        $excludedViolation = $this->createViolationWithFile('rule1', 'src/Generated/Model.php');
        $includedViolation = $this->createViolationWithFile('rule1', 'src/Core/Service.php');

        $rule = $this->createRule('rule1', [$excludedViolation, $includedViolation]);

        $pathExclusionProvider = new RulePathExclusionProvider();
        $pathExclusionProvider->setExclusions('rule1', ['src/Generated']);

        $registry = new RuleOptionsRegistry(pathExclusionProvider: $pathExclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = new RuleExecutor([$rule], $provider, $registry);

        $violations = $executor->execute($this->createMinimalContext());

        self::assertCount(1, $violations);
        self::assertSame($includedViolation, $violations[0]);
    }

    public function testExcludePathsIsolatedPerRule(): void
    {
        $v1 = $this->createViolationWithFile('rule1', 'src/Generated/Model.php');
        $v2 = $this->createViolationWithFile('rule2', 'src/Generated/Model.php');

        $rule1 = $this->createRule('rule1', [$v1]);
        $rule2 = $this->createRule('rule2', [$v2]);

        $pathExclusionProvider = new RulePathExclusionProvider();
        $pathExclusionProvider->setExclusions('rule1', ['src/Generated']);

        $registry = new RuleOptionsRegistry(pathExclusionProvider: $pathExclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = new RuleExecutor([$rule1, $rule2], $provider, $registry);

        $violations = $executor->execute($this->createMinimalContext());

        self::assertCount(1, $violations);
        self::assertSame($v2, $violations[0]);
    }

    public function testExcludePathsWithEmptyFilePassesThrough(): void
    {
        $violation = $this->createViolationWithFile('rule1', '');

        $rule = $this->createRule('rule1', [$violation]);

        $pathExclusionProvider = new RulePathExclusionProvider();
        $pathExclusionProvider->setExclusions('rule1', ['src/Generated']);

        $registry = new RuleOptionsRegistry(pathExclusionProvider: $pathExclusionProvider);
        $provider = $this->createConfiguredProvider();
        $executor = new RuleExecutor([$rule], $provider, $registry);

        $violations = $executor->execute($this->createMinimalContext());

        self::assertCount(1, $violations);
        self::assertSame($violation, $violations[0]);
    }

    private function createViolationWithFile(string $ruleName, string $file): Violation
    {
        return new Violation(
            location: new Location(
                file: $file,
                line: 1,
            ),
            symbolPath: SymbolPath::forFile($file !== '' ? $file : '/unknown'),
            ruleName: $ruleName,
            violationCode: $ruleName,
            message: "Violation from $ruleName in $file",
            severity: Severity::Warning,
        );
    }
}
