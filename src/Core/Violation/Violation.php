<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Violation;

use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Rule\RuleLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * @qmx-threshold code-smell.constructor-overinjection error=16 — Violation is a flat domain VO; its constructor parameters mirror its public surface and bundling would obscure their independence. Re-evaluated when $acceptedLevel took the count to 14: still the same argument, and still inside the allowance
 */
final readonly class Violation
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
        public SymbolPath $symbolPath,
        public string $ruleName,
        public string $violationCode,
        public string $message,
        public Severity $severity,
        public int|float|null $metricValue = null,
        public ?RuleLevel $level = null,
        public array $relatedLocations = [],
        public ?string $recommendation = null,
        public int|float|null $threshold = null,
        public ?SymbolPath $dependencyTarget = null,
        public ?DependencyType $dependencyType = null,
        public ?AcceptedLevel $acceptedLevel = null,
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
     * {@see Violation} is `final readonly` and PHP has no `clone … with`:
     * open-coding a whole-object constructor call wherever a promotion
     * happens multiplies the places a later field can be dropped from.
     *
     * **Being the only such place does not make the copy self-maintaining.**
     * A field added to the constructor with a default is copied nowhere and
     * compiles fine, so what actually catches the omission is a test:
     * `ViolationTest::itCopiesEveryOtherFieldWhenItReportsItselfAsABreach()`
     * reads the constructor's parameters reflectively and fails on any it
     * has not been told is either copied here or rewritten here.
     */
    public function reportedAsBreach(AcceptedLevel $acceptedLevel): self
    {
        return new self(
            location: $this->location,
            symbolPath: $this->symbolPath,
            ruleName: $this->ruleName,
            violationCode: $this->violationCode,
            message: $this->message,
            severity: Severity::Error,
            metricValue: $this->metricValue,
            level: $this->level,
            relatedLocations: $this->relatedLocations,
            recommendation: $this->recommendation,
            threshold: $this->threshold,
            dependencyTarget: $this->dependencyTarget,
            dependencyType: $this->dependencyType,
            acceptedLevel: $acceptedLevel,
        );
    }

    /**
     * Returns the channel this violation was emitted on.
     *
     * The `(ruleName, violationCode)` pair, not the rule class — see
     * {@see ViolationChannel} for why the distinction is load-bearing.
     */
    public function channel(): ViolationChannel
    {
        return new ViolationChannel($this->ruleName, $this->violationCode);
    }

    /**
     * Returns unique identifier for baseline.
     *
     * Format: ruleName:symbolPath
     *
     * @internal Not used in production code. May be removed in a future version.
     */
    public function getFingerprint(): string
    {
        return \sprintf('%s:%s', $this->ruleName, $this->symbolPath->toCanonical());
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
