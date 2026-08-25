<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * @qmx-threshold code-smell.constructor-overinjection warning=17 error=17 — Finding is a flat immutable transport VO; its 15 constructor parameters mirror independent public fields that a parameter bundle would obscure.
 */
final readonly class Finding
{
    /**
     * @param list<Location> $relatedLocations Additional locations (e.g., other copies of duplicated code)
     * @param ?SymbolPath $dependencyTarget Target symbol of the offending dependency edge (for dependency-based rules)
     * @param ?DependencyType $dependencyType Type of the offending dependency edge (for dependency-based rules)
     * @param ?AcceptedLevel $acceptedLevel The level a baseline entry had accepted this finding's group at,
     *                                      present only on a measured breach of that level. `null` on every
     *                                      other finding, including one no baseline ever judged — see
     *                                      {@see reportedAsBreach()}
     */
    public function __construct(
        public Location $location,
        public MetricSubject $subject,
        public SymbolPath $symbolPath,
        public string $ruleName,
        public string $code,
        public string $message,
        public Severity $severity,
        public int|float|null $metricValue = null,
        public array $relatedLocations = [],
        public ?string $recommendation = null,
        public int|float|null $threshold = null,
        public ?SymbolPath $dependencyTarget = null,
        public ?DependencyType $dependencyType = null,
        public ?AcceptedLevel $acceptedLevel = null,
        public ?OccurrenceKey $occurrenceKey = null,
    ) {}

    /**
     * The same finding, reported as a measured breach of what a baseline
     * accepted: severity raised to Error, carrying the level it was accepted
     * at, as specified by ADR 0017.
     *
     * Promotion is what makes a breach fail a build at all — the default
     * `fail_on` is `error`, so without it a channel whose findings are
     * Warnings could grow without bound behind a baseline. It is scoped to a
     * *measured* breach and to nothing else: an entry the mechanism could not
     * apply says nothing about the debt, and its findings keep the severity
     * the rule gave them.
     *
     * The reconstruction lives here rather than at the call site because
     * {@see Finding} is `final readonly` and PHP has no `clone … with`:
     * open-coding a whole-object constructor call wherever a promotion
     * happens multiplies the places a later field can be dropped from.
     *
     * **Being the only such place does not make the copy self-maintaining.**
     * A field added to the constructor with a default is copied nowhere and
     * compiles fine, so what actually catches the omission is a test:
     * `FindingTest::itCopiesEveryOtherFieldWhenItReportsItselfAsABreach()`
     * reads the constructor's parameters reflectively and fails on any it
     * has not been told is either copied here or rewritten here.
     */
    public function reportedAsBreach(AcceptedLevel $acceptedLevel): self
    {
        return new self(
            location: $this->location,
            subject: $this->subject,
            symbolPath: $this->symbolPath,
            ruleName: $this->ruleName,
            code: $this->code,
            message: $this->message,
            severity: Severity::Error,
            metricValue: $this->metricValue,
            relatedLocations: $this->relatedLocations,
            recommendation: $this->recommendation,
            threshold: $this->threshold,
            dependencyTarget: $this->dependencyTarget,
            dependencyType: $this->dependencyType,
            acceptedLevel: $acceptedLevel,
            occurrenceKey: $this->occurrenceKey,
        );
    }

    /**
     * Returns the channel this finding was emitted on.
     *
     * The `(ruleName, code)` pair, not the rule class — see
     * {@see FindingChannel} for why the distinction is load-bearing.
     */
    public function channel(): FindingChannel
    {
        return new FindingChannel($this->code);
    }

    /**
     * Returns unique identifier for baseline.
     *
     * Format: channel:subject[:occurrence][:edge]
     *
     * Used by {@see \Qualimetrix\Reporting\Formatter\GitLabCodeQualityFormatter::generateFingerprint()}
     * (hashed with md5, for cross-MR finding tracking) and by
     * {@see \Qualimetrix\Reporting\Formatter\Sarif\SarifFormatter} (as
     * `partialFingerprints.primaryLocationLineHash`, for GitHub code scanning
     * alert identity). A change to any input here — the occurrence key length
     * included — resets both.
     */
    public function getFingerprint(): string
    {
        $parts = [$this->channel()->code, $this->subject->toCanonical()];

        if ($this->occurrenceKey !== null) {
            $parts[] = $this->occurrenceKey->value;
        }

        if ($this->dependencyTarget !== null) {
            $target = $this->dependencyTarget->toCanonical();
            $parts[] = $this->dependencyType !== null
                ? $this->dependencyType->value . ':' . $target
                : 'untyped-edge:' . \strlen($target) . ':' . $target;
        }

        return implode(':', $parts);
    }

    /**
     * Returns the best available human-readable message.
     *
     * Prefers recommendation when available, falls back to technical message.
     */
    public function getDisplayMessage(): string
    {
        return $this->recommendation ?? $this->message;
    }
}
