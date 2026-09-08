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
 * A key is in exactly one of three states, and the three are disjoint and
 * exhaustive:
 *
 * - **accepted** — written here, read here, printed in the "allowed here"
 *   sentence;
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
     * @param array<string, string> $accepted normalized key => declared kebab spelling
     * @param array<string, string> $answeredByTheClass normalized key => declared kebab spelling
     */
    private function __construct(
        private array $accepted,
        private array $answeredByTheClass,
    ) {}

    /**
     * @param string ...$accepted canonical kebab spellings
     */
    public static function of(string ...$accepted): self
    {
        return new self(self::index(array_values($accepted), []), []);
    }

    /**
     * Keys the class recognises only in order to answer about them itself.
     *
     * @param string ...$keys canonical kebab spellings
     */
    public function alsoAnsweredByTheClass(string ...$keys): self
    {
        $taken = $this->accepted + $this->answeredByTheClass;

        return new self(
            $this->accepted,
            $this->answeredByTheClass + self::index(array_values($keys), $taken),
        );
    }

    /**
     * True when $key — already folded through `ConfigKeySpelling::normalize()` —
     * is in either half, which is to say the class has something to say about it.
     */
    public function knows(string $key): bool
    {
        return isset($this->accepted[$key]) || isset($this->answeredByTheClass[$key]);
    }

    /**
     * True only for the half a reader may accept silently; a key the class
     * answers about itself is known but not accepted.
     */
    public function accepts(string $key): bool
    {
        return isset($this->accepted[$key]);
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
        $spellings = array_values($this->accepted);
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
                    'Rule option key "%s" is declared twice; the three key states must stay disjoint.',
                    $key,
                ));
            }

            $indexed[$normalized] = $key;
        }

        return $indexed;
    }
}
