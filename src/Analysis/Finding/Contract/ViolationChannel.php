<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use InvalidArgumentException;
use Stringable;

/**
 * The address of a kind of finding: a `(ruleName, violationCode)` pair that
 * can appear on an emitted {@see Violation}.
 *
 * Channels are **not** in bijection with rule classes, which is why nothing
 * downstream may key on a rule class or on a rule name alone:
 *
 * - one rule class can emit several channels, some of them under rule names
 *   no class declares as its own (the architecture diagnostics);
 * - one rule class can emit one channel per configured definition (computed
 *   metrics), each with its own thresholds and inversion;
 * - one rule class can emit one channel whose boundaries depend on the symbol.
 *
 * Keying by rule class cannot see the first group at all; keying by rule name
 * alone loses the granularity of the second and third.
 *
 * The channel of an emitted finding is read via {@see Violation::channel()}.
 * There is deliberately no `fromViolation()` factory here: the pair would
 * form a dependency cycle, and the direction that survives is the one where
 * the richer type knows the primitive rather than the other way round.
 */
final readonly class ViolationChannel implements Stringable
{
    private const string SEPARATOR = '#';

    public function __construct(
        public string $ruleName,
        public string $violationCode,
    ) {
        if ($ruleName === '') {
            throw new InvalidArgumentException('ViolationChannel ruleName must not be empty.');
        }

        if ($violationCode === '') {
            throw new InvalidArgumentException('ViolationChannel violationCode must not be empty.');
        }
    }

    /**
     * Parses the {@see toKey()} string form back into a channel.
     *
     * The static declaration mechanism (see
     * `Core\Rule\ChannelDeclarationReader`) stores declarations keyed by this
     * exact form, so this is how a consumer recovers the `(ruleName,
     * violationCode)` pair from such a key — e.g. to check that the
     * `ruleName` half still names a rule that exists.
     *
     * @throws InvalidArgumentException when `$key` does not contain the
     *                                  separator, or either half would be empty
     */
    public static function fromKey(string $key): self
    {
        $parts = explode(self::SEPARATOR, $key, 2);

        if (\count($parts) !== 2) {
            throw new InvalidArgumentException(\sprintf(
                '"%s" is not a valid channel key — expected the form "ruleName%sviolationCode".',
                $key,
                self::SEPARATOR,
            ));
        }

        return new self($parts[0], $parts[1]);
    }

    public function equals(self $other): bool
    {
        return $this->ruleName === $other->ruleName
            && $this->violationCode === $other->violationCode;
    }

    /**
     * Stable string form, suitable as an array key.
     */
    public function toKey(): string
    {
        return $this->ruleName . self::SEPARATOR . $this->violationCode;
    }

    public function __toString(): string
    {
        return $this->toKey();
    }
}
