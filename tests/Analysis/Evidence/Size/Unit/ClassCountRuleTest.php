<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Size\Unit;

use InvalidArgumentException;
use LogicException;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Evidence\Size\ClassCountOptions;
use Qualimetrix\Analysis\Evidence\Size\ClassCountRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(ClassCountRule::class)]
#[CoversClass(ClassCountOptions::class)]
final class ClassCountRuleTest extends TestCase
{
    #[Test]
    public function itDistinguishesMissingAndZeroOwnCountsFromHealthyNamespaces(): void
    {
        $repository = new \Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository();
        foreach (['Missing' => null, 'Zero' => 0, 'Healthy' => 1] as $name => $count) {
            $repository->add(SymbolPath::forNamespace('App\\' . $name), MetricBag::fromArray($count === null ? [] : ['size.class-count' => $count]), null, null);
        }
        $channel = new \Qualimetrix\Analysis\Finding\Contract\FindingChannel('size.class-count');
        $publication = new \Qualimetrix\Analysis\Finding\Contract\ChannelPublication(new \Qualimetrix\Analysis\Finding\Contract\RuleEnablement([
            new \Qualimetrix\Analysis\Finding\Contract\EnablementDecision(
                new \Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress('size.class-count', $channel, \Qualimetrix\Core\Symbol\SymbolLevel::Namespace_, \Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole::Selectable),
                new \Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision(\Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch::On, \Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission::Direct),
            ),
        ], null));
        $session = new \Qualimetrix\Analysis\Finding\Population\PopulationSession(($publication)->publishes(...));
        self::assertSame([], (new ClassCountRule(new ClassCountOptions()))->analyze((new AnalysisContext($repository))->withPopulationTrace($session)));
        $population = $session->freeze();
        self::assertSame(1, $population->judgedCount());
        self::assertSame(2, $population->unjudgedCount());
        self::assertSame(['nonempty-count', 'own-count'], array_column($population->abstentions(), 'gate'));
        self::assertStringContainsString('Zero', $population->abstentions()[0]->examples[0]);
        self::assertStringContainsString('Missing', $population->abstentions()[1]->examples[0]);
    }

    #[Test]
    public function itGetsName(): void
    {
        $rule = new ClassCountRule(new ClassCountOptions());

        self::assertSame('size.class-count', $rule->getName());
    }

    #[Test]
    public function itGetsDescription(): void
    {
        $rule = new ClassCountRule(new ClassCountOptions());

        self::assertSame('Checks number of classes per namespace', $rule::getDescription());
    }

    #[Test]
    public function itGetsOptionsClass(): void
    {
        self::assertSame(ClassCountOptions::class, ClassCountRule::getOptionsClass());
    }

    #[Test]
    public function itGetsCliAliases(): void
    {
        self::assertSame(
            ['class-count-warning' => 'warning', 'class-count-error' => 'error'],
            CliAliasReader::read(ClassCountRule::class),
        );
    }

    #[Test]
    public function itRejectsWrongOptionsTypeInConstructor(): void
    {
        self::expectException(InvalidArgumentException::class);

        new ClassCountRule(new class implements \Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface {
            public static function fromResolved(ResolvedRuleOptionValues $config): static
            {
                return new static();
            }

            public function isEnabled(): bool
            {
                return true;
            }

            public function getSeverity(int|float $value): ?Severity
            {
                return null;
            }

            public static function acceptedOptionKeys(): RuleOptionKeySet
            {
                return RuleOptionKeySet::of([]);
            }
        });
    }

