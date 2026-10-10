<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit\DrillDown;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderEvidence;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Reporting\DrillDown\FindingFilter;
use Qualimetrix\Reporting\FormatterContext;

#[CoversClass(FindingFilter::class)]
final class FindingFilterTest extends TestCase
{
    private FindingFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new FindingFilter();
    }

    /** @return iterable<string, array{list<string>, ?string, list<string>, string}> */
    public static function namespacePublicationCases(): iterable
    {
        yield 'named file' => [['Shop'], null, ['Shop'], 'Shop'];
        yield 'multiple file' => [['Shop', 'Other'], null, ['Other', 'Shop'], 'Other, Shop'];
        yield 'global file' => [[], null, [''], '(global)'];
        yield 'declaration owns its namespace' => [['Other'], 'Shop', ['Shop'], 'Shop'];
    }

    /**
     * @param list<string> $declared
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('namespacePublicationCases')]
    public function itPublishesTheNamespacesUsedForSelection(array $declared, ?string $own, array $expected, string $group): void
    {
        $file = RelativePath::fromString('src/Multi.php');
        $repository = new InMemoryMetricRepository();
        foreach ($declared as $i => $namespace) {
            $repository->addSubject(MetricSubject::declaration(DeclarationPath::of(
                SymbolPath::forClass($namespace, 'Marker' . $i),
                $file,
                DeclarationOrdinal::fromRank(0),
            )), new MetricBag(), $file, 1 + $i);
        }
        $symbol = SymbolPath::forFile($file);
        $subject = $own === null ? MetricSubject::aggregate($symbol) : MetricSubject::declaration(DeclarationPath::of(
            SymbolPath::forMethod($own, 'Cart', 'run'),
            $file,
            DeclarationOrdinal::fromRank(0),
        ));
        $finding = new Finding(new Location($file, 7), $subject, $symbol, 'duplication.clone', 'duplication.clone', 'Physical copy', Severity::Warning);
        $index = FileNamespaceIndex::fromRepository($repository);
        $context = new FormatterContext(namespace: $expected === ['']
            ? \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::regex('^$')
            : \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::exact('Shop'));
        $selected = $this->filter->filterFindings([$finding], $context, $index);
        self::assertSame([$finding], $selected);
        self::assertSame($finding->getFingerprint(), $selected[0]->getFingerprint());
        $record = (new \Qualimetrix\Reporting\Formatter\FindingRecord(
            new \Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry(self::createStub(\Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface::class), ['duplication.clone' => 1]),
            new \Qualimetrix\Reporting\Formatter\Json\JsonSanitizer(),
        ))->of($selected[0], $context, $index);
        self::assertSame(\count($expected) === 1 ? $expected[0] : null, $record['namespace']);
        self::assertSame($expected, $record['namespaces']);
        self::assertSame([$group => [$finding]], \Qualimetrix\Reporting\Formatter\Ordering\FindingSorter::group($selected, \Qualimetrix\Reporting\GroupBy::NamespaceName, $index));
    }

    // --- filterFindings ---

    #[Test]
    public function itReturnsAllFindingsWhenNoFilter(): void
    {
        $findings = [
            $this->createFinding('App\\Service', 'Foo'),
            $this->createFinding('App\\Other', 'Bar'),
        ];

        $context = new FormatterContext();

        $result = $this->filter->filterFindings($findings, $context, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

        self::assertCount(2, $result);
    }

    #[Test]
    public function itFiltersFindingsByNamespaceExactMatch(): void
    {
        $findings = [
            $this->createFinding('App\\Service', 'Foo'),
            $this->createFinding('App\\Other', 'Bar'),
        ];

        $context = new FormatterContext(namespace: \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::exact('App\\Service'));

        $result = $this->filter->filterFindings($findings, $context, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

        self::assertCount(1, $result);
        self::assertSame('Foo', $result[0]->symbolPath->type);
    }

    #[Test]
    public function itFiltersFindingsByNamespaceMatchingChildren(): void
    {
        $findings = [
            $this->createFinding('App\\Service\\Payment', 'Gateway'),
            $this->createFinding('App\\Other', 'Bar'),
        ];

        $context = new FormatterContext(namespace: \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::subtree('App\\Service'));

        $result = $this->filter->filterFindings($findings, $context, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

        self::assertCount(1, $result);
        self::assertSame('Gateway', $result[0]->symbolPath->type);
    }

    #[Test]
    public function itDoesNotMatchFindingsBySimilarNamespacePrefix(): void
    {
        $findings = [
            $this->createFinding('App\\ServiceManager', 'Handler'),
        ];

        $context = new FormatterContext(namespace: \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::subtree('App\\Service'));

        $result = $this->filter->filterFindings($findings, $context, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

        self::assertSame([], $result);
    }

    /**
     * The selector is the shared namespace-pattern primitive, so a glob is a
     * pattern here too rather than a prefix that happens to contain a star.
     */
    #[Test]
    public function itFiltersFindingsByARegexNamespaceSelector(): void
    {
        $findings = [
            $this->createFinding('App\\Domain\\Order', 'Handler'),
            $this->createFinding('App\\Infra\\Order', 'Handler'),
            $this->createFinding('Lib\\Domain\\Order', 'Handler'),
        ];

        $context = new FormatterContext(namespace: \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::regex('App\\\\[^\\\\]+\\\\Order'));

        $result = $this->filter->filterFindings($findings, $context, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

        self::assertCount(2, $result);
    }

    #[Test]
    public function itFiltersFindingsByClassExactMatch(): void
    {
        $findings = [
            $this->createFinding('App\\Service', 'UserService'),
            $this->createFinding('App\\Service', 'OrderService'),
        ];

        $context = new FormatterContext(class: 'App\\Service\\UserService');

        $result = $this->filter->filterFindings($findings, $context, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

        self::assertCount(1, $result);
        self::assertSame('UserService', $result[0]->symbolPath->type);
    }

    #[Test]
    public function itExcludesFindingByClassWhenNoType(): void
    {
        // Namespace-level finding (no type)
        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/Service.php'), 1),
            subject: MetricSubject::aggregate(SymbolPath::forNamespace('App\\Service')),
            symbolPath: SymbolPath::forNamespace('App\\Service'),
            ruleName: 'test.rule',
            code: 'T001',
            message: 'test',
            severity: Severity::Warning,
        );

        $context = new FormatterContext(class: 'App\\Service\\UserService');

        $result = $this->filter->filterFindings([$finding], $context, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

        self::assertSame([], $result);
    }

    #[Test]
    public function itFiltersFindingsByClassWithGlobalNamespace(): void
    {
        $findings = [
            $this->createFinding('', 'GlobalClass'),
        ];

        $context = new FormatterContext(class: 'GlobalClass');

        $result = $this->filter->filterFindings($findings, $context, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

        self::assertCount(1, $result);
    }

    #[Test]
    public function itSelectsFileFindingsByEveryNamespaceDeclaredInTheirSource(): void
    {
        $repository = new InMemoryMetricRepository();
        $file = RelativePath::fromString('src/Multi.php');
        $repository->addSubject(
            MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('Shop\\Inner', 'First'), $file, DeclarationOrdinal::fromRank(0))),
            new MetricBag(),
            $file,
            1,
        );
        $repository->add(SymbolPath::forClass('Other', 'Second'), new MetricBag(), $file, 10);
        $finding = new Finding(
            location: new Location($file, 3),
            subject: MetricSubject::aggregate(SymbolPath::forFile($file)),
            symbolPath: SymbolPath::forFile($file),
            ruleName: 'duplication.clone',
            code: 'duplication.clone',
            message: 'Physical copy',
            severity: Severity::Warning,
        );
        $index = FileNamespaceIndex::fromRepository($repository);

        foreach ([
            \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::subtree('Shop'),
            \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::exact('Other'),
        ] as $namespace) {
            self::assertSame([$finding], $this->filter->filterFindings([$finding], new FormatterContext(namespace: $namespace), $index));
        }
        self::assertSame([], $this->filter->filterFindings([$finding], new FormatterContext(class: 'Shop\\Inner\\First'), $index));
    }

    #[Test]
    public function itSelectsUndeclaredFileFindingsAsGlobalWithoutChangingTheirIdentity(): void
    {
        $file = RelativePath::fromString('src/Script.php');
        $finding = new Finding(
            location: new Location($file, 7),
            subject: MetricSubject::aggregate(SymbolPath::forFile($file)),
            symbolPath: SymbolPath::forFile($file),
            ruleName: 'duplication.clone',
            code: 'duplication.clone',
            message: 'Physical copy',
            severity: Severity::Warning,
        );
        $global = new FormatterContext(namespace: \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::regex('.*'));
        $shop = new FormatterContext(namespace: \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::subtree('Shop'));

        foreach ([FileNamespaceIndex::fromRepository(null)] as $index) {
            self::assertSame([$finding], $this->filter->filterFindings([$finding], $global, $index));
            self::assertSame([], $this->filter->filterFindings([$finding], $shop, $index));
            self::assertSame([], $this->filter->filterFindings([$finding], new FormatterContext(class: 'Shop\\Cart'), $index));
        }
    }

    #[Test]
    public function itUsesADeclaredSubjectBeforeTheOtherNamespacesOfItsFile(): void
    {
        $file = RelativePath::fromString('src/Multi.php');
        $subject = MetricSubject::declaration(DeclarationPath::of(
            SymbolPath::forMethod('Shop', 'Cart', 'run'),
            $file,
            DeclarationOrdinal::fromRank(0),
        ));
        $finding = new Finding(
            location: new Location($file, 7),
            subject: $subject,
            symbolPath: SymbolPath::forFile($file),
            ruleName: 'code-smell.eval',
            code: 'code-smell.eval',
            message: 'Declared site',
            severity: Severity::Warning,
        );
        $repository = new InMemoryMetricRepository();
        $repository->add(SymbolPath::forClass('Other', 'Marker'), new MetricBag(), $file, 20);
        $index = FileNamespaceIndex::fromRepository($repository);

        self::assertSame([$finding], $this->filter->filterFindings([$finding], new FormatterContext(
            namespace: \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::exact('Shop'),
        ), $index));
        self::assertSame([], $this->filter->filterFindings([$finding], new FormatterContext(
            namespace: \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::exact('Other'),
        ), $index));
    }

    // --- filterWorstOffenders ---

    #[Test]
    public function itReturnsAllWorstOffendersWhenNoFilter(): void
    {
        $offenders = [
            $this->createOffender('App\\Service', 'Foo'),
            $this->createOffender('App\\Other', 'Bar'),
        ];

        $context = new FormatterContext();

        $result = $this->filter->filterWorstOffenders($offenders, $context);

        self::assertCount(2, $result);
    }

    #[Test]
    public function itFiltersWorstOffendersByNamespace(): void
    {
        $offenders = [
            $this->createOffender('App\\Service', 'Foo'),
            $this->createOffender('App\\Other', 'Bar'),
        ];

        $context = new FormatterContext(namespace: \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::subtree('App\\Service'));

        $result = $this->filter->filterWorstOffenders($offenders, $context);

        self::assertCount(1, $result);
        self::assertSame('Foo', $result[0]->symbolPath->type);
    }

    #[Test]
    public function itFiltersWorstOffendersByNamespaceMatchingChildren(): void
    {
        $offenders = [
            $this->createOffender('App\\Service\\Sub', 'Handler'),
            $this->createOffender('App\\Other', 'Bar'),
        ];

        $context = new FormatterContext(namespace: \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::subtree('App\\Service'));

        $result = $this->filter->filterWorstOffenders($offenders, $context);

        self::assertCount(1, $result);
        self::assertSame('Handler', $result[0]->symbolPath->type);
    }

    #[Test]
    public function itFiltersWorstOffendersByClass(): void
    {
        $offenders = [
            $this->createOffender('App\\Service', 'UserService'),
            $this->createOffender('App\\Service', 'OrderService'),
        ];

        $context = new FormatterContext(class: 'App\\Service\\UserService');

        $result = $this->filter->filterWorstOffenders($offenders, $context);

        self::assertCount(1, $result);
        self::assertSame('UserService', $result[0]->symbolPath->type);
    }

    #[Test]
    public function itReturnsEmptyWhenWorstOffendersByClassNoMatch(): void
    {
        $offenders = [
            $this->createOffender('App\\Service', 'OrderService'),
        ];

        $context = new FormatterContext(class: 'App\\Service\\UserService');

        $result = $this->filter->filterWorstOffenders($offenders, $context);

        self::assertSame([], $result);
    }

    #[Test]
    public function itNeverSelectsProjectWideFindingsByNamespace(): void
    {
        $findings = [
            $this->createFinding('App\\Service', 'Foo'),
            $this->createProjectFinding(),
        ];

        foreach ([
            \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::regex('.*'),
            \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::subtree('App'),
            \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::exact('App\\Service'),
        ] as $selector) {
            $result = $this->filter->filterFindings($findings, new FormatterContext(namespace: $selector), \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

            foreach ($result as $finding) {
                self::assertNotSame(
                    SymbolType::Project,
                    $finding->symbolPath->getType(),
                    \sprintf('Selector "%s" must not reach the project sentinel.', $selector->definition->display()),
                );
            }
        }
    }

    private function createProjectFinding(): Finding
    {
        return new Finding(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: 'architecture.coverage-gap',
            code: 'architecture.coverage-gap',
            message: 'project-wide finding',
            severity: Severity::Error,
        );
    }

    private function createFinding(string $namespace, string $class): Finding
    {
        return new Finding(
            location: new Location(RelativePath::fromString('src/test.php'), 1),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass($namespace, $class), RelativePath::fromString('src/test.php'), DeclarationOrdinal::fromRank(0))),
            symbolPath: SymbolPath::forClass($namespace, $class),
            ruleName: 'test.rule',
            code: 'T001',
            message: 'test violation',
            severity: Severity::Warning,
        );
    }

    private function createOffender(string $namespace, string $class): WorstOffender
    {
        return new WorstOffender(
            subject: \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of(SymbolPath::forClass($namespace, $class), RelativePath::fromString('src/test.php'), \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
            healthOverall: 50.0,
            label: 'Warning',
            reason: 'test reason',
            evidence: new WorstOffenderEvidence(
                violationCount: 0,
                classCount: 0,
            ),
            overallThresholds: [50.0, 30.0],
        );
    }
}
