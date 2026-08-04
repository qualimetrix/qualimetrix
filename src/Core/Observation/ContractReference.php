<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Observation;

use InvalidArgumentException;
use LogicException;

/**
 * Names the debt contract an observation was produced under, and the
 * revision of that contract.
 *
 * **The version is deliberately not part of identity.** Identity is the
 * {@see $id} alone; the version is compared *after* two references have
 * been matched by id, never as part of the match. Folding the version into
 * the key makes a version bump behave as "the old thing disappeared and a
 * different thing appeared" — which reads as a resolution plus a brand-new
 * finding — instead of "the same thing can no longer be compared".
 *
 * Use {@see matchesIdentity()} to decide whether two references describe
 * the same contract, and {@see hasSameVersion()} afterwards to decide
 * whether they are still comparable.
 */
final readonly class ContractReference
{
    /**
     * @param string $id Stable contract identifier, conventionally the
     *                   violation code it accompanies (e.g.
     *                   `complexity.cyclomatic.method`).
     * @param int $version Contract revision, starting at 1. Bumped whenever
     *                     the axis set, a direction, or an epsilon changes.
     */
    public function __construct(
        public string $id,
        public int $version = 1,
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('ContractReference id must not be empty.');
        }

        if ($version < 1) {
            throw new InvalidArgumentException(
                \sprintf('ContractReference version must be a positive integer, got %d.', $version),
            );
        }
    }

    /**
     * Whether both references describe the same contract, ignoring version.
     *
     * This is the only identity test. See the class docblock for why the
     * version stays out of it.
     */
    public function matchesIdentity(self $other): bool
    {
        return $this->id === $other->id;
    }

    /**
     * Whether both references carry the same version.
     *
     * Only meaningful once {@see matchesIdentity()} returned true; comparing
     * versions across different contracts is not a defined question, and this
     * enforces that rather than leaving it advisory — §5.4/§5.7 of the
     * ratchet-baseline plan order identity before version deliberately, and a
     * caller that skips straight to this method has broken that ordering
     * regardless of what the docblock says.
     *
     * @throws LogicException when the two references describe different
     *                        contracts — call {@see matchesIdentity()} first.
     */
    public function hasSameVersion(self $other): bool
    {
        if (!$this->matchesIdentity($other)) {
            throw new LogicException(
                \sprintf(
                    'ContractReference::hasSameVersion() is only meaningful once matchesIdentity() has returned '
                    . 'true; "%s" and "%s" describe different contracts.',
                    $this->id,
                    $other->id,
                ),
            );
        }

        return $this->version === $other->version;
    }

    /**
     * Human-readable form for diagnostics. Never used as an identity key —
     * it embeds the version, which identity excludes.
     */
    public function describe(): string
    {
        return \sprintf('%s@v%d', $this->id, $this->version);
    }
}
