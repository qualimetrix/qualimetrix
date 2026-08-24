<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderEvidence;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Reporting\Filter\FindingFilter;
use Qualimetrix\Reporting\FormatterContext;

#[CoversClass(FindingFilter::class)]
final class FindingFilterTest extends TestCase
{
    private FindingFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new FindingFilter();
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

        $result = $this->filter->filterFindings($findings, $context);

        self::assertCount(2, $result);
    }

    #[Test]
    public function itFiltersFindingsByNamespaceExactMatch(): void
    {
        $findings = [
            $this->createFinding('App\\Service', 'Foo'),
            $this->createFinding('App\\Other', 'Bar'),
        ];

        $context = new FormatterContext(namespace: 'App\\Service');

        $result = $this->filter->filterFindings($findings, $context);

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

        $context = new FormatterContext(namespace: 'App\\Service');

        $result = $this->filter->filterFindings($findings, $context);

        self::assertCount(1, $result);
        self::assertSame('Gateway', $result[0]->symbolPath->type);
    }

    #[Test]
    public function itDoesNotMatchFindingsBySimilarNamespacePrefix(): void
    {
        $findings = [
            $this->createFinding('App\\ServiceManager', 'Handler'),
        ];

        $context = new FormatterContext(namespace: 'App\\Service');

        $result = $this->filter->filterFindings($findings, $context);

        self::assertSame([], $result);
    }

    /**
     * The selector is the shared namespace-pattern primitive, so a glob is a
     * pattern here too rather than a prefix that happens to contain a star.
     */
    #[Test]
    public function itFiltersFindingsByAGlobNamespaceSelector(): void
    {
        $findings = [
            $this->createFinding('App\\Domain\\Order', 'Handler'),
            $this->createFinding('App\\Infra\\Order', 'Handler'),
            $this->createFinding('Lib\\Domain\\Order', 'Handler'),
        ];

        $context = new FormatterContext(namespace: 'App\\*\\Order');

        $result = $this->filter->filterFindings($findings, $context);

        self::assertCount(2, $result);
    }

    /** An empty selector names no namespace, so it selects nothing rather than the global one. */
    #[Test]
    public function itSelectsNothingForAnEmptyNamespaceSelector(): void
    {
        $findings = [
            $this->createFinding('', 'Handler'),
            $this->createFinding('App', 'Handler'),
        ];

        $context = new FormatterContext(namespace: '');

        self::assertSame([], $this->filter->filterFindings($findings, $context));
    }

    #[Test]
    public function itFiltersFindingsByClassExactMatch(): void
    {
        $findings = [
            $this->createFinding('App\\Service', 'UserService'),
            $this->createFinding('App\\Service', 'OrderService'),
        ];

        $context = new FormatterContext(class: 'App\\Service\\UserService');

        $result = $this->filter->filterFindings($findings, $context);

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

        $result = $this->filter->filterFindings([$finding], $context);

        self::assertSame([], $result);
    }

    #[Test]
    public function itFiltersFindingsByClassWithGlobalNamespace(): void
    {
        $findings = [
            $this->createFinding('', 'GlobalClass'),
        ];

        $context = new FormatterContext(class: 'GlobalClass');

        $result = $this->filter->filterFindings($findings, $context);

        self::assertCount(1, $result);
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

        $context = new FormatterContext(namespace: 'App\\Service');

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

        $context = new FormatterContext(namespace: 'App\\Service');

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
    public function itIgnoresATrailingBackslashInTheNamespaceSelector(): void
    {
        $findings = [
            $this->createFinding('App\\Service', 'Foo'),
            $this->createFinding('App\\Other', 'Bar'),
        ];

        $result = $this->filter->filterFindings($findings, new FormatterContext(namespace: 'App\\Service\\'));

        self::assertCount(1, $result);
    }

    #[Test]
    public function itIgnoresATrailingBackslashWhenFilteringWorstOffenders(): void
    {
        $offenders = [
            $this->createOffender('App\\Service', 'UserService'),
            $this->createOffender('App\\Other', 'OrderService'),
        ];

        $result = $this->filter->filterWorstOffenders($offenders, new FormatterContext(namespace: 'App\\Service\\'));

        self::assertCount(1, $result);
    }

    #[Test]
    public function itNeverSelectsProjectWideFindingsByNamespace(): void
    {
        $findings = [
            $this->createFinding('App\\Service', 'Foo'),
            $this->createProjectFinding(),
        ];

        foreach (['*', 'App\\*', 'App\\Service'] as $selector) {
            $result = $this->filter->filterFindings($findings, new FormatterContext(namespace: $selector));

            foreach ($result as $finding) {
                self::assertNotSame(
                    SymbolType::Project,
                    $finding->symbolPath->getType(),
                    \sprintf('Selector "%s" must not reach the project sentinel.', $selector),
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
            ruleName: 'architecture.coverage',
            code: 'architecture.coverage',
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
            symbolPath: SymbolPath::forClass($namespace, $class),
            file: RelativePath::fromString('src/test.php'),
            healthOverall: 50.0,
            label: 'Warning',
            reason: 'test reason',
            evidence: new WorstOffenderEvidence(
                violationCount: 0,
                classCount: 0,
            ),
        );
    }
}
