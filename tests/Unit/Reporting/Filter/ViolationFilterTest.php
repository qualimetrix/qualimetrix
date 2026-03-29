<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Filter\ViolationFilter;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Health\WorstOffender;

#[CoversClass(ViolationFilter::class)]
final class ViolationFilterTest extends TestCase
{
    private ViolationFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new ViolationFilter();
    }

    // --- filterViolations ---

    public function testFilterViolationsReturnsAllWhenNoFilter(): void
    {
        $violations = [
            $this->createViolation('App\\Service', 'Foo'),
            $this->createViolation('App\\Other', 'Bar'),
        ];

        $context = new FormatterContext();

        $result = $this->filter->filterViolations($violations, $context);

        self::assertCount(2, $result);
    }

    public function testFilterViolationsByNamespaceExactMatch(): void
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

    public function testFilterViolationsByNamespaceMatchesChildren(): void
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

    public function testFilterViolationsByNamespaceDoesNotMatchSimilarPrefix(): void
    {
        $violations = [
            $this->createViolation('App\\ServiceManager', 'Handler'),
        ];

        $context = new FormatterContext(namespace: 'App\\Service');

        $result = $this->filter->filterViolations($violations, $context);

        self::assertSame([], $result);
    }

    public function testFilterViolationsByClassExactMatch(): void
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

    public function testFilterViolationsByClassReturnsFalseWhenNoType(): void
    {
        // Namespace-level violation (no type)
        $violation = new Violation(
            location: new Location('src/Service.php', 1),
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

    public function testFilterViolationsByClassWithGlobalNamespace(): void
    {
        $violations = [
            $this->createViolation('', 'GlobalClass'),
        ];

        $context = new FormatterContext(class: 'GlobalClass');

        $result = $this->filter->filterViolations($violations, $context);

        self::assertCount(1, $result);
    }

    // --- filterWorstOffenders ---

    public function testFilterWorstOffendersReturnsAllWhenNoFilter(): void
    {
        $offenders = [
            $this->createOffender('App\\Service', 'Foo'),
            $this->createOffender('App\\Other', 'Bar'),
        ];

        $context = new FormatterContext();

        $result = $this->filter->filterWorstOffenders($offenders, $context);

        self::assertCount(2, $result);
    }

    public function testFilterWorstOffendersByNamespace(): void
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

    public function testFilterWorstOffendersByNamespaceMatchesChildren(): void
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

    public function testFilterWorstOffendersByClass(): void
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

    public function testFilterWorstOffendersByClassNoMatch(): void
    {
        $offenders = [
            $this->createOffender('App\\Service', 'OrderService'),
        ];

        $context = new FormatterContext(class: 'App\\Service\\UserService');

        $result = $this->filter->filterWorstOffenders($offenders, $context);

        self::assertSame([], $result);
    }

    private function createViolation(string $namespace, string $class): Violation
    {
        return new Violation(
            location: new Location('src/test.php', 1),
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
            file: 'src/test.php',
            healthOverall: 50.0,
            label: 'Warning',
            reason: 'test reason',
            violationCount: 0,
            classCount: 0,
        );
    }
}
