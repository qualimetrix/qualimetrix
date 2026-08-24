<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Stringable;

/**
 * The address of a kind of finding: a `(ruleName, code)` pair that
 * can appear on an emitted {@see Finding}.
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
 * The channel of an emitted finding is read via {@see Finding::channel()}.
 * There is deliberately no `fromFinding()` factory here: the pair would
 * form a dependency cycle, and the direction that survives is the one where
 * the richer type knows the primitive rather than the other way round.
 */
final readonly class FindingChannel implements Stringable
{
    /**
     * The separator of the string form, public because it is the canonical
     * spelling of a channel pair: {@see Rule\ChannelSelector} reads
     * user-authored text in exactly this shape, and a second declaration of
     * the character would be a second place to change it.
     */
    public const string SEPARATOR = '#';

    public function __construct(
        public string $ruleName,
        public string $code,
    ) {
        if ($ruleName === '') {
            throw new InvalidArgumentException('FindingChannel ruleName must not be empty.');
        }

        if ($code === '') {
            throw new InvalidArgumentException('FindingChannel code must not be empty.');
        }
    }

    /**
     * The channel a producer emits for one level of its own name — the one
     * place a level is turned into a channel code.
     *
     * The suffix is a property of the **static** rules that report at more
     * than one level, not of multi-level reporting as such: the six
     * `health.*` channels report at three levels each under one code with no
     * suffix at all, which is the evidence Р1 uses to take the level out of
     * the name in Ш5. What the static ones share is that each used to write
     * its suffix out by hand at both its declaration and its emission point.
     * That is how `CboRule` came to pick `.class` for any level that was not
     * `namespace`: a third level would have been mislabelled silently.
     */
    public static function leveled(string $ruleName, SymbolLevel $level): self
    {
        return new self($ruleName, $ruleName . '.' . $level->value);
    }

    /**
     * Parses the {@see toKey()} string form back into a channel.
     *
     * The static declaration mechanism (see
     * `Core\Rule\ChannelDeclarationReader`) stores declarations keyed by this
     * exact form, so this is how a consumer recovers the `(ruleName,
     * code)` pair from such a key — e.g. to check that the
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
            && $this->code === $other->code;
    }

    /**
     * Stable string form, suitable as an array key.
     */
    public function toKey(): string
    {
        return $this->ruleName . self::SEPARATOR . $this->code;
    }

    public function __toString(): string
    {
        return $this->toKey();
    }
}
