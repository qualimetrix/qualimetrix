<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Observation;

use InvalidArgumentException;
use Stringable;

/**
 * A stable discriminator that tells two findings of the same channel, on the
 * same symbol, apart across runs.
 *
 * This is the carrier that crosses the collector → rule seam: a collector
 * that can distinguish its findings produces one of these and puts it in the
 * data it hands to the rule; the rule attaches it to the
 * {@see DebtObservation} it emits. `DebtObservation::$occurrenceKey` is the
 * *sink*; this type is the *source*, and both sides must agree on it or two
 * packages pass their own tests while the seam is broken.
 *
 * ## Null semantics — the distinction that matters
 *
 * There are two different absences, and only one of them is representable:
 *
 * - **"This channel offers no stable discriminator."** A property of the
 *   channel, not of any one finding: nothing about the finding survives a
 *   re-run in a form that can be matched, so findings collapse into a
 *   counted bucket under their symbol. This is expressed by a null
 *   `DebtObservation::$occurrenceKey`, and it is a legitimate, permanent
 *   state — not a placeholder for a key someone forgot to compute.
 * - **"This particular occurrence has no key."** Not representable, and
 *   deliberately so. An `OccurrenceKey` can never hold an empty or null
 *   value, so a channel that discriminates its occurrences discriminates all
 *   of them. A missing key on one occurrence of a discriminating channel is
 *   an invariant violation to be raised at construction, never a null to be
 *   carried forward — carrying it would silently merge one occurrence into
 *   the bucket while its siblings stay individually addressed, and the
 *   resulting counts would not add up.
 *
 * ## Canonicalization
 *
 * Keys are compared byte-for-byte, so composition must be injective:
 * different part lists must never collapse onto the same key. Both composing
 * factories percent-escape the separator inside each part before joining, so
 * `fromParts('a|b')` and `fromParts('a', 'b')` are distinct.
 *
 * {@see of()} wraps an already-canonical value instead of composing one, so it
 * cannot apply that escaping — accepting an arbitrary string there would make
 * `of('a|b')` byte-identical to `fromParts('a', 'b')` and break the same
 * injectivity this section claims. It is therefore restricted to **opaque
 * single-token identities** (content hashes and the like) that contain
 * neither the separator nor the escape character: a value only `of()` could
 * have produced, never one the composing factories could have produced too.
 *
 * Percent-escaping rather than backslash-escaping, because the parts are
 * usually fully-qualified PHP names: a backslash escape would double every
 * namespace separator and leave the key unreadable in a file humans review.
 * A doubled-separator scheme was rejected outright — it is not injective once
 * an empty part is involved (`['a', '', 'b']` and `['a|b']` collide).
 *
 * Use {@see fromUnorderedParts()} whenever the parts form a set rather than
 * a sequence — a dependency cycle has no first member, so its key must not
 * depend on where a traversal happened to enter it.
 */
final readonly class OccurrenceKey implements Stringable
{
    private const string SEPARATOR = '|';

    private const string ESCAPE = '%';

    private function __construct(public string $value) {}

    /**
     * Wraps an opaque single-token identity — a content hash or similar value
     * that is already, by construction, in canonical form.
     *
     * Not a general-purpose wrapper: a value containing the separator or the
     * escape character could otherwise collide byte-for-byte with a key the
     * composing factories would have produced from different parts (e.g.
     * `of('a|b')` vs. `fromParts('a', 'b')`), which is exactly the collision
     * this type's injectivity claim rules out. Use {@see fromParts()} or
     * {@see fromUnorderedParts()} for anything built from more than one part.
     *
     * @throws InvalidArgumentException when the value is empty — absence is
     *                                  expressed by a null
     *                                  `DebtObservation::$occurrenceKey`,
     *                                  never by an empty key — or when it
     *                                  contains the separator or escape
     *                                  character reserved for composition.
     */
    public static function of(string $value): self
    {
        if ($value === '') {
            throw new InvalidArgumentException(
                'OccurrenceKey must not be empty. A channel that offers no stable discriminator '
                . 'leaves DebtObservation::$occurrenceKey null instead.',
            );
        }

        if (str_contains($value, self::SEPARATOR) || str_contains($value, self::ESCAPE)) {
            throw new InvalidArgumentException(
                \sprintf(
                    'OccurrenceKey::of("%s") is not an opaque single-token identity: it contains "%s" or "%s", '
                    . 'which the composing factories treat specially. A value built from more than one part must '
                    . 'go through fromParts() or fromUnorderedParts() instead, or the injectivity between of() '
                    . 'and the composing factories breaks.',
                    $value,
                    self::SEPARATOR,
                    self::ESCAPE,
                ),
            );
        }

        return new self($value);
    }

    /**
     * Composes a key from ordered parts — the order is part of the identity.
     *
     * @throws InvalidArgumentException when no parts are given, or when every
     *                                  part is empty.
     */
    public static function fromParts(string ...$parts): self
    {
        return new self(self::join($parts));
    }

    /**
     * Composes a key from unordered parts by sorting them first, so the key
     * is independent of the order in which the parts were discovered.
     *
     * This is the canonical form for graph identities (cycles) and for any
     * finding whose members form a set.
     *
     * @throws InvalidArgumentException when no parts are given, or when every
     *                                  part is empty.
     */
    public static function fromUnorderedParts(string ...$parts): self
    {
        sort($parts, \SORT_STRING);

        return new self(self::join($parts));
    }

    public function equals(?self $other): bool
    {
        return $other !== null && $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Variadic collection admits string keys when the caller uses named
     * arguments, so the keys are dropped before anything depends on order.
     *
     * @param array<array-key, string> $parts
     */
    private static function join(array $parts): string
    {
        $parts = array_values($parts);

        if ($parts === []) {
            throw new InvalidArgumentException('OccurrenceKey requires at least one part.');
        }

        $meaningful = array_filter($parts, static fn(string $part): bool => $part !== '');
        if ($meaningful === []) {
            throw new InvalidArgumentException('OccurrenceKey requires at least one non-empty part.');
        }

        // The escape character must be escaped first, or the two replacements
        // interfere and composition stops being injective.
        $escaped = array_map(
            static fn(string $part): string => str_replace(
                [self::ESCAPE, self::SEPARATOR],
                ['%25', '%7C'],
                $part,
            ),
            $parts,
        );

        return implode(self::SEPARATOR, $escaped);
    }
}
