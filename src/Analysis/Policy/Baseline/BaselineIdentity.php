<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use InvalidArgumentException;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;

/**
 * What a baseline entry is *about*: the exact typed subject, the channel,
 * optional semantic occurrence, and — when the finding carries one — the
 * logical dependency edge.
 *
 * Every violation in a run maps onto exactly one identity, and the set of
 * violations sharing an identity is the *group* an entry bounds. Nothing
 * about a finding's magnitude, severity, message or line takes part: those
 * change for reasons that are not debt getting worse.
 *
 * The subject key is an opaque canonical `MetricSubject` string. In
 * particular, declaration subjects retain their file/start-position identity:
 * two declarations of the same FQN are deliberately separate groups.
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
     * into an inert entry (ADR 0017). "Cannot occur" is a fact about emitted
     * findings; for everything else it is a check.
     */
    private const string KEY_SEPARATOR = "\x1F";

    /**
     * @throws InvalidArgumentException when the subject key is empty, or when any component
     *                                  carries the key separator and could therefore shift
     *                                  the boundaries of {@see key()}
     */
    public function __construct(
        public string $subjectKey,
        public ViolationChannel $channel,
        public ?string $occurrenceKey = null,
        public ?BaselineEdge $edge = null,
    ) {
        if ($subjectKey === '') {
            throw new InvalidArgumentException('A baseline identity subject key must not be empty.');
        }

        foreach ([
            'subject key' => $subjectKey,
            'channel' => $channel->toKey(),
            'occurrence key' => $occurrenceKey ?? '',
            'edge' => $edge?->key() ?? '',
        ] as $part => $value) {
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
            $violation->subject->toCanonical(),
            $violation->channel(),
            $violation->occurrenceKey?->value,
            $edge,
        );
    }

    /**
     * Stable string form — the key under which groups are collected and
     * entries are looked up.
     */
    public function key(): string
    {
        return $this->subjectKey
            . self::KEY_SEPARATOR . $this->channel->toKey()
            . self::KEY_SEPARATOR . ($this->occurrenceKey ?? '')
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
        $description = $this->subjectKey . ' ' . $this->channel->toKey();

        if ($this->occurrenceKey !== null) {
            $description .= ' [' . $this->occurrenceKey . ']';
        }

        if ($this->edge !== null) {
            $description .= ' -> ' . $this->edge->target
                . ($this->edge->type !== null ? ' (' . $this->edge->type->value . ')' : '');
        }

        return $description;
    }
}
