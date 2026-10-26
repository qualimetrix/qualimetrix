<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit\Instability;

use InvalidArgumentException;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Coupling\Instability\ClassInstabilityOptions;
use Qualimetrix\Analysis\Evidence\Coupling\Instability\InstabilityOptions;
use Qualimetrix\Analysis\Evidence\Coupling\Instability\InstabilityRule;
use Qualimetrix\Analysis\Evidence\Coupling\Instability\NamespaceInstabilityOptions;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(InstabilityRule::class)]
#[CoversClass(InstabilityOptions::class)]
#[CoversClass(ClassInstabilityOptions::class)]
#[CoversClass(NamespaceInstabilityOptions::class)]
final class InstabilityRuleTest extends TestCase
{
    #[Test]
    #[DataProvider('populationBoundaries')]
    public function itRecordsConfiguredPopulationBoundaries(SymbolLevel $level, string $key, string $gate, string $unit): void
    {
        $paths = $level === SymbolLevel::Class_
            ? [SymbolPath::forClass('App', 'Below'), SymbolPath::forClass('App', 'At')]
            : [SymbolPath::forNamespace('App\\Below'), SymbolPath::forNamespace('App\\At')];
        $infos = array_map(static fn($path) => self::subjectInfo($path, RelativePath::fromString('Sample.php'), 1), $paths);
        $bags = [];
        foreach ($paths as $index => $path) {
            $bags[$path->toCanonical()] = MetricBag::fromArray([
                'coupling.instability' => 0, 'coupling.ca' => 2, 'coupling.ce' => 0,
                'coupling.instability-own' => 0, 'coupling.ca-own' => 2, 'coupling.ce-own' => 0, 'size.class-count' => 2,
                ...[$key => $index + 1],
            ]);
        }
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn($infos);
        $repository->method('all')->willReturn($infos);
        $repository->method('get')->willReturnCallback(static fn(SymbolPath $path): MetricBag => $bags[$path->toCanonical()]);
        $repository->method('getSubject')->willReturnCallback(static fn($subject): MetricBag => $bags[$subject->toSymbolPath()->toCanonical()]);
        $session = new \Qualimetrix\Analysis\Finding\Population\PopulationSession(static fn(): bool => true);
        $rule = new InstabilityRule(new InstabilityOptions(
            class: new ClassInstabilityOptions(minAfferent: 2),
            namespace: new NamespaceInstabilityOptions(minAfferent: 2, minTypeCount: 2),
        ));
        self::assertSame([], $rule->analyzeLevel($level, (new AnalysisContext($repository))->withPopulationTrace($session)));
        $population = $session->freeze();
        self::assertSame(1, $population->judgedCount());
        self::assertSame(1, $population->unjudgedCount());
        self::assertSame($gate, $population->abstentions()[0]->gate);
        self::assertSame($unit, $population->abstentions()[0]->unit);
        self::assertSame(1, $population->abstentions()[0]->count);
    }

    /** @return iterable<string, array{SymbolLevel, string, string, string}> */
    public static function populationBoundaries(): iterable
    {
        yield 'class-afferent' => [SymbolLevel::Class_, 'coupling.ca', 'class-afferent', 'declaration'];
        yield 'own-afferent' => [SymbolLevel::Namespace_, 'coupling.ca-own', 'own-afferent', 'namespace'];
        yield 'own-types' => [SymbolLevel::Namespace_, 'size.class-count', 'own-types', 'namespace'];
    }

