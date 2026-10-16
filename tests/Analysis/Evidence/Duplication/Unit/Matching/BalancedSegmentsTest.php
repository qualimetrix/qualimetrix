<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Unit\Matching;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Duplication\Matching\BalancedSegments;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\TokenNormalizer;

#[CoversClass(BalancedSegments::class)]
final class BalancedSegmentsTest extends TestCase
{
    #[Test]
    public function itSplitsUnmatchedClosersAndSkipsLeadingPunctuation(): void
    {
        $stream = (new TokenNormalizer())->normalize('<?php ) ] } ; , first(); } ; , second();');
        self::assertSame([[5, 4], [12, 4]], BalancedSegments::of($stream, 0, $stream->count()));
    }

    #[Test]
    public function itKeepsAnUnclosedOpenerAtTheEndOfTheRequestedRange(): void
    {
        $stream = (new TokenNormalizer())->normalize('<?php before(); ${value[call(');
        self::assertSame([[0, $stream->count()]], BalancedSegments::of($stream, 0, $stream->count()));
        self::assertSame([[0, 4]], BalancedSegments::of($stream, 0, 4));
    }
}
