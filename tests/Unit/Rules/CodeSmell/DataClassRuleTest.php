<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\CodeSmell;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Rules\CodeSmell\DataClassOptions;
use AiMessDetector\Rules\CodeSmell\DataClassRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DataClassRule::class)]
#[CoversClass(DataClassOptions::class)]
final class DataClassRuleTest extends TestCase
{
    /**
     * Creates a standard metric bag for a concrete class with properties.
     * Override individual metrics as needed.
     *
     * @param array<string, int|null> $overrides
     */
    private function makeMetricBag(array $overrides = []): MetricBag
    {
        $defaults = [
            'woc' => 90,
            'wmc' => 5,
            'methodCount' => 10,
            'propertyCount' => 3,
            'isReadonly' => 0,
            'isPromotedPropertiesOnly' => 0,
            'isDataClass' => 0,
            'isAbstract' => 0,
            'isInterface' => 0,
            'isException' => 0,
        ];

        $values = array_merge($defaults, $overrides);

        $bag = new MetricBag();

        foreach ($values as $key => $value) {
            if ($value !== null) {
                $bag = $bag->with($key, $value);
            }
        }

        return $bag;
    }

    public function testGetName(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        self::assertSame('code-smell.data-class', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        self::assertSame(
            'Detects classes with high public surface but low complexity (Data Classes)',
            $rule->getDescription(),
        );
    }

    public function testGetCategory(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        self::assertSame(RuleCategory::CodeSmell, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        self::assertSame(
            ['woc', 'wmc', 'methodCount', 'propertyCount', 'isReadonly', 'isPromotedPropertiesOnly', 'isDataClass', 'isAbstract', 'isInterface', 'isException'],
            $rule->requires(),
        );
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(
            DataClassOptions::class,
            DataClassRule::getOptionsClass(),
        );
    }

    public function testGetCliAliases(): void
    {
        $aliases = DataClassRule::getCliAliases();

        self::assertArrayHasKey('data-class-woc-threshold', $aliases);
        self::assertArrayHasKey('data-class-wmc-threshold', $aliases);
        self::assertArrayHasKey('data-class-min-methods', $aliases);
        self::assertArrayHasKey('data-class-exclude-readonly', $aliases);
        self::assertArrayHasKey('data-class-exclude-promoted-only', $aliases);
        self::assertArrayHasKey('data-class-exclude-exceptions', $aliases);
        self::assertSame('wocThreshold', $aliases['data-class-woc-threshold']);
        self::assertSame('wmcThreshold', $aliases['data-class-wmc-threshold']);
        self::assertSame('minMethods', $aliases['data-class-min-methods']);
        self::assertSame('excludeReadonly', $aliases['data-class-exclude-readonly']);
        self::assertSame('excludePromotedOnly', $aliases['data-class-exclude-promoted-only']);
        self::assertSame('excludeExceptions', $aliases['data-class-exclude-exceptions']);
    }

    public function testAnalyzeDisabledReturnsEmpty(): void
    {
        $rule = new DataClassRule(new DataClassOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testMinMethodsFilter(): void
    {
        $rule = new DataClassRule(new DataClassOptions(minMethods: 3));

        $symbolPath = SymbolPath::forClass('App\Service', 'SmallClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/SmallClass.php', 10);

        $metricBag = $this->makeMetricBag(['methodCount' => 2]);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$classInfo]);
        $repository->method('get')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(0, $rule->analyze($context));
    }

    public function testReadonlySkipped(): void
    {
        $rule = new DataClassRule(new DataClassOptions(excludeReadonly: true));

        $symbolPath = SymbolPath::forClass('App\Dto', 'ReadonlyDto');
        $classInfo = new SymbolInfo($symbolPath, 'src/Dto/ReadonlyDto.php', 5);

        $metricBag = $this->makeMetricBag(['isReadonly' => 1]);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$classInfo]);
        $repository->method('get')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(0, $rule->analyze($context));
    }

