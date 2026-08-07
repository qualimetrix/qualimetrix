<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline\Filter;

use Qualimetrix\Core\Violation\AcceptedLevel;

/**
 * What the ceiling decided about one group of findings sharing an identity.
 *
 * There are exactly three outcomes, and the third is the one a reader is
 * most likely to collapse into the second:
 *
 * - **accepted** — the group is within what an applicable entry accepted, so
 *   every member is removed from the output;
 * - **measured breach** — the group was compared against an applicable entry
 *   and exceeded it, so every member is reported and promoted to Error
 *   (§5.6 of the baseline-ceiling plan);
 * - **reported** — nothing bounded this group: there is no entry for it, or
 *   the entry could not be applied. Every member is reported at the severity
 *   its own rule gave it. This is *not* a breach; §5.1's governing invariant
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

    public function suppresses(): bool
    {
        return $this->suppresses;
    }
}
