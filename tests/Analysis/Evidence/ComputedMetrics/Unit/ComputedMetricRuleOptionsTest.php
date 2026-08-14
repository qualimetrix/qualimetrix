<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRuleOptions;

#[CoversClass(ComputedMetricRuleOptions::class)]
final class ComputedMetricRuleOptionsTest extends TestCase
{
    #[Test]
    public function itLoadsDefinitionsFromHolderWhenPopulated(): void
    {
        $options = ComputedMetricRuleOptions::fromArray([]);

        self::assertTrue($options->isEnabled());
    }

    #[Test]
    public function itDisablesWhenEnabledFalse(): void
    {
        $options = ComputedMetricRuleOptions::fromArray(['enabled' => false]);

        self::assertFalse($options->isEnabled());
    }

    #[Test]
    public function itIsEnabledByDefault(): void
    {
        $options = ComputedMetricRuleOptions::fromArray([]);

        self::assertTrue($options->isEnabled());
    }

    #[Test]
    public function itAlwaysReturnsNullSeverity(): void
    {
        $options = new ComputedMetricRuleOptions();

        self::assertNull($options->getSeverity(0));
        self::assertNull($options->getSeverity(100));
        self::assertNull($options->getSeverity(-50.5));
    }

    #[Test]
    public function itReturnsHolderDefinitions(): void
    {
        self::assertTrue(ComputedMetricRuleOptions::fromArray(['unrelated' => true])->isEnabled());
    }

    #[Test]
    public function itHasCorrectConstructorDefaults(): void
    {
        $options = new ComputedMetricRuleOptions();

        self::assertTrue($options->isEnabled());
    }
}