    public function testReadonlyNotSkippedWhenOptionFalse(): void
    {
        $rule = new DataClassRule(new DataClassOptions(excludeReadonly: false));

        $symbolPath = SymbolPath::forClass('App\Dto', 'ReadonlyDto');
        $classInfo = new SymbolInfo($symbolPath, 'src/Dto/ReadonlyDto.php', 5);

        $metricBag = $this->makeMetricBag(['isReadonly' => 1]);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$classInfo]);
        $repository->method('get')->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
    }

    public function testPromotedOnlySkipped(): void
    {
        $rule = new DataClassRule(new DataClassOptions(excludePromotedOnly: true));

        $symbolPath = SymbolPath::forClass('App\Dto', 'PromotedDto');
        $classInfo = new SymbolInfo($symbolPath, 'src/Dto/PromotedDto.php', 5);

        $metricBag = $this->makeMetricBag(['isPromotedPropertiesOnly' => 1]);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$classInfo]);
        $repository->method('get')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(0, $rule->analyze($context));
    }

    public function testPromotedOnlyNotSkippedWhenOptionFalse(): void
    {
        $rule = new DataClassRule(new DataClassOptions(excludePromotedOnly: false));

        $symbolPath = SymbolPath::forClass('App\Dto', 'PromotedDto');
        $classInfo = new SymbolInfo($symbolPath, 'src/Dto/PromotedDto.php', 5);

        $metricBag = $this->makeMetricBag(['isPromotedPropertiesOnly' => 1]);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$classInfo]);
        $repository->method('get')->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
    }

    public function testIsDataClassSkipped(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        $symbolPath = SymbolPath::forClass('App\Dto', 'PureDto');
        $classInfo = new SymbolInfo($symbolPath, 'src/Dto/PureDto.php', 5);

        $metricBag = $this->makeMetricBag(['isDataClass' => 1]);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$classInfo]);
        $repository->method('get')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(0, $rule->analyze($context));
    }

    public function testHighWocLowWmc(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = $this->makeMetricBag();

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$classInfo]);
        $repository->method('get')->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertStringContainsString('WOC=90%', $violations[0]->message);
        self::assertStringContainsString('threshold 80%', $violations[0]->message);
        self::assertStringContainsString('WMC=5', $violations[0]->message);
        self::assertStringContainsString('threshold 10', $violations[0]->message);
        self::assertSame(90, $violations[0]->metricValue);
        self::assertSame('code-smell.data-class', $violations[0]->ruleName);
        self::assertSame('code-smell.data-class', $violations[0]->violationCode);
    }

    public function testLowWoc(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'GoodClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/GoodClass.php', 10);

        $metricBag = $this->makeMetricBag(['woc' => 50]);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$classInfo]);
        $repository->method('get')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(0, $rule->analyze($context));
    }

    public function testHighWmc(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'ComplexClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/ComplexClass.php', 10);

        $metricBag = $this->makeMetricBag(['wmc' => 15]);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$classInfo]);
        $repository->method('get')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(0, $rule->analyze($context));
    }

    public function testNullWocSkipped(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'NoWocClass');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/NoWocClass.php', 10);

        // Omit 'woc' key entirely to get null
        $metricBag = (new MetricBag())
            ->with('wmc', 5)
            ->with('methodCount', 10)
            ->with('propertyCount', 3)
            ->with('isReadonly', 0)
            ->with('isPromotedPropertiesOnly', 0)
            ->with('isDataClass', 0)
            ->with('isAbstract', 0)
            ->with('isInterface', 0)
            ->with('isException', 0);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$classInfo]);
        $repository->method('get')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(0, $rule->analyze($context));
    }

    // --- New tests for false positive reduction ---

    public function testInterfaceSkipped(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        $symbolPath = SymbolPath::forClass('App\Contract', 'NodeVisitor');
        $classInfo = new SymbolInfo($symbolPath, 'src/Contract/NodeVisitor.php', 5);

        $metricBag = $this->makeMetricBag(['isInterface' => 1]);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$classInfo]);
        $repository->method('get')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(0, $rule->analyze($context));
    }

    public function testAbstractClassSkipped(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        $symbolPath = SymbolPath::forClass('App\Base', 'AbstractHandler');
        $classInfo = new SymbolInfo($symbolPath, 'src/Base/AbstractHandler.php', 5);

        $metricBag = $this->makeMetricBag(['isAbstract' => 1]);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$classInfo]);
        $repository->method('get')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(0, $rule->analyze($context));
    }

    public function testZeroPropertyClassSkipped(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'StatelessService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/StatelessService.php', 5);

        $metricBag = $this->makeMetricBag(['propertyCount' => 0]);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$classInfo]);
        $repository->method('get')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(0, $rule->analyze($context));
    }

    public function testExceptionClassSkipped(): void
    {
        $rule = new DataClassRule(new DataClassOptions(excludeExceptions: true));

        $symbolPath = SymbolPath::forClass('App\Exception', 'FileNotFoundException');
        $classInfo = new SymbolInfo($symbolPath, 'src/Exception/FileNotFoundException.php', 5);

        $metricBag = $this->makeMetricBag(['isException' => 1]);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$classInfo]);
        $repository->method('get')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(0, $rule->analyze($context));
    }

    public function testExceptionClassNotSkippedWhenOptionFalse(): void
    {
        $rule = new DataClassRule(new DataClassOptions(excludeExceptions: false));

        $symbolPath = SymbolPath::forClass('App\Exception', 'FileNotFoundException');
        $classInfo = new SymbolInfo($symbolPath, 'src/Exception/FileNotFoundException.php', 5);

        $metricBag = $this->makeMetricBag(['isException' => 1]);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$classInfo]);
        $repository->method('get')->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
    }

    // --- Options tests ---

    public function testOptionsFromArrayDefaults(): void
    {
        $options = new DataClassOptions();

        self::assertTrue($options->enabled);
        self::assertSame(80, $options->wocThreshold);
        self::assertSame(10, $options->wmcThreshold);
        self::assertSame(3, $options->minMethods);
        self::assertTrue($options->excludeReadonly);
        self::assertTrue($options->excludePromotedOnly);
        self::assertTrue($options->excludeExceptions);
    }

    public function testOptionsFromArrayCustomValues(): void
    {
        $options = DataClassOptions::fromArray([
            'enabled' => true,
            'woc_threshold' => 70,
            'wmc_threshold' => 15,
            'min_methods' => 5,
            'exclude_readonly' => false,
            'exclude_promoted_only' => false,
            'exclude_exceptions' => false,
        ]);

        self::assertTrue($options->enabled);
        self::assertSame(70, $options->wocThreshold);
        self::assertSame(15, $options->wmcThreshold);
        self::assertSame(5, $options->minMethods);
        self::assertFalse($options->excludeReadonly);
        self::assertFalse($options->excludePromotedOnly);
        self::assertFalse($options->excludeExceptions);
    }

    public function testOptionsFromArrayDualKey(): void
    {
        $options = DataClassOptions::fromArray([
            'wocThreshold' => 75,
            'wmcThreshold' => 12,
            'minMethods' => 4,
            'excludeReadonly' => false,
            'excludePromotedOnly' => false,
            'excludeExceptions' => false,
        ]);

        self::assertSame(75, $options->wocThreshold);
        self::assertSame(12, $options->wmcThreshold);
        self::assertSame(4, $options->minMethods);
        self::assertFalse($options->excludeReadonly);
        self::assertFalse($options->excludePromotedOnly);
        self::assertFalse($options->excludeExceptions);
    }

    public function testOptionsFromEmptyArrayDisabled(): void
    {
        $options = DataClassOptions::fromArray([]);

        self::assertFalse($options->enabled);
    }
}
