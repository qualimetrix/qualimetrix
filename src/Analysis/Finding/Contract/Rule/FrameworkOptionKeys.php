<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

/**
 * The option keys Finding consumes itself, under every producer's `rules:`
 * section, before any options class is asked about anything.
 *
 * No options class declares them and none ever will: {@see \Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory}
 * takes them out of the user's config on the way to `fromArray()`. They are
 * nevertheless legal where a user writes them, so every side that answers
 * *about* the keys a rule takes — the refusal that lists what is allowed, the
 * listing that advertises it — has to name these three alongside whatever the
 * class declared.
 *
 * **This is the one enumeration, and it used to be four.** The count is not a
 * guess: `RuleOptionKeyRecognition` held them as a kebab constant,
 * `RuleOptionsFactory` spelled each as a camel/snake literal pair, and
 * {@see \Qualimetrix\Analysis\Finding\Exclusion\ConfiguredSuppression} declared
 * them a third time under a docblock calling itself the enumeration every
 * consumer shares. That file's promise — "a fourth option is added here, and
 * the applying, judging and reporting sides gain it in the same edit" — is the
 * promise this class now keeps for it, from a namespace the answering sides
 * can also reach. `ConfiguredSuppression` stays the only *reader* of a
 * producer's raw options; that guard is about reading values and is untouched.
 *
 * Declared in the canonical kebab spelling users type. A door hands its keys
 * over in whatever spelling it produces, so a consumer comparing against these
 * folds both sides through `ConfigKeySpelling::normalize()` — and one that
 * must address a value under an authored spelling rewrites it with
 * `ConfigKeySpelling::rewriteLike()` rather than writing the variant out
 * a second time.
 */
final readonly class FrameworkOptionKeys
{
    public const string PATHS = 'suppress-paths';
    public const string NAMESPACES = 'suppress-namespaces';
    public const string NAMESPACE_CHANNELS = 'suppress-namespace-channels';

    /**
     * Canonical kebab spellings, sorted — the order the "allowed here" sentence
     * and the listing's footer both print them in.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $keys = [self::NAMESPACE_CHANNELS, self::NAMESPACES, self::PATHS];
        sort($keys);

        return $keys;
    }
}
