<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Core\Suppression\ThresholdOverride;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\CodeSmell\LongParameterListOptions;
use Qualimetrix\Rules\Complexity\ClassNpathComplexityOptions;
use Qualimetrix\Rules\Complexity\ComplexityOptions;
use Qualimetrix\Rules\Complexity\ComplexityRule;
use Qualimetrix\Rules\Complexity\MethodComplexityOptions;
use Qualimetrix\Rules\Coupling\ClassCboOptions;
use Qualimetrix\Rules\Coupling\DistanceOptions;
use Qualimetrix\Rules\Coupling\NamespaceInstabilityOptions;
use Qualimetrix\Rules\Duplication\CodeDuplicationOptions;
use Qualimetrix\Rules\Maintainability\MaintainabilityOptions;
use Qualimetrix\Rules\Size\MethodCountOptions;
use Qualimetrix\Rules\Size\MethodCountRule;
use Qualimetrix\Rules\Size\PropertyCountOptions;
use Qualimetrix\Rules\Structure\LcomOptions;
use Qualimetrix\Rules\Structure\WmcOptions;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;

/**
 * Integration tests for @qmx-threshold overrides applied to rules.
 */
#[CoversClass(MethodCountRule::class)]
#[CoversClass(ComplexityRule::class)]
#[CoversClass(MethodComplexityOptions::class)]
#[CoversClass(MethodCountOptions::class)]
#[CoversClass(LongParameterListOptions::class)]
#[CoversClass(ClassNpathComplexityOptions::class)]
#[CoversClass(ClassCboOptions::class)]
#[CoversClass(DistanceOptions::class)]
#[CoversClass(NamespaceInstabilityOptions::class)]
#[CoversClass(CodeDuplicationOptions::class)]
#[CoversClass(MaintainabilityOptions::class)]
#[CoversClass(PropertyCountOptions::class)]
#[CoversClass(LcomOptions::class)]
#[CoversClass(WmcOptions::class)]
final class ThresholdOverrideIntegrationTest extends TestCase
{
    public function testWithOverrideOnMethodComplexityOptions(): void
    {
        $options = new MethodComplexityOptions(warning: 10, error: 20);

        $overridden = $options->withOverride(15, 25);

        self::assertSame(15, $overridden->warning);
        self::assertSame(25, $overridden->error);
    }

    public function testWithOverridePreservesNullValues(): void
    {
        $options = new MethodComplexityOptions(warning: 10, error: 20);

        // Override only warning
        $overridden = $options->withOverride(15, null);
        self::assertSame(15, $overridden->warning);
        self::assertSame(20, $overridden->error);

        // Override only error
        $overridden = $options->withOverride(null, 30);
        self::assertSame(10, $overridden->warning);
        self::assertSame(30, $overridden->error);
    }

    public function testWithOverrideOnMethodCountOptions(): void
    {
        $options = new MethodCountOptions(warning: 20, error: 30);

        $overridden = $options->withOverride(25, 40);

        self::assertSame(25, $overridden->warning);
        self::assertSame(40, $overridden->error);

        // Severity should use new thresholds
        self::assertNull($overridden->getSeverity(24));
        self::assertSame(Severity::Warning, $overridden->getSeverity(25));
        self::assertSame(Severity::Error, $overridden->getSeverity(40));
    }