    #[Test]
    public function itAccountsMeasuredZeroAndMissingSelectedPublicationBeforeSeverity(): void
    {
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([
            self::subjectInfo(SymbolPath::forClass('Population', 'Healthy'), RelativePath::fromString('src/Healthy.php'), 1),
            self::subjectInfo(SymbolPath::forClass('Population', 'Missing'), RelativePath::fromString('src/Missing.php'), 1),
        ]);
        $repository->method('getSubject')->willReturnCallback(static fn(\Qualimetrix\Core\Symbol\MetricSubject $subject): MetricBag => $subject->toSymbolPath()->type === 'Healthy' ? (new MetricBag())->with('coupling.instability', 0)->with('coupling.ca', 0)->with('coupling.ce', 0) : (new MetricBag())->with('coupling.ca', 0)->with('coupling.ce', 0));
        $decisions = [];
        foreach (InstabilityRule::channelDeclarations() as $channel => $declaration) {
            foreach ($declaration->levels as $level) {
                $decisions[] = new \Qualimetrix\Analysis\Finding\Contract\EnablementDecision(new \Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress(InstabilityRule::NAME, new \Qualimetrix\Analysis\Finding\Contract\Channel\FindingChannel($channel), $level, \Qualimetrix\Analysis\Finding\Contract\Channel\ChannelSelectionRole::Selectable), new \Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision(\Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch::On, \Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission::Direct));
            }
        }
        $session = new \Qualimetrix\Analysis\Finding\Population\PopulationSession((new \Qualimetrix\Analysis\Finding\Contract\Channel\ChannelPublication(new \Qualimetrix\Analysis\Finding\Contract\RuleEnablement($decisions, null)))->publishes(...));
        $rule = new InstabilityRule(new InstabilityOptions(class: new ClassInstabilityOptions(minAfferent: 0)));
        self::assertSame([], $rule->analyzeLevel(\Qualimetrix\Core\Symbol\SymbolLevel::Class_, (new AnalysisContext($repository))->withPopulationTrace($session)));
        self::assertSame(1, $session->freeze()->judgedCount());
        self::assertSame(1, $session->freeze()->unjudgedCount());
        self::assertStringContainsString('Missing', $session->freeze()->abstentions()[0]->examples[0]);
        self::assertSame('declaration', $session->freeze()->abstentions()[0]->unit);
    }

    #[Test]
    #[DataProvider('roundedLevels')]
    public function itDistinguishesRoundedInstabilityFromItsThreshold(SymbolLevel $level): void
    {
        $path = $level === SymbolLevel::Class_ ? SymbolPath::forClass('App', 'Sample') : SymbolPath::forNamespace('App');
        $repository = self::createStub(MetricRepositoryInterface::class);
        $info = self::subjectInfo($path, RelativePath::fromString('Sample.php'), 1);
        $repository->method('allClassDeclarations')->willReturn([$info]);
        $repository->method('all')->willReturn([$info]);
        $bag = MetricBag::fromArray([
            'coupling.instability' => 0.5, 'coupling.ca' => 2, 'coupling.ce' => 2,
            'coupling.instability-own' => 0.5, 'coupling.ca-own' => 2, 'coupling.ce-own' => 2, 'size.class-count' => 3,
        ]);
        $repository->method('getSubject')->willReturn($bag);
        $repository->method('get')->willReturn($bag);
        $rule = new InstabilityRule(new InstabilityOptions(
            class: new ClassInstabilityOptions(maxWarning: 0.4999, maxError: 1.0),
            namespace: new NamespaceInstabilityOptions(maxWarning: 0.4999, maxError: 1.0),
        ));
        $findings = $rule->analyzeLevel($level, new AnalysisContext($repository));
        self::assertCount(1, $findings);
        self::assertStringContainsString('0.5000 (Ca=2, Ce=2), exceeds threshold of 0.4999', $findings[0]->message);
        self::assertStringContainsString('0.4999', $findings[0]->recommendation ?? '');
    }

    /** @return iterable<string, array{SymbolLevel}> */
    public static function roundedLevels(): iterable
    {
        yield 'class' => [SymbolLevel::Class_];
        yield 'namespace' => [SymbolLevel::Namespace_];
    }

    #[Test]
    public function itReturnsCorrectName(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        self::assertSame('coupling.instability', $rule->getName());
    }

    #[Test]
    public function itReturnsCorrectDescription(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        self::assertSame(
            'Checks instability at class and namespace levels',
            $rule::getDescription(),
        );
    }

    #[Test]
    public function itReturnsCorrectOptionsClass(): void
    {
        self::assertSame(
            InstabilityOptions::class,
            InstabilityRule::getOptionsClass(),
        );
    }

    #[Test]
    public function itReturnsClassAndNamespaceLevels(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        self::assertSame([SymbolLevel::Class_, SymbolLevel::Namespace_], $rule->getSupportedLevels());
    }

    #[Test]
    public function itDeclaresCorrectCliAliases(): void
    {
        self::assertSame([
            'instability-class-warning' => 'class.max_warning',
            'instability-class-error' => 'class.max_error',
            'instability-ns-warning' => 'namespace.max_warning',
            'instability-ns-error' => 'namespace.max_error',
        ], CliAliasReader::read(InstabilityRule::class));
    }

