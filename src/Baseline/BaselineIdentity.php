<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

use InvalidArgumentException;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Core\Violation\ViolationChannel;

/**
 * What a baseline entry is *about*: the symbol, the channel, and — when the
 * finding carries one — the dependency edge (§5.1 of the baseline-ceiling
 * plan).
 *
 * Every violation in a run maps onto exactly one identity, and the set of
 * violations sharing an identity is the *group* an entry bounds. Nothing
 * about a finding's magnitude, severity, message or line takes part: those
 * change for reasons that are not debt getting worse.
 *
 * ## Symbol keys are not unique per declaration, and that is accepted
 *
 * The symbol half is `SymbolPath::toCanonical()`, which is a name, not a
 * location. Three consequences are known and deliberately not fixed here
 * (§13.9 of the plan; the question was left open as §14.2 and is answered
 * in this class):
 *
 * - **Two same-FQN declarations share one entry.** PHP cannot load both, so
 *   the situation is already a defect in the analysed code; merging their
 *   findings into one group bounds their *sum*, which errs toward reporting:
 *   a member added to either declaration breaches the shared ceiling.
 * - **A trait method is keyed once for all consumers**, because it is
 *   measured once at its declaration site.
 * - **`SymbolPath`'s `__PROJECT__` sentinel is a legal PHP namespace name**,
 *   so a namespace literally called `__PROJECT__` canonicalizes to
 *   `project:`. That is a pre-existing defect of `SymbolPath` itself,
 *   independent of the baseline, and is pinned rather than papered over
 *   here.
 *
 * **Why no discriminator was added.** The only candidate available at the
 * emission point is the declaring file, and adding it would cost more than
 * it buys: a symbol moved between files with no other change would strand
 * every entry that mentions it, turning the single most common refactor into
 * a wall of "new" findings — against the goal of not punishing improvement.
 * Worse, the discriminator does not exist for the levels that need it least
 * and would need it most: namespace-, project- and file-keyed findings have
 * no single declaring file. A partial discriminator would make the identity
 * inconsistent across levels while still not resolving the collisions above.
 *
 * **Aggregation-level keys are unambiguous, for a reason worth stating.**
 * The namespace *strategy* (`psr4` / `tokenizer` / `chain`) and the
 * aggregation *prefixes* both change which namespace a symbol is reported
 * under without any code changing. Neither breaks the identity, because both
 * act before the key is formed: the key is always the namespace that was
 * actually reported, and the same configuration produces the same key. What
 * they do cause is a *rename* — the old key goes stale and the new one fires
 * as new, which is the documented rename behaviour (§13.7), noisy rather
 * than silent. An aggregation prefix additionally introduces a parent
 * namespace whose findings are a different symbol from its children's; those
 * are separate identities holding separate ceilings, never one merged group.
 */
final readonly class BaselineIdentity
{
    /**
     * Separator inside {@see key()}. ASCII Unit Separator, chosen because a
     * printable separator could occur inside a component and would let two
     * different identities produce one key.
     *
     * A violation emitted by a rule can never carry this byte — `SymbolPath`,
     * rule names and violation codes are all built from source identifiers —
     * but an identity assembled from a baseline *file* takes its components
     * from arbitrary JSON strings, and a JSON string may spell any code point.
     * So the property is **enforced by the constructor** rather than assumed:
     * a component carrying the separator is rejected, which the loader turns
     * into an inert entry (§6). "Cannot occur" is a fact about emitted
     * findings; for everything else it is a check.
     */
    private const string KEY_SEPARATOR = "\x1F";

    /**
     * @throws InvalidArgumentException when the symbol key is empty, or when any component
     *                                  carries the key separator and could therefore shift
     *                                  the boundaries of {@see key()}
     */
    public function __construct(
        public string $symbolKey,
        public ViolationChannel $channel,
        public ?BaselineEdge $edge = null,
    ) {
        if ($symbolKey === '') {
            throw new InvalidArgumentException('A baseline identity symbol key must not be empty.');
        }

        foreach (['symbol key' => $symbolKey, 'channel' => $channel->toKey(), 'edge' => $edge?->key() ?? ''] as $part => $value) {
            if (str_contains($value, self::KEY_SEPARATOR)) {
                throw new InvalidArgumentException(\sprintf(
                    'A baseline identity %s must not contain the key separator (ASCII Unit Separator).',
                    $part,
                ));
            }
        }
    }

    /**
     * The identity of a finding as emitted.
     */
    public static function forViolation(Violation $violation): self
    {
        $edge = null;

        if ($violation->dependencyTarget !== null) {
            $edge = new BaselineEdge(
                $violation->dependencyTarget->toCanonical(),
                $violation->dependencyType,
            );
        }

        return new self(
            $violation->symbolPath->toCanonical(),
            $violation->channel(),
            $edge,
        );
    }

    /**
     * Stable string form — the key under which groups are collected and
     * entries are looked up.
     */
    public function key(): string
    {
        return $this->symbolKey
            . self::KEY_SEPARATOR . $this->channel->toKey()
            . self::KEY_SEPARATOR . ($this->edge?->key() ?? '');
    }

    /**
     * The short handle a user copies to address this entry.
     */
    public function selector(): EntrySelector
    {
        return EntrySelector::forKey($this->key());
    }

    public function equals(self $other): bool
    {
        return $this->key() === $other->key();
    }

    /**
     * Human-readable form for reports: symbol, channel and, when present,
     * the edge that distinguishes this entry from its neighbours.
     */
    public function describe(): string
    {
        $description = $this->symbolKey . ' ' . $this->channel->toKey();

        if ($this->edge !== null) {
            $description .= ' -> ' . $this->edge->target
                . ($this->edge->type !== null ? ' (' . $this->edge->type->value . ')' : '');
        }

        return $description;
    }
}
