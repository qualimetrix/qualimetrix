<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionCaptureHolder;

#[CoversClass(RuleExclusionCaptureHolder::class)]
final class RuleExclusionCaptureHolderTest extends TestCase
{
    protected function tearDown(): void
    {
        RuleExclusionCaptureHolder::reset();
    }

    #[Test]
    public function itIsDisabledByDefault(): void
    {
        self::assertFalse(RuleExclusionCaptureHolder::isEnabled());
    }

    #[Test]
    public function itCanBeEnabled(): void
    {
        RuleExclusionCaptureHolder::set(true);

        self::assertTrue(RuleExclusionCaptureHolder::isEnabled());
    }

    #[Test]
    public function itCanBeDisabledAfterBeingEnabled(): void
    {
        RuleExclusionCaptureHolder::set(true);
        RuleExclusionCaptureHolder::set(false);

        self::assertFalse(RuleExclusionCaptureHolder::isEnabled());
    }

    #[Test]
    public function itResetsToDisabled(): void
    {
        RuleExclusionCaptureHolder::set(true);
        RuleExclusionCaptureHolder::reset();

        self::assertFalse(RuleExclusionCaptureHolder::isEnabled());
    }
}
