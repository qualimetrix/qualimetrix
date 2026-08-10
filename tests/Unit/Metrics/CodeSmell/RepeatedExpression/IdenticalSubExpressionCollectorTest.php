<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\CodeSmell\RepeatedExpression;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Metrics\CodeSmell\RepeatedExpression\IdenticalSubExpressionCollector;

#[CoversClass(IdenticalSubExpressionCollector::class)]
final class IdenticalSubExpressionCollectorTest extends TestCase
{
    #[Test]
    public function itDeliberatelyDoesNotProvideCallableMetrics(): void
    {
        self::assertNotContains(
            \Qualimetrix\Core\Metric\CallableMetricsProviderInterface::class,
            class_implements(new IdenticalSubExpressionCollector()),
        );
    }
}
