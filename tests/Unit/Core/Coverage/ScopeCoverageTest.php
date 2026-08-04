<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Coverage;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Coverage\ScopeCoverage;
use Qualimetrix\Core\Coverage\ScopeCoverageStatus;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\ViolationChannel;

#[CoversClass(ScopeCoverage::class)]
#[CoversClass(ScopeCoverageStatus::class)]
final class ScopeCoverageTest extends TestCase
{
    #[Test]
    public function itReportsAnEvaluatedSymbolScope(): void
    {
        $coverage = ScopeCoverage::evaluated(
            $this->channel(),
            SymbolPath::forClass('App\\Service', 'OrderService'),
        );

        self::assertTrue($coverage->provesEvaluation());
        self::assertFalse($coverage->isChannelWide());
        self::assertNull($coverage->reason);
    }

    #[Test]
    public function itReportsAnEvaluatedChannelWideScope(): void
    {
        $coverage = ScopeCoverage::evaluated($this->channel());

        self::assertTrue($coverage->isChannelWide());
        self::assertNull($coverage->symbol);
    }

    /**
     * Only a proven evaluation lets the absence of a finding be read as its
     * disappearance. Under either other status, silence carries no
     * information at all.
     */
    #[Test]
    public function itDeniesProofForEveryNonEvaluatedStatus(): void
    {
        self::assertFalse(ScopeCoverage::notEvaluated($this->channel(), 'rule disabled')->provesEvaluation());
        self::assertFalse(ScopeCoverage::indeterminate($this->channel(), 'worker crashed')->provesEvaluation());
        self::assertFalse(ScopeCoverageStatus::NotEvaluated->provesEvaluation());
        self::assertFalse(ScopeCoverageStatus::Indeterminate->provesEvaluation());
        self::assertTrue(ScopeCoverageStatus::Evaluated->provesEvaluation());
    }

    #[Test]
    public function itKeepsTheReasonForANonEvaluatedScope(): void
    {
        $coverage = ScopeCoverage::notEvaluated($this->channel(), 'excluded by exclude_paths');

        self::assertSame(ScopeCoverageStatus::NotEvaluated, $coverage->status);
        self::assertSame('excluded by exclude_paths', $coverage->reason);
    }

    #[Test]
    public function itRejectsANonEvaluatedScopeWithoutAReason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a reason');

        new ScopeCoverage($this->channel(), ScopeCoverageStatus::Indeterminate);
    }

    #[Test]
    public function itRejectsAnEmptyReason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a reason');

        new ScopeCoverage($this->channel(), ScopeCoverageStatus::NotEvaluated, null, '');
    }

    #[Test]
    public function itRejectsAReasonOnAnEvaluatedScope(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not carry a reason');

        new ScopeCoverage($this->channel(), ScopeCoverageStatus::Evaluated, null, 'why?');
    }

    private function channel(): ViolationChannel
    {
        return new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.method');
    }
}
