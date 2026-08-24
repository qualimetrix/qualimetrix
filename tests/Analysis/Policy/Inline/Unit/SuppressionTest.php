<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(Suppression::class)]
final class SuppressionTest extends TestCase
{
    /**
     * The rule half of a channel, for the spellings that do not read it: a
     * one-part selector filters on the finding code alone, so any producer
     * name proves the same thing.
     */
    private const string ANY_RULE = 'any.producer';

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

        self::assertTrue($suppression->matches(self::ANY_RULE, 'complexity.cyclomatic'));
        self::assertFalse($suppression->matches(self::ANY_RULE, 'complexity.cognitive'));
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
        self::assertTrue($suppression->matches(self::ANY_RULE, 'complexity'));
        self::assertFalse($suppression->matches(self::ANY_RULE, 'complexity.cyclomatic'));
        self::assertFalse($suppression->matches(self::ANY_RULE, 'complexity.cyclomatic.callable'));
        self::assertFalse($suppression->matches(self::ANY_RULE, 'coupling'));
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

        self::assertTrue($suppression->matches(self::ANY_RULE, 'complexity.cyclomatic.callable'));
        self::assertTrue($suppression->matches(self::ANY_RULE, 'complexity.cyclomatic.class'));
        // The parent is not one of its own descendants: a directive meaning
        // both is written twice.
        self::assertFalse($suppression->matches(self::ANY_RULE, 'complexity.cyclomatic'));
        self::assertFalse($suppression->matches(self::ANY_RULE, 'complexity.cognitive.callable'));
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

        self::assertTrue($suppression->matches(self::ANY_RULE, 'architecture.coverage'));
        self::assertFalse($suppression->matches(self::ANY_RULE, 'architecture.coverage.source'));
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

        self::assertTrue($suppression->matches(self::ANY_RULE, 'complexity.cyclomatic'));
        self::assertTrue($suppression->matches(self::ANY_RULE, 'coupling.distance'));
        self::assertTrue($suppression->matches(self::ANY_RULE, 'size.method-count'));
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
        self::assertFalse($suppression->matches(self::ANY_RULE, 'complexity.cyclomatic'));
        self::assertFalse($suppression->matches(self::ANY_RULE, 'complexity'));
    }

    private function subject(): MetricSubject
    {
        return MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('src/Foo.php')));
    }
}
