<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(Suppression::class)]
final class SuppressionTest extends TestCase
{
    #[Test]
    public function itMatchesExactRule(): void
    {
        $suppression = new Suppression(
            rule: 'complexity.cyclomatic',
            reason: 'Legacy code',
            line: 10,
            type: SuppressionType::Symbol,
            subject: $this->subject(),
            controlScope: ControlScope::Callable,
        );

        self::assertTrue($suppression->matches('complexity.cyclomatic', SymbolLevel::Class_));
        self::assertFalse($suppression->matches('complexity.cognitive', SymbolLevel::Class_));
    }

    #[Test]
    public function itDoesNotTreatABarePrefixAsAGroup(): void
    {
        $suppression = new Suppression(
            rule: 'complexity',
            reason: 'Legacy code',
            line: 10,
            type: SuppressionType::Symbol,
            subject: $this->subject(),
            controlScope: ControlScope::Callable,
        );

        // `complexity` addresses the channel called `complexity` — there is
        // none — and nothing else. A group is written `complexity.*`.
        self::assertTrue($suppression->matches('complexity', SymbolLevel::Class_));
        self::assertFalse($suppression->matches('complexity.cyclomatic', SymbolLevel::Class_));
        self::assertFalse($suppression->matches('complexity.cyclomatic.callable', SymbolLevel::Class_));
        self::assertFalse($suppression->matches('coupling', SymbolLevel::Class_));
    }

    #[Test]
    public function itMatchesStrictDescendantsOfAGroupSelector(): void
    {
        $suppression = new Suppression(
            rule: 'complexity.cyclomatic.*',
            reason: 'Legacy code',
            line: 10,
            type: SuppressionType::Symbol,
            subject: $this->subject(),
            controlScope: ControlScope::Callable,
        );

        self::assertTrue($suppression->matches('complexity.cyclomatic.callable', SymbolLevel::Class_));
        self::assertTrue($suppression->matches('complexity.cyclomatic.class', SymbolLevel::Class_));
        // The parent is not one of its own descendants: a directive meaning
        // both is written twice.
        self::assertFalse($suppression->matches('complexity.cyclomatic', SymbolLevel::Class_));
        self::assertFalse($suppression->matches('complexity.cognitive.callable', SymbolLevel::Class_));
    }

    #[Test]
    public function itDoesNotCaptureADottedDescendantOfAnExactName(): void
    {
        // The latent defect this substrate removes: a channel named as a
        // dotted descendant of an existing one used to fall under every
        // selector of its parent.
        $suppression = new Suppression(
            rule: 'architecture.coverage',
            reason: null,
            line: 10,
            type: SuppressionType::Symbol,
            subject: $this->subject(),
            controlScope: ControlScope::Callable,
        );

        self::assertTrue($suppression->matches('architecture.coverage', SymbolLevel::Class_));
        self::assertFalse($suppression->matches('architecture.coverage.source', SymbolLevel::Class_));
    }

    #[Test]
    public function itWildcardMatchesAllRules(): void
    {
        $suppression = new Suppression(
            rule: '*',
            reason: 'Ignore all',
            line: 10,
            type: SuppressionType::File,
        );

        self::assertTrue($suppression->matches('complexity.cyclomatic', SymbolLevel::Class_));
        self::assertTrue($suppression->matches('coupling.distance', SymbolLevel::Class_));
        self::assertTrue($suppression->matches('size.method-count', SymbolLevel::Class_));
    }

    #[Test]
    public function itConstructorProperties(): void
    {
        $suppression = new Suppression(
            rule: 'complexity.cyclomatic',
            reason: 'Complex business logic',
            line: 42,
            type: SuppressionType::NextLine,
        );

        self::assertSame('complexity.cyclomatic', $suppression->rule);
        self::assertSame('Complex business logic', $suppression->reason);
        self::assertSame(42, $suppression->line);
        self::assertSame(SuppressionType::NextLine, $suppression->type);
    }

    #[Test]
    public function itConstructorWithNullReason(): void
    {
        $suppression = new Suppression(
            rule: 'complexity',
            reason: null,
            line: 42,
            type: SuppressionType::Symbol,
            subject: $this->subject(),
            controlScope: ControlScope::Callable,
        );

        self::assertNull($suppression->reason);
    }

    #[Test]
    public function itReverseDoesNotMatch(): void
    {
        $suppression = new Suppression(
            rule: 'complexity.cyclomatic.callable',
            reason: null,
            line: 10,
            type: SuppressionType::Symbol,
            subject: $this->subject(),
            controlScope: ControlScope::Callable,
        );

        // More specific pattern does NOT match less specific subject
        self::assertFalse($suppression->matches('complexity.cyclomatic', SymbolLevel::Class_));
        self::assertFalse($suppression->matches('complexity', SymbolLevel::Class_));
    }

    private function subject(): MetricSubject
    {
        return MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('src/Foo.php')));
    }
}
