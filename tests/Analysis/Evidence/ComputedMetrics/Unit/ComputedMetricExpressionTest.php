<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Unit;

use ArrayAccess;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDefaults;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricExpression;

/**
 * The reader that replaced a pattern over formula text.
 *
 * Every refused shape gets its own case. A single assertion over a list passes
 * as soon as the FIRST entry throws, which is how the pattern this replaced
 * looked correct while `m .offsetGet("k")` and `m ["k"]` walked past it: both
 * were found by review, not by a green test over a list.
 */
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricOutcome;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricReads;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricSubjectEvaluation;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\MetricLookup;
use Qualimetrix\Core\Symbol\SymbolLevel;

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
    #[Test]
    public function itEvaluatesOrderedNullableMeansWithoutDroppingZero(): void
    {
        foreach ([
            ['weighted_mean(m["a"], 1, m["b"], 3)', [], null],
            ['weighted_mean(m["a"], 1, m["b"], 3)', ['a' => 0], 0.0],
            ['weighted_mean(m["a"], 1, m["b"], 3)', ['b' => 80], 80.0],
            ['weighted_mean(m["a"], 1, m["b"], 3)', ['a' => 0, 'b' => 80], 60.0],
            ['weighted_mean(m["a"] ?? m["b"], 1)', [], null],
            ['weighted_mean(m["flag"] > 0 ? m["a"] : m["b"], 1)', ['flag' => 1], null],
            ['weighted_mean(m["flag"] > 0 ? m["a"] : m["b"], 1)', ['flag' => 0, 'b' => 0], 0.0],
            ['weighted_mean(m["flag"] > 0 ? (m["a"] ?? 20) : m["b"], 1)', ['flag' => 1], 20.0],
            ['weighted_mean((m["flag"] > 0 and m["a"] > 0) ? 10 : m["b"], 1)', ['flag' => 0], null],
        ] as [$formula, $values, $expected]) {
            self::assertSame([[], $expected], $this->expression->evaluateOn($formula, new MetricLookup($values)), $formula);
        }
        $ordered = 'weighted_mean(m["a"], 1, m["b"], 1, m["c"], 1)';
        self::assertSame([[], 1.0 / 3.0], $this->expression->evaluateOn($ordered, new MetricLookup(['a' => 1e16, 'b' => -1e16, 'c' => 1])));
    }

    #[Test]
    public function itKeepsStrictReadsInsideNullableMeanValuesAndWeights(): void
    {
        foreach ([
            ['weighted_mean(m["a"] + 1, 1)', ['a']],
            ['weighted_mean(max(m["a"], 1), 1)', ['a']],
            ['weighted_mean(m["a"], m["w"])', ['w']],
            ['weighted_mean(m["flag"] > 0 ? m["a"] * 2 : m["b"], 1)', ['a']],
            ['weighted_mean(m["flag"] > 0 ? m["a"] : m["b"], m["w"])', ['w']],
            ['weighted_mean(m["flag"] > 0 ? m["a"] : m["b"], 1)', ['flag']],
        ] as [$formula, $missing]) {
            $values = str_contains($formula, 'flag') && $missing !== ['flag'] ? ['flag' => 1] : [];
            self::assertSame([$missing, null], $this->expression->evaluateOn($formula, new MetricLookup($values)), $formula);
        }
        self::assertSame(['a'], $this->expression->missingKeysOf('weighted_mean(m["a"] * 2, 1)', static fn(string $key): bool => false));
        self::assertSame([], $this->expression->missingKeysOf('weighted_mean(m["a"], 1)', static fn(string $key): bool => false));
    }

    #[Test]
    public function itValidatesWeightsEvenForAbsentValues(): void
    {
        $evaluation = new ComputedMetricSubjectEvaluation($this->expression);
        foreach (['0', '-1', 'null', 'true', '"1"', '1e999'] as $weight) {
            $definition = self::customFormula('weighted_mean(m["a"], ' . $weight . ')');
            self::assertSame(ComputedMetricOutcome::FAILURE, $evaluation->evaluate($definition, SymbolLevel::Class_, [])->kind, $weight);
        }
        foreach (['weighted_mean()', 'weighted_mean(1)', 'weighted_mean(1e308, 1e308)'] as $formula) {
            self::assertSame(ComputedMetricOutcome::FAILURE, $evaluation->evaluate(self::customFormula($formula), SymbolLevel::Class_, [])->kind, $formula);
        }
    }

    #[Test]
    public function itReturnsTheClosedSubjectOutcomesWithoutNumericCoercion(): void
    {
        $evaluation = new ComputedMetricSubjectEvaluation();
        foreach ([
            ['80', [], ComputedMetricOutcome::VALUE, 80],
            ['0', [], ComputedMetricOutcome::VALUE, 0],
            ['null', [], ComputedMetricOutcome::NO_VALUE, null],
            ['weighted_mean(m["a"], 1)', [], ComputedMetricOutcome::NO_VALUE, null],
            ['m["a"] + 1', [], ComputedMetricOutcome::MISSING_KEYS, null],
            ['true', [], ComputedMetricOutcome::FAILURE, null],
            ['"80"', [], ComputedMetricOutcome::FAILURE, null],
            ['1e999', [], ComputedMetricOutcome::FAILURE, null],
            ['sqrt(-1)', [], ComputedMetricOutcome::FAILURE, null],
            ['log(-1)', [], ComputedMetricOutcome::FAILURE, null],
            ['1 / 0', [], ComputedMetricOutcome::FAILURE, null],
        ] as [$formula, $values, $kind, $value]) {
            $outcome = $evaluation->evaluate(self::customFormula($formula), SymbolLevel::Class_, $values);
            self::assertSame($kind, $outcome->kind, $formula);
            self::assertSame($value, $outcome->value, $formula);
            if ($kind === ComputedMetricOutcome::MISSING_KEYS) {
                self::assertSame(['a'], $outcome->missingKeys);
            }
            if ($kind === ComputedMetricOutcome::FAILURE) {
                self::assertNotEmpty($outcome->reason);
            }
        }
    }

    #[Test]
    public function itReturnsNoValueForExactCopiedNullableBuiltinFormulas(): void
    {
        $evaluation = new ComputedMetricSubjectEvaluation();
        $defaults = ComputedMetricDefaults::getDefaults();
        foreach (['health.cohesion', 'health.overall'] as $name) {
            $builtin = $defaults[$name];
            $copied = new ComputedMetricDefinition($builtin->name, $builtin->formulas, $builtin->description, $builtin->levels);
            foreach ([SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project] as $level) {
                self::assertSame(ComputedMetricOutcome::NOT_APPLICABLE, $evaluation->evaluate($builtin, $level, [])->kind);
                $outcome = $evaluation->evaluate($copied, $level, []);
                self::assertSame(ComputedMetricOutcome::NO_VALUE, $outcome->kind, $name . ' ' . $level->value);
                self::assertNull($outcome->value);
                self::assertNull($outcome->reason);
            }
        }
    }

    #[Test]
    public function itPreservesStrictClampOperandsOutsideTheNullableMeanResult(): void
    {
        $evaluation = new ComputedMetricSubjectEvaluation();
        foreach ([
            ['clamp(weighted_mean(m["a"], 1), 0, 100)', [], ComputedMetricOutcome::NO_VALUE, null],
            ['clamp(weighted_mean(m["a"], 1), 0, 100)', ['a' => 0], ComputedMetricOutcome::VALUE, 0.0],
            ['clamp(weighted_mean(m["a"], 1), 0, 100)', ['a' => 150], ComputedMetricOutcome::VALUE, 100.0],
            ['clamp(weighted_mean(m["a"], 1), 0, 100)', ['a' => -10], ComputedMetricOutcome::VALUE, 0.0],
            ['weighted_mean(clamp(weighted_mean(m["a"], 1), 0, 100), 1, m["b"], 1)', ['b' => 80], ComputedMetricOutcome::VALUE, 80.0],
            ['weighted_mean(m["flag"] > 0 ? clamp(weighted_mean(m["a"], 1), 0, 100) : m["b"], 1)', ['flag' => 1], ComputedMetricOutcome::NO_VALUE, null],
            ['weighted_mean(m["flag"] > 0 ? clamp(weighted_mean(m["a"], 1), 0, 100) : m["b"], 1)', ['flag' => 0, 'b' => 0], ComputedMetricOutcome::VALUE, 0.0],
            ['weighted_mean(m["flag"] > 0 ? clamp(weighted_mean(m["a"] + 1, 1), 0, 100) : m["b"], 1)', ['flag' => 1], ComputedMetricOutcome::MISSING_KEYS, null],
            ['max(clamp(weighted_mean(m["a"], 1), 0, 100), 1)', [], ComputedMetricOutcome::VALUE, 1],
            ['clamp(weighted_mean(m["a"] + 1, 1), 0, 100)', [], ComputedMetricOutcome::MISSING_KEYS, null],
            ['clamp(weighted_mean(abs(m["a"]), 1), 0, 100)', [], ComputedMetricOutcome::MISSING_KEYS, null],
            ['clamp(weighted_mean(m["a"], m["w"]), 0, 100)', [], ComputedMetricOutcome::MISSING_KEYS, null],
            ['clamp(weighted_mean(m["a"], 1), m["min"], 100)', [], ComputedMetricOutcome::MISSING_KEYS, null],
            ['clamp(weighted_mean(m["a"], 0), 0, 100)', [], ComputedMetricOutcome::FAILURE, null],
            ['clamp(weighted_mean(m["a"], 1), null, 100)', [], ComputedMetricOutcome::FAILURE, null],
            ['clamp(weighted_mean(m["a"], 1), 0, "100")', [], ComputedMetricOutcome::FAILURE, null],
            ['clamp(null, 0, 100)', [], ComputedMetricOutcome::FAILURE, null],
            ['clamp(m["a"], 0, 100)', [], ComputedMetricOutcome::MISSING_KEYS, null],
        ] as [$formula, $values, $kind, $value]) {
            $outcome = $evaluation->evaluate(self::customFormula($formula), SymbolLevel::Class_, $values);
            self::assertSame($kind, $outcome->kind, $formula);
            self::assertSame($value, $outcome->value, $formula);
        }
    }

    #[Test]
    public function itEvaluatesNullableMeanClampArgumentsOnceInNativeOrder(): void
    {
        foreach ([null, 0, 40] as $value) {
            /** @implements ArrayAccess<string, int|null> */
            $metrics = new class ($value) implements ArrayAccess {
                /** @var list<string> */
                public array $reads = [];

                public function __construct(private readonly ?int $value) {}

                public function offsetExists(mixed $offset): bool
                {
                    return \is_string($offset) && \in_array($offset, ['value', 'weight', 'min', 'max'], true);
                }

                public function offsetGet(mixed $offset): ?int
                {
                    if (!\is_string($offset)) {
                        return null;
                    }
                    $this->reads[] = $offset;

                    return match ($offset) {
                        'value' => $this->value,
                        'weight' => 1,
                        'min' => 0,
                        'max' => 100,
                        default => null,
                    };
                }

                public function offsetSet(mixed $offset, mixed $value): void
                {
                    throw new LogicException('Formula inputs are immutable.');
                }

                public function offsetUnset(mixed $offset): void
                {
                    throw new LogicException('Formula inputs are immutable.');
                }
            };
            $result = $this->expression->evaluate('clamp(weighted_mean(m["value"], m["weight"]), m["min"], m["max"])', ['m' => $metrics]);
            self::assertSame($value === null ? null : (float) $value, $result);
            self::assertSame(['value', 'weight', 'min', 'max'], $metrics->reads);
        }
    }

    private static function customFormula(string $formula): ComputedMetricDefinition
    {
        return new ComputedMetricDefinition('computed.test', ['class' => $formula], '', [SymbolLevel::Class_]);
    }

}