    public function testMethodCountRuleUsesOverriddenThreshold(): void
    {
        $symbolPath = SymbolPath::forClass('App\\Service', 'BigService');
        $symbolInfo = new SymbolInfo($symbolPath, 'src/Service/BigService.php', 10);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$symbolInfo]);
        $repository->method('get')->willReturn(
            MetricBag::fromArray([MetricName::STRUCTURE_METHOD_COUNT => 25]),
        );

        // Default thresholds: warning=20, error=30
        // Value 25 would trigger warning with defaults
        $rule = new MethodCountRule(new MethodCountOptions(warning: 20, error: 30));

        // Without override — should have warning
        $contextNoOverride = new AnalysisContext(metrics: $repository);
        $violationsNoOverride = $rule->analyze($contextNoOverride);
        self::assertCount(1, $violationsNoOverride);
        self::assertSame(Severity::Warning, $violationsNoOverride[0]->severity);

        // With override raising warning to 30 — no violation
        $contextWithOverride = new AnalysisContext(
            metrics: $repository,
            thresholdOverrides: [
                'src/Service/BigService.php' => [
                    new ThresholdOverride('size.method-count', 30, 40, 1, 100),
                ],
            ],
        );
        $violationsWithOverride = $rule->analyze($contextWithOverride);
        self::assertCount(0, $violationsWithOverride);
    }

    public function testClassLevelOverrideAppliesToMethodsInClass(): void
    {
        // Class-level annotation scope: line 10-50
        // Method at line 20 falls within scope
        $methodPath = SymbolPath::forMethod('App\\Service', 'BigService', 'doStuff');
        $methodInfo = new SymbolInfo($methodPath, 'src/Service/BigService.php', 20);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturnCallback(function (SymbolType $type) use ($methodInfo) {
            return match ($type) {
                SymbolType::Method => [$methodInfo],
                default => [],
            };
        });
        $repository->method('get')->willReturn(
            MetricBag::fromArray([MetricName::COMPLEXITY_CCN => 15, MetricName::COMPLEXITY_COGNITIVE => 5]),
        );

        $options = new ComplexityOptions(
            method: new MethodComplexityOptions(warning: 10, error: 20),
        );
        $rule = new ComplexityRule($options);

        // Without override — CCN 15 exceeds warning=10
        $contextNoOverride = new AnalysisContext(metrics: $repository);
        $violationsNoOverride = $rule->analyze($contextNoOverride);
        self::assertCount(1, $violationsNoOverride);
        self::assertSame(Severity::Warning, $violationsNoOverride[0]->severity);

        // With class-level override (line 10-50) raising warning to 20
        $contextWithOverride = new AnalysisContext(
            metrics: $repository,
            thresholdOverrides: [
                'src/Service/BigService.php' => [
                    new ThresholdOverride('complexity.cyclomatic', 20, 30, 10, 50),
                ],
            ],
        );
        $violationsWithOverride = $rule->analyze($contextWithOverride);
        self::assertCount(0, $violationsWithOverride);
    }

    public function testMethodLevelOverrideOnlyAppliesToSpecificMethod(): void
    {
        $method1Path = SymbolPath::forMethod('App\\Service', 'Service', 'complexMethod');
        $method1Info = new SymbolInfo($method1Path, 'src/Service/Service.php', 20);

        $method2Path = SymbolPath::forMethod('App\\Service', 'Service', 'otherMethod');
        $method2Info = new SymbolInfo($method2Path, 'src/Service/Service.php', 60);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturnCallback(function (SymbolType $type) use ($method1Info, $method2Info) {
            return match ($type) {
                SymbolType::Method => [$method1Info, $method2Info],
                default => [],
            };
        });
        $repository->method('get')->willReturn(
            MetricBag::fromArray([MetricName::COMPLEXITY_CCN => 15, MetricName::COMPLEXITY_COGNITIVE => 5]),
        );

        $options = new ComplexityOptions(
            method: new MethodComplexityOptions(warning: 10, error: 20),
        );
        $rule = new ComplexityRule($options);

        // Method-level override for complexMethod only (line 18-40)
        $context = new AnalysisContext(
            metrics: $repository,
            thresholdOverrides: [
                'src/Service/Service.php' => [
                    new ThresholdOverride('complexity.cyclomatic', 20, 30, 18, 40),
                ],
            ],
        );

        $violations = $rule->analyze($context);

        // complexMethod (line 20) is within override scope — no violation (15 < 20)
        // otherMethod (line 60) is outside override scope — has violation (15 >= 10)
        self::assertCount(1, $violations);
        self::assertSame('otherMethod', $violations[0]->symbolPath->member);
    }

    /**
     * Regression: withOverride() must preserve non-threshold fields.
     *
     * Previously all Options classes constructed new static() passing only
     * warning/error values, silently resetting enabled, scope, minClassCount,
     * excludeReadonly, voWarning/voError, etc. to defaults.
     */
    public function testWithOverridePreservesNonThresholdFields(): void
    {
        // LongParameterListOptions — has voWarning/voError
        $lpl = new LongParameterListOptions(
            enabled: false,
            warning: 5,
            error: 8,
            voWarning: 10,
            voError: 16,
        );
        $lplOverridden = $lpl->withOverride(6, 9);
        self::assertFalse($lplOverridden->enabled, 'LPL: enabled must be preserved');
        self::assertSame(6, $lplOverridden->warning);
        self::assertSame(9, $lplOverridden->error);
        self::assertSame(10, $lplOverridden->voWarning, 'LPL: voWarning must be preserved');
        self::assertSame(16, $lplOverridden->voError, 'LPL: voError must be preserved');

        // MaintainabilityOptions — has excludeTests, minLoc
        $mi = new MaintainabilityOptions(
            enabled: false,
            warning: 50.0,
            error: 25.0,
            excludeTests: false,
            minLoc: 20,
        );
        $miOverridden = $mi->withOverride(45.0, 15.0);
        self::assertFalse($miOverridden->enabled, 'MI: enabled must be preserved');
        self::assertSame(45.0, $miOverridden->warning);
        self::assertSame(15.0, $miOverridden->error);
        self::assertFalse($miOverridden->excludeTests, 'MI: excludeTests must be preserved');
        self::assertSame(20, $miOverridden->minLoc, 'MI: minLoc must be preserved');

        // ClassCboOptions — has scope
        $cbo = new ClassCboOptions(
            enabled: false,
            warning: 10,
            error: 15,
            scope: 'application',
        );
        $cboOverridden = $cbo->withOverride(12, 18);
        self::assertFalse($cboOverridden->enabled, 'CBO: enabled must be preserved');
        self::assertSame('application', $cboOverridden->scope, 'CBO: scope must be preserved');

        // DistanceOptions — has includeNamespaces, minClassCount
        $dist = new DistanceOptions(
            enabled: false,
            maxDistanceWarning: 0.4,
            maxDistanceError: 0.6,
            includeNamespaces: ['App\\Domain'],
            minClassCount: 5,
        );
        $distOverridden = $dist->withOverride(0.5, 0.7);
        self::assertFalse($distOverridden->enabled, 'Distance: enabled must be preserved');
        self::assertSame(['App\\Domain'], $distOverridden->includeNamespaces, 'Distance: includeNamespaces must be preserved');
        self::assertSame(5, $distOverridden->minClassCount, 'Distance: minClassCount must be preserved');

        // NamespaceInstabilityOptions — has minClassCount
        $nsi = new NamespaceInstabilityOptions(
            enabled: false,
            maxWarning: 0.7,
            maxError: 0.9,
            minClassCount: 5,
        );
        $nsiOverridden = $nsi->withOverride(0.75, 0.85);
        self::assertFalse($nsiOverridden->enabled, 'NSI: enabled must be preserved');
        self::assertSame(5, $nsiOverridden->minClassCount, 'NSI: minClassCount must be preserved');

        // CodeDuplicationOptions — has min_lines, min_tokens
        $dup = new CodeDuplicationOptions(
            enabled: false,
            min_lines: 10,
            min_tokens: 100,
            warning: 3,
            error: 20,
        );
        $dupOverridden = $dup->withOverride(5, 30);
        self::assertFalse($dupOverridden->enabled, 'Dup: enabled must be preserved');
        self::assertSame(10, $dupOverridden->min_lines, 'Dup: min_lines must be preserved');
        self::assertSame(100, $dupOverridden->min_tokens, 'Dup: min_tokens must be preserved');

        // PropertyCountOptions — has excludeReadonly, excludePromotedOnly
        $prop = new PropertyCountOptions(
            enabled: false,
            warning: 10,
            error: 15,
            excludeReadonly: false,
            excludePromotedOnly: false,
        );
        $propOverridden = $prop->withOverride(12, 18);
        self::assertFalse($propOverridden->enabled, 'Prop: enabled must be preserved');
        self::assertFalse($propOverridden->excludeReadonly, 'Prop: excludeReadonly must be preserved');
        self::assertFalse($propOverridden->excludePromotedOnly, 'Prop: excludePromotedOnly must be preserved');

        // LcomOptions — has excludeReadonly, minMethods
        $lcom = new LcomOptions(
            enabled: false,
            warning: 4,
            error: 6,
            excludeReadonly: false,
            minMethods: 5,
        );
        $lcomOverridden = $lcom->withOverride(5, 8);
        self::assertFalse($lcomOverridden->enabled, 'LCOM: enabled must be preserved');
        self::assertFalse($lcomOverridden->excludeReadonly, 'LCOM: excludeReadonly must be preserved');
        self::assertSame(5, $lcomOverridden->minMethods, 'LCOM: minMethods must be preserved');

        // WmcOptions — has excludeDataClasses
        $wmc = new WmcOptions(
            enabled: false,
            warning: 40,
            error: 70,
            excludeDataClasses: true,
        );
        $wmcOverridden = $wmc->withOverride(45, 75);
        self::assertFalse($wmcOverridden->enabled, 'WMC: enabled must be preserved');
        self::assertTrue($wmcOverridden->excludeDataClasses, 'WMC: excludeDataClasses must be preserved');

        // ClassNpathComplexityOptions — has enabled (default false)
        $npath = new ClassNpathComplexityOptions(
            enabled: true,
            maxWarning: 300,
            maxError: 800,
        );
        $npathOverridden = $npath->withOverride(400, 900);
        self::assertTrue($npathOverridden->enabled, 'NPath: enabled must be preserved');
    }

    /**
     * Reflection-based safety net: for every Options class implementing ThresholdAwareOptionsInterface,
     * verifies that withOverride() preserves all non-threshold constructor properties.
     *
     * This catches the "forgotten field" bug automatically when new properties are added
     * to any Options class without updating its withOverride() method.
     */
    public function testAllThresholdAwareOptionsPreserveFieldsViaReflection(): void
    {
        $optionsDir = \dirname(__DIR__, 3) . '/src/Rules';
        $optionsFiles = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($optionsDir, FilesystemIterator::SKIP_DOTS),
        );

        $testedClasses = 0;

        foreach ($optionsFiles as $file) {
            if (!str_ends_with($file->getFilename(), 'Options.php')) {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            \assert($content !== false);

            if (preg_match('/^namespace\s+([\w\\\\]+);/m', $content, $nsMatch)
                && preg_match('/^final\s+readonly\s+class\s+(\w+)/m', $content, $classMatch)) {
                $fqcn = $nsMatch[1] . '\\' . $classMatch[1];

                if (!class_exists($fqcn)) {
                    continue;
                }

                $reflection = new ReflectionClass($fqcn);

                if ($reflection->isAbstract() || !$reflection->implementsInterface(ThresholdAwareOptionsInterface::class)) {
                    continue;
                }

                $this->assertWithOverridePreservesAllProperties($reflection); // @phpstan-ignore argument.type
                ++$testedClasses;
            }
        }

        // Sanity: we should have tested all 24 classes
        self::assertGreaterThanOrEqual(24, $testedClasses, 'Expected at least 24 ThresholdAwareOptions classes');
    }

    /**
     * @param ReflectionClass<ThresholdAwareOptionsInterface> $reflection
     */
    private function assertWithOverridePreservesAllProperties(ReflectionClass $reflection): void
    {
        $className = $reflection->getShortName();
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor, "{$className}: must have a constructor");

        // Build an instance with non-default values for every property
        $args = [];

        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';
            $args[$param->getName()] = $this->generateNonDefaultValue($typeName, $param);
        }

        $instance = $reflection->newInstanceArgs($args);
        \assert($instance instanceof ThresholdAwareOptionsInterface);

        // Call withOverride with null (should preserve everything)
        $overridden = $instance->withOverride(null, null);

        // Every public property should be identical
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            self::assertSame(
                $prop->getValue($instance),
                $prop->getValue($overridden),
                "{$className}::\${$prop->getName()} must be preserved by withOverride(null, null)",
            );
        }
    }

    /**
     * Generates a non-default value for a constructor parameter to detect if withOverride() loses it.
     */
    private function generateNonDefaultValue(string $typeName, ReflectionParameter $param): mixed
    {
        // Use a value different from the default
        $default = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;

        return match ($typeName) {
            'bool' => $default !== false ? false : true,
            'int' => $default !== 99 ? 99 : 98,
            'float' => $default !== 99.9 ? 99.9 : 98.8,
            'string' => $default !== 'test_override' ? 'test_override' : 'test_other',
            'array' => $default !== ['test_ns'] ? ['test_ns'] : ['other_ns'],
            default => $param->allowsNull() ? null : throw new RuntimeException(
                "Cannot generate test value for {$param->getName()} of type {$typeName}",
            ),
        };
    }

    public function testViolationMessageContainsOverriddenThreshold(): void
    {
        $symbolPath = SymbolPath::forClass('App\\Service', 'BigService');
        $symbolInfo = new SymbolInfo($symbolPath, 'src/Service/BigService.php', 10);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$symbolInfo]);
        $repository->method('get')->willReturn(
            MetricBag::fromArray([MetricName::STRUCTURE_METHOD_COUNT => 35]),
        );

        // Default thresholds: warning=20, error=30
        // Override raises warning to 40, error to 50
        $rule = new MethodCountRule(new MethodCountOptions(warning: 20, error: 30));

        $context = new AnalysisContext(
            metrics: $repository,
            thresholdOverrides: [
                'src/Service/BigService.php' => [
                    new ThresholdOverride('size.method-count', 40, 50, 1, 100),
                ],
            ],
        );

        // Value 35 < overridden warning 40 — no violation
        $violations = $rule->analyze($context);
        self::assertCount(0, $violations);

        // Now override only raises warning to 30 (value 35 >= 30 = warning)
        $contextLower = new AnalysisContext(
            metrics: $repository,
            thresholdOverrides: [
                'src/Service/BigService.php' => [
                    new ThresholdOverride('size.method-count', 30, 50, 1, 100),
                ],
            ],
        );

        $violations = $rule->analyze($contextLower);
        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        // Violation message and threshold must reflect the overridden value (30), not the default (20)
        self::assertSame(30, $violations[0]->threshold);
        self::assertStringContainsString('threshold of 30', $violations[0]->message);
    }

    public function testMethodLevelOverridePriorityInRule(): void
    {
        $symbolPath = SymbolPath::forClass('App\\Service', 'BigService');
        $symbolInfo = new SymbolInfo($symbolPath, 'src/Service/BigService.php', 20);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$symbolInfo]);
        $repository->method('get')->willReturn(
            MetricBag::fromArray([MetricName::STRUCTURE_METHOD_COUNT => 25]),
        );

        $rule = new MethodCountRule(new MethodCountOptions(warning: 20, error: 30));

        // Class-level override (line 10-100): warning=30 (would suppress violation)
        // Method-level override (line 15-40): warning=22 (should still trigger)
        $context = new AnalysisContext(
            metrics: $repository,
            thresholdOverrides: [
                'src/Service/BigService.php' => [
                    new ThresholdOverride('size.method-count', 30, 50, 10, 100),
                    new ThresholdOverride('size.method-count', 22, 50, 15, 40),
                ],
            ],
        );

        // Method-level override (narrower span) should win: warning=22, value=25 >= 22 -> violation
        $violations = $rule->analyze($context);
        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame(22, $violations[0]->threshold);
    }

    public function testPrefixOverrideAppliesToMultipleRules(): void
    {
        $options = new MethodComplexityOptions(warning: 10, error: 20);

        self::assertInstanceOf(ThresholdAwareOptionsInterface::class, $options);

        // A 'complexity' prefix override should match 'complexity.cyclomatic'
        $override = new ThresholdOverride('complexity', 20, 30, 1, null);
        self::assertTrue($override->matches('complexity.cyclomatic'));
        self::assertTrue($override->matches('complexity.cognitive'));
        self::assertFalse($override->matches('coupling.cbo'));
    }
}
