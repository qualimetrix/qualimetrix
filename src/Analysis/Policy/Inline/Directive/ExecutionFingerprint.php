<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Analysis\Finding\Contract\Finding;

/**
 * What one rule execution produced, in the form two executions can be compared
 * in.
 *
 * Two tallies of the same findings: one keyed by the whole finding, one by what
 * a finding *is* without the boundary it names. Keeping both is what lets a
 * comparison say "the same findings fired against a different boundary" rather
 * than only "something moved" — the difference between a directive that does
 * nothing and one whose promise the measured value had already overrun.
 *
 * The message belongs to the whole-finding key on purpose. Several rules spell
 * the boundary into their prose instead of, or as well as, the `threshold`
 * field, so a key built from the field alone would miss exactly the difference
 * the threshold audit exists to see.
 */
final readonly class ExecutionFingerprint
{
    /**
     * @param array<string, int> $tally whole finding => how many were produced
     * @param array<string, string> $identity whole finding => the same finding without its boundary
     */
    private function __construct(
        private array $tally,
        private array $identity,
    ) {}

    /** @param list<Finding> $findings */
    public static function of(array $findings): self
    {
        $tally = [];
        $identity = [];

        foreach ($findings as $finding) {
            $identityKey = implode("\0", [
                $finding->code,
                $finding->subject->toCanonical(),
                $finding->severity->value,
                self::scalar($finding->metricValue),
            ]);
            $key = implode("\0", [$identityKey, self::scalar($finding->threshold), $finding->message]);

            $tally[$key] = ($tally[$key] ?? 0) + 1;
            $identity[$key] = $identityKey;
        }

        ksort($tally);

        return new self($tally, $identity);
    }

    /**
     * What one removal did, read off two whole runs.
     *
     * Three answers, and the middle one is the reason this object carries the
     * boundary at all. When the two runs differ *only* in what the same
     * findings say their boundary was — same channel, same subject, same
     * severity, same measured value — the directive was applied and the
     * finding fired regardless: a promise made and not kept, which is not the
     * same as an annotation that does nothing. Anything else that moved is an
     * outcome change.
     */
    public function compareTo(self $counterfactual): DirectiveEffect
    {
        $removed = self::excess($this->tally, $counterfactual->tally);
        $added = self::excess($counterfactual->tally, $this->tally);

        if ($removed === [] && $added === []) {
            return DirectiveEffect::Inert;
        }

        return self::byIdentity($removed, $this->identity) === self::byIdentity($added, $counterfactual->identity)
            ? DirectiveEffect::Overrun
            : DirectiveEffect::Effective;
    }

    public function reproduces(self $other): bool
    {
        return $this->tally === $other->tally;
    }

    /**
     * The channels the two runs disagree about, for a caller that has to say
     * what drifted.
     *
     * @return list<string>
     */
    public function disagreementWith(self $other): array
    {
        $channels = array_unique(array_map(
            static fn(string $key): string => explode("\0", $key)[0],
            [
                ...array_keys(self::excess($this->tally, $other->tally)),
                ...array_keys(self::excess($other->tally, $this->tally)),
            ],
        ));
        sort($channels);

        return $channels;
    }

    /**
     * The bag difference of two tallies: how many of each finding the left run
     * produced beyond the right one.
     *
     * @param array<string, int> $left
     * @param array<string, int> $right
     *
     * @return array<string, int>
     */
    private static function excess(array $left, array $right): array
    {
        $excess = [];

        foreach ($left as $key => $count) {
            $surplus = $count - ($right[$key] ?? 0);
            if ($surplus > 0) {
                $excess[$key] = $surplus;
            }
        }

        return $excess;
    }

    /**
     * The same difference counted by what a finding is rather than by what it
     * says its boundary was.
     *
     * @param array<string, int> $excess
     * @param array<string, string> $identity
     *
     * @return array<string, int>
     */
    private static function byIdentity(array $excess, array $identity): array
    {
        $counted = [];

        foreach ($excess as $key => $count) {
            $identityKey = $identity[$key] ?? $key;
            $counted[$identityKey] = ($counted[$identityKey] ?? 0) + $count;
        }

        ksort($counted);

        return $counted;
    }

    private static function scalar(int|float|null $value): string
    {
        return $value === null ? '' : var_export($value, true);
    }
}
