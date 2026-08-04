<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Observation;

/**
 * The shape of a {@see DebtObservation}.
 *
 * The kind is not a taxonomy of rules — a rule's behaviour is a combination
 * of independent traits, and any partition of that space keeps meeting
 * members that straddle two classes. The kind describes one thing only:
 * how many axes the observation carries and whether it needs a stable
 * occurrence discriminator to be addressable at all.
 */
enum ObservationKind: string
{
    /** Exactly one measured axis (CCN of a method, MI of a class). */
    case Scalar = 'scalar';

    /** Two or more measured axes compared as a strict Pareto vector. */
    case Vector = 'vector';

    /**
     * A repeatable finding within one symbol. Axes are optional — an
     * occurrence may carry a magnitude as well as its multiplicity.
     */
    case Occurrence = 'occurrence';

    /**
     * The finding either exists for the symbol or it does not; no magnitude.
     * Carries zero axes and never an {@see OccurrenceKey} — see
     * {@see maximumAxes()} and {@see permitsOccurrenceKey()}.
     */
    case Presence = 'presence';

    /**
     * A finding whose identity is a structure spanning several symbols
     * (a dependency cycle). Its identity must be canonical and independent
     * of traversal order, so a graph observation is meaningless without an
     * {@see OccurrenceKey} and one is required.
     */
    case Graph = 'graph';

    /**
     * The smallest number of axes an observation of this kind may carry.
     */
    public function minimumAxes(): int
    {
        return match ($this) {
            self::Scalar => 1,
            self::Vector => 2,
            self::Occurrence, self::Presence, self::Graph => 0,
        };
    }

    /**
     * The largest number of axes an observation of this kind may carry,
     * or null when unbounded.
     *
     * `Presence` is capped at zero, matching its own docblock ("no
     * magnitude"): a finding either exists for the symbol or it does not,
     * and §7.3 of the ratchet-baseline plan defines no magnitude comparison
     * for presence findings. Nothing in the channel-trait inventory needs a
     * presence-with-magnitude member, so the cap is the semantics rather
     * than a placeholder for one still to be defined.
     */
    public function maximumAxes(): ?int
    {
        return match ($this) {
            self::Scalar => 1,
            self::Presence => 0,
            self::Vector, self::Occurrence, self::Graph => null,
        };
    }

    /**
     * Whether an observation of this kind is invalid without an
     * {@see OccurrenceKey}.
     */
    public function requiresOccurrenceKey(): bool
    {
        return $this === self::Graph;
    }

    /**
     * Whether an observation of this kind may carry an {@see OccurrenceKey}
     * at all.
     *
     * `Presence` is the one kind that forbids it rather than merely not
     * requiring it: §7.3 defines presence comparison over identity present /
     * new / missing, and never consults an occurrence key while doing so, so
     * a `Presence` observation carrying one would suggest a role the type
     * does not have. Every other kind permits one — `Graph` requires it,
     * the rest leave it optional.
     */
    public function permitsOccurrenceKey(): bool
    {
        return $this !== self::Presence;
    }
}
