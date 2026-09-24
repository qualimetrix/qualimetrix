<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Unit;

use PhpParser\Node\Stmt\If_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CodeSmell\RepeatedExpression\IfChain;

#[CoversClass(IfChain::class)]
final class IfChainTest extends TestCase
{
    #[Test]
    public function itContinuesThroughAnElseHoldingOnlyAnIf(): void
    {
        $ifs = $this->ifs('<?php if ($a) {} else if ($b) {} else { // comment' . "\n" . ' if ($c) {} }');

        self::assertSame([$ifs[1], $ifs[2]], (new IfChain())->continuations($ifs[0]));
    }

    #[Test]
    public function itStopsAtAnElseHoldingMoreThanAnIf(): void
    {
        $ifs = $this->ifs('<?php if ($a) {} else { $a = next($items); if ($a) {} }');

        self::assertSame([], (new IfChain())->continuations($ifs[0]));
    }

    /** @return list<If_> */
    private function ifs(string $code): array
    {
        /** @var list<If_> $ifs */
        $ifs = (new NodeFinder())->findInstanceOf((new ParserFactory())->createForHostVersion()->parse($code) ?? [], If_::class);

        return $ifs;
    }
}
