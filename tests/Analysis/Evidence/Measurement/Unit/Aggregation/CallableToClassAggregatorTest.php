<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit\Aggregation;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\CallableToClassAggregator;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(CallableToClassAggregator::class)]
final class CallableToClassAggregatorTest extends TestCase
{
    /**
     * A callable without an exact declaration subject used to be skipped, while
     * the same loop counted it in `size.symbol-method-count` — a class summing
     * one population and counting another. `MetricSubjectIndex::store()` keeps
     * the state out of a real run today, so the repository is stubbed: the
     * project's rule for this condition is a refusal, and the refusal has to
     * survive whatever makes the state reachable later.
     */
    #[Test]
    public function itRefusesACallableWithoutAnExactDeclarationSubject(): void
    {
        $owner = new LogicalClassPath(SymbolPath::forClass('App', 'Service'));
        $subjectless = new SymbolInfo(
            SymbolPath::forMethod('App', 'Service', 'calculate'),
            RelativePath::fromString('src/Service.php'),
            10,
            CallableKind::Method,
            $owner,
        );
        self::assertNull($subjectless->subject, 'the fixture must actually carry no subject');

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allCallables')->willReturn([$subjectless]);
        $repository->method('get')->willReturn(new MetricBag());
        $repository->method('getSubject')->willReturn(new MetricBag());

        $definitions = [new MetricDefinition('complexity.ccn', SymbolLevel::Callable, [
            SymbolLevel::Class_->value => [AggregationStrategy::Sum],
        ])];

        self::expectException(LogicException::class);
        self::expectExceptionMessage('Callable metrics require an exact declaration subject');

        (new CallableToClassAggregator(self::createStub(ProfilerInterface::class)))
            ->aggregate($repository, $definitions);
    }
}
