<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Unit\DataClass;

use PHPUnit\Framework\Attributes\CoversClass;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Design\DataClass\DataClassOptions;
use Qualimetrix\Analysis\Evidence\Design\DataClass\DataClassRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelPublication;
use Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole;
use Qualimetrix\Analysis\Finding\Contract\EnablementDecision;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission;
use Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch;
use Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Population\PopulationSession;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

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
            'design.woc' => 10,
            'complexity.wmc' => 5,
            'size.method-count.total' => 10,
            'size.property-count' => 3,
            'design.is-readonly' => 0,
            'design.is-promoted-properties-only' => 0,
            'design.is-abstract' => 0,
            'design.is-interface' => 0,
            'design.is-exception' => 0,
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

    #[Test]
    public function itAccountsForWrongLogicalKindsBeforeReadingClassMetrics(): void
    {
        $info = self::subjectInfo(SymbolPath::forMethod('App', 'Service', 'run'), RelativePath::fromString('service.php'), 1);
        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::once())->method('allClassDeclarations')->willReturn([$info]);
        $repository->expects(self::never())->method('getSubject');
        $session = self::populationSession(DataClassRule::NAME);
        self::assertSame([], (new DataClassRule(new DataClassOptions()))->analyze((new AnalysisContext($repository))->withPopulationTrace($session)));
        self::assertSame(0, $session->freeze()->judgedCount());
        self::assertSame(1, $session->freeze()->unjudgedCount());
        self::assertSame('logical-class-kind', $session->freeze()->abstentions()[0]->gate);
    }

    #[Test]
    public function itKeepsPopulationEligibilityIndependentOfAccountingSelection(): void
    {
        $info = self::subjectInfo(SymbolPath::fromClassFqn('App\\Data'), RelativePath::fromString('data.php'), 1);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$info]);
        $repository->method('getSubject')->willReturn($this->makeMetricBag(['design.is-exception' => null]));
        foreach ([false, true] as $selected) {
            foreach ([false, true] as $excludeExceptions) {
                $session = self::populationSession(DataClassRule::NAME, $selected);
                $context = (new AnalysisContext($repository))->withPopulationTrace($session);
                $findings = (new DataClassRule(new DataClassOptions(excludeExceptions: $excludeExceptions)))->analyze($context);
                self::assertCount($excludeExceptions ? 0 : 1, $findings);
                self::assertSame($selected && !$excludeExceptions ? 1 : 0, $session->freeze()->judgedCount());
                self::assertSame($selected && $excludeExceptions ? 1 : 0, $session->freeze()->unjudgedCount());
                if ($selected && $excludeExceptions) {
                    self::assertSame('exception-classification', $session->freeze()->abstentions()[0]->gate);
                }
            }
        }
    }

    #[Test]
    public function itKeepsLiteralFlagsStrictAndBypassesDisabledExceptionReads(): void
    {
        $info = self::subjectInfo(SymbolPath::fromClassFqn('App\\Data'), RelativePath::fromString('data.php'), 1);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$info]);
        $metrics = $this->makeMetricBag()->with('design.is-exception', \NAN);
        $repository->method('getSubject')->willReturn($metrics);
        self::assertCount(1, (new DataClassRule(new DataClassOptions(excludeExceptions: false)))->analyze(new AnalysisContext($repository)));
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$info]);
        $repository->method('getSubject')->willReturn($metrics->with('design.is-interface', 1.0)->with('design.is-abstract', 1.0));
        self::assertCount(1, (new DataClassRule(new DataClassOptions(excludeExceptions: false)))->analyze(new AnalysisContext($repository)));
        foreach (['design.is-interface', 'design.is-abstract'] as $key) {
            $repository = self::createStub(MetricRepositoryInterface::class);
            $repository->method('allClassDeclarations')->willReturn([$info]);
            $repository->method('getSubject')->willReturn($metrics->with($key, 1));
            $session = self::populationSession(DataClassRule::NAME);
            self::assertSame([], (new DataClassRule(new DataClassOptions(excludeExceptions: false)))->analyze((new AnalysisContext($repository))->withPopulationTrace($session)));
            self::assertSame($key === 'design.is-interface' ? 'interface-class' : 'abstract-class', $session->freeze()->abstentions()[0]->gate);
        }
    }

    #[Test]
    public function itCountsHealthyDataClassJudgementsBeforeTheCompoundFindingThreshold(): void
    {
        $info = self::subjectInfo(SymbolPath::fromClassFqn('App\\Behaviour'), RelativePath::fromString('behaviour.php'), 1);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$info]);
        $repository->method('getSubject')->willReturn($this->makeMetricBag(['design.woc' => 100]));
        $session = self::populationSession(DataClassRule::NAME);
        self::assertSame([], (new DataClassRule(new DataClassOptions()))->analyze((new AnalysisContext($repository))->withPopulationTrace($session)));
        self::assertSame(1, $session->freeze()->judgedCount());
        self::assertSame(0, $session->freeze()->unjudgedCount());
    }

    #[Test]
    public function itAbstainsOnUnknownExceptionOnlyWhenExclusionIsEnabled(): void
    {
        $metrics = $this->makeMetricBag(['design.is-exception' => null]);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([
            self::subjectInfo(SymbolPath::fromClassFqn('App\\Unknown'), RelativePath::fromString('unknown.php'), 1),
        ]);
        $repository->method('getSubject')->willReturn($metrics);
        self::assertSame([], (new DataClassRule(new DataClassOptions(excludeExceptions: true)))->analyze(new AnalysisContext($repository)));
        self::assertCount(1, (new DataClassRule(new DataClassOptions(excludeExceptions: false)))->analyze(new AnalysisContext($repository)));
    }

    #[Test]
    public function itGetsName(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        self::assertSame('design.data-class', $rule->getName());
    }

    #[Test]
    public function itGetsDescription(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        self::assertSame(
            'Detects classes whose public interface is mostly data access rather than behavior (Data Classes)',
            $rule::getDescription(),
        );
    }

    #[Test]
    public function itGetsOptionsClass(): void
    {
        self::assertSame(
            DataClassOptions::class,
            DataClassRule::getOptionsClass(),
        );
    }

    #[Test]
    public function itGetsCliAliases(): void
    {
        $aliases = CliAliasReader::read(DataClassRule::class);

        self::assertArrayHasKey('data-class-woc-threshold', $aliases);
        self::assertArrayHasKey('data-class-wmc-threshold', $aliases);
        self::assertArrayHasKey('data-class-min-members', $aliases);
        self::assertArrayHasKey('data-class-exclude-readonly', $aliases);
        self::assertArrayHasKey('data-class-exclude-promoted-only', $aliases);
        self::assertArrayHasKey('data-class-exclude-exceptions', $aliases);
        self::assertSame('wocThreshold', $aliases['data-class-woc-threshold']);
        self::assertSame('wmcThreshold', $aliases['data-class-wmc-threshold']);
        self::assertSame('minMembers', $aliases['data-class-min-members']);
        self::assertSame('excludeReadonly', $aliases['data-class-exclude-readonly']);
        self::assertSame('excludePromotedOnly', $aliases['data-class-exclude-promoted-only']);
        self::assertSame('excludeExceptions', $aliases['data-class-exclude-exceptions']);
    }

    #[Test]
    public function itAnalyzeDisabledReturnsEmpty(): void
    {
        $rule = new DataClassRule(new DataClassOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('allClassDeclarations');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itFiltersOnMinMembers(): void
    {
        $rule = new DataClassRule(new DataClassOptions(minMembers: 3));

        $symbolPath = SymbolPath::forClass('App\Service', 'SmallClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/SmallClass.php'), 10);

        $metricBag = $this->makeMetricBag(['size.method-count.total' => 1, 'size.property-count' => 1]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(0, $rule->analyze($context));
    }

    #[Test]
    public function itSkipsReadonlyWhenExcluded(): void
    {
        $rule = new DataClassRule(new DataClassOptions(excludeReadonly: true));

        $symbolPath = SymbolPath::forClass('App\Dto', 'ReadonlyDto');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Dto/ReadonlyDto.php'), 5);

        $metricBag = $this->makeMetricBag(['design.is-readonly' => 1]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(0, $rule->analyze($context));
    }

    #[Test]
    public function itDoesNotSkipReadonlyWhenOptionFalse(): void
    {
        $rule = new DataClassRule(new DataClassOptions(excludeReadonly: false));

        $symbolPath = SymbolPath::forClass('App\Dto', 'ReadonlyDto');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Dto/ReadonlyDto.php'), 5);

        $metricBag = $this->makeMetricBag(['design.is-readonly' => 1]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
    }

    #[Test]
    public function itSkipsPromotedOnlyWhenExcluded(): void
    {
        $rule = new DataClassRule(new DataClassOptions(excludePromotedOnly: true));

        $symbolPath = SymbolPath::forClass('App\Dto', 'PromotedDto');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Dto/PromotedDto.php'), 5);

        $metricBag = $this->makeMetricBag(['design.is-promoted-properties-only' => 1]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(0, $rule->analyze($context));
    }

    #[Test]
    public function itDoesNotSkipPromotedOnlyWhenOptionFalse(): void
    {
        $rule = new DataClassRule(new DataClassOptions(excludePromotedOnly: false));

        $symbolPath = SymbolPath::forClass('App\Dto', 'PromotedDto');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Dto/PromotedDto.php'), 5);

        $metricBag = $this->makeMetricBag(['design.is-promoted-properties-only' => 1]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
    }

    #[Test]
    public function itFlagsAClassMadeOfNothingButAccessors(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        $symbolPath = SymbolPath::forClass('App\Dto', 'PureDto');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Dto/PureDto.php'), 5);

        $metricBag = $this->makeMetricBag(['design.woc' => 0]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(1, $rule->analyze($context));
    }

    #[Test]
    public function itDetectsLowWocLowWmc(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $metricBag = $this->makeMetricBag();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertStringContainsString('only 10% of the public interface is behavior', $findings[0]->message);
        self::assertStringContainsString('threshold 33%', $findings[0]->message);
        self::assertStringContainsString('WMC=5', $findings[0]->message);
        self::assertStringContainsString('threshold 10', $findings[0]->message);
        self::assertSame(10, $findings[0]->metricValue);
        self::assertSame('design.data-class', $findings[0]->ruleName);
        self::assertSame('design.data-class', $findings[0]->code);
    }

    #[Test]
    public function itDoesNotFlagHighWoc(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'GoodClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/GoodClass.php'), 10);

        $metricBag = $this->makeMetricBag(['design.woc' => 50]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(0, $rule->analyze($context));
    }

    #[Test]
    public function itDoesNotFlagHighWmc(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'ComplexClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/ComplexClass.php'), 10);

        $metricBag = $this->makeMetricBag(['complexity.wmc' => 15]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(0, $rule->analyze($context));
    }

    #[Test]
    public function itSkipsNullWoc(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'NoWocClass');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/NoWocClass.php'), 10);

        // Omit 'design.woc' key entirely to get null
        $metricBag = (new MetricBag())
            ->with('complexity.wmc', 5)
            ->with('size.method-count.total', 10)
            ->with('size.property-count', 3)
            ->with('design.is-readonly', 0)
            ->with('design.is-promoted-properties-only', 0)
            ->with('design.is-abstract', 0)
            ->with('design.is-interface', 0)
            ->with('design.is-exception', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(0, $rule->analyze($context));
    }

    // --- New tests for false positive reduction ---

    #[Test]
    public function itSkipsInterfaces(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        $symbolPath = SymbolPath::forClass('App\Contract', 'NodeVisitor');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Contract/NodeVisitor.php'), 5);

        $metricBag = $this->makeMetricBag(['design.is-interface' => 1]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(0, $rule->analyze($context));
    }

    #[Test]
    public function itSkipsAbstractClasses(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        $symbolPath = SymbolPath::forClass('App\Base', 'AbstractHandler');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Base/AbstractHandler.php'), 5);

        $metricBag = $this->makeMetricBag(['design.is-abstract' => 1]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(0, $rule->analyze($context));
    }

    #[Test]
    public function itSkipsZeroPropertyClasses(): void
    {
        $rule = new DataClassRule(new DataClassOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'StatelessService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/StatelessService.php'), 5);

        $metricBag = $this->makeMetricBag(['size.property-count' => 0]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(0, $rule->analyze($context));
    }

    #[Test]
    public function itSkipsExceptionClassWhenExcluded(): void
    {
        $rule = new DataClassRule(new DataClassOptions(excludeExceptions: true));

        $symbolPath = SymbolPath::forClass('App\Exception', 'FileNotFoundException');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Exception/FileNotFoundException.php'), 5);

        $metricBag = $this->makeMetricBag(['design.is-exception' => 1]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertCount(0, $rule->analyze($context));
    }

    #[Test]
    public function itDoesNotSkipExceptionClassWhenOptionFalse(): void
    {
        $rule = new DataClassRule(new DataClassOptions(excludeExceptions: false));

        $symbolPath = SymbolPath::forClass('App\Exception', 'FileNotFoundException');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Exception/FileNotFoundException.php'), 5);

        $metricBag = $this->makeMetricBag(['design.is-exception' => 1]);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
    }

    // --- Options tests ---

    #[Test]
    public function itHasOptionsDefaults(): void
    {
        $options = new DataClassOptions();

        self::assertTrue($options->enabled);
        self::assertSame(33, $options->wocThreshold);
        self::assertSame(10, $options->wmcThreshold);
        self::assertSame(3, $options->minMembers);
        self::assertTrue($options->excludeReadonly);
        self::assertTrue($options->excludePromotedOnly);
        self::assertTrue($options->excludeExceptions);
    }

    #[Test]
    public function itLoadsOptionsFromArrayWithCustomValues(): void
    {
        $options = DataClassOptions::fromResolved(ResolvedOptionsFixture::values(DataClassOptions::class, [
            'enabled' => true,
            'woc_threshold' => 70,
            'wmc_threshold' => 15,
            'min_members' => 5,
            'exclude_readonly' => false,
            'exclude_promoted_only' => false,
            'exclude_exceptions' => false,
        ]));

        self::assertTrue($options->enabled);
        self::assertSame(70, $options->wocThreshold);
        self::assertSame(15, $options->wmcThreshold);
        self::assertSame(5, $options->minMembers);
        self::assertFalse($options->excludeReadonly);
        self::assertFalse($options->excludePromotedOnly);
        self::assertFalse($options->excludeExceptions);
    }

    #[Test]
    public function itLoadsOptionsFromArrayWithDualKey(): void
    {
        $options = DataClassOptions::fromResolved(ResolvedOptionsFixture::values(DataClassOptions::class, [
            'wocThreshold' => 75,
            'wmcThreshold' => 12,
            'minMembers' => 4,
            'excludeReadonly' => false,
            'excludePromotedOnly' => false,
            'excludeExceptions' => false,
        ]));

        self::assertSame(75, $options->wocThreshold);
        self::assertSame(12, $options->wmcThreshold);
        self::assertSame(4, $options->minMembers);
        self::assertFalse($options->excludeReadonly);
        self::assertFalse($options->excludePromotedOnly);
        self::assertFalse($options->excludeExceptions);
    }

    #[Test]
    public function itUsesConstructorDefaultsForAnEmptyBodyAndHonoursExplicitDisablement(): void
    {
        self::assertEquals(new DataClassOptions(), DataClassOptions::fromResolved(ResolvedOptionsFixture::values(DataClassOptions::class, [])));
        self::assertFalse(DataClassOptions::fromResolved(ResolvedOptionsFixture::values(DataClassOptions::class, ['enabled' => false]))->isEnabled());
    }
    #[Test]
    public function itProjectsDuplicateLogicalClassScoresToIndependentExactDeclarations(): void
    {
        $class = SymbolPath::forClass('App\\Service', 'Twin');
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([
            self::subjectInfo($class, RelativePath::fromString('src/A.php'), 100),
            self::subjectInfo($class, RelativePath::fromString('src/B.php'), 200),
        ]);
        $repository->method('getSubject')->willReturn($this->makeMetricBag());

        $findings = (new DataClassRule(new DataClassOptions()))
            ->analyze(new AnalysisContext($repository));

        self::assertCount(2, $findings);
        $subjects = array_map(static fn($finding): string => $finding->subject->toCanonical(), $findings);
        sort($subjects);
        self::assertSame([
            'declaration:class:App\\Service\\Twin@src/A.php',
            'declaration:class:App\\Service\\Twin@src/B.php',
        ], $subjects);
    }

    private static function populationSession(string $producer, bool $selected = true): PopulationSession
    {
        return new PopulationSession((new ChannelPublication(new RuleEnablement([new EnablementDecision(
            new SelectionCellAddress($producer, new FindingChannel($producer), SymbolLevel::Class_, ChannelSelectionRole::Selectable),
            new AuthoredCellDecision($selected ? CellSwitch::On : CellSwitch::Off, CellAdmission::Direct),
        )], null)))->publishes(...));
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
