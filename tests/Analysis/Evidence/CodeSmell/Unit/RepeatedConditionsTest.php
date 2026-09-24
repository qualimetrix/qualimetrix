<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use PhpParser\Node\Stmt\If_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\RepeatedExpression\RepeatedConditions;

#[CoversClass(RepeatedConditions::class)]
final class RepeatedConditionsTest extends TestCase
{
    #[Test]
    public function itDelegatesNarrowStructuralComparisonForRepeatedIfConditions(): void
    {
        $if = (new NodeFinder())->findFirstInstanceOf(
            (new ParserFactory())->createForHostVersion()->parse('<?php if ($ready) {} elseif ($ready) {}') ?? [],
            If_::class,
        );
        self::assertInstanceOf(If_::class, $if);

        $findings = (new RepeatedConditions())->findings($if, 'file');
        self::assertCount(1, $findings);
        self::assertSame('duplicate_condition', $findings[0]->type);
    }

    #[Test]
    public function itFollowsAnElseHoldingOnlyAnIfAsTheSameChain(): void
    {
        $if = (new NodeFinder())->findFirstInstanceOf(
            (new ParserFactory())->createForHostVersion()->parse("<?php if (\$a) {} else if (\$b) {} else { // comment\n if (\$a) {} }") ?? [],
            If_::class,
        );
        self::assertInstanceOf(If_::class, $if);
        $conditions = new RepeatedConditions();

        self::assertSame([['duplicate_condition', 2]], array_map(static fn($f): array => [$f->type, $f->line], $conditions->findings($if, 'file')));
    }

    #[Test]
    public function itComparesConditionsWithTheirCalls(): void
    {
        $if = (new NodeFinder())->findFirstInstanceOf(
            (new ParserFactory())->createForHostVersion()->parse('<?php if ($it->valid()) {} elseif ($it->valid()) {}') ?? [],
            If_::class,
        );
        self::assertInstanceOf(If_::class, $if);

        self::assertCount(1, (new RepeatedConditions())->findings($if, 'file'));
    }
}
