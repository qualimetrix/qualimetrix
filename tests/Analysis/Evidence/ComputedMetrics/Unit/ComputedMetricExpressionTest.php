<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricExpression;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricReads;

/**
 * The reader that replaced a pattern over formula text.
 *
 * Every refused shape gets its own case. A single assertion over a list passes
 * as soon as the FIRST entry throws, which is how the pattern this replaced
 * looked correct while `m .offsetGet("k")` and `m ["k"]` walked past it: both
 * were found by review, not by a green test over a list.
 */
#[CoversClass(ComputedMetricExpression::class)]
#[CoversClass(ComputedMetricReads::class)]
final class ComputedMetricExpressionTest extends TestCase
{
    private ComputedMetricExpression $expression;

    protected function setUp(): void
    {
        $this->expression = new ComputedMetricExpression();
    }

    #[Test]
    #[DataProvider('accessesThatAreNotALiteralIndex')]
    public function itRefusesAnAccessThatIsNotALiteralIndex(string $formula, string $why): void
    {
        self::assertFalse($this->expression->everyAccessIsALiteralIndex($formula), $why);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function accessesThatAreNotALiteralIndex(): iterable
    {
        yield 'a method call on the lookup' => [
            'm.offsetGet("complexity.ccn")',
            'ArrayAccess is public, so the parser accepts a method call and the key becomes invisible',
        ];
        yield 'a method call with a space' => [
            'm .offsetExists("complexity.ccn")',
            'whitespace is nothing to the parser and was everything to the pattern',
        ];
        yield 'an index built by concatenation' => [
            'm["complexity." ~ "ccn"]',
            'a computed index names no key that can be checked',
        ];
        yield 'an index that is another lookup' => [
            'm[m["complexity.ccn"]]',
            'the outer index is a value, not a key',
        ];
        yield 'the lookup handed to a function' => [
            'max(m, 1)',
            'a formula that passes the whole lookup around reaches keys nothing enumerated',
        ];
        yield 'the lookup guarded on its own' => [
            '(m ?? 0)',
            'the variable itself is not an access to a metric',
        ];
    }

    /**
     * A space between `m` and its index is not a defect — the parser does not
     * see it. This is the counterweight to the cases above: the refusal has to
     * be about the SHAPE of the access, not about how it was typed.
     */
    #[Test]
    public function itAcceptsALiteralIndexHoweverItIsSpaced(): void
    {
        self::assertTrue($this->expression->everyAccessIsALiteralIndex('m ["complexity.ccn"] + m[ \'size.loc\' ]'));

        self::assertSame(
            ['complexity.ccn', 'size.loc'],
            $this->expression->keysOf('m ["complexity.ccn"] + m[ \'size.loc\' ]'),
        );
    }

    /**
     * The reason this reads the tree rather than the text: whether a key is
     * needed is a fact about each occurrence, and a name-keyed pattern cannot
     * hold two answers for one name.
     */
    #[Test]
    public function itMissesAKeyThatIsGuardedInOnePlaceAndBareInAnother(): void
    {
        $formula = 'm["complexity.ccn"] * 2 + (m["complexity.ccn"] ?? 0)';

        self::assertSame(['complexity.ccn'], $this->expression->keysOf($formula));
        self::assertSame(['complexity.ccn'], $this->missing($formula));
    }

    /**
     * What a formula misses, given which keys are present.
     *
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function provideMissingKeyCases(): iterable
    {
        yield 'a guarded read with a literal fallback needs nothing' => ['(m["a"] ?? 0) * 2', [], []];
        yield 'a bare read of an absent key' => ['m["a"] + 1', [], ['a']];
        yield 'a bare read of a present key' => ['m["a"] + 1', ['a'], []];
        yield 'two bare reads, one absent' => ['m["a"] + m["b"]', ['a'], ['b']];
        // The right side of `??` is read only where the left is absent.
        yield 'a fallback between metrics, left present' => ['m["a"] ?? m["b"]', ['a'], []];
        yield 'a fallback between metrics, only right present' => ['m["a"] ?? m["b"]', ['b'], []];
        yield 'a fallback between metrics, neither present' => ['m["a"] ?? m["b"]', [], ['a', 'b']];
        yield 'a chain ending in a literal needs nothing' => ['m["a"] ?? m["b"] ?? 0', [], []];
        yield 'a chain ending in a metric, only the last present' => ['m["a"] ?? m["b"] ?? m["c"]', ['c'], []];
        yield 'a chain ending in a metric, none present' => ['m["a"] ?? m["b"] ?? m["c"]', [], ['a', 'b', 'c']];
        // The inner null is handed to the outer `??`, which catches it.
        yield 'a parenthesised chain guarded to its last link' => ['(m["a"] ?? m["b"]) ?? 0', [], []];
        yield 'a fallback inside arithmetic, both absent' => ['(m["a"] ?? m["b"]) + m["c"]', ['c'], ['a', 'b']];
        yield 'a fallback inside arithmetic, left present' => ['(m["a"] ?? m["b"]) + m["c"]', ['a', 'c'], []];
        // `??` guards nothing inside an operator: `null * 2` is already 0.
        yield 'a read inside arithmetic under ??' => ['(m["a"] * 2) ?? 0', [], ['a']];
        // Whether an arithmetic left side is null is not decided here, so its
        // right side is taken as read.
        yield 'an undecidable left side reads the right side' => ['(m["a"] * 2) ?? m["b"]', ['a'], ['b']];
        // Which branch runs depends on the symbol's values, so a key only one
        // branch reads is not certainly read; the evaluator judges it per symbol.
        yield 'a key only one branch reads' => ['m["a"] > 0 ? m["b"] : m["c"]', ['a'], []];
        yield 'a key only the else branch reads' => ['m["a"] > 0 ? 7 : m["c"]', ['a'], []];
        yield 'a key both branches read' => ['m["a"] > 0 ? m["b"] : m["b"] * 2', ['a'], ['b']];
        yield 'a key both branches hand to an enclosing ??' => ['(m["a"] > 0 ? m["b"] : m["b"]) ?? 0', ['a'], []];
        // One path consumes it, the other hands it to `??`: not certain.
        yield 'a key one branch consumes and the other hands to ??' => ['(m["a"] > 0 ? m["b"] * 2 : m["b"]) ?? 0', ['a'], []];
        yield 'a key a nested ternary reads in both of its branches' => ['m["a"] > 0 ? 1 : (m["c"] > 0 ? m["b"] : m["b"])', ['a', 'c'], []];
        // The condition always runs, and a null in it decides the branch.
        yield 'a bare read in the condition' => ['m["a"] > 0 ? 1 : 2', [], ['a']];
        yield 'a guarded read in the condition' => ['(m["a"] ?? 0) > 0 ? m["b"] : 2', [], []];
        yield 'the condition of an elvis' => ['m["a"] ?: m["b"]', [], ['a']];
        yield 'the fallback of an elvis' => ['m["a"] ?: m["b"]', ['a'], []];
        // `and` and `or` run their right side only on the left's value.
        yield 'the right side of and' => ['m["a"] > 0 and m["b"] > 0', ['a'], []];
        yield 'the right side of ||' => ['m["a"] > 0 || m["b"] > 0', ['a'], []];
        yield 'the left side of and' => ['m["a"] > 0 && m["b"] > 0', ['b'], ['a']];
        yield 'both sides of xor' => ['m["a"] > 0 xor m["b"] > 0', ['a'], ['b']];
        yield 'a function argument' => ['max(m["a"], 1)', [], ['a']];
    }

    /**
     * @param list<string> $present
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideMissingKeyCases')]
    public function itMissesExactlyTheAbsentKeysTheFormulaWouldReadAsNull(string $formula, array $present, array $expected): void
    {
        self::assertSame($expected, $this->missing($formula, $present));
    }

    #[Test]
    public function itReadsOnlyComputedMetricReferences(): void
    {
        $formula = '(m["health.complexity"] ?? 75) * 0.5 + (m["computed.density"] ?? 0) + m["size.loc"]';

        self::assertSame(
            ['health.complexity', 'computed.density'],
            $this->expression->computedReferencesOf($formula),
        );
    }

    /**
     * The index has a base, and the base has to be `m`.
     *
     * `m["complexity.ccn"]["health.overall"]` indexes the VALUE the first read
     * returned. Asking only "is this a literal index" made the guard and the
     * reader answer about different nodes: the guard saw a legal access and the
     * reader collected `health.overall` as a key of its own — a dependency edge
     * to a metric the formula never reads, which reached "circular dependency"
     * on a formula with no cycle.
     */
    #[Test]
    public function itReadsNoKeyOutOfAnIndexOnSomethingOtherThanTheLookup(): void
    {
        $formula = 'm["complexity.ccn"]["health.overall"]';

        self::assertSame(['complexity.ccn'], $this->expression->keysOf($formula));
        self::assertSame([], $this->expression->computedReferencesOf($formula));
    }

    /**
     * @param list<string> $present
     *
     * @return list<string>
     */
    private function missing(string $formula, array $present = []): array
    {
        return $this->expression->missingKeysOf($formula, static fn(string $key): bool => \in_array($key, $present, true));
    }
}
