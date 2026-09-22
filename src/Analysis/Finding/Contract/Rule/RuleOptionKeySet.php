<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;

/**
 * The option keys one options class — or one level slot of one — answers for.
 *
 * A rule's own `fromArray()` body is the authority on which keys it reads, and
 * reflection over constructor parameters cannot see into a method body. This
 * value is how that authority is stated instead of guessed, in the same shape
 * ADR 0038 gave the warning boundary: the class says, the reader asks.
 *
 * A key is in exactly one of four states, and the four are disjoint and
 * exhaustive:
 *
 * - **accepted** — written here, read here, printed in the "allowed here"
 *   sentence;
 * - **accepted and validated by the class** — writable and printed like an
 *   accepted key, but its ingress-specific carrier has no generic
 *   {@see RuleOptionShape}; the options class validates it in its own words;
 * - **answered by the class** — recognised only so that `fromArray()` may
 *   refuse it in its own words, or accept a spelling that means "leave things
 *   as they are". A reader must neither warn nor refuse on these: the class
 *   speaks for itself. `UnassignedClassOptions::assertNoContradictoryEnabled()`
 *   is the case that forces this state to exist;
 * - **unknown** — everything else.
 *
 * Keys are declared in the canonical kebab spelling users type
 * (`docs/internal/CLI_CONVENTIONS.md`), and that is the only spelling
 * {@see self::acceptedForDisplay()} prints. Comparison folds both sides through
 * {@see ConfigKeySpelling::normalize()}, so snake, camel and kebab spellings of
 * one key stay the same key.
 */
