<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Size\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Size\PropertyCountOptions;
use Qualimetrix\Analysis\Evidence\Size\PropertyCountRule;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(PropertyCountRule::class)]
#[CoversClass(PropertyCountOptions::class)]
final class PropertyCountRuleTest extends TestCase
{
    #[Test]
    public function itGetsName(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions());
        self::assertSame('size.property-count', $rule->getName());
    }

    #[Test]
    public function itProducesNoFindingBelowThreshold(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 10,
            error: 15,
        ));

        $context = $this->createContext(propertyCount: 8);
        $findings = $rule->analyze($context);

        self::assertCount(0, $findings);
    }

    #[Test]
    public function itGeneratesWarningAboveWarningThreshold(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 10,
            error: 15,
        ));

        $context = $this->createContext(propertyCount: 12);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertStringContainsString('Property count is 12, exceeds threshold of 10. Consider splitting the class or using composition', $findings[0]->message);
    }

    #[Test]
    public function itGeneratesErrorAboveErrorThreshold(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 10,
            error: 15,
        ));

        $context = $this->createContext(propertyCount: 18);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertStringContainsString('Property count is 18, exceeds threshold of 15. Consider splitting the class or using composition', $findings[0]->message);
    }

    #[Test]
    public function itRespectsCustomThresholds(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 5,
            error: 8,
        ));

        // Below warning threshold
        $context = $this->createContext(propertyCount: 4);
        self::assertCount(0, $rule->analyze($context));

        // Above warning threshold
        $context = $this->createContext(propertyCount: 6);
        $findings = $rule->analyze($context);
        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);

        // Above error threshold
        $context = $this->createContext(propertyCount: 10);
        $findings = $rule->analyze($context);
        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
    }

    #[Test]
    public function itSetsCorrectSymbolPathOnFinding(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 10,
            error: 15,
        ));

        $context = $this->createContext(
            propertyCount: 12,
            namespace: 'App\\Domain',
            class: 'User',
        );

        $findings = $rule->analyze($context);
        self::assertCount(1, $findings);

        $symbolPath = $findings[0]->symbolPath;
        self::assertSame('App\\Domain', $symbolPath->namespace);
        self::assertSame('User', $symbolPath->type);
    }

    #[Test]
    public function itProducesNoFindingWhenPropertyCountIsNull(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 10,
            error: 15,
        ));

        $bag = new MetricBag();
        // No propertyCount metric

        $symbolPath = SymbolPath::forClass('App', 'Test');
        $symbolInfo = self::subjectInfo(
            symbolPath: $symbolPath,
            file: RelativePath::fromString(basename(__FILE__)),
            line: 1,
        );

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$symbolInfo]);
        $repository->method('get')
            ->willReturn($bag);

        $context = new AnalysisContext($repository);

        $findings = $rule->analyze($context);
        self::assertCount(0, $findings);
    }

    #[Test]
    public function itAppliesTheExactSubjectOverrideAndPreservesOutput(): void
    {
        $classInfo = self::subjectInfo(
            SymbolPath::forClass('App', 'Overridden'),
            RelativePath::fromString('src/Overridden.php'),
            10,
        );
        $subject = $classInfo->subject;
        self::assertNotNull($subject);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([$classInfo]);
        $repository->method('get')->willReturn((new MetricBag())->with('propertyCount', 12));
        $context = new AnalysisContext(
            metrics: $repository,
            thresholdOverrides: [
                'src/Overridden.php' => [new ThresholdOverride('size.property-count', 11, 12, 1, $subject, ControlScope::Class_, 100)],
            ],
        );

        $findings = (new PropertyCountRule(new PropertyCountOptions(warning: 15, error: 20)))->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame(12, $findings[0]->threshold);
        self::assertSame('Property count is 12, exceeds threshold of 12. Consider splitting the class or using composition', $findings[0]->message);
        self::assertSame($subject->toCanonical(), $findings[0]->subject->toCanonical());
    }

    #[Test]
    public function itAppliesDefaultThresholds(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions());

        // Below default warning (15)
        $context = $this->createContext(propertyCount: 14);
        self::assertCount(0, $rule->analyze($context));

        // At default warning (15) — triggers warning with >= comparison
        $context = $this->createContext(propertyCount: 15);
        $findings = $rule->analyze($context);
        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);

        // Above default warning (15)
        $context = $this->createContext(propertyCount: 16);
        $findings = $rule->analyze($context);
        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);

        // At default error (20) — triggers error with >= comparison
        $context = $this->createContext(propertyCount: 20);
        $findings = $rule->analyze($context);
        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);

        // Above default error (20)
        $context = $this->createContext(propertyCount: 21);
        $findings = $rule->analyze($context);
        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
    }

    #[Test]
    public function itExcludesReadonlyClassByDefault(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 10,
            error: 15,
            excludeReadonly: true,
        ));

        $context = $this->createContext(propertyCount: 12, isReadonly: 1);
        $findings = $rule->analyze($context);

        self::assertCount(0, $findings, 'Readonly class should be excluded when excludeReadonly is true');
    }

    #[Test]
    public function itDoesNotExcludeReadonlyClassWhenFilterDisabled(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 10,
            error: 15,
            excludeReadonly: false,
        ));

        $context = $this->createContext(propertyCount: 12, isReadonly: 1);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings, 'Readonly class should NOT be excluded when excludeReadonly is false');
        self::assertSame(Severity::Warning, $findings[0]->severity);
    }

    #[Test]
    public function itExcludesPromotedOnlyClassByDefault(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 10,
            error: 15,
            excludePromotedOnly: true,
        ));

        $context = $this->createContext(propertyCount: 12, isPromotedOnly: 1);
        $findings = $rule->analyze($context);

        self::assertCount(0, $findings, 'Promoted-only class should be excluded when excludePromotedOnly is true');
    }

    #[Test]
    public function itDoesNotExcludePromotedOnlyClassWhenFilterDisabled(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 10,
            error: 15,
            excludePromotedOnly: false,
        ));

        $context = $this->createContext(propertyCount: 12, isPromotedOnly: 1);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings, 'Promoted-only class should NOT be excluded when excludePromotedOnly is false');
        self::assertSame(Severity::Warning, $findings[0]->severity);
    }

    #[Test]
    public function itProducesFindingsWhenBothFiltersDisabled(): void
    {
        $rule = new PropertyCountRule(new PropertyCountOptions(
            warning: 10,
            error: 15,
            excludeReadonly: false,
            excludePromotedOnly: false,
        ));

        // Readonly + promoted-only class
        $context = $this->createContext(propertyCount: 12, isReadonly: 1, isPromotedOnly: 1);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings, 'Both filters disabled should produce violations');
    }

    private function createContext(
        int $propertyCount,
        string $namespace = 'App',
        string $class = 'TestClass',
        ?int $isReadonly = null,
        ?int $isPromotedOnly = null,
    ): AnalysisContext {
        $bag = (new MetricBag())
            ->with('propertyCount', $propertyCount);

        if ($isReadonly !== null) {
            $bag = $bag->with('isReadonly', $isReadonly);
        }

        if ($isPromotedOnly !== null) {
            $bag = $bag->with('isPromotedPropertiesOnly', $isPromotedOnly);
        }

        $symbolPath = SymbolPath::forClass($namespace, $class);
        $symbolInfo = self::subjectInfo(
            symbolPath: $symbolPath,
            file: RelativePath::fromString(basename(__FILE__)),
            line: 1,
        );

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$symbolInfo]);
        $repository->method('get')
            ->willReturn($bag);

        return new AnalysisContext($repository);
    }
    #[Test]
    public function itProjectsDuplicateLogicalClassScoresToIndependentExactDeclarations(): void
    {
        $class = SymbolPath::forClass('App\\Service', 'Twin');
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([
            self::subjectInfo($class, RelativePath::fromString('src/A.php'), 100),
            self::subjectInfo($class, RelativePath::fromString('src/B.php'), 200),
        ]);
        $repository->method('get')->willReturn(
            (new MetricBag())
                ->with('propertyCount', 12)
                ->with('isReadonly', 0)
                ->with('isPromotedPropertiesOnly', 0),
        );

        $findings = (new PropertyCountRule(new PropertyCountOptions(warning: 10, error: 15)))
            ->analyze(new AnalysisContext($repository));

        self::assertCount(2, $findings);
        $subjects = array_map(static fn($finding): string => $finding->subject->toCanonical(), $findings);
        sort($subjects);
        self::assertSame([
            'declaration:class:App\\Service\\Twin@src/A.php',
            'declaration:class:App\\Service\\Twin@src/B.php',
        ], $subjects);
    }

    private static function subjectInfo(\Qualimetrix\Core\Symbol\SymbolPath $symbolPath, ?\Qualimetrix\Core\Path\RelativePath $file, ?int $line): \Qualimetrix\Core\Symbol\SymbolInfo
    {
        $type = $symbolPath->getType();
        if (\in_array($type, [\Qualimetrix\Core\Symbol\SymbolType::File, \Qualimetrix\Core\Symbol\SymbolType::Namespace_, \Qualimetrix\Core\Symbol\SymbolType::Project], true)) {
            return new \Qualimetrix\Core\Symbol\SymbolInfo(\Qualimetrix\Core\Symbol\MetricSubject::aggregate($symbolPath), $file, $line);
        }

        \assert($file !== null);
        $kind = $type === \Qualimetrix\Core\Symbol\SymbolType::Class_ ? null : ($type === \Qualimetrix\Core\Symbol\SymbolType::Function_ ? \Qualimetrix\Core\Symbol\CallableKind::Function : \Qualimetrix\Core\Symbol\CallableKind::Method);

        return new \Qualimetrix\Core\Symbol\SymbolInfo(
            \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of($symbolPath, $file, \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
            $file,
            $line,
            $kind,
        );
    }
}
