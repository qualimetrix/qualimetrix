<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Stringable;

/**
 * The address of a kind of finding: one name that can appear on an emitted
 * {@see Finding}.
 *
 * The identity used to be a `(ruleName, code)` pair spelled `rule#code`. It is
 * the code alone now, because the pair never carried two independent facts: a
 * code names exactly one channel — the container build refuses two producers
 * declaring one code — so the rule half was a second spelling of something the
 * registry already answers. What the pair did carry was ambiguity: `rule#code`
 * is one identity in a key, two fields in JSON, and a third thing in a
 * selector, and every consumer had to decide which.
 *
 * The rule survives as what it always was, a **separate** published field and
 * an edge of the registry:
 *
 * - `Finding::$ruleName` is published as `rule` and is not always the producer:
 *   the architecture and annotation diagnostics are emitted under their own
 *   identity by a producer whose own name differs;
 * - {@see ChannelIdentityInterface::producerOf()} answers which rule produces a
 *   channel, for every consumer that needs the edge rather than the name.
 *
 * Channels are **not** in bijection with rule classes, which is why nothing
 * downstream may key on a rule class:
 *
 * - one rule class can emit several channels, some of them published under rule
 *   names no class declares as its own (the architecture diagnostics);
 * - one rule class can emit one channel per configured definition (computed
 *   metrics), each with its own thresholds and inversion;
 * - one rule class can emit one channel whose boundaries depend on the symbol.
 *
 * The channel of an emitted finding is read via {@see Finding::channel()}.
 * There is deliberately no `fromFinding()` factory here: the pair would
 * form a dependency cycle, and the direction that survives is the one where
 * the richer type knows the primitive rather than the other way round.
 */
final readonly class FindingChannel implements Stringable
{
    /**
     * The separator of the retired `rule#code` spelling.
     *
     * Kept, and public, because the spelling has to be **refused** rather than
     * merely stop matching. Every surface that used to accept it — the three
     * `@qmx-ignore` forms, the selection selectors, the channel-keyed exclusion
     * option — reads user-authored text whose grammar admits `#`, so a retired
     * form would otherwise parse into a name no producer can have and address
     * nothing in silence. One declaration of the character, so the refusals
     * cannot drift apart from each other.
     */
    public const string RETIRED_PAIR_SEPARATOR = '#';

    public function __construct(
        public string $code,
    ) {
        if ($code === '') {
            throw new InvalidArgumentException('FindingChannel code must not be empty.');
        }

        if (self::isRetiredPairSpelling($code)) {
            throw new InvalidArgumentException(\sprintf(
                'FindingChannel code "%s" carries "%s". %s',
                $code,
                self::RETIRED_PAIR_SEPARATOR,
                self::retiredPairAdvice($code),
            ));
        }
    }

    /**
     * The channel a producer emits for one level of its own name — the one
     * place a level is turned into a channel code.
     *
     * The suffix is a property of the **static** rules that report at more
     * than one level, not of multi-level reporting as such: the six
     * `health.*` channels report at three levels each under one name with no
     * suffix at all, which is the evidence Р1 uses to take the level out of
     * the name in Ш5c. What the static ones share is that each used to write
     * its suffix out by hand at both its declaration and its emission point.
     * That is how `CboRule` came to pick `.class` for any level that was not
     * `namespace`: a third level would have been mislabelled silently.
     */
    public static function leveled(string $ruleName, SymbolLevel $level): self
    {
        return new self($ruleName . '.' . $level->value);
    }

    /** Whether authored text is written in the retired `rule#code` spelling. */
    public static function isRetiredPairSpelling(string $raw): bool
    {
        return str_contains($raw, self::RETIRED_PAIR_SEPARATOR);
    }

    /**
     * What to say to an author who wrote the retired spelling: the half that is
     * now the whole name, so the correction is a deletion and not a lookup.
     */
    public static function retiredPairAdvice(string $raw): string
    {
        $parts = explode(self::RETIRED_PAIR_SEPARATOR, $raw);
        $code = end($parts);

        return \sprintf(
            'The "ruleName%scode" spelling of a channel is gone: a channel is named by its code alone.'
            . ' Write "%s".',
            self::RETIRED_PAIR_SEPARATOR,
            $code === false || $code === '' ? '<the channel name>' : $code,
        );
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
