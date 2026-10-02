<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DeclarationBinding;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DeclarationReach;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(Suppression::class)]
final class SuppressionTest extends TestCase
{
    #[Test]
    public function itMatchesExactRule(): void
    {
        $suppression = new Suppression(
            rule: 'complexity.ccn',
            reason: 'Legacy code',
            line: 10,
            type: SuppressionType::Symbol,
            position: 0,
            binding: new DeclarationBinding($this->subject(), ControlScope::Callable, DeclarationReach::whole(null, 'test')),
        );

        self::assertTrue($suppression->matches('complexity.ccn', SymbolLevel::Class_));
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
            position: 0,
            binding: new DeclarationBinding($this->subject(), ControlScope::Callable, DeclarationReach::whole(null, 'test')),
        );

        // `complexity` addresses the channel called `complexity` — there is
        // none — and nothing else. A group is written `complexity.*`.
        self::assertTrue($suppression->matches('complexity', SymbolLevel::Class_));
        self::assertFalse($suppression->matches('complexity.ccn', SymbolLevel::Class_));
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
            position: 0,
            binding: new DeclarationBinding($this->subject(), ControlScope::Callable, DeclarationReach::whole(null, 'test')),
        );

        self::assertTrue($suppression->matches('complexity.cyclomatic.callable', SymbolLevel::Class_));
        self::assertTrue($suppression->matches('complexity.cyclomatic.class', SymbolLevel::Class_));
        // The parent is not one of its own descendants: a directive meaning
        // both is written twice.
        self::assertFalse($suppression->matches('complexity.ccn', SymbolLevel::Class_));
        self::assertFalse($suppression->matches('complexity.cognitive.callable', SymbolLevel::Class_));
    }

    #[Test]
    public function itDoesNotCaptureADottedDescendantOfAnExactName(): void
    {
        // The latent defect this substrate removes: a channel named as a
        // dotted descendant of an existing one used to fall under every
        // selector of its parent.
        $suppression = new Suppression(
            rule: 'architecture.coverage-gap',
            reason: null,
            line: 10,
            type: SuppressionType::Symbol,
            position: 0,
            binding: new DeclarationBinding($this->subject(), ControlScope::Callable, DeclarationReach::whole(null, 'test')),
        );

        self::assertTrue($suppression->matches('architecture.coverage-gap', SymbolLevel::Class_));
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
            position: 0,
        );

        self::assertTrue($suppression->matches('complexity.ccn', SymbolLevel::Class_));
        self::assertTrue($suppression->matches('coupling.distance', SymbolLevel::Class_));
        self::assertTrue($suppression->matches('size.method-count', SymbolLevel::Class_));
    }

    #[Test]
    public function itConstructorProperties(): void
    {
        $suppression = new Suppression(
            rule: 'complexity.ccn',
            reason: 'Complex business logic',
            line: 42,
            type: SuppressionType::NextLine,
            position: 0,
            silencedLine: 42 + 1,
        );

        self::assertSame('complexity.ccn', $suppression->rule);
        self::assertSame('Complex business logic', $suppression->reason);
        self::assertSame(42, $suppression->line);
        self::assertSame(SuppressionType::NextLine, $suppression->type);
        self::assertSame(0, $suppression->position);
        self::assertSame(43, $suppression->silencedLine);
    }

    #[Test]
    #[DataProvider('provideInvalidCoordinates')]
    public function itRejectsInvalidCoordinateCombinations(
        int $position,
        SuppressionType $type,
        ?int $silencedLine,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Suppression(
            rule: 'complexity.ccn',
            reason: null,
            line: 42,
            type: $type,
            position: $position,
            silencedLine: $silencedLine,
        );
    }

    /** @return iterable<string, array{int, SuppressionType, ?int, string}> */
    public static function provideInvalidCoordinates(): iterable
    {
        yield 'negative position' => [-1, SuppressionType::File, null, 'A suppression position must be non-negative'];
        yield 'next-line without silenced line' => [0, SuppressionType::NextLine, null, 'A carried next-line suppression requires its silenced line'];
        yield 'file with silenced line' => [0, SuppressionType::File, 43, 'no other suppression may carry one'];
    }

    #[Test]
    public function itConstructorWithNullReason(): void
    {
        $suppression = new Suppression(
            rule: 'complexity',
            reason: null,
            line: 42,
            type: SuppressionType::Symbol,
            position: 0,
            binding: new DeclarationBinding($this->subject(), ControlScope::Callable, DeclarationReach::whole(null, 'test')),
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
            position: 0,
            binding: new DeclarationBinding($this->subject(), ControlScope::Callable, DeclarationReach::whole(null, 'test')),
        );

        // More specific pattern does NOT match less specific subject
        self::assertFalse($suppression->matches('complexity.ccn', SymbolLevel::Class_));
        self::assertFalse($suppression->matches('complexity', SymbolLevel::Class_));
    }

    private function subject(): MetricSubject
    {
        return MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('src/Foo.php')));
    }
}