final readonly class RuleOptionKeySet
{
    /**
     * An accepted key carries its form; a key the class answers about itself
     * does not, because the class — not the reader — decides what may stand
     * there. The form is part of the accepted entry rather than a second
     * declaration beside it: a key whose shape were stated somewhere else
     * could go out of step with the key set that admits it.
     *
     * @param array<string, string> $accepted normalized key => declared kebab spelling
     * @param array<string, RuleOptionShape> $shapes normalized key => the form its value may take
     * @param array<string, string> $acceptedAndValidatedByTheClass normalized key => declared kebab spelling
     * @param array<string, string> $answeredByTheClass normalized key => declared kebab spelling
     */
    private function __construct(
        private array $accepted,
        private array $shapes,
        private array $acceptedAndValidatedByTheClass,
        private array $answeredByTheClass,
    ) {}

    /**
     * @param array<string, RuleOptionShape> $accepted canonical kebab spelling => the form the value may take
     */
    public static function of(array $accepted): self
    {
        $indexed = self::index(array_map(strval(...), array_keys($accepted)), []);
        $shapes = [];

        foreach ($accepted as $key => $shape) {
            $shapes[ConfigKeySpelling::normalize((string) $key)] = $shape;
        }

        return new self($indexed, $shapes, [], []);
    }

    /**
     * Keys the class recognises only in order to answer about them itself.
     *
     * @param string ...$keys canonical kebab spellings
     */
    public function alsoAnsweredByTheClass(string ...$keys): self
    {
        $taken = $this->accepted + $this->acceptedAndValidatedByTheClass + $this->answeredByTheClass;

        return new self(
            $this->accepted,
            $this->shapes,
            $this->acceptedAndValidatedByTheClass,
            $this->answeredByTheClass + self::index(array_values($keys), $taken),
        );
    }

    /**
     * Adds writable keys whose authored/runtime carriers the options class
     * validates itself because no generic {@see RuleOptionShape} describes
     * them without importing an owner-specific type.
     */
    public function alsoAcceptedAndValidatedByTheClass(string ...$keys): self
    {
        $taken = $this->accepted + $this->acceptedAndValidatedByTheClass + $this->answeredByTheClass;

        return new self(
            $this->accepted,
            $this->shapes,
            $this->acceptedAndValidatedByTheClass + self::index(array_values($keys), $taken),
            $this->answeredByTheClass,
        );
    }

    /**
     * The hierarchical rule's level slots, taken from the one place their
     * existence is stated.
     *
     * A slot is an accepted key like any other, but its name and its form are
     * not written here a second time: writing `'callable' => block()` beside a
     * `levelOptionsClasses()` that already names `callable` is two declarations
     * of one fact, and they were already out of step — the walk into a slot
     * accepts `null` ("an empty level block means what an omitted one means")
     * while a hand-written `block()` did not say so. The form is therefore
     * fixed here, once: a block of options, or nothing.
     *
     * @param array<string, class-string<LevelOptionsInterface>> $levelOptionsClasses as `HierarchicalRuleOptionsInterface` names them
     */
    public function withLevelSlots(array $levelOptionsClasses): self
    {
        $slots = array_map(strval(...), array_keys($levelOptionsClasses));
        $shapes = $this->shapes;
        $taken = $this->accepted + $this->acceptedAndValidatedByTheClass + $this->answeredByTheClass;

        foreach ($slots as $slot) {
            $shapes[ConfigKeySpelling::normalize($slot)] = RuleOptionShape::block()->orNull();
        }

        return new self(
            $this->accepted + self::index($slots, $taken),
            $shapes,
            $this->acceptedAndValidatedByTheClass,
            $this->answeredByTheClass,
        );
    }

    /**
     * True when $key — already folded through `ConfigKeySpelling::normalize()` —
     * is in any declared state, which is to say the class has something to say about it.
     */
    public function knows(string $key): bool
    {
        return isset($this->accepted[$key])
            || isset($this->acceptedAndValidatedByTheClass[$key])
            || isset($this->answeredByTheClass[$key]);
    }

    /**
     * True for either writable state; a key the class recognises only to give
     * a bespoke answer is known but not accepted.
     */
    public function accepts(string $key): bool
    {
        return isset($this->accepted[$key]) || isset($this->acceptedAndValidatedByTheClass[$key]);
    }

    /**
     * The spelling the class declared an accepted key under, for a $key already
     * folded through {@see ConfigKeySpelling::normalize()}; null when nothing
     * here accepts it.
     *
     * Asked rather than derived. The declared spelling is canonical kebab by
     * the invariant {@see self::index()} enforces, so rewriting the normalized
     * form would produce the same string today — and would be a second
     * derivation of a fact this object already holds, agreeing by construction
     * until the invariant ever moves.
     */
    public function spellingOf(string $key): ?string
    {
        return $this->accepted[$key] ?? $this->acceptedAndValidatedByTheClass[$key] ?? null;
    }

    /**
     * The declared form of a generically validated accepted key — already
     * folded through `ConfigKeySpelling::normalize()` — or null when the class
     * validates the carrier itself or the key is not accepted.
     */
    public function shapeOf(string $key): ?RuleOptionShape
    {
        return $this->shapes[$key] ?? null;
    }

    /**
     * Canonical kebab spellings of the accepted half, sorted, for the
     * "allowed here" sentence. The answered-by-the-class half is deliberately
     * absent: it is not a list of keys anyone may write.
     *
     * @return list<string>
     */
    public function acceptedForDisplay(): array
    {
        $spellings = array_values($this->accepted + $this->acceptedAndValidatedByTheClass);
        sort($spellings);

        return $spellings;
    }

    /**
     * @param list<string> $keys
     * @param array<string, string> $taken normalized keys already spoken for
     *
     * @return array<string, string> normalized key => declared kebab spelling
     */
    private static function index(array $keys, array $taken): array
    {
        $indexed = [];

        foreach ($keys as $key) {
            $normalized = ConfigKeySpelling::normalize($key);

            if ($normalized === '') {
                throw new LogicException('A rule option key set cannot declare a blank key.');
            }

            if (ConfigKeySpelling::rewriteLike($normalized, 'a-b') !== $key) {
                throw new LogicException(\sprintf(
                    'Rule option key "%s" must be declared in canonical kebab spelling ("%s").',
                    $key,
                    ConfigKeySpelling::rewriteLike($normalized, 'a-b'),
                ));
            }

            if (isset($taken[$normalized]) || isset($indexed[$normalized])) {
                throw new LogicException(\sprintf(
                    'Rule option key "%s" is declared twice; the four key states must stay disjoint.',
                    $key,
                ));
            }

            $indexed[$normalized] = $key;
        }

        return $indexed;
    }
}
