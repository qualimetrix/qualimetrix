<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Design;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Design\GodClassOptions;
use Qualimetrix\Rules\Design\GodClassRule;

#[CoversClass(GodClassRule::class)]
#[CoversClass(GodClassOptions::class)]
final class GodClassRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new GodClassRule(new GodClassOptions());

        self::assertSame('design.god-class', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new GodClassRule(new GodClassOptions());

        self::assertSame('Detects God Classes (overly complex, large, low cohesion)', $rule->getDescription());
    }

    public function testGetCategory(): void
    {
        $rule = new GodClassRule(new GodClassOptions());

        self::assertSame(RuleCategory::Design, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new GodClassRule(new GodClassOptions());

        self::assertSame(['wmc', 'lcom', 'tcc', 'classLoc', 'methodCount', 'isReadonly'], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(GodClassOptions::class, GodClassRule::getOptionsClass());
    }

    public function testGetCliAliases(): void
    {
        self::assertSame(
            [
                'god-class-wmc-threshold' => 'wmcThreshold',
                'god-class-lcom-threshold' => 'lcomThreshold',
                'god-class-tcc-threshold' => 'tccThreshold',
                'god-class-class-loc-threshold' => 'classLocThreshold',
                'god-class-min-criteria' => 'minCriteria',
                'god-class-min-methods' => 'minMethods',
                'god-class-exclude-readonly' => 'excludeReadonly',
            ],
            GodClassRule::getCliAliases(),
        );
    }

    public function testAnalyzeDisabledReturnsEmpty(): void
    {
        $rule = new GodClassRule(new GodClassOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testReadonlySkipped(): void
    {
        $rule = new GodClassRule(new GodClassOptions(excludeReadonly: true));

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())
            ->with('wmc', 50)
            ->with('lcom', 4)
            ->with('tcc', 0.1)
            ->with('classLoc', 350)
            ->with('methodCount', 10)
            ->with('isReadonly', 1);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Class_ ? [$classInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testMinMethodsFilter(): void
    {
        $rule = new GodClassRule(new GodClassOptions(minMethods: 3));

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())
            ->with('wmc', 50)
            ->with('lcom', 4)
            ->with('tcc', 0.1)
            ->with('classLoc', 350)
            ->with('methodCount', 2)
            ->with('isReadonly', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Class_ ? [$classInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testAllFourCriteriaMet(): void
    {
        $rule = new GodClassRule(new GodClassOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())
            ->with('wmc', 50)
            ->with('lcom', 4)
            ->with('tcc', 0.1)
            ->with('classLoc', 350)
            ->with('methodCount', 10)
            ->with('isReadonly', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Class_ ? [$classInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(4, $violations[0]->metricValue);
        self::assertSame('design.god-class', $violations[0]->ruleName);
        self::assertSame('design.god-class', $violations[0]->violationCode);
    }

    public function testThreeOfFourCriteriaMet(): void
    {
        $rule = new GodClassRule(new GodClassOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // TCC = 0.2 < 0.33, so TCC criterion matched; LCOM not vetoed (TCC < 0.5)
        // WMC matched, LCOM matched, TCC matched, LOC not matched → 3/4
        $metricBag = (new MetricBag())
            ->with('wmc', 50)
            ->with('lcom', 4)
            ->with('tcc', 0.2)
            ->with('classLoc', 100)
            ->with('methodCount', 10)
            ->with('isReadonly', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Class_ ? [$classInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame(3, $violations[0]->metricValue);
    }

    public function testTwoOfFourCriteriaMet(): void
    {
        $rule = new GodClassRule(new GodClassOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // TCC = 0.5 (not matched + vetoes LCOM), classLoc = 100 (not matched) → only WMC matched
        $metricBag = (new MetricBag())
            ->with('wmc', 50)
            ->with('lcom', 4)
            ->with('tcc', 0.5)
            ->with('classLoc', 100)
            ->with('methodCount', 10)
            ->with('isReadonly', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Class_ ? [$classInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testHighTccVetoesLcomCriterion(): void
    {
        $rule = new GodClassRule(new GodClassOptions());

        $symbolPath = SymbolPath::forClass('App\Printer', 'Standard');
        $classInfo = new SymbolInfo($symbolPath, 'src/Printer/Standard.php', 10);

        // WMC=400 (matched), LCOM=100 (vetoed — excluded from evaluable), TCC=0.8 (not matched), LOC=1500 (matched)
        // evaluableCount=3 (WMC + TCC + LOC), matchedCount=2 (WMC + LOC) → not a god class
        $metricBag = (new MetricBag())
            ->with('wmc', 400)
            ->with('lcom', 100)
            ->with('tcc', 0.8)
            ->with('classLoc', 1500)
            ->with('methodCount', 50)
            ->with('isReadonly', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Class_ ? [$classInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testTccVetoThresholdExactlyHalf(): void
    {
        $rule = new GodClassRule(new GodClassOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // TCC = 0.5 exactly → vetoes LCOM (excluded from evaluable)
        // evaluableCount=3 (WMC + TCC + LOC), matchedCount=2 (WMC + LOC) → 2/3
        $metricBag = (new MetricBag())
            ->with('wmc', 50)
            ->with('lcom', 4)
            ->with('tcc', 0.5)
            ->with('classLoc', 350)
            ->with('methodCount', 10)
            ->with('isReadonly', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Class_ ? [$classInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testTccBelowVetoThresholdDoesNotVetoLcom(): void
    {
        $rule = new GodClassRule(new GodClassOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // TCC = 0.49 < 0.5 → does NOT veto LCOM
        // WMC matched, LCOM matched, TCC not matched (0.49 >= 0.33), LOC matched → 3/4
        $metricBag = (new MetricBag())
            ->with('wmc', 50)
            ->with('lcom', 4)
            ->with('tcc', 0.49)
            ->with('classLoc', 350)
            ->with('methodCount', 10)
            ->with('isReadonly', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Class_ ? [$classInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame(3, $violations[0]->metricValue);
    }

    public function testMissingTccAdjustsEvaluableCount(): void
    {
        $rule = new GodClassRule(new GodClassOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // No TCC metric — 3 evaluable, all 3 matched → Error
        $metricBag = (new MetricBag())
            ->with('wmc', 50)
            ->with('lcom', 4)
            ->with('classLoc', 350)
            ->with('methodCount', 10)
            ->with('isReadonly', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Class_ ? [$classInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(3, $violations[0]->metricValue);
    }

    public function testMissingTccAndLcom(): void
    {
        $rule = new GodClassRule(new GodClassOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // Only WMC and classLoc — 2 evaluable, minCriteria=3 → no violation
        $metricBag = (new MetricBag())
            ->with('wmc', 50)
            ->with('classLoc', 350)
            ->with('methodCount', 10)
            ->with('isReadonly', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Class_ ? [$classInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testCustomThresholds(): void
    {
        $rule = new GodClassRule(new GodClassOptions(
            wmcThreshold: 20,
            lcomThreshold: 2,
            tccThreshold: 0.5,
            classLocThreshold: 100,
        ));

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())
            ->with('wmc', 25)
            ->with('lcom', 3)
            ->with('tcc', 0.2)
            ->with('classLoc', 150)
            ->with('methodCount', 5)
            ->with('isReadonly', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Class_ ? [$classInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    public function testMessageListsMatchedCriteria(): void
    {
        $rule = new GodClassRule(new GodClassOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())
            ->with('wmc', 50)
            ->with('lcom', 4)
            ->with('tcc', 0.1)
            ->with('classLoc', 350)
            ->with('methodCount', 10)
            ->with('isReadonly', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => $type === SymbolType::Class_ ? [$classInfo] : []);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertStringContainsString('high WMC (50 >= 47)', $violations[0]->message);
        self::assertStringContainsString('high LCOM (4 >= 3)', $violations[0]->message);
        self::assertStringContainsString('low TCC (0.10 < 0.33)', $violations[0]->message);
        self::assertStringContainsString('large size (350 >= 300 LOC)', $violations[0]->message);
        self::assertStringContainsString('4/4 criteria', $violations[0]->message);
    }

    public function testOptionsFromArrayDefaults(): void
    {
        $options = GodClassOptions::fromArray(['enabled' => true]);

        self::assertTrue($options->isEnabled());
        self::assertSame(47, $options->wmcThreshold);
        self::assertSame(3, $options->lcomThreshold);
        self::assertSame(0.33, $options->tccThreshold);
        self::assertSame(300, $options->classLocThreshold);
        self::assertSame(3, $options->minCriteria);
        self::assertSame(3, $options->minMethods);
        self::assertTrue($options->excludeReadonly);
    }

    public function testOptionsFromArrayCustomValues(): void
    {
        $options = GodClassOptions::fromArray([
            'wmc_threshold' => 30,
            'lcom_threshold' => 5,
            'tcc_threshold' => 0.25,
            'class_loc_threshold' => 200,
            'min_criteria' => 2,
            'min_methods' => 5,
            'exclude_readonly' => false,
        ]);

        self::assertTrue($options->isEnabled());
        self::assertSame(30, $options->wmcThreshold);
        self::assertSame(5, $options->lcomThreshold);
        self::assertSame(0.25, $options->tccThreshold);
        self::assertSame(200, $options->classLocThreshold);
        self::assertSame(2, $options->minCriteria);
        self::assertSame(5, $options->minMethods);
        self::assertFalse($options->excludeReadonly);
    }

    public function testOptionsFromArrayDualKey(): void
    {
        $options = GodClassOptions::fromArray([
            'wmcThreshold' => 30,
            'lcomThreshold' => 5,
            'tccThreshold' => 0.25,
            'classLocThreshold' => 200,
            'minCriteria' => 2,
            'minMethods' => 5,
            'excludeReadonly' => false,
        ]);

        self::assertSame(30, $options->wmcThreshold);
        self::assertSame(5, $options->lcomThreshold);
        self::assertSame(0.25, $options->tccThreshold);
        self::assertSame(200, $options->classLocThreshold);
        self::assertSame(2, $options->minCriteria);
        self::assertSame(5, $options->minMethods);
        self::assertFalse($options->excludeReadonly);
    }

    public function testOptionsFromEmptyArrayDisabled(): void
    {
        $options = GodClassOptions::fromArray([]);

        self::assertFalse($options->isEnabled());
    }
}