    #[Test]
    public function itReturnsEmptyWhenDisabled(): void
    {
        $rule = new ClassCountRule(new ClassCountOptions(enabled: false));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itReturnsEmptyWhenBelowThreshold(): void
    {
        $rule = new ClassCountRule(new ClassCountOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $namespaceInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 0);

        $metricBag = (new MetricBag())->with('size.class-count', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$namespaceInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itGeneratesWarning(): void
    {
        $rule = new ClassCountRule(new ClassCountOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $namespaceInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 0);

        // 18 classes is above warning (15) but below error (25)
        $metricBag = (new MetricBag())->with('size.class-count', 18);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$namespaceInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame('Class count is 18, exceeds threshold of 15. Consider splitting into sub-namespaces', $findings[0]->message);
        self::assertSame(18, $findings[0]->metricValue);
        self::assertSame('size.class-count', $findings[0]->ruleName);
        self::assertSame('size.class-count', $findings[0]->code);
    }

    #[Test]
    public function itGeneratesError(): void
    {
        $rule = new ClassCountRule(new ClassCountOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $namespaceInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 0);

        // 30 classes is above error threshold (25)
        $metricBag = (new MetricBag())->with('size.class-count', 30);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$namespaceInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame('Class count is 30, exceeds threshold of 25. Consider splitting into sub-namespaces', $findings[0]->message);
    }

    #[Test]
    #[DataProvider('thresholdDataProvider')]
    public function itRespectsBoundaryThresholds(
        int $classCount,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new ClassCountRule(new ClassCountOptions(warning: $warning, error: $error));

        $symbolPath = SymbolPath::forNamespace('App\Test');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('test.php'), 0);

        $metricBag = (new MetricBag())->with('size.class-count', $classCount);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        if ($expectedSeverity === null) {
            self::assertCount(0, $findings);
        } else {
            self::assertCount(1, $findings);
            self::assertSame($expectedSeverity, $findings[0]->severity);
            $selectedThreshold = $expectedSeverity === Severity::Error ? $error : $warning;
            self::assertStringContainsString(($classCount === $selectedThreshold ? 'reaches' : 'exceeds') . ' threshold of', $findings[0]->message);
        }
    }

    /**
     * @return iterable<string, array{int, int, int, ?Severity}>
     */
    public static function thresholdDataProvider(): iterable
    {
        yield 'below warning' => [9, 10, 15, null];
        yield 'at warning' => [10, 10, 15, Severity::Warning];
        yield 'above warning, below error' => [12, 10, 15, Severity::Warning];
        yield 'at error' => [15, 10, 15, Severity::Error];
        yield 'above error' => [20, 10, 15, Severity::Error];
    }

    #[Test]
    public function itJudgesEachNamespaceByItsOwnClassesIncludingParents(): void
    {
        $rule = new ClassCountRule(new ClassCountOptions());
        $large = SymbolPath::forNamespace('App\\Large');
        $largeChild = SymbolPath::forNamespace('App\\Large\\Child');
        $small = SymbolPath::forNamespace('App\\Small');
        $smallChild = SymbolPath::forNamespace('App\\Small\\Child');
        $counts = [
            'App\\Large' => (new MetricBag())->with('size.class-count', 30)->with('size.class-count.sum', 31),
            'App\\Large\\Child' => (new MetricBag())->with('size.class-count', 1)->with('size.class-count.sum', 1),
            'App\\Small' => (new MetricBag())->with('size.class-count', 3)->with('size.class-count.sum', 30),
            'App\\Small\\Child' => (new MetricBag())->with('size.class-count', 27)->with('size.class-count.sum', 27),
        ];
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([
            self::subjectInfo($large, RelativePath::fromString('src/Large.php'), 0),
            self::subjectInfo($largeChild, RelativePath::fromString('src/Large/Child.php'), 0),
            self::subjectInfo($small, RelativePath::fromString('src/Small.php'), 0),
            self::subjectInfo($smallChild, RelativePath::fromString('src/Small/Child.php'), 0),
        ]);
        $repository->method('get')->willReturnCallback(static function (SymbolPath $path) use ($counts): MetricBag {
            if ($path->namespace === null || !\array_key_exists($path->namespace, $counts)) {
                throw new LogicException('The fixture has no count for this namespace.');
            }

            return $counts[$path->namespace];
        });

        $findings = $rule->analyze(new AnalysisContext($repository, namespaceTree: new NamespaceTree([
            'App\\Large', 'App\\Large\\Child', 'App\\Small', 'App\\Small\\Child',
        ])));

        self::assertCount(2, $findings);
        self::assertSame([30, 27], array_map(static fn($finding): int|float|null => $finding->metricValue, $findings));
        self::assertSame([$large, $smallChild], array_map(static fn($finding): SymbolPath => $finding->symbolPath, $findings));
    }

    #[Test]
    public function itStaysSilentWhenOnlySubtreeCountCrossesThreshold(): void
    {
        $rule = new ClassCountRule(new ClassCountOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $namespaceInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 0);

        $metricBag = (new MetricBag())
            ->with('size.class-count', 3)
            ->with('size.class-count.sum', 18);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$namespaceInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertSame([], $findings);
    }

    #[Test]
    public function itJudgesOwnCountWhenParentSubtreeIsLarger(): void
    {
        $rule = new ClassCountRule(new ClassCountOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $namespaceInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 0);

        $metricBag = (new MetricBag())
            ->with('size.class-count', 30)
            ->with('size.class-count.sum', 35);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$namespaceInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        $findings = $rule->analyze($context);
        self::assertCount(1, $findings);
        self::assertSame(30, $findings[0]->metricValue);
    }

    #[Test]
    public function itLoadsOptionsDefaultsFromArray(): void
    {
        $options = ClassCountOptions::fromResolved(ResolvedOptionsFixture::values(ClassCountOptions::class, ['enabled' => true]));

        self::assertTrue($options->isEnabled());
        self::assertSame(15, $options->warning);
        self::assertSame(25, $options->error);
    }

    #[Test]
    public function itLoadsOptionsCustomValuesFromArray(): void
    {
        $options = ClassCountOptions::fromResolved(ResolvedOptionsFixture::values(ClassCountOptions::class, [
            'enabled' => true,
            'warning' => 10,
            'error' => 20,
        ]));

        self::assertTrue($options->isEnabled());
        self::assertSame(10, $options->warning);
        self::assertSame(20, $options->error);
    }

    #[Test]
    public function itUsesConstructorDefaultsForAnEmptyBodyAndHonoursExplicitDisablement(): void
    {
        self::assertEquals(new ClassCountOptions(), ClassCountOptions::fromResolved(ResolvedOptionsFixture::values(ClassCountOptions::class, [])));
        self::assertFalse(ClassCountOptions::fromResolved(ResolvedOptionsFixture::values(ClassCountOptions::class, ['enabled' => false]))->isEnabled());
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
