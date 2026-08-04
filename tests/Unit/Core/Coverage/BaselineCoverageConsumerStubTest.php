<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Coverage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Comparison\ComparisonStatus;
use Qualimetrix\Core\Coverage\RunCoverageInterface;
use Qualimetrix\Core\Coverage\ScopeCoverage;
use Qualimetrix\Core\Observation\ContractReference;
use Qualimetrix\Core\Observation\DebtObservation;
use Qualimetrix\Core\Observation\OccurrenceKey;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\ViolationChannel;

/**
 * Exercises the coverage contract from the *consumer's* side before it is
 * frozen.
 *
 * The consumer is the component that compares a recorded finding against a
 * run. It is allowed to depend on Core and on nothing else, which is the whole
 * reason the coverage contract lives in Core rather than next to the
 * orchestration code that will implement it. A stub standing in for that
 * consumer is the only way to prove, at the time the contract is defined, that
 * the questions the consumer needs to ask are answerable — a contract that
 * only its producer has ever called is a contract whose consumer has not been
 * consulted.
 *
 * The stub deliberately imports nothing outside `Qualimetrix\Core`.
 */
#[CoversClass(ScopeCoverage::class)]
#[CoversClass(ComparisonStatus::class)]
final class BaselineCoverageConsumerStubTest extends TestCase
{
    #[Test]
    public function itResolvesARecordedFindingThatDisappearedUnderProvenCoverage(): void
    {
        $consumer = new RecordedFindingConsumerStub(
            new InMemoryRunCoverage([
                $this->channel()->toKey() . '|' . $this->symbol()->toCanonical() => ScopeCoverage::evaluated(
                    $this->channel(),
                    $this->symbol(),
                ),
            ]),
            declaredChannels: [$this->channel()->toKey()],
        );

        self::assertSame(
            ComparisonStatus::Resolved,
            $consumer->classify($this->channel(), $this->symbol(), stillPresent: false),
        );
    }

    #[Test]
    public function itMatchesARecordedFindingThatIsStillPresent(): void
    {
        $consumer = new RecordedFindingConsumerStub(
            new InMemoryRunCoverage([
                $this->channel()->toKey() . '|' . $this->symbol()->toCanonical() => ScopeCoverage::evaluated(
                    $this->channel(),
                    $this->symbol(),
                ),
            ]),
            declaredChannels: [$this->channel()->toKey()],
        );

        self::assertSame(
            ComparisonStatus::Matched,
            $consumer->classify($this->channel(), $this->symbol(), stillPresent: true),
        );
    }

    /**
     * The failure this contract exists to prevent: without coverage, a symbol
     * that was simply never looked at is indistinguishable from one whose debt
     * was paid off, and the recorded entry gets silently dropped.
     */
    #[Test]
    public function itRefusesToResolveWhenCoverageCannotProveEvaluation(): void
    {
        $consumer = new RecordedFindingConsumerStub(
            new InMemoryRunCoverage([]),
            declaredChannels: [$this->channel()->toKey()],
        );

        $status = $consumer->classify($this->channel(), $this->symbol(), stillPresent: false);

        self::assertSame(ComparisonStatus::Unobserved, $status);
    }

    #[Test]
    public function itAnswersChannelWideForAFindingWithNoOwningSymbol(): void
    {
        $consumer = new RecordedFindingConsumerStub(
            new InMemoryRunCoverage([
                $this->graphChannel()->toKey() => ScopeCoverage::evaluated($this->graphChannel()),
            ]),
            declaredChannels: [$this->graphChannel()->toKey()],
        );

        self::assertSame(
            ComparisonStatus::Resolved,
            $consumer->classify($this->graphChannel(), symbol: null, stillPresent: false),
        );
    }

