<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use InvalidArgumentException;

/**
 * The catalog metric keys a channel's reported magnitude may come from.
 *
 * **What this type does and does not buy.** It takes `string`s, so it is not a
 * typed reference to a metric: the type gives non-emptiness by arity, the
 * existence of the key is decided by registry assembly
 * ({@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass}),
 * and nothing here or there protects against a typo in the *value* of a
 * constant. What is typed is the **call site**: an author writes
 * `MetricName::COMPLEXITY_CCN` rather than `'complexity.ccn'`, which is what a
 * literal guard reading PHP tokens can see. Any wording stronger than that is
 * a claim this class does not honour.
 *
 * A separate type rather than a `list<string>` parameter on
 * {@see ChannelDeclaration::judging()} for two reasons, in this order:
 * the declaration's levels are already variadic and a signature cannot carry a
 * second variadic; and a plain list would make the empty list *expressible*,
 * which is the thing {@see ChannelDeclaration}'s own docblock refuses for its
 * levels. Here the same refusal is spelled the same way — a mandatory first
 * key plus a variadic rest — so "a judging channel names at least one metric"
 * is unstatable-otherwise rather than checked.
 *
 * **More than one key means candidates, not a set to sum.** A channel declares
 * several when its own body picks between them: `coupling.cbo` reads
 * `coupling.cbo` or `coupling.cbo-app` depending on its `scope` option, and a
 * complexity channel reads a base key at callable level and that key's `.max`
 * aggregate at class level. Author order is preserved rather than sorted —
 * unlike the levels of a declaration, these are alternatives whose order is the
 * order the producer's own code considers them in, and canonicalising it would
 * claim a relation between them that does not exist.
 */
final readonly class JudgedMetrics
{
    /** @var non-empty-list<string> */
    public array $keys;

    /**
     * The constructor is private, so the only caller is {@see of()} below and
     * the empty list is excluded by that signature rather than refused here.
     *
     * @param non-empty-list<string> $keys
     */
    private function __construct(array $keys)
    {
        $this->keys = self::withoutRepetition($keys);
    }

    /**
     * The metric keys this channel's magnitude may be read from, at least one.
     *
     * The first key is mandatory and the rest variadic, so a caller cannot
     * express the empty list at all. With the constructor private, this
     * signature is the whole enforcement of "a judging channel names a metric".
     */
    public static function of(string $metricKey, string ...$moreKeys): self
    {
        return new self([$metricKey, ...array_values($moreKeys)]);
    }

    /**
     * @param non-empty-list<string> $keys
     *
     * @return non-empty-list<string>
     */
    private static function withoutRepetition(array $keys): array
    {
        foreach ($keys as $key) {
            // A repeated key is refused rather than collapsed, as a repeated
            // level is: it says the author believes the channel reads that
            // metric under two different circumstances, and one of the two is
            // written wrong.
            if (\count(array_keys($keys, $key, true)) > 1) {
                throw new InvalidArgumentException(\sprintf(
                    'A channel names the judged metric "%s" more than once.',
                    $key,
                ));
            }
        }

        return $keys;
    }
}
