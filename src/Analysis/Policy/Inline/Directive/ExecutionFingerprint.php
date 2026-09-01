<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;

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
 * The message belongs to the boundary half on purpose. Several rules spell the
 * boundary into their prose instead of, or as well as, the `threshold` field,
 * so a key built from the field alone would miss exactly the difference the
 * threshold audit exists to see.
 *
 * **Every other field of a finding is part of its identity, and the split is
 * checked rather than trusted.** A field this class does not read does not
 * exist for the audit: a difference in it reads as "nothing moved", which is
 * the verdict that tells an author to delete an annotation. Since a field added
 * to {@see Finding} with a default compiles fine everywhere, what catches the
 * omission is
 * {@see \Qualimetrix\Tests\Analysis\Policy\Inline\Unit\Directive\ExecutionFingerprintFieldCoverageTest},
 * which reads the constructor reflectively and fails on any parameter neither
 * list names — the same treatment {@see Finding::reportedAsBreach()} already
 * gets.
 */
final readonly class ExecutionFingerprint
{
    /**
     * @param array<string, int> $tally whole finding => how many were produced
     * @param array<string, string> $identity whole finding => the same finding without its boundary
     * @param array<string, string> $channel whole finding => the channel it was published on, kept
     *                                       beside the key rather than parsed back out of it
     */
    private function __construct(
        private array $tally,
        private array $identity,
        private array $channel,
    ) {}

    /**
     * The fields that say what a finding *is*. Everything not named here or in
     * {@see BOUNDARY_FIELDS} is invisible to this comparison, which is why the
     * two lists are checked against `Finding`'s constructor by test.
     *
     * @var list<string>
     */
    public const array IDENTITY_FIELDS = [
        'location',
        'subject',
        'symbolPath',
        'ruleName',
        'code',
        'severity',
        'metricValue',
        'relatedLocations',
        'dependencyTarget',
        'dependencyType',
        'acceptedLevel',
        'occurrenceKey',
    ];

    /**
     * The fields that say which boundary a finding was judged against — the
     * ones a threshold directive is allowed to move without that counting as a
     * different outcome.
     *
     * Prose belongs here, and not only the `message`: rules spell the boundary
     * into the recommendation as readily as into the message
     * (`ComplexityRule` writes "Max cyclomatic complexity: 12 (threshold: 10)"
     * there). Counting that prose as part of what a finding *is* would turn
     * every overrun on such a rule into `Effective` — the finding fired both
     * times, but the sentence advising a fix moved with the number.
     *
     * @var list<string>
     */
    public const array BOUNDARY_FIELDS = ['threshold', 'message', 'recommendation'];

    /** @param list<Finding> $findings */
    public static function of(array $findings): self
    {
        $tally = [];
        $identity = [];
        $channel = [];

        foreach ($findings as $finding) {
            $identityKey = self::identityOf($finding);
            $key = $identityKey . "\0" . self::boundaryOf($finding);

            $tally[$key] = ($tally[$key] ?? 0) + 1;
            $identity[$key] = $identityKey;
            $channel[$key] = $finding->code;
        }

        ksort($tally);

        return new self($tally, $identity, $channel);
    }

    /**
     * What a finding *is*: every field except the two that say which boundary
     * it was judged against.
     *
     * Spelled out rather than read reflectively, because dynamic property
     * access is not something static analysis can check — which is the whole
     * point of the fields being named. {@see IDENTITY_FIELDS} declares what
     * this reads, and two tests hold the declaration to it: one reflective over
     * `Finding`'s constructor, one that moves each field in turn and demands
     * the fingerprint notice.
     */
    private static function identityOf(Finding $finding): string
    {
        return implode("\0", [
            self::location($finding->location),
            $finding->subject->toCanonical(),
            $finding->symbolPath->toString(),
            $finding->ruleName,
            $finding->code,
            $finding->severity->value,
            self::number($finding->metricValue),
            implode('|', array_map(self::location(...), $finding->relatedLocations)),
            $finding->dependencyTarget?->toCanonical() ?? '',
            $finding->dependencyType->name ?? '',
            $finding->acceptedLevel?->describe() ?? '',
            $finding->occurrenceKey->value ?? '',
        ]);
    }

    /** The boundary a finding names, in both places a rule may name it. */
    private static function boundaryOf(Finding $finding): string
    {
        return implode("\0", [
            self::number($finding->threshold),
            $finding->message,
            $finding->recommendation ?? '',
        ]);
    }

    private static function location(Location $location): string
    {
        return \sprintf(
            '%s:%s:%s',
            $location->file?->value() ?? '',
            $location->line ?? '',
            $location->precise ? '1' : '0',
        );
    }

    private static function number(int|float|null $value): string
    {
        return $value === null ? '' : var_export($value, true);
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
            fn(string $key): string => $this->channel[$key] ?? $other->channel[$key] ?? $key,
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

}
