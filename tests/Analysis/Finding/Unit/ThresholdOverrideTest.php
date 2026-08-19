<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(ThresholdOverride::class)]
final class ThresholdOverrideTest extends TestCase
{
    #[Test]
    public function itMatchesExact(): void
    {
        $override = new ThresholdOverride(
            rulePattern: 'complexity.cyclomatic',
            warning: 15,
            error: 25,
            line: 10,
            subject: $this->subject(),
            controlScope: ControlScope::Callable,
        );

        self::assertTrue($override->matches('complexity.cyclomatic'));
        self::assertFalse($override->matches('complexity.cognitive'));
        self::assertFalse($override->matches('coupling.cbo'));
    }

    #[Test]
    public function itRejectsABarePrefix(): void
    {
        $override = new ThresholdOverride(
            rulePattern: 'complexity',
            warning: 15,
            error: 25,
            line: 10,
            subject: $this->subject(),
            controlScope: ControlScope::Callable,
        );

        self::assertFalse($override->matches('complexity.cyclomatic'));
        self::assertFalse($override->matches('complexity.cognitive'));
        self::assertFalse($override->matches('coupling.cbo'));
    }

    #[Test]
    public function itRejectsAGroupSelector(): void
    {
        // A threshold belongs to one rule's options object, so there is no
        // group form at all — not even the explicit one selection accepts.
        $override = new ThresholdOverride(
            rulePattern: 'complexity.*',
            warning: 15,
            error: 25,
            line: 10,
            subject: $this->subject(),
            controlScope: ControlScope::Callable,
        );

        self::assertFalse($override->matches('complexity.cyclomatic'));
        self::assertFalse($override->matches('complexity.cognitive'));
    }

    #[Test]
    public function itRejectsTheWildcardToken(): void
    {
        // `@qmx-threshold * 30` used to reset every rule's threshold on a
        // symbol. That is the footgun the exact form removes.
        $override = new ThresholdOverride(
            rulePattern: '*',
            warning: 30,
            error: 50,
            line: 10,
            subject: $this->subject(),
            controlScope: ControlScope::Callable,
        );

        self::assertFalse($override->matches('complexity.cyclomatic'));
        self::assertFalse($override->matches('coupling.cbo'));
        self::assertFalse($override->matches('anything'));
    }

    #[Test]
    public function itFieldsAreAccessible(): void
    {
        $override = new ThresholdOverride(
            rulePattern: 'complexity.cyclomatic',
            warning: 15,
            error: 25,
            line: 10,
            subject: $this->subject(),
            controlScope: ControlScope::Callable,
            endLine: 50,
        );

        self::assertSame('complexity.cyclomatic', $override->rulePattern);
        self::assertSame(15, $override->warning);
        self::assertSame(25, $override->error);
        self::assertSame(10, $override->line);
        self::assertSame(50, $override->endLine);
    }

    #[Test]
    public function itNullWarningAndError(): void
    {
        $override = new ThresholdOverride(
            rulePattern: 'complexity.cyclomatic',
            warning: null,
            error: 25,
            line: 10,
            subject: $this->subject(),
            controlScope: ControlScope::Callable,
        );

        self::assertNull($override->warning);
        self::assertSame(25, $override->error);
    }

    #[Test]
    public function itFloatThresholds(): void
    {
        $override = new ThresholdOverride(
            rulePattern: 'coupling.instability',
            warning: 0.7,
            error: 0.9,
            line: 10,
            subject: $this->subject(),
            controlScope: ControlScope::Callable,
        );

        self::assertSame(0.7, $override->warning);
        self::assertSame(0.9, $override->error);
    }

    private function subject(): MetricSubject
    {
        return MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('src/Foo.php')));
    }
}
