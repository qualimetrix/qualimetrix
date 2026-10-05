<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Ceiling;

use Qualimetrix\Analysis\Finding\Contract\AcceptedLevel;

/**
 * What the ceiling decided about one group of findings sharing an identity.
 *
 * There are four outcomes, and the third is the one a reader is most likely
 * to collapse into the second:
 *
 * - **accepted** — the group is within what an applicable entry accepted, so
 *   every member is removed from the output;
 * - **measured breach** — the group was compared against an applicable entry
 *   and exceeded it, so every member is reported and promoted to Error
 *   (ADR 0017);
 * - **not compared** — an entry exists, but this invocation did not establish
 *   a complete comparable group. The original severity and recorded level
 *   reach reporting with the reason;
 * - **reported** — nothing bounded this group: there is no entry for it, or
 *   the entry could not be applied. Every member is reported at the severity
 *   its own rule gave it. This is *not* a breach; ADR 0017 governing invariant
 *   is that an entry the mechanism cannot apply says nothing about the debt,
 *   and failing a build on it would punish a user for a stale file rather
 *   than for worsening code.
 *
 * Keeping "reported" and "breached" as separate outcomes rather than "not
 * accepted" is what makes that distinction unforgettable at the one site
 * that acts on it.
 */
final readonly class GroupCeilingVerdict
{
    private function __construct(
        private bool $suppresses,
        public ?AcceptedLevel $breachedLevel,
        public ?AcceptedLevel $uncomparedLevel = null,
        public ?IncomparabilityReason $uncomparedReason = null,
    ) {}

    /**
     * The group is within its entry: it does not reach the output.
     */
    public static function accepted(): self
    {
        return new self(true, null);
    }

    /**
     * Nothing applicable bounds this group: report it exactly as the rule
     * produced it.
     */
    public static function reported(): self
    {
        return new self(false, null);
    }

    /**
     * The group was measured against an applicable entry and exceeded it.
     */
    public static function breached(AcceptedLevel $acceptedLevel): self
    {
        return new self(false, $acceptedLevel);
    }

    public static function uncompared(AcceptedLevel $acceptedLevel, IncomparabilityReason $reason): self
    {
        return new self(false, null, $acceptedLevel, $reason);
    }

    public function status(): string
    {
        return match (true) {
            $this->suppresses => 'accepted',
            $this->breachedLevel !== null => 'breached',
            $this->uncomparedLevel !== null => 'not-compared',
            default => 'reported',
        };
    }

    public function suppresses(): bool
    {
        return $this->suppresses;
    }
}
