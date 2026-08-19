<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderEvidence;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Reporting\Filter\ViolationFilter;
use Qualimetrix\Reporting\FormatterContext;

#[CoversClass(ViolationFilter::class)]
final class ViolationFilterTest extends TestCase
{
    private ViolationFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new ViolationFilter();
    }

    // --- filterViolations ---

    #[Test]
    public function itReturnsAllViolationsWhenNoFilter(): void
    {
        $violations = [
            $this->createViolation('App\\Service', 'Foo'),
            $this->createViolation('App\\Other', 'Bar'),
        ];

        $context = new FormatterContext();

        $result = $this->filter->filterViolations($violations, $context);

        self::assertCount(2, $result);
    }

    #[Test]
    public function itFiltersViolationsByNamespaceExactMatch(): void
    {
        $violations = [
            $this->createViolation('App\\Service', 'Foo'),
            $this->createViolation('App\\Other', 'Bar'),
        ];

        $context = new FormatterContext(namespace: 'App\\Service');

        $result = $this->filter->filterViolations($violations, $context);

        self::assertCount(1, $result);
        self::assertSame('Foo', $result[0]->symbolPath->type);
    }

    #[Test]
    public function itFiltersViolationsByNamespaceMatchingChildren(): void
    {
        $violations = [
            $this->createViolation('App\\Service\\Payment', 'Gateway'),
            $this->createViolation('App\\Other', 'Bar'),
        ];

        $context = new FormatterContext(namespace: 'App\\Service');

        $result = $this->filter->filterViolations($violations, $context);

        self::assertCount(1, $result);
        self::assertSame('Gateway', $result[0]->symbolPath->type);
    }

    #[Test]
    public function itDoesNotMatchViolationsBySimilarNamespacePrefix(): void
    {
        $violations = [
            $this->createViolation('App\\ServiceManager', 'Handler'),
        ];

        $context = new FormatterContext(namespace: 'App\\Service');

        $result = $this->filter->filterViolations($violations, $context);

        self::assertSame([], $result);
    }

    /**
     * The selector is the shared namespace-pattern primitive, so a glob is a
     * pattern here too rather than a prefix that happens to contain a star.
     */
    #[Test]
    public function itFiltersViolationsByAGlobNamespaceSelector(): void
    {
        $violations = [
            $this->createViolation('App\\Domain\\Order', 'Handler'),
            $this->createViolation('App\\Infra\\Order', 'Handler'),
            $this->createViolation('Lib\\Domain\\Order', 'Handler'),
        ];

        $context = new FormatterContext(namespace: 'App\\*\\Order');

        $result = $this->filter->filterViolations($violations, $context);

        self::assertCount(2, $result);
    }

    /** An empty selector names no namespace, so it selects nothing rather than the global one. */
    #[Test]
    public function itSelectsNothingForAnEmptyNamespaceSelector(): void
    {
        $violations = [
            $this->createViolation('', 'Handler'),
            $this->createViolation('App', 'Handler'),
        ];

        $context = new FormatterContext(namespace: '');

        self::assertSame([], $this->filter->filterViolations($violations, $context));
    }

    #[Test]
    public function itFiltersViolationsByClassExactMatch(): void
    {
        $violations = [
            $this->createViolation('App\\Service', 'UserService'),
            $this->createViolation('App\\Service', 'OrderService'),
        ];

        $context = new FormatterContext(class: 'App\\Service\\UserService');

        $result = $this->filter->filterViolations($violations, $context);

        self::assertCount(1, $result);
        self::assertSame('UserService', $result[0]->symbolPath->type);
    }

    #[Test]
    public function itExcludesViolationByClassWhenNoType(): void
    {
        // Namespace-level violation (no type)
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Service.php'), 1),
            subject: MetricSubject::aggregate(SymbolPath::forNamespace('App\\Service')),
            symbolPath: SymbolPath::forNamespace('App\\Service'),
            ruleName: 'test.rule',
            violationCode: 'T001',
            message: 'test',
            severity: Severity::Warning,
        );

        $context = new FormatterContext(class: 'App\\Service\\UserService');

        $result = $this->filter->filterViolations([$violation], $context);

        self::assertSame([], $result);
    }

    #[Test]
    public function itFiltersViolationsByClassWithGlobalNamespace(): void
    {
        $violations = [
            $this->createViolation('', 'GlobalClass'),
        ];

        $context = new FormatterContext(class: 'GlobalClass');

        $result = $this->filter->filterViolations($violations, $context);

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
        $violations = [
            $this->createViolation('App\\Service', 'Foo'),
            $this->createViolation('App\\Other', 'Bar'),
        ];

        $result = $this->filter->filterViolations($violations, new FormatterContext(namespace: 'App\\Service\\'));

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
        $violations = [
            $this->createViolation('App\\Service', 'Foo'),
            $this->createProjectViolation(),
        ];

        foreach (['*', 'App\\*', 'App\\Service'] as $selector) {
            $result = $this->filter->filterViolations($violations, new FormatterContext(namespace: $selector));

            foreach ($result as $violation) {
                self::assertNotSame(
                    SymbolType::Project,
                    $violation->symbolPath->getType(),
                    \sprintf('Selector "%s" must not reach the project sentinel.', $selector),
                );
            }
        }
    }

    private function createProjectViolation(): Violation
    {
        return new Violation(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: 'architecture.coverage',
            violationCode: 'architecture.coverage',
            message: 'project-wide finding',
            severity: Severity::Error,
        );
    }

    private function createViolation(string $namespace, string $class): Violation
    {
        return new Violation(
            location: new Location(RelativePath::fromString('src/test.php'), 1),
            subject: MetricSubject::declaration(new DeclarationPath(
                SymbolPath::forClass($namespace, $class),
                RelativePath::fromString('src/test.php'),
                0,
            )),
            symbolPath: SymbolPath::forClass($namespace, $class),
            ruleName: 'test.rule',
            violationCode: 'T001',
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
