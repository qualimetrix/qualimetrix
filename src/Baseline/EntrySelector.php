<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

use InvalidArgumentException;
use Stringable;

/**
 * The short handle that addresses exactly one entry in a baseline file.
 *
 * **Why an entry needs one at all.** The obvious spelling
 * `<symbol>#<channel>` cannot address an entry: `#` already separates
 * `ruleName` from `violationCode` inside a channel key, and two forbidden
 * edges out of one class on one channel agree on every other component
 * (§6). The selector is therefore a digest of the *complete* identity —
 * symbol, channel and edge — so that whatever the identity distinguishes,
 * the selector distinguishes too.
 *
 * **Why 12 lowercase hex characters.** Two forces pull against each other:
 * a digest long enough that two entries in one file never collide, and short
 * enough that a human copies it out of `check` output into
 * `baseline:cleanup --remove` without a slip. 12 hex characters carry 48
 * bits; by the birthday bound the chance that *any* pair collides is about
 * n²/2⁴⁹ — roughly 1.8·10⁻⁷ for a ten-thousand-entry baseline and 1.8·10⁻⁵
 * for a hundred thousand, which is a scale no real baseline reaches. Twelve
 * is also the upper end of the range git uses for abbreviated object names,
 * so it is demonstrably a length people type. Shorter (8 hex = 32 bits) puts
 * a ten-thousand-entry file at roughly a 1-in-90 chance of an internal
 * collision, which is far too likely for an addressing scheme.
 *
 * **Why SHA-256 and not xxh3.** The selector is printed to users and ends up
 * in their scripts, so it must not depend on how their PHP was built. The
 * retired `ViolationHasher` used `xxh3` when the extension offered it and
 * SHA-256 otherwise, which made the same violation hash differently on two
 * machines. SHA-256 is always available and its truncation is uniformly
 * distributed.
 *
 * A collision, if one ever occurs, is visible rather than silent: a lookup
 * returns both entries ({@see Baseline::findBySelector()}) and the caller
 * reports the ambiguity instead of guessing.
 */
final readonly class EntrySelector implements Stringable
{
    /** Digest length in hexadecimal characters — see the class docblock. */
    public const int LENGTH = 12;

    private const string PATTERN = '/^[0-9a-f]{' . self::LENGTH . '}$/';

    private function __construct(
        public string $value,
    ) {}

    /**
     * Computes the selector for an identity key
     * ({@see BaselineIdentity::key()}) or for the raw payload of an entry
     * whose identity could not be parsed.
     */
    public static function forKey(string $key): self
    {
        return new self(substr(hash('sha256', $key), 0, self::LENGTH));
    }

    /**
     * Parses a selector typed or pasted by a user.
     *
     * Case is normalized, because a user copying from a report has no reason
     * to know the digest is lowercase.
     *
     * @throws InvalidArgumentException when the input is not a selector
     */
    public static function fromString(string $raw): self
    {
        $selector = self::tryFromString($raw);

        if ($selector === null) {
            throw new InvalidArgumentException(\sprintf(
                '"%s" is not a baseline entry selector — expected %d hexadecimal characters.',
                $raw,
                self::LENGTH,
            ));
        }

        return $selector;
    }

    /**
     * The non-throwing form, for command-line input that should produce a
     * message rather than a stack trace.
     */
    public static function tryFromString(string $raw): ?self
    {
        $normalized = strtolower(trim($raw));

        if (preg_match(self::PATTERN, $normalized) !== 1) {
            return null;
        }

        return new self($normalized);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
