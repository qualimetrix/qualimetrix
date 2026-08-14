<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\RepeatedExpression\IdenticalSubExpressionCollector;

#[CoversClass(IdenticalSubExpressionCollector::class)]
final class IdenticalSubExpressionCollectorTest extends TestCase
{
    #[Test]
    public function itDeliberatelyDoesNotProvideCallableMetrics(): void
    {
        self::assertNotContains(
            \Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableMetricsProviderInterface::class,
            class_implements(new IdenticalSubExpressionCollector()),
        );
    }
}
