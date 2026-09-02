<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * What the run's configuration let each producer do, recorded by the execution
 * that decided it rather than re-derived from configuration afterwards.
 *
 * A rule decides its own enablement, and a hierarchical rule decides it per
 * level inside {@see \Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleInterface::analyzeLevel()};
 * the computed-metric host decides per producer against its own per-producer
 * options. Every consumer outside execution could only re-derive that from the
 * merged configuration, and a re-derivation is a second copy of the semantics.
 * The copy this type replaces read a single top-level `enabled` key and
 * therefore reported a rule disabled at every level as enabled, which made a
 * live directive on it `Inert` and the command exit 2.
 *
 * **Three answers, not two.** `declares()` and `ran()` are separate questions
 * on purpose: a producer that does not report at a level at all has no pair
 * here, and its absence is not a disablement. `@qmx-threshold coupling.cbo` on
 * a method is that case — the rule reports at class and namespace level — and
 * reading absence as "disabled" would answer a directive's question with a
 * fact about a level it never addressed.
 */
final readonly class LevelActivity
{
    /**
     * @param array<string, array<string, bool>> $byProducer producer name => level value => ran
     */
    private function __construct(private array $byProducer) {}

    /**
     * @param array<string, array<string, bool>> $byProducer
     */
    public static function fromMap(array $byProducer): self
    {
        return new self($byProducer);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /** Whether this producer reports at this level at all. */
    public function declares(string $producer, SymbolLevel $level): bool
    {
        return \array_key_exists($level->value, $this->byProducer[$producer] ?? []);
    }

    /**
     * Whether this producer's level ran. Meaningless unless
     * {@see declares()} holds for the same pair, and `false` there would be a
     * disablement the producer never had.
     */
    public function ran(string $producer, SymbolLevel $level): bool
    {
        return $this->byProducer[$producer][$level->value] ?? false;
    }

    /**
     * Whether configuration switched this producer off at every level it
     * declares — the question a directive asks when nothing tells it which
     * level it landed on, and the only honest answer for a producer whose
     * levels are all off.
     */
    public function disabledEverywhere(string $producer): bool
    {
        $levels = $this->byProducer[$producer] ?? [];

        return $levels !== [] && !\in_array(true, $levels, true);
    }

    /**
     * Whether any of the given levels ran.
     *
     * A level the producer does not declare is skipped rather than counted as
     * "did not run" — see the class docblock on why absence is not
     * disablement. When that leaves nothing to go on, the answer falls back to
     * the producer-wide fact {@see disabledEverywhere()}.
     *
     * Two different situations reach that fallback, and it is the right answer
     * for both, which is why they are not told apart here. The caller could
     * not determine a level at all — a directive on a whole file — and the
     * producer-wide fact is then the only fact there is. Or the caller named a
     * level this producer does not report at: `@qmx-threshold coupling.cbo` on
     * a method, where the rule reports at class and namespace level. Measured
     * on both branches of that second case: with the rule live the answer is
     * "ran", so the verdict stays where it was (`inert`, exit 2) instead of
     * becoming a disablement by vacuous truth; with every declared level
     * switched off the answer is "did not run", which is what a threshold
     * addressing that rule should hear, because a threshold addresses the rule
     * and the rule did not run. No input reaches this fallback and gets a
     * wrong answer from it.
     *
     * @param list<SymbolLevel> $levels
     */
    public function ranAtAnyOf(string $producer, array $levels): bool
    {
        $declared = false;

        foreach ($levels as $level) {
            if (!$this->declares($producer, $level)) {
                continue;
            }

            $declared = true;

            if ($this->ran($producer, $level)) {
                return true;
            }
        }

        return $declared ? false : !$this->disabledEverywhere($producer);
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public function toMap(): array
    {
        return $this->byProducer;
    }
}