    #[Test]
    public function itKeepsAGraphFindingUnobservedWhenTheGraphWasIncomplete(): void
    {
        $consumer = new RecordedFindingConsumerStub(
            new InMemoryRunCoverage([
                $this->graphChannel()->toKey() => ScopeCoverage::indeterminate(
                    $this->graphChannel(),
                    'dependency graph incomplete: 3 files failed to parse',
                ),
            ]),
            declaredChannels: [$this->graphChannel()->toKey()],
        );

        self::assertSame(
            ComparisonStatus::Unobserved,
            $consumer->classify($this->graphChannel(), symbol: null, stillPresent: false),
        );
    }

    /**
     * Precedence, not preference: an entry can be un-evaluated *and* belong to
     * a channel no rule declares. The rule's absence is decided first, because
     * nothing else about the entry can be computed once it is gone.
     */
    #[Test]
    public function itDecidesOrphanedBeforeUnobserved(): void
    {
        $consumer = new RecordedFindingConsumerStub(
            new InMemoryRunCoverage([]),
            declaredChannels: [],
        );

        $status = $consumer->classify($this->channel(), $this->symbol(), stillPresent: false);

        self::assertSame(ComparisonStatus::Orphaned, $status);
        self::assertLessThan(
            ComparisonStatus::Unobserved->precedence(),
            ComparisonStatus::Orphaned->precedence(),
        );
    }

    /**
     * The observation the recorded entry was captured from travels through the
     * same Core types, so the consumer can read its contract and axes without
     * reaching outside Core.
     */
    #[Test]
    public function itReadsACapturedObservationWithoutLeavingCore(): void
    {
        $observation = DebtObservation::graph(
            new ContractReference('architecture.circular-dependency', 2),
            OccurrenceKey::fromUnorderedParts('App\\B', 'App\\A'),
        );

        self::assertSame('App\\A|App\\B', $observation->occurrenceKey?->value);
        self::assertTrue(
            $observation->contract->matchesIdentity(new ContractReference('architecture.circular-dependency')),
        );
    }

    private function channel(): ViolationChannel
    {
        return new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.method');
    }

    private function graphChannel(): ViolationChannel
    {
        return new ViolationChannel('architecture.circular-dependency', 'architecture.circular-dependency');
    }

    private function symbol(): SymbolPath
    {
        return SymbolPath::forMethod('App\\Service', 'OrderService', 'calculate');
    }
}

/**
 * Minimal coverage source. Anything it was not told about is unknown, which is
 * the honest default: a run that lost track of a scope must not claim it.
 */
final readonly class InMemoryRunCoverage implements RunCoverageInterface
{
    /**
     * @param array<string, ScopeCoverage> $known
     */
    public function __construct(private array $known) {}

    public function forSymbol(ViolationChannel $channel, SymbolPath $symbol): ScopeCoverage
    {
        return $this->known[$channel->toKey() . '|' . $symbol->toCanonical()]
            ?? ScopeCoverage::indeterminate($channel, 'scope not covered by this run', $symbol);
    }

    public function forChannel(ViolationChannel $channel): ScopeCoverage
    {
        return $this->known[$channel->toKey()]
            ?? ScopeCoverage::indeterminate($channel, 'channel not covered by this run');
    }
}

/**
 * Stands in for the component that will compare recorded findings against a
 * run. Implements only the slice of the precedence ordering that coverage
 * participates in — enough to prove the contract answers the consumer's
 * questions, and no more.
 */
final readonly class RecordedFindingConsumerStub
{
    /**
     * @param list<string> $declaredChannels Channel keys some rule declares in this build.
     */
    public function __construct(
        private RunCoverageInterface $coverage,
        private array $declaredChannels,
    ) {}

    public function classify(
        ViolationChannel $channel,
        ?SymbolPath $symbol,
        bool $stillPresent,
    ): ComparisonStatus {
        if (!\in_array($channel->toKey(), $this->declaredChannels, true)) {
            return ComparisonStatus::Orphaned;
        }

        $scope = $symbol === null
            ? $this->coverage->forChannel($channel)
            : $this->coverage->forSymbol($channel, $symbol);

        if (!$scope->provesEvaluation()) {
            return ComparisonStatus::Unobserved;
        }

        return $stillPresent ? ComparisonStatus::Matched : ComparisonStatus::Resolved;
    }
}