    #[Test]
    public function itThrowsForInvalidOptionsType(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Expected');

        $invalidOptions = self::createStub(\Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface::class);
        new InstabilityRule($invalidOptions);
    }

    // Class-level tests

    #[Test]
    public function itReturnsEmptyWhenClassLevelDisabled(): void
    {
        $rule = new InstabilityRule(
            new InstabilityOptions(
                class: new ClassInstabilityOptions(enabled: false),
            ),
        );

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(SymbolLevel::Class_, $context));
    }

    #[Test]
    public function itReturnsEmptyWhenNoClasses(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(SymbolLevel::Class_, $context));
    }

    #[Test]
    public function itSkipsClassesWithoutInstabilityMetric(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $metricBag = new MetricBag();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(SymbolLevel::Class_, $context));
    }

    #[Test]
    public function itGeneratesClassInstabilityWarning(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // 0.85 is above warning (0.8), below error (0.95)
        $metricBag = (new MetricBag())
            ->with('coupling.instability', 0.85)
            ->with('coupling.ca', 2)
            ->with('coupling.ce', 12);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame('Instability is 0.85 (Ca=2, Ce=12), exceeds threshold of 0.80. Reduce outgoing dependencies', $findings[0]->message);
        self::assertSame(0.85, $findings[0]->metricValue);
        self::assertSame('coupling.instability', $findings[0]->ruleName);
        self::assertSame('coupling.instability', $findings[0]->code);
    }

    #[Test]
    public function itGeneratesClassInstabilityError(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // 0.97 is above error (0.95)
        $metricBag = (new MetricBag())
            ->with('coupling.instability', 0.97)
            ->with('coupling.ca', 1)
            ->with('coupling.ce', 32);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame(0.97, $findings[0]->metricValue);
    }

    #[Test]
    public function itSkipsClassBelowDefaultMinAfferent(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'LeafService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/LeafService.php'), 10);

        // Ca=0, below default minAfferent=1, should be skipped
        $metricBag = (new MetricBag())
            ->with('coupling.instability', 1.0)
            ->with('coupling.ca', 0)
            ->with('coupling.ce', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(0, $findings);
    }

    #[Test]
    public function itDoesNotSkipClassWhenMinAfferentIsZero(): void
    {
        $rule = new InstabilityRule(
            new InstabilityOptions(
                class: new ClassInstabilityOptions(minAfferent: 0),
            ),
        );

        $symbolPath = SymbolPath::forClass('App\Service', 'LeafService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/LeafService.php'), 10);

        // Ca=0, but minAfferent=0 means check all classes
        $metricBag = (new MetricBag())
            ->with('coupling.instability', 1.0)
            ->with('coupling.ca', 0)
            ->with('coupling.ce', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
    }

    #[Test]
    public function itDefaultsMissingClassEfferentCouplingToZeroInTheExactMessage(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions(
            class: new ClassInstabilityOptions(minAfferent: 0),
        ));
        $symbolPath = SymbolPath::forClass('App\\Service', 'LeafService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/LeafService.php'), 10);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')->willReturn(
            (new MetricBag())->with('coupling.instability', 1.0),
        );

        $findings = $rule->analyzeLevel(SymbolLevel::Class_, new AnalysisContext($repository));

        self::assertCount(1, $findings);
        self::assertSame(
            'Instability is 1.00 (Ca=0, Ce=0), exceeds threshold of 0.95. Reduce outgoing dependencies',
            $findings[0]->message,
        );
        self::assertSame($classInfo->subject?->toCanonical(), $findings[0]->subject->toCanonical());
    }

    #[Test]
    public function itSkipsClassWhenCaOneBelowMinAfferentTwo(): void
    {
        $rule = new InstabilityRule(
            new InstabilityOptions(
                class: new ClassInstabilityOptions(minAfferent: 2),
            ),
        );

        $symbolPath = SymbolPath::forClass('App\Service', 'NearLeafService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/NearLeafService.php'), 10);

        // Ca=1, below minAfferent=2, should be skipped
        $metricBag = (new MetricBag())
            ->with('coupling.instability', 0.92)
            ->with('coupling.ca', 1)
            ->with('coupling.ce', 11);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(0, $findings);
    }

    #[Test]
    public function itDoesNotSkipClassWhenCaTwoMeetsMinAfferentTwo(): void
    {
        $rule = new InstabilityRule(
            new InstabilityOptions(
                class: new ClassInstabilityOptions(minAfferent: 2),
            ),
        );

        $symbolPath = SymbolPath::forClass('App\Service', 'CoreService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/CoreService.php'), 10);

        // Ca=2, meets minAfferent=2, should NOT be skipped
        $metricBag = (new MetricBag())
            ->with('coupling.instability', 0.85)
            ->with('coupling.ca', 2)
            ->with('coupling.ce', 12);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
    }

    // Namespace-level tests

    #[Test]
    public function itReturnsEmptyWhenNamespaceLevelDisabled(): void
    {
        $rule = new InstabilityRule(
            new InstabilityOptions(
                namespace: new NamespaceInstabilityOptions(enabled: false),
            ),
        );

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(SymbolLevel::Namespace_, $context));
    }

    #[Test]
    public function itGeneratesNamespaceInstabilityWarning(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // 0.80 reaches warning, below error (0.95)
        $metricBag = (new MetricBag())
            ->with('coupling.instability-own', 0.8)
            ->with('coupling.ca-own', 3)
            ->with('coupling.ce-own', 22)
            ->with('size.class-count', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Namespace_, $context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertStringContainsString('Instability is 0.80 (Ca=3, Ce=22), reaches threshold of 0.80. Reduce outgoing dependencies', $findings[0]->message);
        self::assertSame('coupling.instability', $findings[0]->code);
    }

    #[Test]
    public function itGeneratesNamespaceInstabilityError(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // 0.98 is above error (0.95)
        $metricBag = (new MetricBag())
            ->with('coupling.instability-own', 0.98)
            ->with('coupling.ca-own', 1)
            ->with('coupling.ce-own', 49)
            ->with('size.class-count', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Namespace_, $context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame(0.98, $findings[0]->metricValue);
        self::assertStringContainsString('exceeds threshold of 0.95', $findings[0]->message);
    }

    #[Test]
    public function itSkipsNamespaceBelowDefaultMinAfferent(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // own Ca=0, below default minAfferent=1, should be skipped
        $metricBag = (new MetricBag())
            ->with('coupling.instability-own', 1.0)
            ->with('coupling.ca-own', 0)
            ->with('coupling.ce-own', 10)
            ->with('size.class-count', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Namespace_, $context);

        self::assertCount(0, $findings);
    }

    #[Test]
    public function itDoesNotSkipNamespaceWhenMinAfferentIsZero(): void
    {
        $rule = new InstabilityRule(
            new InstabilityOptions(
                namespace: new NamespaceInstabilityOptions(minAfferent: 0),
            ),
        );

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // Ca=0, but minAfferent=0 means check all namespaces
        $metricBag = (new MetricBag())
            ->with('coupling.instability-own', 1.0)
            ->with('coupling.ca-own', 0)
            ->with('coupling.ce-own', 10)
            ->with('size.class-count', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Namespace_, $context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
    }

    #[Test]
    public function itSkipsNamespaceWhenCaOneBelowMinAfferentTwo(): void
    {
        $rule = new InstabilityRule(
            new InstabilityOptions(
                namespace: new NamespaceInstabilityOptions(minAfferent: 2),
            ),
        );

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // own Ca=1, below minAfferent=2, should be skipped
        $metricBag = (new MetricBag())
            ->with('coupling.instability-own', 0.92)
            ->with('coupling.ca-own', 1)
            ->with('coupling.ce-own', 11)
            ->with('size.class-count', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Namespace_, $context);

        self::assertCount(0, $findings);
    }

    // Namespace minTypeCount tests

    #[Test]
    public function itSkipsNamespaceBelowMinTypeCount(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // one own class, below the default minTypeCount (3)
        $metricBag = (new MetricBag())
            ->with('coupling.instability-own', 0.98)
            ->with('coupling.ca-own', 1)
            ->with('coupling.ce-own', 49)
            ->with('size.class-count', 1);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Namespace_, $context);

        self::assertCount(0, $findings);
    }

    #[Test]
    public function itChecksNamespaceWhenAboveMinTypeCount(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // five own classes, above the default minTypeCount (3)
        $metricBag = (new MetricBag())
            ->with('coupling.instability-own', 0.98)
            ->with('coupling.ca-own', 1)
            ->with('coupling.ce-own', 49)
            ->with('size.class-count', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Namespace_, $context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
    }

    // Own scope

    #[Test]
    public function itJudgesANamespaceOnItsOwnInstabilityAndNotOnItsSubtree(): void
    {
        $findings = self::namespaceFindings(MetricBag::fromArray([
            'coupling.instability' => 0.1, 'coupling.ca' => 9, 'coupling.ce' => 1,
            'coupling.instability-own' => 0.9, 'coupling.ca-own' => 1, 'coupling.ce-own' => 9,
            'size.class-count' => 3,
        ]), new NamespaceInstabilityOptions());

        self::assertCount(1, $findings);
        self::assertSame(0.9, $findings[0]->metricValue);
        self::assertStringContainsString('Instability is 0.90 (Ca=1, Ce=9)', $findings[0]->message);
    }

    #[Test]
    public function itDoesNotJudgeAnUnstableSubtreeWhoseOwnScopeIsStable(): void
    {
        self::assertSame([], self::namespaceFindings(MetricBag::fromArray([
            'coupling.instability' => 0.99, 'coupling.ca' => 1, 'coupling.ce' => 99,
            'coupling.instability-own' => 0.5, 'coupling.ca-own' => 2, 'coupling.ce-own' => 2,
            'size.class-count' => 3,
        ]), new NamespaceInstabilityOptions()));
    }

    /**
     * A namespace declaring no type publishes only its subtree values: with
     * the default minimum it is below the own-type boundary, and without one
     * its own instability is missing evidence, not a measured 0.
     *
     * @param array{gate: string, dataGap: bool} $abstention
     */
    #[Test]
    #[DataProvider('typelessNamespaces')]
    public function itNeverJudgesANamespaceThatDeclaresNoTypeOfItsOwn(int $minTypeCount, array $abstention): void
    {
        $session = new \Qualimetrix\Analysis\Finding\Population\PopulationSession(static fn(): bool => true);
        $findings = self::namespaceFindings(MetricBag::fromArray([
            'coupling.instability' => 0.857, 'coupling.ca' => 1, 'coupling.ce' => 6,
            'coupling.ca-own' => 0, 'coupling.ce-own' => 0, 'size.class-count.sum' => 3, 'size.interface-count.sum' => 3,
        ]), new NamespaceInstabilityOptions(minTypeCount: $minTypeCount, minAfferent: 0), $session);

        self::assertSame([], $findings);
        $population = $session->freeze();
        self::assertSame(0, $population->judgedCount());
        self::assertSame($abstention['gate'], $population->abstentions()[0]->gate);
        self::assertSame($abstention['dataGap'], $population->abstentions()[0]->dataGap);
    }

    /** @return iterable<string, array{int, array{gate: string, dataGap: bool}}> */
    public static function typelessNamespaces(): iterable
    {
        yield 'default minimum' => [3, ['gate' => 'own-types', 'dataGap' => false]];
        yield 'no minimum' => [0, ['gate' => 'own-instability', 'dataGap' => true]];
    }

    #[Test]
    public function itCountsOwnInterfacesTraitsAndImplementingEnumsTowardsTheMinimum(): void
    {
        $bag = [
            'coupling.instability-own' => 0.9, 'coupling.ca-own' => 1, 'coupling.ce-own' => 9,
            'size.interface-count' => 1, 'size.trait-count' => 1, 'size.implementing-enum-count' => 1,
        ];

        self::assertCount(1, self::namespaceFindings(MetricBag::fromArray($bag), new NamespaceInstabilityOptions()));
        self::assertSame([], self::namespaceFindings(MetricBag::fromArray([...$bag, 'size.implementing-enum-count' => 0, 'size.enum-count' => 5]), new NamespaceInstabilityOptions()));
    }

    #[Test]
    public function itGatesTheAfferentMinimumOnOwnCa(): void
    {
        $bag = [
            'coupling.instability' => 0.9, 'coupling.ca' => 5, 'coupling.ce' => 45,
            'coupling.instability-own' => 1.0, 'coupling.ca-own' => 0, 'coupling.ce-own' => 10,
            'size.class-count' => 3,
        ];

        self::assertSame([], self::namespaceFindings(MetricBag::fromArray($bag), new NamespaceInstabilityOptions()));
        self::assertCount(1, self::namespaceFindings(MetricBag::fromArray($bag), new NamespaceInstabilityOptions(minAfferent: 0)));
    }

    /** @return list<\Qualimetrix\Analysis\Finding\Contract\Finding> */
    private static function namespaceFindings(MetricBag $bag, NamespaceInstabilityOptions $options, ?\Qualimetrix\Analysis\Finding\Population\PopulationSession $session = null): array
    {
        $info = self::subjectInfo(SymbolPath::forNamespace('App\\Ctx'), RelativePath::fromString('src/Ctx'), null);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([$info]);
        $repository->method('get')->willReturn($bag);
        $context = new AnalysisContext($repository);

        return (new InstabilityRule(new InstabilityOptions(namespace: $options)))->analyzeLevel(
            SymbolLevel::Namespace_,
            $session === null ? $context : $context->withPopulationTrace($session),
        );
    }

    // Legacy analyze() tests

    #[Test]
    public function itAnalyzesBothLevels(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $classPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = self::subjectInfo($classPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $nsPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = self::subjectInfo($nsPath, RelativePath::fromString('src/Service'), null);

        $classBag = (new MetricBag())
            ->with('coupling.instability', 0.85)
            ->with('coupling.ca', 2)
            ->with('coupling.ce', 12);
        $nsBag = (new MetricBag())
            ->with('coupling.instability-own', 0.88)
            ->with('coupling.ca-own', 3)
            ->with('coupling.ce-own', 22)
            ->with('size.class-count', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolLevel $level) => match ($level) {
                SymbolLevel::Class_ => [$classInfo],
                SymbolLevel::Namespace_ => [$nsInfo],
                default => [],
            });
        $repository->method('allClassDeclarations')->willReturn([$classInfo]);
        $repository->method('getSubject')->willReturn($classBag);
        $repository->method('get')->willReturn($nsBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(2, $findings);
    }

    // Options tests

    #[Test]
    public function itParsesClassOptionsFromArray(): void
    {
        $options = ClassInstabilityOptions::fromResolved(ResolvedOptionsFixture::values(ClassInstabilityOptions::class, [
            'enabled' => false,
            'max_warning' => 0.7,
            'max_error' => 0.9,
        ]));

        self::assertFalse($options->enabled);
        self::assertSame(0.7, $options->maxWarning);
        self::assertSame(0.9, $options->maxError);
    }

    #[Test]
    public function itUsesClassOptionDefaults(): void
    {
        $options = ClassInstabilityOptions::fromResolved(ResolvedOptionsFixture::values(ClassInstabilityOptions::class, []));

        self::assertTrue($options->enabled);
        self::assertSame(0.8, $options->maxWarning);
        self::assertSame(0.95, $options->maxError);
    }

    #[Test]
    public function itParsesNamespaceOptionsFromArray(): void
    {
        $options = NamespaceInstabilityOptions::fromResolved(ResolvedOptionsFixture::values(NamespaceInstabilityOptions::class, [
            'enabled' => false,
            'max_warning' => 0.75,
            'max_error' => 0.92,
        ]));

        self::assertFalse($options->enabled);
        self::assertSame(0.75, $options->maxWarning);
        self::assertSame(0.92, $options->maxError);
    }

    #[Test]
    public function itParsesInstabilityOptionsFromHierarchicalArray(): void
    {
        $options = InstabilityOptions::fromResolved(ResolvedOptionsFixture::values(InstabilityOptions::class, [
            'class' => [
                'max_warning' => 0.7,
                'max_error' => 0.9,
            ],
            'namespace' => [
                'max_warning' => 0.75,
                'max_error' => 0.92,
            ],
        ]));

        self::assertTrue($options->isEnabled());
        self::assertTrue($options->class->isEnabled());
        self::assertSame(0.7, $options->class->maxWarning);
        self::assertTrue($options->namespace->isEnabled());
        self::assertSame(0.75, $options->namespace->maxWarning);
    }

    #[Test]
    public function itReturnsCorrectOptionsForLevel(): void
    {
        $options = new InstabilityOptions();

        self::assertSame($options->class, $options->forLevel(SymbolLevel::Class_));
        self::assertSame($options->namespace, $options->forLevel(SymbolLevel::Namespace_));
    }

    #[Test]
    public function itThrowsForUnsupportedLevel(): void
    {
        $options = new InstabilityOptions();

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Level callable is not supported by InstabilityRule');

        $options->forLevel(SymbolLevel::Callable);
    }

    #[Test]
    public function itChecksWhetherLevelIsEnabled(): void
    {
        $options = new InstabilityOptions(
            class: new ClassInstabilityOptions(enabled: true),
            namespace: new NamespaceInstabilityOptions(enabled: false),
        );

        self::assertTrue($options->isLevelEnabled(SymbolLevel::Class_));
        self::assertFalse($options->isLevelEnabled(SymbolLevel::Namespace_));
    }

    #[Test]
    public function itGetsSupportedLevels(): void
    {
        $options = new InstabilityOptions();

        self::assertSame([SymbolLevel::Class_, SymbolLevel::Namespace_], $options->getSupportedLevels());
    }

    #[Test]
    public function itParsesNamespaceMinTypeCountFromArray(): void
    {
        $options = NamespaceInstabilityOptions::fromResolved(ResolvedOptionsFixture::values(NamespaceInstabilityOptions::class, [
            'min_type_count' => 5,
        ]));

        self::assertSame(5, $options->minTypeCount);
    }

    #[Test]
    public function itDefaultsNamespaceMinTypeCountToThree(): void
    {
        $options = NamespaceInstabilityOptions::fromResolved(ResolvedOptionsFixture::values(NamespaceInstabilityOptions::class, [
            'enabled' => true,
        ]));

        self::assertSame(3, $options->minTypeCount);
    }

    #[Test]
    public function itParsesNamespaceMinTypeCountCamelCaseAlias(): void
    {
        $options = NamespaceInstabilityOptions::fromResolved(ResolvedOptionsFixture::values(NamespaceInstabilityOptions::class, [
            'minTypeCount' => 7,
        ]));

        self::assertSame(7, $options->minTypeCount);
    }

    #[Test]
    #[DataProvider('retiredClassCountKeys')]
    public function itRefusesEveryRetiredClassCountSpelling(string $key): void
    {
        self::expectException(\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal::class);
        self::expectExceptionMessage($key);
        ResolvedOptionsFixture::values(NamespaceInstabilityOptions::class, [$key => 3]);
    }

    /** @return iterable<string, array{string}> */
    public static function retiredClassCountKeys(): iterable
    {
        yield 'kebab' => ['min-class-count'];
        yield 'snake' => ['min_class_count'];
        yield 'camel' => ['minClassCount'];
    }

    #[Test]
    #[DataProvider('instabilityThresholdDataProvider')]
    public function itRespectsInstabilityThresholdBoundaries(
        float $instability,
        float $warning,
        float $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new InstabilityRule(
            new InstabilityOptions(
                class: new ClassInstabilityOptions(
                    maxWarning: $warning,
                    maxError: $error,
                ),
            ),
        );

        $symbolPath = SymbolPath::forClass('App', 'Test');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('test.php'), 1);

        $metricBag = (new MetricBag())
            ->with('coupling.instability', $instability)
            ->with('coupling.ca', 5)
            ->with('coupling.ce', 10);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allClassDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('getSubject')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        if ($expectedSeverity === null) {
            self::assertCount(0, $findings);
        } else {
            self::assertCount(1, $findings);
            self::assertSame($expectedSeverity, $findings[0]->severity);
            $selectedThreshold = $expectedSeverity === Severity::Error ? $error : $warning;
            self::assertStringContainsString(($instability === $selectedThreshold ? 'reaches' : 'exceeds') . ' threshold of', $findings[0]->message);
            self::assertSame($instability, $findings[0]->metricValue);
            self::assertEquals($selectedThreshold, $findings[0]->threshold);
        }
    }

    /**
     * @return iterable<string, array{float, float, float, ?Severity}>
     */
    public static function instabilityThresholdDataProvider(): iterable
    {
        yield 'below warning threshold' => [0.79, 0.8, 0.95, null];
        yield 'at warning threshold' => [0.8, 0.8, 0.95, Severity::Warning];
        yield 'raw above warning, same two-digit display' => [0.8049, 0.8, 0.95, Severity::Warning];
        yield 'above warning, below error' => [0.9, 0.8, 0.95, Severity::Warning];
        yield 'at error threshold' => [0.95, 0.8, 0.95, Severity::Error];
        yield 'above error threshold' => [1.0, 0.8, 0.95, Severity::Error];
    }

    #[Test]
    public function itParsesClassMinAfferentFromArray(): void
    {
        $options = ClassInstabilityOptions::fromResolved(ResolvedOptionsFixture::values(ClassInstabilityOptions::class, [
            'min_afferent' => 3,
        ]));

        self::assertSame(3, $options->minAfferent);
    }

    #[Test]
    public function itParsesClassMinAfferentCamelCaseAlias(): void
    {
        $options = ClassInstabilityOptions::fromResolved(ResolvedOptionsFixture::values(ClassInstabilityOptions::class, [
            'minAfferent' => 5,
        ]));

        self::assertSame(5, $options->minAfferent);
    }

    #[Test]
    public function itDefaultsClassMinAfferentToOne(): void
    {
        $options = ClassInstabilityOptions::fromResolved(ResolvedOptionsFixture::values(ClassInstabilityOptions::class, []));

        self::assertSame(1, $options->minAfferent);
    }

    #[Test]
    public function itPreservesClassMinAfferentOnOverride(): void
    {
        $options = new ClassInstabilityOptions(minAfferent: 3);
        $overridden = $options->withOverride(0.9, null);

        self::assertSame(3, $overridden->minAfferent);
        self::assertSame(0.9, $overridden->maxWarning);
    }

    #[Test]
    public function itParsesNamespaceMinAfferentFromArray(): void
    {
        $options = NamespaceInstabilityOptions::fromResolved(ResolvedOptionsFixture::values(NamespaceInstabilityOptions::class, [
            'min_afferent' => 2,
        ]));

        self::assertSame(2, $options->minAfferent);
    }

    #[Test]
    public function itDefaultsNamespaceMinAfferentToOne(): void
    {
        $options = NamespaceInstabilityOptions::fromResolved(ResolvedOptionsFixture::values(NamespaceInstabilityOptions::class, [
            'enabled' => true,
        ]));

        self::assertSame(1, $options->minAfferent);
    }

    #[Test]
    public function itPreservesNamespaceMinAfferentOnOverride(): void
    {
        $options = new NamespaceInstabilityOptions(minAfferent: 4);
        $overridden = $options->withOverride(0.9, null);

        self::assertSame(4, $overridden->minAfferent);
        self::assertSame(0.9, $overridden->maxWarning);
        self::assertSame(3, $overridden->minTypeCount);
    }

    #[Test]
    public function itParsesClassOptionsFromArrayWithCamelCase(): void
    {
        $options = ClassInstabilityOptions::fromResolved(ResolvedOptionsFixture::values(ClassInstabilityOptions::class, [
            'enabled' => false,
            'maxWarning' => 0.7,
            'maxError' => 0.9,
        ]));

        self::assertFalse($options->enabled);
        self::assertSame(0.7, $options->maxWarning);
        self::assertSame(0.9, $options->maxError);
    }

    #[Test]
    public function itParsesNamespaceOptionsFromArrayWithCamelCase(): void
    {
        $options = NamespaceInstabilityOptions::fromResolved(ResolvedOptionsFixture::values(NamespaceInstabilityOptions::class, [
            'enabled' => false,
            'maxWarning' => 0.75,
            'maxError' => 0.92,
        ]));

        self::assertFalse($options->enabled);
        self::assertSame(0.75, $options->maxWarning);
        self::assertSame(0.92, $options->maxError);
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
        $repository->method('getSubject')->willReturn(
            (new MetricBag())->with('coupling.instability', 0.85)->with('coupling.ca', 2)->with('coupling.ce', 12),
        );

        $findings = (new InstabilityRule(new InstabilityOptions()))
            ->analyzeLevel(SymbolLevel::Class_, new AnalysisContext($repository));

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
            $kind === \Qualimetrix\Core\Symbol\CallableKind::Method ? \Qualimetrix\Core\Symbol\DeclarationPath::of(\Qualimetrix\Core\Symbol\SymbolPath::forClass($symbolPath->namespace ?? '', $symbolPath->type ?? ''), $file, \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0)) : null,
        );
    }
}
